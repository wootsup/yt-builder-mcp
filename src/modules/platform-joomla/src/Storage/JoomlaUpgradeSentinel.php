<?php
/**
 * JoomlaUpgradeSentinel — version-tracked upgrade self-heal + stale-media prune.
 *
 * W9-T7 (gap #23). Joomla's package updater runs `script.php::postflight()`
 * on a normal Update — that reseeds the schema sentinel and prunes stale
 * media. But a MANUAL file-swap (SFTP / Akeeba restore / ZIP-overwrite of the
 * plugin folder) skips the installer lifecycle entirely: the new code lands on
 * disk carrying the new {@see \YTB_MCP_VERSION}, yet no postflight ever runs.
 *
 * This sentinel is the request-time recovery path — the Joomla-side analogue
 * of the api-mapper {@see \ApiMapper\Source\Migration\PluginVersionSentinel}
 * (which fires on `plugins_loaded`). It is deliberately LIGHTER than the
 * api-mapper version: yt-builder-mcp has no compiled GraphQL schema cache to
 * bust, so reconciliation is just:
 *
 *   1. Re-seed the storage schema-version row (idempotent — recovers an
 *      install whose tables exist but whose schema_version option was lost).
 *   2. Prune stale media that older versions shipped but the current package
 *      no longer does (Joomla's media install does NOT delete removed files
 *      on upgrade — concretely the W11-removed media/com_ytbmcp/css/admin.css).
 *   3. Persist the on-disk version so the reconcile is a one-shot no-op until
 *      the next version change.
 *
 * # Idempotency + fail-safety
 *
 * `reconcile()` is idempotent within a request (the persisted version then
 * matches on the next call). Every step is wrapped so a failure NEVER unwinds
 * the bootstrap that called it — a missed prune is recoverable on the next
 * upgrade, and a missed reseed is recovered by {@see JoomlaSchemaVersion::ensure}
 * which the system plugin also calls unconditionally.
 *
 * The where-is-media-stored detail is injected so the class stays unit-testable
 * without a Joomla bootstrap (tests pass a temp dir + an in-memory option store).
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Storage
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Storage;

defined('_JEXEC') or die;

// SSoT (Audit A1-M2): the prune list lives in the dependency-free shared file
// so the package installer script and this sentinel cannot diverge. Required
// by computed path — it ships alongside this class inside plg_system_ytbmcp.zip
// at `modules/platform-joomla/src/Shared/`.
require_once __DIR__ . '/../Shared/ytbmcp-joomla-detect.php';

final class JoomlaUpgradeSentinel
{
    /**
     * Storage key for the last-reconciled plugin version. Distinct from the
     * schema_version sentinel — schema_version tracks the DB shape, this
     * tracks the shipped CODE version so a code-only swap is detected.
     */
    public const VERSION_OPTION_KEY = 'plugin_version';

    /**
     * Media files shipped by an EARLIER version that the current package no
     * longer ships. Paths are relative to the site media root
     * (`JPATH_ROOT/media/`).
     *
     * - com_ytbmcp/css/admin.css : the W11 native-redesign dropped the
     *   component stylesheet (see ytbmcp.xml <media> comment + JoomlaBrandAssets
     *   ::renderInlineStyles deprecation). Joomla's media install leaves the
     *   old file behind on upgrade — this prune removes it.
     *
     * SSoT (Audit A1-M2, 2026-05-25): the canonical list now lives in
     * {@see ytbmcp_shared_stale_media()} (the dependency-free shared file the
     * package installer also consumes). This constant is a BC alias kept in
     * lockstep — {@see pruneStaleMedia()} reads the shared function, not this
     * constant, so behaviour has exactly one source.
     *
     * @var list<string>
     */
    public const STALE_MEDIA = [
        'com_ytbmcp/css/admin.css',
    ];

    public function __construct(
        private readonly JoomlaOptionStore $store = new JoomlaOptionStore(),
    ) {
    }

    /**
     * Reconcile the on-disk code version against the last-reconciled value.
     *
     * @param string $currentVersion The version baked into the shipped code
     *                               (typically the YTB_MCP_VERSION constant).
     * @param string $mediaRoot      Absolute path to the site media root
     *                               (`JPATH_ROOT/media`). Stale-media paths in
     *                               {@see self::STALE_MEDIA} are resolved
     *                               against this.
     * @return bool True when a version change was detected and handled;
     *              false when the stored version already matched (no-op).
     */
    public function reconcile(string $currentVersion, string $mediaRoot): bool
    {
        if ($currentVersion === '') {
            return false;
        }

        $stored = (string) $this->store->get(self::VERSION_OPTION_KEY, '');

        // version_compare('==') so upgrades, downgrades AND dev-checkout swaps
        // all trip the reconcile (any change is worth healing).
        if ($stored !== '' && \version_compare($stored, $currentVersion, '==')) {
            return false;
        }

        // 1. Recover the schema-version sentinel (idempotent INSERT IGNORE).
        try {
            JoomlaSchemaVersion::ensure();
        } catch (\Throwable) {
            // Recoverable on the next request via the system plugin's own
            // unconditional JoomlaSchemaVersion::ensure() call.
        }

        // 2. Prune stale media the new package no longer ships.
        $this->pruneStaleMedia($mediaRoot);

        // 3. Persist the on-disk version so this is a one-shot until the next
        //    version change. set() (insert-or-update) — the row may already
        //    exist from a previous reconcile.
        try {
            $this->store->set(self::VERSION_OPTION_KEY, $currentVersion, false);
        } catch (\Throwable) {
            // If the write fails the reconcile simply re-runs next request —
            // every step above is idempotent, so that is harmless.
        }

        return true;
    }

    /**
     * Delete stale media files (list-driven, fail-safe).
     *
     * Each unlink is independently guarded so one read-only / missing file
     * never aborts the rest. A path that escapes the media root (defence
     * against a malformed STALE_MEDIA entry) is skipped.
     *
     * @param string $mediaRoot Absolute site media root.
     * @return list<string> The relative paths that were actually removed
     *                      (useful for tests + observability).
     */
    public function pruneStaleMedia(string $mediaRoot): array
    {
        $removed = [];
        $mediaRoot = \rtrim($mediaRoot, '/');
        if ($mediaRoot === '') {
            return $removed;
        }

        // SSoT: iterate the shared canonical list (not the BC const) so the
        // request-time prune and the installer prune share exactly one source.
        foreach (ytbmcp_shared_stale_media() as $rel) {
            // Reject an empty or path-traversal entry in the (static, trusted,
            // but defensive) list — never resolve a `..` segment outside the
            // media root. `trim()` to '' catches a whitespace-only entry.
            if (\trim($rel) === '' || \str_contains($rel, '..')) {
                continue;
            }
            $target = $mediaRoot . '/' . \ltrim($rel, '/');

            try {
                if (\is_file($target)) {
                    if (@\unlink($target)) {
                        $removed[] = $rel;
                    }
                }
            } catch (\Throwable) {
                // Best-effort — a failed prune is retried on the next upgrade.
                continue;
            }
        }

        return $removed;
    }
}
