<?php
/**
 * Pickup nonce channel — cookbook §2.8 / §6.4. 300s TTL, IP-bound default,
 * one-shot semantics. Same response-shape collapse rules (404 for
 * malformed/expired/consumed) as WP-side to prevent timing-oracle.
 *
 * @package WootsUp\BuilderMcp\Platform\Joomla\Rest
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Rest;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaTransientStore;
use WootsUp\BuilderMcp\Util\Base64Url;

final class JoomlaPickupChannel
{
    public const TTL_SECONDS = 300;
    public const TRANSIENT_PREFIX = 'pickup_';
    public const NONCE_MIN_LENGTH = 32;
    public const NONCE_MAX_LENGTH = 64;

    public function __construct(private readonly JoomlaTransientStore $store = new JoomlaTransientStore())
    {
    }

    public static function generateNonce(): string
    {
        return Base64Url::generate(32);
    }

    public static function isValidNonceShape(string $nonce): bool
    {
        $len = \strlen($nonce);
        if ($len < self::NONCE_MIN_LENGTH || $len > self::NONCE_MAX_LENGTH) {
            return false;
        }
        return (bool) \preg_match('/^[A-Za-z0-9_-]+$/', $nonce);
    }

    /**
     * @param array{token:string, site_url:string, ip:string, ip_bound:bool} $payload
     */
    public function issue(string $nonce, array $payload): bool
    {
        if (!self::isValidNonceShape($nonce)) {
            return false;
        }
        return $this->store->set(self::TRANSIENT_PREFIX . $nonce, $payload, self::TTL_SECONDS);
    }

    /**
     * @return array{token:string, site_url:string}|array{__ip_mismatch__:bool}|null
     *
     * Cookbook §2.8.6 timing-oracle defense: every return path is
     * preceded by a randomised micro-sleep so an external observer
     * cannot distinguish "wrong key shape" (cheap, no DB round-trip)
     * from "expired" (one DB round-trip), "consumed" (one DB round-
     * trip + delete), "ip mismatch" (one DB round-trip + hash_equals),
     * or "valid" (one DB round-trip + delete) by latency alone. The
     * jitter window (500–2000 µs) is small enough to be invisible to
     * legitimate users yet larger than the typical variance between
     * the four branches on a healthy MySQL instance.
     */
    public function claim(string $nonce, string $remoteIp): ?array
    {
        if (!self::isValidNonceShape($nonce)) {
            self::jitter();
            return null;
        }
        $key = self::TRANSIENT_PREFIX . $nonce;
        $payload = $this->store->get($key);
        if (!\is_array($payload) || !isset($payload['token'], $payload['site_url'])) {
            self::jitter();
            return null;
        }
        if (($payload['ip_bound'] ?? false) && \is_string($payload['ip'] ?? null)) {
            if (!\hash_equals((string) $payload['ip'], $remoteIp)) {
                self::jitter();
                return ['__ip_mismatch__' => true];
            }
        }
        // ONE-SHOT — delete before returning.
        $this->store->delete($key);
        self::jitter();
        return [
            'token' => (string) $payload['token'],
            'site_url' => (string) $payload['site_url'],
        ];
    }

    /**
     * Randomised micro-sleep (cookbook §2.8.6). Falls back silently
     * when random_int() is unavailable (PHP < 7.0 — well below our
     * 8.1 floor, but guarded for defense-in-depth).
     */
    private static function jitter(): void
    {
        try {
            \usleep(\random_int(500, 2000));
        } catch (\Throwable) {
            // No-op — better to skip the jitter than to crash claim().
        }
    }
}
