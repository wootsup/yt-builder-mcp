<?php
/**
 * Installer script for plg_system_ytbmcp.
 *
 * Pattern lifted from api-mapper InstallerScript (battle-tested against
 * J5 + J6 install/update/uninstall lifecycles).
 *
 * Wave 4 fix-round F3 + Round-3 (Audit-A5 NEW-P1) changes:
 *   - Audit-A5 P1-1: `delete_data_on_uninstall` is read from the plugin's
 *     params (System → Manage → Plugins → System - YT Builder MCP →
 *     "Uninstall behaviour"). The hard-coded `false` constant is gone;
 *     admins explicitly opt in to a full wipe.
 *   - Audit-A5 P1-2 + NEW-P1: the manifest's `<uninstall><sql>` files
 *     are now intentionally EMPTY (see sql/uninstall.*.sql). The
 *     destructive DROP TABLE statements are issued PROGRAMMATICALLY by
 *     {@see dropOwnedTables()} only when the gate is opted-in. OPT-OUT
 *     is therefore a true no-op — customer signing keys + API client
 *     registrations survive an uninstall and rehydrate on the next
 *     install, exactly as the language INI promises.
 *
 *     (Previous F3 attempt tried to file_put_contents-rewrite the
 *     manifest SQL files on disk; that violated the manifest-as-shipped
 *     contract AND left a hidden data-loss regression because the
 *     destructive variant still ran via Joomla's manifest hook. The
 *     Round-3 fix inverts the model: empty manifest SQL + opt-in
 *     programmatic DROP.)
 *
 * @package    WootsUp\Plugin\System\Ytbmcp
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;

class PlgSystemYtbmcpInstallerScript extends InstallerScript
{
    /** @var string */
    protected $minimumJoomla = '5.0';

    /** @var string */
    protected $minimumPhp = '8.2';

    /**
     * Tables owned by yt-builder-mcp. The uninstall path drops these
     * when the customer-data-protection gate is opted-in.
     *
     * Order matters when foreign keys are involved — in our case the
     * three tables are independent, so any order is safe.
     */
    private const OWNED_TABLES = [
        '#__ytb_mcp_options',
        '#__ytb_mcp_transients',
        '#__ytb_mcp_lock',
    ];

    public function preflight($type, $parent): bool
    {
        if (!parent::preflight($type, $parent)) {
            return false;
        }
        return true;
    }

    public function postflight($type, $parent): bool
    {
        if ($type === 'install' || $type === 'update' || $type === 'discover_install') {
            $this->seedSchemaVersion();
        }
        return true;
    }

    /**
     * Pre-uninstall hook. Honours the `delete_data_on_uninstall` plugin
     * param: when not opted in, the canonical-data-bearing tables stay
     * intact so the customer can reinstall without losing their signing
     * key and registered API clients.
     *
     * Round-3 model (Audit-A5 NEW-P1): the manifest's `<uninstall><sql>`
     * files (see sql/uninstall.*.sql) are EMPTY. This method is the
     * single source-of-truth for destructive uninstall behaviour:
     *
     *   - OPT-IN (`delete_data_on_uninstall = 1`):
     *       → {@see dropOwnedTables()} issues DROP TABLE IF EXISTS for
     *         each owned table via DI-resolved DatabaseInterface.
     *
     *   - OPT-OUT (`delete_data_on_uninstall = 0`, default):
     *       → true no-op. The three #__ytb_mcp_* tables stay in place.
     *         A reinstall picks the data right back up (signing key,
     *         registered Bearer kids, transients, lock rows).
     *
     * The previous file-mutation snapshot dance is GONE — it never
     * worked end-to-end because the manifest DROP-SQL still fired
     * after this method returned. Empty manifest SQL closes the loop.
     */
    public function uninstall($parent): bool
    {
        if ($this->isDeleteDataOptedIn()) {
            $this->dropOwnedTables();
            // Audit A5-F1: a full opt-in wipe must ALSO remove the Tier-3
            // encryption-key fallback dir. It deliberately lives OUTSIDE the
            // manifest-owned media/com_ytbmcp/ tree (so a normal uninstall does
            // NOT orphan the encrypted signing_secret), which means the manifest
            // <media> removal never touches it — opt-in wipe must do it here.
            $this->wipeEncryptionKeyDirectory();
            return true;
        }

        // OPT-OUT — communicate the no-op so an admin sees confirmation
        // of the data-preservation behaviour in the install-result page.
        // Audit A5-F1: the recovery promise is now TRUE — the Tier-3 fallback
        // key lives at media/ytb_mcp_secure/ (not the manifest-owned
        // media/com_ytbmcp/), so it survives the uninstall and the encrypted
        // signing_secret stays decodable on reinstall.
        Factory::getApplication()->enqueueMessage(
            'YT Builder MCP uninstall: "Delete all data on uninstall" is OFF. '
            . 'The #__ytb_mcp_options, #__ytb_mcp_transients and #__ytb_mcp_lock '
            . 'tables and the encryption-key fallback were preserved. A future '
            . 'reinstall will recover your signing key and registered API '
            . 'clients automatically.',
            'info'
        );
        return true;
    }

    /**
     * Audit A5-F1 (opt-in full wipe): remove the Tier-3 encryption-key
     * fallback directory `media/ytb_mcp_secure/`. Only ever called from the
     * `delete_data_on_uninstall = 1` branch — a true opt-in full wipe should
     * leave nothing behind, including the off-tree key fallback.
     *
     * Mirrors the stale-media prune's `..`-traversal guard: the target is
     * computed from JPATH_ROOT and verified to stay under the media root before
     * any deletion. Fail-safe — every step is guarded so an uninstall never
     * fatals on a read-only / missing file.
     */
    private function wipeEncryptionKeyDirectory(): void
    {
        try {
            if (!\defined('JPATH_ROOT')) {
                return;
            }
            $mediaRoot = \rtrim(JPATH_ROOT, '/') . '/media';

            // Relative dir, kept in lockstep with
            // JoomlaEncryptionKeyResolver::TIER3_RELATIVE (dirname).
            $rel = 'ytb_mcp_secure';
            if (\str_contains($rel, '..')) {
                return;
            }
            $targetDir = $mediaRoot . '/' . $rel;

            // Defence: the resolved path must stay under the media root.
            $realMedia  = \realpath($mediaRoot);
            $realTarget = \realpath($targetDir);
            if ($realTarget === false) {
                return; // already gone — nothing to wipe.
            }
            if ($realMedia === false || \strncmp($realTarget, $realMedia . '/', \strlen($realMedia) + 1) !== 0) {
                return; // refuse to delete anything outside media/.
            }

            // Remove known files first (key + hardening artefacts), then rmdir.
            foreach (['.encryption_key', '.htaccess', 'web.config', 'index.html'] as $file) {
                $path = $realTarget . '/' . $file;
                if (\is_file($path)) {
                    @\unlink($path);
                }
            }
            @\rmdir($realTarget);
        } catch (\Throwable $e) {
            // Best-effort — an uninstall must never fatal on key-dir cleanup.
        }
    }

    /**
     * Read the `delete_data_on_uninstall` plugin param. Defaults to
     * `'0'` (false) when the plugin row is unknown or the param is
     * missing — customer-data protection is the safe default.
     */
    private function isDeleteDataOptedIn(): bool
    {
        try {
            $plugin = PluginHelper::getPlugin('system', 'ytbmcp');
            if ($plugin === null) {
                return false;
            }
            // `$plugin->params` is a JSON string on raw read; the
            // PluginHelper auto-unpacks into a Registry on most code
            // paths. Both shapes resolve via the same `get` accessor
            // pattern (Registry::get / array-access fallback).
            $params = $plugin->params ?? null;
            if (\is_string($params)) {
                $params = new \Joomla\Registry\Registry($params);
            }
            if (\is_object($params) && \method_exists($params, 'get')) {
                $value = $params->get('delete_data_on_uninstall', '0');
                return (string) $value === '1';
            }
            if (\is_array($params)) {
                return (string) ($params['delete_data_on_uninstall'] ?? '0') === '1';
            }
            return false;
        } catch (\Throwable) {
            // Any failure → safe default (no destructive uninstall).
            return false;
        }
    }

    /**
     * Drop the yt-builder-mcp tables programmatically. Each DROP is
     * wrapped in its own try/catch so a partial-state install (one
     * table missing, two present) still removes the present tables
     * cleanly.
     */
    private function dropOwnedTables(): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage(
                'YT Builder MCP uninstall: could not resolve database driver: ' . $e->getMessage(),
                'warning'
            );
            return;
        }

        foreach (self::OWNED_TABLES as $table) {
            try {
                $sql = 'DROP TABLE IF EXISTS ' . $db->quoteName($table);
                $db->setQuery($sql)->execute();
            } catch (\Throwable $e) {
                Factory::getApplication()->enqueueMessage(
                    \sprintf(
                        'YT Builder MCP uninstall: DROP %s failed: %s',
                        $table,
                        $e->getMessage()
                    ),
                    'warning'
                );
            }
        }
    }

    /**
     * Seed the `schema_version` sentinel option once the plugin's tables
     * exist. Idempotent — INSERT IGNORE semantics via DI-resolved
     * DatabaseInterface.
     */
    private function seedSchemaVersion(): void
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $now   = \time();
            $key   = 'schema_version';
            $value = '1';
            $query = $db->createQuery()
                ->insert($db->quoteName('#__ytb_mcp_options'))
                ->columns($db->quoteName(['option_key', 'option_value', 'autoload', 'created_at', 'updated_at']))
                ->values(':key, :value, 0, :ct, :ut')
                ->bind(':key',   $key,   \Joomla\Database\ParameterType::STRING)
                ->bind(':value', $value, \Joomla\Database\ParameterType::STRING)
                ->bind(':ct',    $now,   \Joomla\Database\ParameterType::INTEGER)
                ->bind(':ut',    $now,   \Joomla\Database\ParameterType::INTEGER);

            // INSERT IGNORE pattern (driver-agnostic via try/catch on duplicate-key).
            try {
                $db->setQuery($query)->execute();
            } catch (\Throwable $duplicate) {
                // Row exists → upgrade-from-existing-install. No-op.
            }
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage(
                'YT Builder MCP schema-version seed failed: ' . $e->getMessage(),
                'warning'
            );
        }
    }
}
