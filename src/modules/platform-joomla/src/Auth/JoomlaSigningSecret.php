<?php
/**
 * Joomla twin of {@see WootsUp\BuilderMcp\Auth\SigningSecret}.
 *
 * Holds the HMAC signing secret used by {@see KeyService::generate} +
 * {@see KeyService::verify} across the REST surface. Behaviour matches
 * the WP-side byte-for-byte EXCEPT for two platform substitutions:
 *
 *   - Storage backend: {@see JoomlaOptionStore} (#__ytb_mcp_options)
 *     instead of wp_option.
 *   - Encryption-at-rest key source: {@see JoomlaEncryptionKeyResolver}
 *     3-tier resolution instead of AUTH_KEY (ADR-001 / Pillar 3).
 *
 * Storage envelope format is IDENTICAL — `enc1:` prefix + base64(IV ||
 * TAG || CIPHERTEXT). Legacy plaintext installs are decoded
 * transparently; the next `rotate()` re-encrypts.
 *
 * Cookbook reference: §2.4 (signing-secret contract) +
 * §1.3.5 (Joomla-side option-storage adapter pattern).
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Auth
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Auth;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Platform\Joomla\Encryption\JoomlaEncryptionKeyResolver;
use WootsUp\BuilderMcp\Platform\Joomla\Exception\AuthUnavailableException;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class JoomlaSigningSecret
{
    public const OPTION_KEY = 'signing_secret';
    private const ENC_PREFIX = 'enc1:';
    private const CIPHER     = 'aes-256-gcm';

    /**
     * Return the current secret, creating one if absent. Race-safe via
     * JoomlaOptionStore::add() (INSERT IGNORE).
     */
    public static function ensure(?JoomlaOptionStore $store = null): string
    {
        $store ??= new JoomlaOptionStore();

        $existing = self::get($store);
        if ($existing !== null) {
            return $existing;
        }

        $secret = \bin2hex(\random_bytes(64));
        $stored = self::encodeForStorage($secret);
        if ($store->add(self::OPTION_KEY, $stored)) {
            return $secret;
        }

        // Parallel request beat us — re-read.
        $existing = self::get($store);
        if ($existing !== null) {
            return $existing;
        }

        // Extremely unlikely (deletion mid-flight). Fall back to non-atomic set.
        //
        // R8-A4 P1: do NOT ignore the persistence boolean. If the write
        // silently fails (disk-full, row-lock, a future driver regression of
        // the bind-on-driver class the deploy-delta fixed), returning the
        // in-memory secret would let KeyService sign tokens with a secret that
        // never reached the DB → the next request reads a DIFFERENT secret →
        // every Bearer verification fails, with no error surfaced at write
        // time. Surface it: log EVENT_WRITE_FAILED + throw so the REST layer
        // emits a structured 503 instead of issuing un-verifiable tokens.
        $persisted = $store->set(self::OPTION_KEY, $stored);
        if (!$persisted) {
            SecurityLogger::log(SecurityLogger::EVENT_WRITE_FAILED, [
                'platform' => 'joomla',
                'op'       => 'signing_secret_persist',
                'option'   => self::OPTION_KEY,
                'reason'   => 'option store returned false after add() miss and re-read miss',
            ]);
            throw new AuthUnavailableException(
                'Signing secret could not be persisted to the options table.'
            );
        }
        return $secret;
    }

    /** Read the stored secret, decoding the `enc1:` envelope when present. */
    public static function get(?JoomlaOptionStore $store = null): ?string
    {
        $store ??= new JoomlaOptionStore();
        $raw     = $store->get(self::OPTION_KEY, null);
        if (!\is_string($raw) || $raw === '') {
            return null;
        }
        return self::decodeFromStorage($raw);
    }

    /** Force-rotate (invalidates every previously issued token). */
    public static function rotate(?JoomlaOptionStore $store = null): string
    {
        $store ??= new JoomlaOptionStore();
        $secret  = \bin2hex(\random_bytes(64));
        $store->set(self::OPTION_KEY, self::encodeForStorage($secret));
        return $secret;
    }

    /** Wrap in `enc1:` envelope when a key is available; else plaintext. */
    private static function encodeForStorage(string $secret): string
    {
        $key = JoomlaEncryptionKeyResolver::resolve();
        if ($key === null) {
            self::warnNoKey();
            return $secret;
        }
        try {
            $iv  = \random_bytes(12);
            $tag = '';
            $ct  = \openssl_encrypt(
                $secret,
                self::CIPHER,
                $key,
                \OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                16
            );
            if (!\is_string($ct) || !\is_string($tag)) {
                return $secret;
            }
            return self::ENC_PREFIX . \base64_encode($iv . $tag . $ct);
        } catch (\Throwable) {
            return $secret;
        }
    }

    /** Reverse of encodeForStorage. Null on corruption (caller regenerates). */
    private static function decodeFromStorage(string $stored): ?string
    {
        if (!\str_starts_with($stored, self::ENC_PREFIX)) {
            return $stored;
        }
        $key = JoomlaEncryptionKeyResolver::resolve();
        if ($key === null) {
            self::warnNoKey();
            return null;
        }
        $payload = \base64_decode(\substr($stored, \strlen(self::ENC_PREFIX)), true);
        if (!\is_string($payload) || \strlen($payload) <= 12 + 16) {
            return null;
        }
        $iv  = \substr($payload, 0, 12);
        $tag = \substr($payload, 12, 16);
        $ct  = \substr($payload, 12 + 16);
        try {
            $plain = \openssl_decrypt(
                $ct,
                self::CIPHER,
                $key,
                \OPENSSL_RAW_DATA,
                $iv,
                $tag,
                ''
            );
            return \is_string($plain) ? $plain : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Round-3 A4 P2: routed through SecurityLogger so the encryption-key
     * tier-fail event appears alongside every other security event in
     * the same structured-log stream. The constant slug is
     * {@see SecurityLogger::EVENT_ENCRYPTION_KEY_MISSING}.
     */
    private static function warnNoKey(): void
    {
        SecurityLogger::log(SecurityLogger::EVENT_ENCRYPTION_KEY_MISSING, [
            'platform'    => 'joomla',
            'tiers_tried' => ['constant', 'outside_webroot_file', 'media_auto_generated'],
            'fallback'    => 'plaintext_storage',
            'remediation' => 'define YTB_MCP_ENCRYPTION_KEY in configuration.php '
                . '(Tier 1) per Joomla security best-practices.',
        ]);
    }
}
