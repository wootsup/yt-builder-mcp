<?php
/**
 * PickupController — unauthenticated POST /setup/pickup endpoint.
 *
 * Wave C (2026-05-22): one-shot, IP-bound, short-TTL nonce exchange for the
 * "Copy AI prompt" flow in wp-admin. The wp-admin Reveal-Box stores a
 * pickup-payload `{token, site_url, ip, ip_bound}` under transient
 * `ytb_mcp_pickup_<nonce>` (TTL 300 s). The npm setup-wizard (run by the
 * AI client's Bash tool) POSTs `{nonce}` to this endpoint and receives the
 * token + site URL in exchange. The transient is deleted on successful
 * read so a single pickup-URL cannot be replayed.
 *
 * Hardening H2 (2026-05-22): the nonce-channel storage primitives now live
 * in {@see \WootsUp\BuilderMcp\Storage\PickupChannel} — this controller is
 * a thin REST adapter that translates PickupChannel's return-shape into
 * RFC-compliant HTTP responses and surfaces every branch through
 * SecurityLogger for forensic visibility. Empty REMOTE_ADDR is now
 * rate-limited (was: no-op → unlimited budget bypass via stripped XFF).
 *
 * Security model:
 *   - 256-bit nonce (random_bytes(32), base64url) — no brute-force possible.
 *   - One-shot: delete_transient() runs in PickupChannel::claim before return.
 *   - IP-bound by default: REMOTE_ADDR of the wp-admin generator is
 *     compared against the consumer's REMOTE_ADDR. Customers behind a
 *     corporate NAT can opt-out (`ip_bound=false` in the payload) when
 *     they trust the local network.
 *   - Rate-limit: 10 attempts / 60 s / IP (per-IP transient counter,
 *     orthogonal to the bearer-keyed RateLimiter used by RestController).
 *     Empty REMOTE_ADDR returns rate_limited too — prevents stripped-XFF /
 *     misconfigured-proxy bypass.
 *   - Same response shape for "expired" / "consumed" / "never-existed"
 *     to prevent timing oracle attacks on the nonce space.
 *   - Nonces are NEVER logged — only sha256(ip)[0:16] + http_status.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Rest
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Rest;

use WootsUp\BuilderMcp\Storage\PickupChannel;
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class PickupController extends PublicRestController
{
    /**
     * Pickup-transient prefix; full key = prefix + base64url-nonce.
     *
     * @deprecated since H2 — kept as a thin forwarder to PickupChannel
     *             so existing tests/extensions referencing the constant
     *             continue to work. Use PickupChannel::TRANSIENT_PREFIX.
     */
    public const PICKUP_TRANSIENT_PREFIX = PickupChannel::TRANSIENT_PREFIX;

    /**
     * Default pickup TTL (matches the wp-admin handler).
     *
     * @deprecated since H2 — kept as a forwarder. Use PickupChannel::TTL_SECONDS.
     */
    public const PICKUP_TTL_SECONDS = PickupChannel::TTL_SECONDS;

    /** Maximum pickup attempts per IP within the rate-limit window. */
    public const RATE_LIMIT_MAX_ATTEMPTS = 10;

    /** Rate-limit window in seconds. */
    public const RATE_LIMIT_WINDOW_SECONDS = 60;

    public function register_routes(): void
    {
        \register_rest_route(self::NAMESPACE, '/setup/pickup', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_claim'],
            // Public — the nonce IS the credential.
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Handle a pickup-claim. Validates rate-limit + nonce shape + transient
     * payload + IP-binding (in PickupChannel), then deletes the transient
     * and returns the payload to the consumer. Every branch is logged
     * through SecurityLogger so forensics can reconstruct the attack
     * surface from a single grep.
     */
    public function handle_claim(\WP_REST_Request $request): \WP_REST_Response
    {
        $remoteIp = $this->getRemoteIp();

        // Step 1 — rate limit (before any transient lookup).
        // SEC-IP-1: empty REMOTE_ADDR is a defense-in-depth fail-closed — we
        // cannot enforce per-IP budget without an IP, so we deny outright.
        if ($remoteIp === '') {
            SecurityLogger::log(SecurityLogger::EVENT_PICKUP_RATE_LIMITED, [
                'ip_hash' => 'empty',
                'http_status' => 429,
            ]);
            return new \WP_REST_Response([
                'error' => 'rate_limited',
                'message' => 'Pickup blocked: no client IP available.',
                'retry_after' => self::RATE_LIMIT_WINDOW_SECONDS,
            ], 429);
        }
        $ipHash = \substr(\hash('sha256', $remoteIp), 0, 16);
        // ARCH-REUSE-2 (H4): use the shared RateLimiter primitive instead of
        // a private duplicate of the fixed-window algorithm. Bucket-key is
        // `pickup_rl_<ipHash>` — RateLimiter prefixes it to
        // `ytb_mcp_rate_pickup_rl_<ipHash>`.
        $rateError = RateLimiter::checkGeneric(
            'pickup_rl_' . $ipHash,
            self::RATE_LIMIT_MAX_ATTEMPTS,
            self::RATE_LIMIT_WINDOW_SECONDS,
        );
        if ($rateError !== null) {
            SecurityLogger::log(SecurityLogger::EVENT_PICKUP_RATE_LIMITED, [
                'ip_hash' => $ipHash,
                'http_status' => 429,
            ]);
            return new \WP_REST_Response([
                'error' => 'rate_limited',
                'message' => $rateError->get_error_message(),
                'retry_after' => self::RATE_LIMIT_WINDOW_SECONDS,
            ], 429);
        }

        // Step 2 — validate request body shape.
        $body = $request->get_json_params();
        if (!is_array($body) || !isset($body['nonce']) || !is_string($body['nonce'])) {
            SecurityLogger::log(SecurityLogger::EVENT_PICKUP_NOT_FOUND, [
                'ip_hash' => $ipHash,
                'http_status' => 400,
                'reason' => 'invalid_request',
            ]);
            return new \WP_REST_Response(
                ['error' => 'invalid_request', 'message' => 'Missing or non-string `nonce` field.'],
                400,
            );
        }
        $nonce = (string) $body['nonce'];

        // Step 3-5 — delegate to PickupChannel (single owner of the
        // transient channel + shape validation + IP-binding logic).
        $result = PickupChannel::claim($nonce, $remoteIp);

        if ($result === null) {
            // Malformed / expired / consumed / never-existed — same response
            // shape across all four so the nonce-space is not enumerable.
            SecurityLogger::log(SecurityLogger::EVENT_PICKUP_NOT_FOUND, [
                'ip_hash' => $ipHash,
                'http_status' => 404,
            ]);
            return new \WP_REST_Response(
                ['error' => 'not_found', 'message' => 'Pickup not available.'],
                404,
            );
        }

        if (\array_key_exists('__ip_mismatch__', $result)) {
            SecurityLogger::log(SecurityLogger::EVENT_PICKUP_IP_MISMATCH, [
                'ip_hash' => $ipHash,
                'http_status' => 403,
            ]);
            return new \WP_REST_Response(
                [
                    'error' => 'ip_mismatch',
                    'message' => 'Pickup is bound to a different IP. Run the wizard from the same network as wp-admin, or regenerate the pickup with the "different machine" option.',
                ],
                403,
            );
        }

        // Happy-path — PickupChannel has already deleted the transient.
        // Defensive guard: PickupChannel::claim only returns
        // ['token'=>..., 'site_url'=>...] in this branch, but PHPStan can't
        // narrow across the array-key-existence check above, so we re-assert.
        $token = isset($result['token']) && \is_string($result['token']) ? $result['token'] : '';
        $siteUrl = isset($result['site_url']) && \is_string($result['site_url']) ? $result['site_url'] : '';

        SecurityLogger::log(SecurityLogger::EVENT_PICKUP_CLAIMED, [
            'ip_hash' => $ipHash,
            'http_status' => 200,
        ]);
        return new \WP_REST_Response([
            'token' => $token,
            'site_url' => $siteUrl,
            'plugin_version' => defined('YTB_MCP_VERSION') ? (string) \YTB_MCP_VERSION : 'dev',
        ], 200);
    }

    /**
     * Read the client IP from REMOTE_ADDR, falling back to the empty string.
     * We deliberately do NOT trust X-Forwarded-For — that header is
     * caller-controllable and would let a bypass IP-binding by forging it.
     * Sites behind reverse-proxies need to set REMOTE_ADDR upstream
     * (nginx `real_ip_header` or equivalent).
     */
    private function getRemoteIp(): string
    {
        if (!isset($_SERVER['REMOTE_ADDR']) || !is_string($_SERVER['REMOTE_ADDR'])) {
            return '';
        }
        $ip = trim($_SERVER['REMOTE_ADDR']);
        return $ip;
    }
}
