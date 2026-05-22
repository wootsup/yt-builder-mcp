<?php
/**
 * SecurityLogger — single sink for security-relevant events.
 *
 * Wave 6 Round-2 R2.9. Before this module, every security-event branch
 * either logged nothing at all (silent fail-closed) or scattered raw
 * error_log() calls with ad-hoc message formats. Both make forensics
 * impossible. This class is the one place to emit a structured line per
 * security event so an admin running `tail -f /var/log/php_errors.log`
 * sees a coherent narrative.
 *
 * Output format (single line):
 *   [yt-builder-mcp][security] <event> <json-context>
 *
 * The `<event>` slug is one of the constants defined below. Context is
 * `json_encode()`d (with JSON_UNESCAPED_SLASHES) — never throws because
 * we replace any non-encodable value with its var_export() string before
 * encoding.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Util
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Util;

final class SecurityLogger
{
    public const EVENT_BEARER_FAIL = 'bearer_fail';
    public const EVENT_SCOPE_DENY = 'scope_deny';
    public const EVENT_RATE_LIMIT = 'rate_limit';
    public const EVENT_WRITE_FAILED = 'write_failed';
    public const EVENT_CROSS_TEMPLATE_DENY = 'cross_template_deny';
    public const EVENT_CACHE_FLUSH_FAILED = 'cache_flush_failed';
    public const EVENT_KEYSTORE_RACE = 'keystore_race';
    public const EVENT_LOCK_TIMEOUT = 'lock_timeout';
    public const EVENT_PICKUP_CLAIMED = 'pickup_claimed';
    public const EVENT_PICKUP_NOT_FOUND = 'pickup_not_found';
    public const EVENT_PICKUP_IP_MISMATCH = 'pickup_ip_mismatch';
    public const EVENT_PICKUP_RATE_LIMITED = 'pickup_rate_limited';

    /**
     * Emit a structured security-event line via error_log().
     *
     * @param string $event   One of the EVENT_* constants — never user input
     *                        (callers pass a const, not a string from a request).
     * @param array<string, mixed> $context Arbitrary structured context.
     *                        Non-encodable values are coerced to strings.
     */
    public static function log(string $event, array $context = []): void
    {
        if (!function_exists('error_log')) {
            return;
        }

        $sanitized = self::sanitizeContext($context);
        try {
            $encoded = json_encode($sanitized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $encoded = '{}';
        }

        \error_log(sprintf('[yt-builder-mcp][security] %s %s', $event, $encoded));
    }

    /**
     * Coerce any non-JSON-encodable value to a safe string so json_encode
     * never throws. Resource handles + circular references are the only
     * realistic blockers; both turn into a `var_export` snippet here.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function sanitizeContext(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
                continue;
            }
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $out[$key] = self::sanitizeContext($value);
                continue;
            }
            if (is_object($value) && method_exists($value, '__toString')) {
                $out[$key] = (string) $value;
                continue;
            }
            // Resource, closure, complex object — coerce to a debug string.
            $out[$key] = '[' . gettype($value) . ']';
        }
        return $out;
    }
}
