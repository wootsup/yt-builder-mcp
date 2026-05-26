<?php
/**
 * SHARED, DEPENDENCY-FREE SSoT for two pieces of logic that were previously
 * DUPLICATED between the request-time platform-joomla classes and the
 * package-level installer script (Audit A1-M1 + A1-M2, 2026-05-25):
 *
 *   1. YOOtheme-Pro detection — the `#__extensions` `manifest_cache` query
 *      plus the `templates/yootheme/templateDetails.xml` fallback.
 *      Consumed by {@see \WootsUp\BuilderMcp\Platform\Joomla\Yootheme\JoomlaYoothemeProbe}
 *      and by `Pkg_YtbmcpInstallerScript::detectYoothemeVersion()`.
 *
 *   2. STALE_MEDIA — the list of media files an EARLIER version shipped that
 *      the current package no longer ships and must prune on upgrade (Joomla's
 *      <media> install never deletes removed files). Consumed by
 *      {@see \WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaUpgradeSentinel}
 *      (request-time, manual-file-swap path) and by the package script's
 *      `pruneStaleMedia()` (hook-driven Update path).
 *
 * # Why a plain-PHP file (no namespace, no `use`)
 *
 * The package installer script (`pkg_ytbmcp/script.php`) runs at package
 * POSTFLIGHT time, when the platform-joomla PSR-4 classes are still sealed
 * inside the not-yet-resolved `plg_system_ytbmcp.zip` sub-extension — there is
 * NO deterministic, install-safe path from the package script to those classes
 * (require_once-by-path is unreachable). So the shared logic lives here as
 * dependency-free functions that BOTH sides `require_once` by computed path:
 *
 *   - the namespaced classes require it from
 *     `__DIR__ . '/../Shared/ytbmcp-joomla-detect.php'`;
 *   - release.php ALSO stages a copy next to the package script (at the package
 *     ZIP root) so `pkg_ytbmcp/script.php` can require it without reaching into
 *     the nested sub-extension ZIP.
 *
 * Joomla core IS loaded by the time either consumer calls these functions, so
 * the fully-qualified `\Joomla\...` references inside the function bodies
 * resolve at call time. No top-level `use` keeps this file safe to include
 * from any namespace.
 *
 * Every function is fail-safe: any DB / filesystem / parse error degrades to
 * null (detection) or a no-op (prune) rather than throwing — neither the admin
 * page nor the installer may ever fatal on this logic.
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Shared
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

defined('_JEXEC') or die;

// Guard against double-include: the file is required by both the namespaced
// classes AND the package script, which may both run in one request lifecycle.
if (!\function_exists('ytbmcp_shared_stale_media')) {

    /**
     * The Joomla extension `element` for the YOOtheme Pro template (free + Pro
     * both ship element `yootheme`; presence + version-floor is the gate).
     */
    if (!\defined('YTBMCP_YT_TEMPLATE_ELEMENT')) {
        \define('YTBMCP_YT_TEMPLATE_ELEMENT', 'yootheme');
    }

    /**
     * Media files shipped by an EARLIER version that the current package no
     * longer ships. Paths are relative to the site media root
     * (`JPATH_ROOT/media/`). One line per removal.
     *
     * - com_ytbmcp/css/admin.css : the W11 native-redesign dropped the
     *   component stylesheet. Joomla's media install leaves the old file behind
     *   on upgrade — this list drives its removal.
     *
     * @return list<string>
     */
    function ytbmcp_shared_stale_media(): array
    {
        return [
            'com_ytbmcp/css/admin.css',
        ];
    }

    /**
     * Detect the installed YOOtheme Pro version string, or null when YT is not
     * present. Detection order: `#__extensions.manifest_cache` →
     * `templates/yootheme/templateDetails.xml`.
     */
    function ytbmcp_shared_detect_yootheme_version(): ?string
    {
        $fromExtensions = ytbmcp_shared_yt_version_from_extensions();
        if ($fromExtensions !== null) {
            return $fromExtensions;
        }

        return ytbmcp_shared_yt_version_from_manifest_file();
    }

    /**
     * Read the YT version from the site `yootheme` template's `#__extensions`
     * row (`type='template' AND element='yootheme' AND client_id=0 AND
     * enabled=1`). Returns null when the row is absent/disabled, when
     * `manifest_cache` carries no `version`, or on any DB error.
     */
    function ytbmcp_shared_yt_version_from_extensions(): ?string
    {
        try {
            /** @var \Joomla\Database\DatabaseInterface $db */
            $db = \Joomla\CMS\Factory::getContainer()
                ->get(\Joomla\Database\DatabaseInterface::class);

            $element  = (string) \constant('YTBMCP_YT_TEMPLATE_ELEMENT');
            $type     = 'template';
            $clientId = 0; // site application
            $enabled  = 1;

            $query = $db->createQuery()
                ->select($db->quoteName('manifest_cache'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = :type')
                ->where($db->quoteName('element') . ' = :element')
                ->where($db->quoteName('client_id') . ' = :clientId')
                ->where($db->quoteName('enabled') . ' = :enabled')
                ->bind(':type', $type, \Joomla\Database\ParameterType::STRING)
                ->bind(':element', $element, \Joomla\Database\ParameterType::STRING)
                ->bind(':clientId', $clientId, \Joomla\Database\ParameterType::INTEGER)
                ->bind(':enabled', $enabled, \Joomla\Database\ParameterType::INTEGER);

            /** @var mixed $raw */
            $raw = $db->setQuery($query)->loadResult();
        } catch (\Throwable) {
            return null;
        }

        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            return null;
        }
        if (!isset($decoded['version']) || !\is_string($decoded['version'])) {
            return null;
        }

        $version = \trim($decoded['version']);
        return $version !== '' ? $version : null;
    }

    /**
     * Fallback: parse the `<version>` element from the YOOtheme template
     * manifest on disk. Returns null when the file is missing/unparseable.
     */
    function ytbmcp_shared_yt_version_from_manifest_file(): ?string
    {
        $base = \defined('JPATH_SITE')
            ? JPATH_SITE
            : (\defined('JPATH_ROOT') ? JPATH_ROOT : null);
        if ($base === null) {
            return null;
        }

        $element      = (string) \constant('YTBMCP_YT_TEMPLATE_ELEMENT');
        $manifestPath = $base . '/templates/' . $element . '/templateDetails.xml';
        if (!\is_file($manifestPath) || !\is_readable($manifestPath)) {
            return null;
        }

        try {
            $xml = @\simplexml_load_file($manifestPath);
            if ($xml === false || !isset($xml->version)) {
                return null;
            }
            $version = \trim((string) $xml->version);
            return $version !== '' ? $version : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
