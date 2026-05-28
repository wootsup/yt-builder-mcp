<?php
/**
 * RateLimiter — per-kid sliding-window rate limit using transients.
 *
 * Wave-6 Fix 15. Defense-in-depth against runaway/abusive bearer keys —
 * a compromised token or a buggy client retry-loop is bounded server-side.
 *
 * Algorithm: simple fixed-window counter. Each kid gets a transient
 * `ytb_mcp_rate_<kid>` whose value is the count of requests within the
 * current 60s window. The window starts when the first request lands
 * (TTL is set on first increment). When the count exceeds the limit, the
 * limiter returns a WP_Error 429.
 *
 * Default limits:
 *   - write-method requests: 60 / minute / kid
 *   - read-method requests:  not rate-limited (cheap)
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Rest
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Rest;

final class RateLimiter
{
    public const WINDOW_SECONDS = 60;
    public const WRITE_LIMIT = 60;

    /**
     * Increment the counter for $kid and return null when within the
     * configured limit, or a WP_Error 429 when the limit is exceeded.
     */
    public static function checkWrite(string $kid): ?\WP_Error
    {
        if ($kid === '') {
            return null;
        }
        $transientKey = self::transientKey($kid);
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return null;
        }

        /** @var mixed $current */
        $current = \get_transient($transientKey);
        $count = is_int($current) ? $current : 0;
        $count++;

        if ($count > self::WRITE_LIMIT) {
            // Wave-1 Fix C-4: dedicated event-name lets SIEM/forensics
            // filter the "compromised bearer / runaway client" signal
            // without the per-IP pickup-limiter noise.
            \WootsUp\BuilderMcp\Util\SecurityLogger::log(
                \WootsUp\BuilderMcp\Util\SecurityLogger::EVENT_MCP_WRITE_RATE_LIMITED,
                [
                    'kid' => $kid,
                    'limit' => self::WRITE_LIMIT,
                    'window_seconds' => self::WINDOW_SECONDS,
                ],
            );
            return new \WP_Error(
                'yootheme_builder_mcp.rate_limited',
                sprintf(
                    'Rate limit exceeded: %d writes/%ds per key.',
                    self::WRITE_LIMIT,
                    self::WINDOW_SECONDS,
                ),
                [
                    'status' => 429,
                    // Wave-1 Fix C-4 — well-known token + scope dimension so
                    // MCP clients can render a "retry in N seconds" toast
                    // without parsing the human-readable message.
                    'error' => 'rate_limit_exceeded',
                    'retry_after_seconds' => self::WINDOW_SECONDS,
                    'scope' => 'kid',
                    // Back-compat: pre-Wave-1 fields kept for clients that
                    // already parse them.
                    'limit' => self::WRITE_LIMIT,
                    'window_seconds' => self::WINDOW_SECONDS,
                    'retry_after' => self::WINDOW_SECONDS,
                ],
            );
        }

        \set_transient($transientKey, $count, self::WINDOW_SECONDS);
        return null;
    }

    /**
     * Test-only / admin-only helper: clear the counter for a kid.
     */
    public static function reset(string $kid): void
    {
        if (function_exists('delete_transient')) {
            \delete_transient(self::transientKey($kid));
        }
    }

    /**
     * Generic rate-limit primitive. Counts attempts in a fixed window keyed
     * by an arbitrary bucket-identifier. Used by both per-kid write-limits
     * and per-IP PickupController claims.
     *
     * Hardening H4 (ARCH-REUSE-1): single source of truth for the
     * "fixed-window counter via WP transient" algorithm — callers pick
     * their own bucket-key + budget + window. Previously each call-site
     * (RateLimiter::checkWrite + PickupController::checkRateLimit)
     * duplicated the same code-shape with different constants.
     *
     * Returns null when within budget; returns WP_Error 429 + retry_after
     * when exceeded.
     */
    public static function checkGeneric(string $bucketKey, int $maxAttempts, int $windowSeconds): ?\WP_Error
    {
        if ($bucketKey === '' || $maxAttempts <= 0 || $windowSeconds <= 0) {
            return null;
        }
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return null;
        }

        $transientKey = 'ytb_mcp_rate_' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', $bucketKey) ?? '', 0, 80);
        $current = \get_transient($transientKey);
        $count = is_int($current) ? $current : 0;
        $count++;

        if ($count > $maxAttempts) {
            return new \WP_Error(
                'yootheme_builder_mcp.rate_limited',
                sprintf('Rate limit exceeded: %d requests/%ds per bucket.', $maxAttempts, $windowSeconds),
                ['status' => 429, 'limit' => $maxAttempts, 'window_seconds' => $windowSeconds, 'retry_after' => $windowSeconds],
            );
        }
        \set_transient($transientKey, $count, $windowSeconds);
        return null;
    }

    private static function transientKey(string $kid): string
    {
        return 'ytb_mcp_rate_' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', $kid) ?? '', 0, 64);
    }
}
