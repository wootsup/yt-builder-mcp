<?php
/**
 * PickupChannel — single owner of the Wave-C pickup-URL transient channel.
 *
 * Consolidates the producer (SettingsPage::handle_generate) and consumer
 * (PickupController::handle_claim) sides of the one-shot pickup-nonce flow.
 * Single source of truth for:
 *  - Transient prefix (ytb_mcp_pickup_)
 *  - TTL (300 s)
 *  - Nonce shape validation (32..64 base64url chars, regex [A-Za-z0-9_-])
 *  - Payload schema {token, site_url, ip, ip_bound, created_at}
 *
 * One-shot semantics: claim() deletes the transient BEFORE returning so a
 * second claim with the same nonce always returns null.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Storage
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Storage;

use WootsUp\BuilderMcp\Util\Base64Url;

final class PickupChannel
{
    /** Transient-prefix; full key = prefix + base64url-nonce. */
    public const TRANSIENT_PREFIX = 'ytb_mcp_pickup_';

    /** Default pickup TTL — long enough for human copy/paste, short enough for safety. */
    public const TTL_SECONDS = 300;

    /** Minimum nonce length (base64url of 32 raw bytes = 43 chars, but we accept 32+ for flexibility). */
    public const NONCE_MIN_LENGTH = 32;

    /** Maximum nonce length (leaves headroom without forcing a migration). */
    public const NONCE_MAX_LENGTH = 64;

    /**
     * Issue a new pickup nonce + store the payload.
     *
     * Defense-in-depth: all IO is wrapped in try/catch so a hostile
     * transient backend (broken Redis, full disk, fatal in a `set_transient`
     * filter) cannot escalate to a fatal in the producer-side admin flow.
     *
     * @param array{token: string, site_url: string, ip: string, ip_bound: bool} $payload
     * @return string The nonce (43-char base64url), or empty string on storage failure
     */
    public static function issue(array $payload, int $ttl = self::TTL_SECONDS): string
    {
        if (!\function_exists('set_transient')) {
            return '';
        }
        try {
            $nonce = self::generateNonce();
            $stored = \set_transient(
                self::TRANSIENT_PREFIX . $nonce,
                \array_merge($payload, ['created_at' => \time()]),
                $ttl,
            );
            return $stored ? $nonce : '';
        } catch (\Throwable $e) {
            if (\function_exists('error_log')) {
                \error_log('[yt-builder-mcp][PickupChannel] issue failed: ' . $e->getMessage());
            }
            return '';
        }
    }

    /**
     * Claim a pickup nonce — one-shot, IP-bound by default.
     *
     * Return-shape:
     *  - `null`                                — malformed nonce, expired, consumed, never-existed.
     *                                            Callers MUST NOT distinguish these to prevent
     *                                            info-leak / timing oracle attacks.
     *  - `['__ip_mismatch__' => true]`         — IP-binding mismatch. Transient is NOT consumed;
     *                                            legitimate user can still claim within TTL.
     *  - `['token' => ..., 'site_url' => ...]` — happy-path. Transient deleted before return.
     *
     * @return array{token: string, site_url: string}|array{__ip_mismatch__: bool}|null
     */
    public static function claim(string $nonce, string $remoteIp): ?array
    {
        if (!self::isValidNonceShape($nonce)) {
            return null;
        }
        if (!\function_exists('get_transient') || !\function_exists('delete_transient')) {
            return null;
        }
        try {
            $key = self::TRANSIENT_PREFIX . $nonce;
            /** @var mixed $payload */
            $payload = \get_transient($key);
            if (!\is_array($payload)
                || !isset($payload['token'], $payload['site_url'])
                || !\is_string($payload['token'])
                || !\is_string($payload['site_url'])
            ) {
                return null;
            }

            $ipBound = isset($payload['ip_bound']) ? (bool) $payload['ip_bound'] : true;
            if ($ipBound) {
                $storedIp = isset($payload['ip']) && \is_string($payload['ip']) ? (string) $payload['ip'] : '';
                if ($storedIp !== '' && $storedIp !== $remoteIp) {
                    // 403 — do NOT consume; legitimate user from the right IP
                    // can still claim within TTL.
                    return ['__ip_mismatch__' => true];
                }
            }

            // One-shot delete BEFORE returning the payload.
            \delete_transient($key);

            return [
                'token' => (string) $payload['token'],
                'site_url' => (string) $payload['site_url'],
            ];
        } catch (\Throwable $e) {
            if (\function_exists('error_log')) {
                \error_log('[yt-builder-mcp][PickupChannel] claim failed: ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Generate a 256-bit pickup nonce (base64url-encoded, ~43 chars).
     *
     * Hardening H4 (ARCH-REUSE-3): delegates to the shared Base64Url helper.
     */
    public static function generateNonce(): string
    {
        return Base64Url::generate(32);
    }

    /**
     * Base64url-character + length validation. The nonce is generated by
     * `random_bytes(32)` → base64url (43 chars), but we accept 32..64 to
     * leave room for length-experimentation without forcing a migration.
     *
     * Hardening H4 (ARCH-REUSE-3): delegates to the shared Base64Url helper.
     */
    public static function isValidNonceShape(string $nonce): bool
    {
        return Base64Url::isValid($nonce, self::NONCE_MIN_LENGTH, self::NONCE_MAX_LENGTH);
    }
}
