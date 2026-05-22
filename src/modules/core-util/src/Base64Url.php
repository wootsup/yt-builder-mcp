<?php
/**
 * Base64Url — RFC-4648 §5 URL-safe base64 helper.
 *
 * Standard PHP base64_encode produces output with `+`, `/`, and `=` padding
 * characters that need URL-encoding. This class produces base64url-encoded
 * strings directly (using `-` and `_` substitutes + no padding) — the same
 * format used by JWT, OAuth bearer tokens, and the pickup-nonce flow.
 *
 * Hardening H4 (ARCH-REUSE-3): single source of truth for the
 * `random_bytes → strtr → rtrim` triplet that was previously inlined in
 * `PickupChannel::generateNonce()` and the validation regex inlined in
 * `PickupChannel::isValidNonceShape()`.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Util
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Util;

final class Base64Url
{
    /**
     * Generate `$bytes` cryptographically-random bytes and return them
     * base64url-encoded (no padding, `-`/`_` substituted for `+`/`/`).
     *
     * @throws \InvalidArgumentException When $bytes < 1.
     * @throws \Exception                When random_bytes fails (unrecoverable).
     */
    public static function generate(int $bytes): string
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('byte count must be >= 1');
        }
        return self::encode(\random_bytes($bytes));
    }

    /**
     * Encode an arbitrary binary string as base64url (no padding).
     */
    public static function encode(string $raw): string
    {
        return \rtrim(\strtr(\base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Validate that `$s` is a syntactically-valid base64url string whose
     * length sits in `[$minLen..$maxLen]`. Does not decode — purely a
     * cheap shape-check suitable for pre-DB lookup gatekeeping.
     */
    public static function isValid(string $s, int $minLen = 1, int $maxLen = 1024): bool
    {
        $len = \strlen($s);
        if ($len < $minLen || $len > $maxLen) {
            return false;
        }
        return (bool) \preg_match('/^[A-Za-z0-9_-]+$/', $s);
    }
}
