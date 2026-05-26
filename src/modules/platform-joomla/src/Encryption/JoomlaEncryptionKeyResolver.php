<?php
/**
 * 3-tier encryption-key resolver for the Joomla platform.
 *
 * ADR-001 / Cookbook §Pillar 3 — DO NOT derive from Joomla's
 * `configuration.php $secret` because some Recovery-Tools regenerate
 * it, which would render every encrypted SigningSecret unreadable.
 *
 * Resolution order (first non-empty wins):
 *   1. PHP constant `YTB_MCP_ENCRYPTION_KEY` (defined in configuration.php
 *      or php.ini — preferred for multi-server deployments)
 *   2. Dedicated key-file outside webroot:
 *      `dirname(JPATH_ROOT) . '/ytb-mcp-encryption.key'`
 *   3. Auto-generated key-file inside `media/ytb_mcp_secure/` with hardened
 *      .htaccess + web.config + index.html denial (last-resort web-accessible
 *      fallback).
 *
 * UNINSTALL-SAFETY (Audit A5-F1, 2026-05-25): the Tier-3 directory lives at
 * `media/ytb_mcp_secure/` and is DELIBERATELY NOT under `media/com_ytbmcp/`.
 * The package manifest's `<media destination="com_ytbmcp">` element makes
 * Joomla delete the whole `media/com_ytbmcp/` tree on a package uninstall —
 * which would have wiped the Tier-3 key even with `delete_data_on_uninstall=0`,
 * orphaning the encrypted `signing_secret` row (decode → null → silent
 * regenerate → all previously-issued Bearer tokens break). The dedicated
 * `media/ytb_mcp_secure/` folder is not manifest-owned, so it survives an
 * uninstall and the "we recover your signing key" promise holds.
 *
 * BACKWARD-COMPAT: pre-A5-F1 alpha installs auto-generated the key at the old
 * `media/com_ytbmcp/.encryption_key` location. On first resolve, if the new
 * path is empty but the legacy file exists, we MIGRATE the legacy key into the
 * new location (preserving the bytes so the existing encrypted secret stays
 * decodable) and harden the new directory. The migration is fail-safe — any
 * error degrades to a fresh auto-generate, never crashes the auth path.
 *
 * Returns null only when ALL three tiers fail — in that case
 * SigningSecret degrades to plaintext storage (cookbook §2.4.6) and a
 * loud error_log message asks the operator to set the constant.
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Encryption
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Encryption;

defined('_JEXEC') or die;

final class JoomlaEncryptionKeyResolver
{
    /** Tier-2 key file name (lives one level above JPATH_ROOT). */
    public const TIER2_FILENAME = 'ytb-mcp-encryption.key';

    /**
     * Tier-3 fallback path (lives in media/ — web-accessible but
     * htaccess/web.config/dotfile-denied).
     *
     * Audit A5-F1: this is DELIBERATELY NOT under `media/com_ytbmcp/`. A
     * package uninstall removes `media/com_ytbmcp/` (manifest-owned via
     * `<media destination="com_ytbmcp">`), which would wipe the key even when
     * the customer opted OUT of data deletion. `media/ytb_mcp_secure/` is not
     * manifest-owned, so it survives an uninstall.
     */
    public const TIER3_RELATIVE = '/media/ytb_mcp_secure/.encryption_key';

    /**
     * Legacy Tier-3 path used by pre-A5-F1 alpha installs. Read once for the
     * one-time migration into {@see self::TIER3_RELATIVE}; never written to.
     */
    public const TIER3_LEGACY_RELATIVE = '/media/com_ytbmcp/.encryption_key';

    private static ?string $cache = null;

    /**
     * Resolve the encryption-key bytes (32 raw bytes — SHA-256 of the
     * underlying secret). Returns null when no tier resolves.
     */
    public static function resolve(): ?string
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $secret = self::tier1ConstantKey()
            ?? self::tier2OutsideWebrootFile()
            ?? self::tier3MediaFile();

        if ($secret === null || $secret === '') {
            return null;
        }

        // Derive the AES-256 key (32 raw bytes) with a domain-separation
        // prefix so the same source can't accidentally collide with a
        // different consumer's key.
        $derived    = \hash('sha256', 'ytb_mcp_joomla:' . $secret, true);
        self::$cache = $derived;
        return $derived;
    }

    /** @internal Test-only reset hook. */
    public static function resetForTests(): void
    {
        self::$cache = null;
    }

    private static function tier1ConstantKey(): ?string
    {
        if (\defined('YTB_MCP_ENCRYPTION_KEY')) {
            $val = (string) \constant('YTB_MCP_ENCRYPTION_KEY');
            return $val !== '' ? $val : null;
        }
        return null;
    }

    private static function tier2OutsideWebrootFile(): ?string
    {
        $root = \defined('JPATH_ROOT') ? JPATH_ROOT : '';
        if ($root === '') {
            return null;
        }
        $path = \dirname($root) . '/' . self::TIER2_FILENAME;
        if (\is_file($path) && \is_readable($path)) {
            $val = \trim((string) \file_get_contents($path));
            return $val !== '' ? $val : null;
        }
        return null;
    }

    private static function tier3MediaFile(): ?string
    {
        $root = \defined('JPATH_ROOT') ? JPATH_ROOT : '';
        if ($root === '') {
            return null;
        }
        $path = $root . self::TIER3_RELATIVE;

        if (\is_file($path) && \is_readable($path)) {
            $val = \trim((string) \file_get_contents($path));
            return $val !== '' ? $val : null;
        }

        // Audit A5-F1 backward-compat: a pre-relocation alpha install may have
        // its key at the legacy `media/com_ytbmcp/.encryption_key` path. If so,
        // migrate it into the uninstall-safe location so the EXISTING encrypted
        // secret stays decodable. Fail-safe — a migration error falls through
        // to a fresh auto-generate (which would still preserve the legacy file,
        // so a later retry can succeed).
        $migrated = self::migrateLegacyTier3Key($root, $path);
        if ($migrated !== null) {
            return $migrated;
        }

        // Auto-generate on first miss. 64 hex chars (256 bits CSPRNG).
        try {
            $dir = \dirname($path);
            if (!\is_dir($dir)) {
                @\mkdir($dir, 0700, true);
            }
            $newKey = \bin2hex(\random_bytes(32));
            if (@\file_put_contents($path, $newKey, \LOCK_EX) !== false) {
                @\chmod($path, 0600);
                self::hardenTier3Directory($dir);
                self::notifyOperatorTier3Generated();
                return $newKey;
            }
        } catch (\Throwable) {
            // Silent failure → tier returns null, caller degrades gracefully.
        }
        return null;
    }

    /**
     * One-time migration of a legacy Tier-3 key (pre-A5-F1, stored at
     * `media/com_ytbmcp/.encryption_key`) into the uninstall-safe location.
     *
     * Reads the legacy bytes verbatim and writes them to the new path (chmod
     * 600 + harden the new dir). Returns the migrated key on success, or null
     * when there is no legacy key OR the migration could not be completed — in
     * which case the caller proceeds to auto-generate a fresh key. Entirely
     * fail-safe (@-suppressed + try/catch): defense-in-depth must NEVER crash
     * the auth path.
     *
     * The legacy file is intentionally left in place: removing it is the job of
     * the opt-in full-wipe uninstall, not of a read-time resolver — and keeping
     * it lets a failed write retry on the next request.
     *
     * @param string $root    JPATH_ROOT.
     * @param string $newPath Absolute path to {@see self::TIER3_RELATIVE}.
     */
    private static function migrateLegacyTier3Key(string $root, string $newPath): ?string
    {
        try {
            $legacyPath = $root . self::TIER3_LEGACY_RELATIVE;
            if (!\is_file($legacyPath) || !\is_readable($legacyPath)) {
                return null;
            }

            $legacyKey = \trim((string) @\file_get_contents($legacyPath));
            if ($legacyKey === '') {
                return null;
            }

            $dir = \dirname($newPath);
            if (!\is_dir($dir)) {
                @\mkdir($dir, 0700, true);
            }

            if (@\file_put_contents($newPath, $legacyKey, \LOCK_EX) === false) {
                // Could not persist into the new location — return the legacy
                // bytes anyway so THIS request decodes correctly; the next
                // request retries the migration.
                return $legacyKey;
            }

            @\chmod($newPath, 0600);
            self::hardenTier3Directory($dir);
            return $legacyKey;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Multi-server-aware deny rules for the Tier-3 directory (cookbook
     * §2.10.2 + Pillar 3). The original `.htaccess` defends Apache
     * only; nginx + IIS would happily serve the key over HTTP. We now
     * emit:
     *
     *   - `.htaccess`   (Apache 2.2 + 2.4 syntax for portability)
     *   - `web.config`  (IIS — Microsoft URL Rewrite + StaticContent)
     *   - `index.html`  (zero-byte denial for directory listings)
     *
     * nginx is the awkward case: its config lives outside the document
     * root and cannot be augmented from a writable user-controlled
     * directory. The leading-dot filename (`.encryption_key`) gives
     * partial defense (most nginx default configs deny dotfiles via
     * `location ~ /\. { deny all; }`) but the only deterministic fix
     * is the one-time admin warning enqueued by
     * {@see notifyOperatorTier3Generated()} recommending Tier 2.
     */
    private static function hardenTier3Directory(string $dir): void
    {
        // Apache — both 2.2 (Order/Deny) and 2.4 (Require) syntax.
        $htaccess = "# yt-builder-mcp Tier-3 fallback — DO NOT REMOVE.\n"
            . "Require all denied\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Order allow,deny\n"
            . "    Deny from all\n"
            . "</IfModule>\n"
            . "<FilesMatch \"\\.(key|encryption_key)$\">\n"
            . "    Require all denied\n"
            . "</FilesMatch>\n";
        @\file_put_contents($dir . '/.htaccess', $htaccess);

        // IIS — Microsoft URL Rewrite + StaticContent deny rule.
        $webConfig = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<configuration>\n"
            . "    <system.webServer>\n"
            . "        <security>\n"
            . "            <requestFiltering>\n"
            . "                <hiddenSegments>\n"
            . "                    <add segment=\".encryption_key\" />\n"
            . "                </hiddenSegments>\n"
            . "                <fileExtensions>\n"
            . "                    <add fileExtension=\".key\" allowed=\"false\" />\n"
            . "                </fileExtensions>\n"
            . "            </requestFiltering>\n"
            . "        </security>\n"
            . "        <authorization>\n"
            . "            <remove users=\"*\" roles=\"\" verbs=\"\" />\n"
            . "            <add accessType=\"Deny\" users=\"*\" />\n"
            . "        </authorization>\n"
            . "    </system.webServer>\n"
            . "</configuration>\n";
        @\file_put_contents($dir . '/web.config', $webConfig);

        @\file_put_contents($dir . '/index.html', '<!doctype html><title></title>');
    }

    /**
     * One-time enqueueMessage warning when Tier-3 has just been
     * auto-generated. Recommends the operator move the key to Tier 2
     * (outside webroot) — the only resolution path that guarantees
     * the key is unreachable over HTTP on every web server.
     *
     * Idempotent via a persistent option flag so we never nag twice.
     * Best-effort: any failure (no JoomlaOptionStore, no app, no
     * session) is swallowed — defense-in-depth must never crash the
     * primary code path.
     */
    private static function notifyOperatorTier3Generated(): void
    {
        try {
            // Use late-static-resolved class names so this method is
            // safe to call before the composer autoloader has had a
            // chance to populate every PSR-4 alias.
            if (!\class_exists('\WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore')) {
                return;
            }
            /** @var \WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore $store */
            $store = new \WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore();
            $flagKey = 'tier3_auto_generated_warning_shown';
            if ((string) $store->get($flagKey, '') === '1') {
                return;
            }

            if (\class_exists('\Joomla\CMS\Factory')) {
                $app = \Joomla\CMS\Factory::getApplication();
                if (\method_exists($app, 'enqueueMessage')) {
                    $app->enqueueMessage(
                        'YT Builder MCP: a fallback encryption key was generated inside the web-accessible'
                        . ' media/ytb_mcp_secure/ folder (a non-manifest-owned location that survives'
                        . ' a package uninstall). For production deployments we strongly recommend'
                        . ' moving the key outside the webroot. Define YTB_MCP_ENCRYPTION_KEY in'
                        . ' configuration.php, or place the key in a file named'
                        . ' ytb-mcp-encryption.key one directory above JPATH_ROOT. The current'
                        . ' fallback is protected by .htaccess + web.config but cannot be hardened'
                        . ' for nginx without manual server-config changes.',
                        'warning'
                    );
                }
            }

            // Persist the flag so this warning fires once per install.
            $store->set($flagKey, '1');
        } catch (\Throwable) {
            // Best-effort — never block the calling code path.
        }
    }
}
