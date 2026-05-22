<?php
/**
 * SigningSecret — owner of the long-lived HMAC signing key used by {@see KeyService}.
 *
 * Stored as 64 bytes of CSPRNG output, hex-encoded (128 chars), in
 * `wp_option('ytb_mcp_signing_secret')` with autoload=false. The option is
 * created lazily on the first call to {@see SigningSecret::ensure()} —
 * activation-hook timing is intentionally avoided so the secret is only
 * generated once the plugin actually needs it.
 *
 * Wave-6 hardening (Fix 4):
 *  - Race-fix: ensure() uses {@see add_option} (atomic create-if-absent) so
 *    two concurrent requests that both miss the option do NOT generate two
 *    different secrets and then race on update_option. The loser re-reads.
 *  - Encrypt-at-rest: when `AUTH_KEY` is defined (always true in standard
 *    wp-config.php), the secret is AES-256-GCM encrypted before persistence.
 *    Storage format: "enc1:" + base64(iv || tag || ciphertext).
 *    Plain values (legacy installs) are decoded transparently, then
 *    re-encrypted on the next rotate() — backward compatible.
 *
 * Rotation invalidates every previously issued token (no key-rollover window
 * in Wave 1 — Wave 4 may add `secret_id` if rotation becomes a real flow).
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Auth
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Auth;

final class SigningSecret
{
    public const OPTION = 'ytb_mcp_signing_secret';

    /** Version prefix marking AES-256-GCM encrypted payloads. */
    private const ENC_PREFIX = 'enc1:';

    private const CIPHER = 'aes-256-gcm';

    /**
     * Return the current secret, creating one if absent.
     */
    public static function ensure(): string
    {
        $existing = self::get();
        if ($existing !== null) {
            return $existing;
        }

        // Race-safe insert: try add_option first. If it returns false, a
        // parallel request beat us to it — re-read and use that value.
        $secret = bin2hex(random_bytes(64));
        $stored = self::encodeForStorage($secret);
        if (\add_option(self::OPTION, $stored, '', false)) {
            return $secret;
        }

        $existing = self::get();
        if ($existing !== null) {
            return $existing;
        }

        // add_option returned false but get() returned null — extremely
        // unlikely (would require a deletion mid-flight). Fall back to
        // update_option (non-atomic) to keep the plugin functional.
        \update_option(self::OPTION, $stored, false);
        return $secret;
    }

    /**
     * Return the stored secret, or null if none has been generated yet.
     * Transparently decodes the `enc1:` envelope; falls back to the raw
     * stored value when the envelope is missing (legacy plaintext install).
     */
    public static function get(): ?string
    {
        /** @var mixed $raw */
        $raw = \get_option(self::OPTION, null);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return self::decodeFromStorage($raw);
    }

    /**
     * Replace the stored secret with a freshly generated one. Returns the new value.
     *
     * Every previously issued token immediately becomes invalid (sigs no longer match).
     */
    public static function rotate(): string
    {
        $secret = bin2hex(random_bytes(64));
        \update_option(self::OPTION, self::encodeForStorage($secret), false);
        return $secret;
    }

    /**
     * Wrap the raw secret in the `enc1:` envelope when an AUTH_KEY is
     * available; otherwise return the raw secret (graceful degrade).
     */
    private static function encodeForStorage(string $secret): string
    {
        $authKey = self::authKey();
        if ($authKey === null) {
            self::warnNoAuthKey();
            return $secret;
        }

        try {
            $iv = random_bytes(12); // 96-bit IV is the standard GCM length
            $tag = '';
            $ct = \openssl_encrypt(
                $secret,
                self::CIPHER,
                $authKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                16,
            );
            if (!is_string($ct) || !is_string($tag)) {
                return $secret;
            }
            return self::ENC_PREFIX . base64_encode($iv . $tag . $ct);
        } catch (\Throwable) {
            // Encryption failure → fall back to plaintext. Better than
            // refusing to issue tokens.
            return $secret;
        }
    }

    /**
     * Reverse {@see encodeForStorage}. On corrupted/undecryptable envelopes,
     * returns null so callers treat it as "secret missing" and regenerate.
     */
    private static function decodeFromStorage(string $stored): ?string
    {
        if (!str_starts_with($stored, self::ENC_PREFIX)) {
            // Legacy plaintext install — return as-is.
            return $stored;
        }
        $authKey = self::authKey();
        if ($authKey === null) {
            // Encrypted blob but no key to decrypt — log + treat as missing.
            self::warnNoAuthKey();
            return null;
        }
        $payload = base64_decode(substr($stored, strlen(self::ENC_PREFIX)), true);
        if (!is_string($payload) || strlen($payload) <= 12 + 16) {
            return null;
        }
        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ct = substr($payload, 12 + 16);
        try {
            $plain = \openssl_decrypt(
                $ct,
                self::CIPHER,
                $authKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
            );
            return is_string($plain) ? $plain : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Derive the encryption key from wp-config.php's `AUTH_KEY` constant.
     * Hashed to 32 bytes (AES-256 key length). Returns null when AUTH_KEY
     * is undefined (graceful degrade).
     */
    private static function authKey(): ?string
    {
        if (!defined('AUTH_KEY')) {
            return null;
        }
        $val = (string) \constant('AUTH_KEY');
        if ($val === '' || str_starts_with($val, 'put your unique')) {
            // wp-config.php hasn't been customised; AUTH_KEY is the default placeholder.
            return null;
        }
        return hash('sha256', 'ytb_mcp:' . $val, true);
    }

    private static function warnNoAuthKey(): void
    {
        if (function_exists('error_log')) {
            \error_log(
                '[yt-builder-mcp] AUTH_KEY is not defined in wp-config.php — '
                . 'signing secret will be stored in plaintext. Please define AUTH_KEY '
                . 'per WordPress security best-practices.',
            );
        }
    }
}
