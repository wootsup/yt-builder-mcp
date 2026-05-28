<?php
/**
 * POST /v1/setup/pickup — unauthenticated nonce-exchange endpoint.
 *
 * Cookbook §2.8 / §2.8.5 / §2.8.6 / §2.8.7 / §2.10.4 / §2.11.4 /
 * §2.12.4 #12 + §6.4. One-shot, IP-bound, short-TTL nonce → token
 * exchange for the "Copy AI prompt" flow in com_ytbmcp's Joomla admin
 * (com_ytbmcp Dashboard Reveal-Box). The npm setup wizard
 * (run by the AI client's Bash tool) POSTs `{nonce}` here and receives
 * `{token, site_url, plugin_version}` in exchange. The transient is
 * deleted on read so a pickup-URL cannot be replayed.
 *
 * Public — extends {@see BaseController}, NOT
 * {@see \WootsUp\BuilderMcp\Platform\Joomla\Rest\AbstractApiController}.
 * The nonce IS the credential; requiring a Bearer here would defeat
 * the purpose (the wizard does not HAVE one yet).
 *
 * SECURITY HARDENING (Audit A2 P0-3, 2026-05-24):
 *
 *  - SEC-IP-1 fail-closed (cookbook §2.12.3 Risk #6): when REMOTE_ADDR
 *    is empty/missing/non-string we return 429 immediately. We cannot
 *    enforce per-IP rate-budget without an IP, so refusing is the
 *    safe default. Logged with ip_hash='empty' so misconfigured-proxy
 *    drift surfaces in the audit log.
 *
 *  - REMOTE_ADDR direct read (cookbook §2.12.3 Risk #6): we DO NOT
 *    trust X-Forwarded-For — that header is caller-controllable. Sites
 *    behind a reverse-proxy must set REMOTE_ADDR upstream (nginx
 *    `real_ip_header` or equivalent). We also do NOT use Joomla's
 *    Input filter for the IP (would normalise differently per filter
 *    profile and break hash_equals comparisons).
 *
 *  - Timing-oracle collapse (cookbook §2.8.6): the `__ip_mismatch__`
 *    branch returns 404 with the SAME shape as not_found / expired /
 *    consumed / never-existed. A 403 here would tell an attacker
 *    "this nonce is real but you have the wrong IP" → enumerates the
 *    live-nonce subspace. Same shape collapses the enumeration.
 *
 *  - Nonce hygiene (cookbook §2.8.7): nonces are NEVER logged. We
 *    log only sha256(remote_ip)[0:16] + http_status. The transient
 *    payload is also never logged (would contain the issued token).
 *
 * @package    WootsUp\Component\Ytbmcp\Api\Controller
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Component\Ytbmcp\Api\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Uri\Uri;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaJsonResponse;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaPickupChannel;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaRateLimiter;
use WootsUp\BuilderMcp\Util\SecurityLogger;

class PickupController extends BaseController
{
    /** Plugin-version emitted on the happy-path response (parity with WP-side). */
    private const PLUGIN_VERSION = '1.1.7';

    /** Maximum pickup attempts per IP within the rate-limit window. */
    private const RATE_LIMIT_MAX_ATTEMPTS = 10;

    /** Rate-limit window in seconds (60 s per cookbook §2.6). */
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    /**
     * POST /v1/setup/pickup
     *
     * Validates rate-limit → request body → nonce shape → IP-binding
     * (in PickupChannel) → deletes the transient → returns the payload.
     * Every branch is logged through SecurityLogger so a single grep
     * reconstructs the attack surface.
     */
    public function claim(): void
    {
        /** @var CMSApplicationInterface $app */
        $app = Factory::getApplication();

        $remoteIp = $this->getRemoteIp();

        // Step 1 — SEC-IP-1 fail-closed (cookbook §2.12.3 Risk #6).
        // Empty REMOTE_ADDR means we cannot enforce per-IP budget.
        // Deny outright with 429 + log the misconfigured-proxy drift.
        if ($remoteIp === '') {
            SecurityLogger::log(SecurityLogger::EVENT_PICKUP_RATE_LIMITED, [
                'platform'    => 'joomla',
                'ip_hash'     => 'empty',
                'http_status' => 429,
            ]);
            JoomlaJsonResponse::send($app, [
                'error'       => 'rate_limited',
                'message'     => 'Pickup blocked: no client IP available.',
                'retry_after' => self::RATE_LIMIT_WINDOW_SECONDS,
            ], 429);
            return;
        }

        $ipHash = \substr(\hash('sha256', $remoteIp), 0, 16);

        // Step 2 — rate-limit (10 attempts / 60 s / IP).
        $limiter   = new JoomlaRateLimiter();
        $rateError = $limiter->checkGeneric(
            'pickup_rl_' . $ipHash,
            self::RATE_LIMIT_MAX_ATTEMPTS,
            self::RATE_LIMIT_WINDOW_SECONDS
        );
        if ($rateError !== null) {
            SecurityLogger::log(SecurityLogger::EVENT_PICKUP_RATE_LIMITED, [
                'platform'    => 'joomla',
                'ip_hash'     => $ipHash,
                'http_status' => 429,
            ]);
            JoomlaJsonResponse::send($app, [
                'error'       => 'rate_limited',
                'message'     => \sprintf(
                    'Rate limit exceeded: %d attempts per %d seconds.',
                    self::RATE_LIMIT_MAX_ATTEMPTS,
                    self::RATE_LIMIT_WINDOW_SECONDS
                ),
                'retry_after' => self::RATE_LIMIT_WINDOW_SECONDS,
            ], 429);
            return;
        }

        // Step 3 — validate request body shape (JSON object with string nonce).
        $body = $this->readJsonBody();
        if (!\is_array($body) || !isset($body['nonce']) || !\is_string($body['nonce'])) {
            SecurityLogger::log(SecurityLogger::EVENT_PICKUP_NOT_FOUND, [
                'platform'    => 'joomla',
                'ip_hash'     => $ipHash,
                'http_status' => 400,
                'reason'      => 'invalid_request',
            ]);
            JoomlaJsonResponse::send($app, [
                'error'   => 'invalid_request',
                'message' => 'Missing or non-string `nonce` field.',
            ], 400);
            return;
        }

        $nonce = (string) $body['nonce'];

        // Step 4 — delegate to PickupChannel (single owner of the
        // transient channel + nonce-shape validation + IP-binding).
        $result = (new JoomlaPickupChannel())->claim($nonce, $remoteIp);

        if ($result === null) {
            // Malformed / expired / consumed / never-existed — same
            // response shape across all four (cookbook §2.8.6 timing-
            // oracle defense) so the nonce-space is not enumerable.
            SecurityLogger::log(SecurityLogger::EVENT_PICKUP_NOT_FOUND, [
                'platform'    => 'joomla',
                'ip_hash'     => $ipHash,
                'http_status' => 404,
            ]);
            JoomlaJsonResponse::send($app, [
                'error'   => 'not_found',
                'message' => 'Pickup not available.',
            ], 404);
            return;
        }

        if (\array_key_exists('__ip_mismatch__', $result)) {
            // Cookbook §2.8.6 — collapse 403 → 404 same-shape. A 403
            // would leak "this nonce IS valid but wrong IP" → attacker
            // enumerates live-nonce subspace via timing+status pair.
            SecurityLogger::log(SecurityLogger::EVENT_PICKUP_IP_MISMATCH, [
                'platform'    => 'joomla',
                'ip_hash'     => $ipHash,
                'http_status' => 404,
            ]);
            JoomlaJsonResponse::send($app, [
                'error'   => 'not_found',
                'message' => 'Pickup not available.',
            ], 404);
            return;
        }

        // Happy-path — PickupChannel has already deleted the transient.
        $token   = isset($result['token']) && \is_string($result['token']) ? $result['token'] : '';
        $siteUrl = isset($result['site_url']) && \is_string($result['site_url'])
            ? $result['site_url']
            : (string) Uri::root();

        SecurityLogger::log(SecurityLogger::EVENT_PICKUP_CLAIMED, [
            'platform'    => 'joomla',
            'ip_hash'     => $ipHash,
            'http_status' => 200,
        ]);
        JoomlaJsonResponse::send($app, [
            'token'          => $token,
            'site_url'       => $siteUrl,
            'plugin_version' => self::PLUGIN_VERSION,
        ], 200);
    }

    /**
     * Read REMOTE_ADDR directly (cookbook §2.12.3 Risk #6).
     *
     * Returns the trimmed value or '' when absent / non-string. We
     * deliberately do NOT trust X-Forwarded-For (caller-controllable
     * → would let an attacker bypass IP-binding by forging it). Sites
     * behind a reverse-proxy must set REMOTE_ADDR upstream.
     */
    private function getRemoteIp(): string
    {
        if (!isset($_SERVER['REMOTE_ADDR']) || !\is_string($_SERVER['REMOTE_ADDR'])) {
            return '';
        }
        return \trim($_SERVER['REMOTE_ADDR']);
    }

    /**
     * Decode the JSON request body. Returns null for unparseable input;
     * the caller treats null + non-object identically (both → 400).
     *
     * @return array<string, mixed>|null
     */
    private function readJsonBody(): ?array
    {
        $raw = (string) ($this->input?->getRaw() ?? \file_get_contents('php://input') ?: '');
        if ($raw === '') {
            return null;
        }
        $decoded = \json_decode($raw, true);
        return \is_array($decoded) ? $decoded : null;
    }
}
