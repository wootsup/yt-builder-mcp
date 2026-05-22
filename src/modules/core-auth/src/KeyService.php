<?php
/**
 * KeyService — Stripe-style Bearer-token sign + verify using HMAC-SHA256.
 *
 * Token format:  ytb_live_<payloadB64Url>.<sigB64Url>
 *
 * - payloadB64Url = base64url(JSON-encoded claims), claims include `kid`, `scope`, `exp`
 * - sigB64Url     = base64url(HMAC-SHA256(payloadB64Url, signingSecret))
 *
 * The format mirrors api-mapper's `amk_live_*.*` convention. Signing-Secret is
 * a 32-byte (64-hex-char) value generated on first plugin activation and
 * stored in `wp_option('ytb_mcp_signing_secret')` (autoload=false).
 *
 * Comparison uses {@see hash_equals} to defend against timing attacks.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Auth
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Auth;

final class KeyService
{
    /** Token-Prefix (descriptive, distinguishes live vs future "_test_" keys). */
    public const PREFIX = 'ytb_live_';

    /** Maximum allowed payload size in bytes (defense-in-depth against memory abuse). */
    private const MAX_PAYLOAD_BYTES = 4096;

    public function __construct(private readonly string $signingSecret)
    {
        if ($signingSecret === '') {
            throw new \InvalidArgumentException('signingSecret must not be empty.');
        }
    }

    /**
     * Issue a new Bearer-token for the given key-id with the given claims.
     *
     * The `kid` claim is always taken from the first parameter, even if the
     * caller's `$claims` array also contains a `kid` entry — this keeps the
     * canonical kid authoritative and prevents accidental override.
     *
     * @param string               $kid    Stable key identifier (UUID-style).
     * @param array<string, mixed> $claims At minimum `exp` (Unix timestamp) and `scope`.
     *
     * @return string The signed token.
     */
    public function generate(string $kid, array $claims): string
    {
        if ($kid === '') {
            throw new \InvalidArgumentException('kid must not be empty.');
        }

        $claims['kid'] = $kid;
        $payloadJson = json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $payloadB64 = self::base64UrlEncode($payloadJson);

        $sig = hash_hmac('sha256', $payloadB64, $this->signingSecret, true);
        $sigB64 = self::base64UrlEncode($sig);

        return self::PREFIX . $payloadB64 . '.' . $sigB64;
    }

    /**
     * Verify a Bearer-token and return its decoded claims.
     *
     * @return array<string, mixed> The decoded claims (includes `kid`, `scope`, `exp`).
     *
     * @throws InvalidTokenException If the format, signature, or JSON payload is invalid.
     * @throws ExpiredTokenException If the token's `exp` claim is in the past.
     */
    public function verify(string $token): array
    {
        if (!preg_match('/^' . preg_quote(self::PREFIX, '/') . '([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $token, $m)) {
            throw new InvalidTokenException('Token format invalid.');
        }

        [, $payloadB64, $sigB64] = $m;

        if (strlen($payloadB64) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidTokenException('Token payload exceeds maximum size.');
        }

        $expectedSig = hash_hmac('sha256', $payloadB64, $this->signingSecret, true);
        $providedSig = self::base64UrlDecode($sigB64);
        if ($providedSig === null || !hash_equals($expectedSig, $providedSig)) {
            throw new InvalidTokenException('Token signature mismatch.');
        }

        $payloadJson = self::base64UrlDecode($payloadB64);
        if ($payloadJson === null) {
            throw new InvalidTokenException('Token payload is not valid base64url.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payloadJson, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidTokenException('Token payload is not valid JSON.', 0, $e);
        }

        if (!is_array($decoded)) {
            throw new InvalidTokenException('Token payload must be a JSON object.');
        }

        /** @var array<string, mixed> $claims */
        $claims = $decoded;

        // Wave-6 Fix 13: `exp` is now strict — if present, it MUST be an int.
        // A non-int `exp` (e.g. string "0", array, null sneaked in via a
        // hand-crafted payload) used to be silently ignored, leaving the
        // token perpetually valid. Reject such payloads outright.
        if (array_key_exists('exp', $claims)) {
            if (!is_int($claims['exp'])) {
                throw new InvalidTokenException('Token claim `exp` must be an integer Unix timestamp.');
            }
            if ($claims['exp'] < time()) {
                throw new ExpiredTokenException('Token expired.');
            }
        }

        if (!isset($claims['kid']) || !is_string($claims['kid']) || $claims['kid'] === '') {
            throw new InvalidTokenException('Token payload missing kid claim.');
        }

        return $claims;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $padded = $data . str_repeat('=', (4 - strlen($data) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
