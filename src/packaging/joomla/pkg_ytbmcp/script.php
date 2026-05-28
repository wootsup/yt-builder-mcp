<?php
/**
 * Package installer for pkg_ytbmcp.
 *
 * Joomla 5/6 package-level lifecycle. The package's child extensions
 * (plg_system_ytbmcp, com_ytbmcp) have their own InstallerScript files
 * for fine-grained gates; this one handles package-level concerns:
 *  - minimum Joomla / PHP versions
 *  - safety messages to the administrator
 *  - post-install activation of the system + webservices plugins (so
 *    customers don't have to remember to enable them manually)
 *  - a rich, branded "install-complete" panel (W11-T5) mirroring
 *    api-mapper's: WootsUp logo, YOOtheme Pro / PHP / Joomla compatibility
 *    checks, getting-started tiles, dark-mode aware scoped CSS.
 *
 * DEFENSIVE DESIGN (W11-T5): the panel renderer is wrapped so a render
 * error NEVER aborts the install. The auto-enable + version gates run
 * first; the panel is best-effort cosmetic output echoed at the end.
 *
 * @package    WootsUp\BuilderMcp\Packaging\Joomla
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Router\Route;

/**
 * @internal Class name is dictated by Joomla packaging convention:
 *           `Pkg<Element>InstallerScript` where Element = `Ytbmcp`.
 */
class Pkg_YtbmcpInstallerScript extends InstallerScript
{
    /** @var string */
    protected $minimumJoomla = '5.0';

    /** @var string */
    protected $minimumPhp = '8.2';

    /** Minimum YOOtheme Pro version the page-builder REST surface needs. */
    private const MIN_YT_VERSION = '4.0';

    /** Product version (kept in sync with pkg_ytbmcp.xml <version>). */
    private const PLUGIN_VERSION = '1.1.7';

    /**
     * W9-T7 (#23): media files shipped by an EARLIER version that the current
     * package no longer ships. Joomla's <media> install does NOT delete files
     * removed between versions on upgrade, so they must be pruned explicitly.
     * Paths are relative to JPATH_ROOT/media/.
     *
     * SSoT (Audit A1-M2, 2026-05-25): the CANONICAL list lives in the
     * dependency-free shared file
     * `platform-joomla/src/Shared/ytbmcp-joomla-detect.php`
     * ({@see ytbmcp_shared_stale_media()}), which release.php stages next to
     * this script at the package ZIP root. {@see pruneStaleMedia()} loads it
     * first and only falls back to THIS inline copy when the shared file is
     * unreachable. The inline copy is therefore a guarded fail-safe, not a
     * second source-of-truth — the package installer cannot reach the
     * platform-joomla PSR-4 classes during postflight (they are sealed inside
     * the not-yet-resolved plg_system_ytbmcp.zip), so a defense-in-depth
     * fallback is retained.
     *
     * @var list<string>
     */
    private const STALE_MEDIA = [
        'com_ytbmcp/css/admin.css',
    ];

    /**
     * Filename of the dependency-free SSoT helper release.php stages next to
     * this script (package ZIP root). {@see loadSharedDetect()}.
     */
    private const SHARED_DETECT_FILE = 'ytbmcp-joomla-detect.php';

    /** Getting-started + footer URLs (parity with com_ytbmcp Dashboard HtmlView). */
    private const DOCS_URL  = 'https://github.com/wootsup/yt-builder-mcp#readme';
    private const HOME_URL  = 'https://wootsup.com';
    private const SETUP_CMD = 'npx -y @wootsup/yt-builder-mcp setup';

    /** Brand teal — the only colour inside the logo SVG mark. */
    private const COLOR_TEAL = '#2fd1cd';

    /**
     * Called before install / update / uninstall.
     *
     * @param  string           $type    install|update|uninstall|discover_install
     * @param  InstallerAdapter $parent  package installer adapter
     */
    public function preflight($type, $parent): bool
    {
        if (!parent::preflight($type, $parent)) {
            return false;
        }
        return true;
    }

    /**
     * Auto-enable both plugins post-install so customers don't have to dig
     * through the Plugin Manager, then render the branded install-complete
     * panel. Matches api-mapper packaging UX.
     */
    public function postflight($type, $parent): bool
    {
        if ($type === 'install' || $type === 'update' || $type === 'discover_install') {
            // Enable BOTH our plugins: the system plugin (autoloader /
            // session-strip / bootstrap / scheduler) AND the webservices
            // plugin (REST route registration). The webservices plugin is
            // mandatory — without it every REST route 404s.
            $this->enablePlugin('system', 'ytbmcp', 'System - YT Builder MCP');
            $this->enablePlugin('webservices', 'ytbmcp', 'Web Services - YT Builder MCP');

            // W9-T7 (#23): on the Update path, prune media files that older
            // versions shipped but the current package no longer does
            // (Joomla's <media> install never deletes removed files on
            // upgrade). Fail-safe — a failed prune never aborts the update.
            if ($type === 'update') {
                $this->pruneStaleMedia();
            }

            // Best-effort cosmetic output. A render error here must NEVER
            // abort the install — wrap the whole thing in a Throwable guard.
            try {
                echo $this->renderInstallCompletePanel($type);
            } catch (\Throwable $e) {
                // Swallow: the plugins are already enabled (the load-bearing
                // step), so a panel failure is purely cosmetic.
            }
        }
        return true;
    }

    /**
     * Delete stale media files the new package no longer ships (W9-T7 #23).
     *
     * List-driven ({@see self::STALE_MEDIA}) + fail-safe: each unlink is
     * independently guarded, a traversal-bearing entry is skipped, and a
     * missing/read-only file is a silent no-op (the request-time sentinel
     * retries on the next bootstrap). Resolved against JPATH_ROOT/media/.
     */
    private function pruneStaleMedia(): void
    {
        $mediaRoot = \defined('JPATH_ROOT') ? \rtrim(JPATH_ROOT, '/') . '/media' : '';
        if ($mediaRoot === '') {
            return;
        }

        // SSoT-primary (Audit A1-M2): consult the shared canonical list; the
        // inline self::STALE_MEDIA is a guarded fail-safe when the shared file
        // could not be staged/loaded.
        $staleMedia = $this->loadSharedDetect() && \function_exists('ytbmcp_shared_stale_media')
            ? ytbmcp_shared_stale_media()
            : self::STALE_MEDIA;

        $removed = [];
        foreach ($staleMedia as $rel) {
            if ($rel === '' || \str_contains($rel, '..')) {
                continue;
            }
            $target = $mediaRoot . '/' . \ltrim($rel, '/');
            try {
                if (\is_file($target) && @\unlink($target)) {
                    $removed[] = $rel;
                }
            } catch (\Throwable $e) {
                // Best-effort — retried by the request-time sentinel.
            }
        }

        if ($removed !== []) {
            $this->safeEnqueue(
                'YT Builder MCP update: pruned ' . \count($removed)
                . ' stale media file(s) from earlier versions ('
                . \implode(', ', $removed) . ').',
                'info'
            );
        }
    }

    private function enablePlugin(string $folder, string $element, string $label): void
    {
        try {
            // NB: bind() takes $value BY REFERENCE in Joomla 5/6. Pre-declare
            // each bound value as a real variable so it has an addressable
            // reference — an inline assignment raises
            // "Argument #2 ($value) could not be passed by reference".
            $type = 'plugin';

            $db    = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $query = $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = :type')
                ->where($db->quoteName('folder') . ' = :folder')
                ->where($db->quoteName('element') . ' = :element')
                ->bind(':type', $type, \Joomla\Database\ParameterType::STRING)
                ->bind(':folder', $folder, \Joomla\Database\ParameterType::STRING)
                ->bind(':element', $element, \Joomla\Database\ParameterType::STRING);
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            // Best-effort: never block install on a post-step.
            $this->safeEnqueue(
                'YT Builder MCP plugin auto-enable failed (' . $folder . '/' . $element . '): '
                . $e->getMessage()
                . ' — enable manually under System → Manage → Plugins → ' . $label . '.',
                'warning'
            );
        }
    }

    // ---------------------------------------------------------------------
    // W11-T5 — rich install-complete panel (mirrors api-mapper)
    // ---------------------------------------------------------------------

    /**
     * Build the full branded install-complete panel: scoped + dark-mode CSS,
     * WootsUp logo header, requirement/compatibility checks, getting-started
     * tile grid. Pure string builder — no echo, no side effects.
     */
    private function renderInstallCompletePanel(string $type): string
    {
        $checks = $this->collectRequirementChecks();

        // Surface any hard-fail compatibility issues as Joomla messages too
        // (the panel is visual; the enqueued message is the actionable alert).
        $this->enqueueCriticalIssues($checks);

        $html  = $this->renderPanelStyle();
        $html .= '<div class="ytbmcp-install-complete">';
        $html .= $this->renderHeader($type);
        $html .= $this->renderChecksGrid($checks);
        $html .= $this->renderTiles();
        $html .= $this->renderFooter();
        $html .= '</div>';

        return $html;
    }

    /**
     * Run the requirement / compatibility checks.
     *
     * @return array<string,array{label:string,status:bool,detail:string,icon:string}>
     */
    private function collectRequirementChecks(): array
    {
        // --- YOOtheme Pro (the key ask) -----------------------------------
        $ytVersion = $this->detectYoothemeVersion();
        $ytPresent = $ytVersion !== null;
        $ytOk      = $ytPresent && version_compare($ytVersion, self::MIN_YT_VERSION, '>=');

        if ($ytOk) {
            $ytDetail = 'Detected ' . $ytVersion . ' — compatible (≥ ' . self::MIN_YT_VERSION . ').';
        } elseif ($ytPresent) {
            $ytDetail = 'Detected ' . $ytVersion . ' — below ' . self::MIN_YT_VERSION
                . '. The page-builder REST surface stays inert until YOOtheme Pro is updated.';
        } else {
            $ytDetail = 'YOOtheme Pro not found — the page-builder REST surface stays inert until it\'s installed.';
        }

        // --- PHP ----------------------------------------------------------
        $phpOk = version_compare(PHP_VERSION, $this->minimumPhp, '>=');

        // --- Joomla -------------------------------------------------------
        $jVersion = defined('JVERSION') ? (string) JVERSION : '0.0.0';
        $jOk      = version_compare($jVersion, $this->minimumJoomla, '>=');

        return [
            'yootheme' => [
                'label'  => 'YOOtheme Pro',
                'status' => $ytOk,
                'detail' => $ytDetail,
                'icon'   => 'Palette',
            ],
            'php' => [
                'label'  => 'PHP',
                'status' => $phpOk,
                'detail' => $phpOk
                    ? 'Detected ' . PHP_VERSION . ' — meets ≥ ' . $this->minimumPhp . '.'
                    : 'Detected ' . PHP_VERSION . ' — below the required ≥ ' . $this->minimumPhp . '.',
                'icon'   => 'Code',
            ],
            'joomla' => [
                'label'  => 'Joomla',
                'status' => $jOk,
                'detail' => $jOk
                    ? 'Detected ' . $jVersion . ' — meets ≥ ' . $this->minimumJoomla . '.'
                    : 'Detected ' . $jVersion . ' — below the required ≥ ' . $this->minimumJoomla . '.',
                'icon'   => 'Layers',
            ],
        ];
    }

    /**
     * Detect the installed YOOtheme Pro version.
     *
     * SSoT-primary (Audit A1-M1, 2026-05-25): the canonical detection SQL +
     * manifest parse live in the dependency-free shared file
     * `ytbmcp-joomla-detect.php` (staged next to this script by release.php).
     * Resolution order, each fail-safe:
     *
     *   1. {@see ytbmcp_shared_detect_yootheme_version()} from the shared file.
     *   2. {@see JoomlaYoothemeProbe} (when its PSR-4 class happens to be
     *      loadable — not guaranteed during the PACKAGE postflight).
     *   3. The inline replication below (last-resort defense-in-depth).
     *
     * Returns null when YT is absent or on any error — fail-safe so detection
     * never fatals the install.
     */
    private function detectYoothemeVersion(): ?string
    {
        // 1. Shared SSoT helper (primary path).
        if ($this->loadSharedDetect() && \function_exists('ytbmcp_shared_detect_yootheme_version')) {
            try {
                $version = ytbmcp_shared_detect_yootheme_version();
                if (is_string($version) && $version !== '') {
                    return $version;
                }
            } catch (\Throwable $e) {
                // Fall through to the probe / inline replication below.
            }
        }

        // 2. PSR-4 probe (only when autoloaded).
        $probeClass = '\\WootsUp\\BuilderMcp\\Platform\\Joomla\\Yootheme\\JoomlaYoothemeProbe';
        if (class_exists($probeClass)) {
            try {
                /** @var object{detectVersion:callable} $probe */
                $probe   = new $probeClass();
                $version = $probe->detectVersion();
                return is_string($version) && $version !== '' ? $version : null;
            } catch (\Throwable $e) {
                // Fall through to the inline replication below.
            }
        }

        // 3. Inline replication (defense-in-depth fail-safe).
        $fromExtensions = $this->yoothemeVersionFromExtensions();
        if ($fromExtensions !== null) {
            return $fromExtensions;
        }

        return $this->yoothemeVersionFromManifestFile();
    }

    /**
     * Best-effort `require_once` of the dependency-free SSoT helper that
     * release.php stages next to this script at the package ZIP root. Returns
     * true once the shared functions are defined.
     *
     * Idempotent + fail-safe: a missing file or include error simply returns
     * false, and the caller degrades to the PSR-4 probe / inline fallback. The
     * package installer cannot reach the platform-joomla PSR-4 classes during
     * postflight (sealed inside the not-yet-resolved plg_system_ytbmcp.zip), so
     * this staged copy is how the package consumes the SSoT.
     */
    private function loadSharedDetect(): bool
    {
        if (\function_exists('ytbmcp_shared_detect_yootheme_version')) {
            return true;
        }
        try {
            $shared = __DIR__ . '/' . self::SHARED_DETECT_FILE;
            if (\is_file($shared) && \is_readable($shared)) {
                require_once $shared;
            }
        } catch (\Throwable $e) {
            return false;
        }
        return \function_exists('ytbmcp_shared_detect_yootheme_version');
    }

    /** Inline twin of JoomlaYoothemeProbe::versionFromExtensions(). */
    private function yoothemeVersionFromExtensions(): ?string
    {
        try {
            $db       = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $element  = 'yootheme';
            $tplType  = 'template';
            $clientId = 0; // site application
            $enabled  = 1;

            $query = $db->createQuery()
                ->select($db->quoteName('manifest_cache'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = :type')
                ->where($db->quoteName('element') . ' = :element')
                ->where($db->quoteName('client_id') . ' = :clientId')
                ->where($db->quoteName('enabled') . ' = :enabled')
                ->bind(':type', $tplType, \Joomla\Database\ParameterType::STRING)
                ->bind(':element', $element, \Joomla\Database\ParameterType::STRING)
                ->bind(':clientId', $clientId, \Joomla\Database\ParameterType::INTEGER)
                ->bind(':enabled', $enabled, \Joomla\Database\ParameterType::INTEGER);

            $raw = $db->setQuery($query)->loadResult();
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['version']) || !is_string($decoded['version'])) {
            return null;
        }

        $version = trim($decoded['version']);
        return $version !== '' ? $version : null;
    }

    /** Inline twin of JoomlaYoothemeProbe::versionFromManifestFile(). */
    private function yoothemeVersionFromManifestFile(): ?string
    {
        $base = defined('JPATH_SITE') ? JPATH_SITE : (defined('JPATH_ROOT') ? JPATH_ROOT : null);
        if ($base === null) {
            return null;
        }

        $manifestPath = $base . '/templates/yootheme/templateDetails.xml';
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            return null;
        }

        try {
            $xml = @simplexml_load_file($manifestPath);
            if ($xml === false || !isset($xml->version)) {
                return null;
            }
            $version = trim((string) $xml->version);
            return $version !== '' ? $version : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Scoped + dark-mode-aware CSS. Scoped to `.ytbmcp-install-complete` so
     * nothing leaks into the Atum admin chrome. Dark mode keys off Joomla's
     * `html[data-bs-theme="dark"]` (Atum/Bootstrap 5) + legacy `.dark`.
     */
    private function renderPanelStyle(): string
    {
        $teal = self::COLOR_TEAL;

        return <<<CSS
<style>
.ytbmcp-install-complete {
    background: #f6f8f9;
    padding: 40px;
    border-radius: 10px;
    margin: 20px 0;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
.ytbmcp-install-complete .ytbmcp-header { text-align: center; margin-bottom: 28px; }
.ytbmcp-install-complete .ytbmcp-logo { display: inline-block; margin-bottom: 14px; }
.ytbmcp-install-complete h2 {
    color: #1f2937;
    font-size: 1.75rem;
    font-weight: 700;
    margin: 0 0 6px;
    line-height: 1.2;
}
.ytbmcp-install-complete .ytbmcp-unofficial {
    display: inline-block;
    margin-top: 8px;
    padding: 2px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    font-size: 0.7rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #64748b;
}
.ytbmcp-install-complete .ytbmcp-subtitle { color: #64748b; font-size: 1rem; line-height: 1.5; margin: 6px 0 0; }
.ytbmcp-install-complete .ytbmcp-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 14px;
    margin-bottom: 28px;
}
.ytbmcp-install-complete .ytbmcp-card {
    background: #ffffff;
    border-radius: 8px;
    padding: 18px 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    border-left: 4px solid transparent;
}
.ytbmcp-install-complete .ytbmcp-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}
.ytbmcp-install-complete .ytbmcp-card-title { display: flex; align-items: center; gap: 8px; }
.ytbmcp-install-complete .ytbmcp-card-icon { color: #64748b; }
.ytbmcp-install-complete .ytbmcp-card-label { color: #1f2937; font-size: 1rem; font-weight: 600; }
.ytbmcp-install-complete .ytbmcp-badge {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.ytbmcp-install-complete .ytbmcp-card-detail { color: #64748b; font-size: 0.875rem; line-height: 1.5; margin: 0; }
.ytbmcp-install-complete .ytbmcp-tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}
.ytbmcp-install-complete .ytbmcp-tile {
    display: block;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 18px 20px;
    text-decoration: none;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
}
.ytbmcp-install-complete .ytbmcp-tile:hover {
    border-color: {$teal};
    box-shadow: 0 4px 14px -6px rgba(0, 0, 0, 0.2);
    transform: translateY(-1px);
}
.ytbmcp-install-complete .ytbmcp-tile-head { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.ytbmcp-install-complete .ytbmcp-tile-icon { color: {$teal}; }
.ytbmcp-install-complete .ytbmcp-tile-num {
    font-size: 0.75rem;
    font-weight: 700;
    color: {$teal};
}
.ytbmcp-install-complete .ytbmcp-tile-title { color: #1f2937; font-size: 1rem; font-weight: 600; }
.ytbmcp-install-complete .ytbmcp-tile-detail { color: #64748b; font-size: 0.85rem; line-height: 1.45; margin: 0; }
.ytbmcp-install-complete code.ytbmcp-cmd {
    display: block;
    margin-top: 6px;
    padding: 8px 10px;
    background: #0f172a;
    color: #e2e8f0;
    border-radius: 6px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.8rem;
    overflow-x: auto;
}
.ytbmcp-install-complete .ytbmcp-footer {
    text-align: center;
    font-size: 0.8rem;
    color: #94a3b8;
    border-top: 1px solid #e2e8f0;
    padding-top: 16px;
}
.ytbmcp-install-complete .ytbmcp-footer a { color: #64748b; text-decoration: none; }
.ytbmcp-install-complete .ytbmcp-footer a:hover { color: {$teal}; }

/* --- Dark mode (Joomla Atum / Bootstrap 5 + legacy) --- */
html[data-bs-theme="dark"] .ytbmcp-install-complete,
[data-bs-theme="dark"] .ytbmcp-install-complete,
html.dark .ytbmcp-install-complete,
.dark .ytbmcp-install-complete { background: #1b2530 !important; }
html[data-bs-theme="dark"] .ytbmcp-install-complete h2,
[data-bs-theme="dark"] .ytbmcp-install-complete h2 { color: #f1f5f9 !important; }
html[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-subtitle,
[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-subtitle { color: #94a3b8 !important; }
html[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-card,
[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-card { background: #243140 !important; }
html[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-card-label,
[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-card-label { color: #f1f5f9 !important; }
html[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-card-detail,
[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-card-detail,
html[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-card-icon,
[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-card-icon { color: #94a3b8 !important; }
html[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-tile,
[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-tile { background: #243140 !important; border-color: #334155 !important; }
html[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-tile-title,
[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-tile-title { color: #f1f5f9 !important; }
html[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-tile-detail,
[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-tile-detail { color: #94a3b8 !important; }
html[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-footer,
[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-footer { color: #64748b !important; border-top-color: #334155 !important; }
html[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-footer a,
[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-footer a { color: #94a3b8 !important; }
html[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-unofficial,
[data-bs-theme="dark"] .ytbmcp-install-complete .ytbmcp-unofficial { border-color: #334155 !important; color: #94a3b8 !important; }
</style>
CSS;
    }

    private function renderHeader(string $type): string
    {
        $heading = $type === 'update' ? 'Update Complete' : 'Installation Complete';
        $subtitle = $type === 'update'
            ? 'Now running version ' . htmlspecialchars(self::PLUGIN_VERSION) . '.'
            : 'Version ' . htmlspecialchars(self::PLUGIN_VERSION) . ' is ready to use.';

        $html  = '<div class="ytbmcp-header">';
        $html .= $this->renderLogo(52, 'ytbmcp-logo');
        $html .= '<h2>' . $this->icon('PartyPopper', 26) . ' ' . htmlspecialchars($heading) . '</h2>';
        $html .= '<p class="ytbmcp-subtitle">YT Builder MCP for YOOtheme Pro</p>';
        $html .= '<p class="ytbmcp-subtitle">' . $subtitle . '</p>';
        $html .= '<span class="ytbmcp-unofficial">unofficial</span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string,array{label:string,status:bool,detail:string,icon:string}> $checks
     */
    private function renderChecksGrid(array $checks): string
    {
        $html = '<div class="ytbmcp-grid">';
        foreach ($checks as $check) {
            $color   = $check['status'] ? '#10b981' : '#f59e0b';
            $bg      = $check['status'] ? 'rgba(16,185,129,0.12)' : 'rgba(245,158,11,0.12)';
            $border  = $check['status'] ? 'rgba(16,185,129,0.4)' : 'rgba(245,158,11,0.4)';
            $glyph   = $check['status'] ? 'CheckCircle2' : 'AlertTriangle';

            $html .= '<div class="ytbmcp-card" style="border-left-color:' . $border . ';">';
            $html .= '<div class="ytbmcp-card-head">';
            $html .= '<div class="ytbmcp-card-title">';
            $html .= '<span class="ytbmcp-card-icon">' . $this->icon($check['icon'], 18) . '</span>';
            $html .= '<strong class="ytbmcp-card-label">' . htmlspecialchars($check['label']) . '</strong>';
            $html .= '</div>';
            $html .= '<span class="ytbmcp-badge" style="background:' . $bg . ';">';
            $html .= '<span style="color:' . $color . ';">' . $this->icon($glyph, 16) . '</span>';
            $html .= '</span>';
            $html .= '</div>';
            $html .= '<p class="ytbmcp-card-detail">' . htmlspecialchars($check['detail']) . '</p>';
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    private function renderTiles(): string
    {
        // ① Generate first key — route to the com_ytbmcp Dashboard keys tab.
        $keysUrl = $this->safeRoute('index.php?option=com_ytbmcp&tab=keys');

        $tiles = [
            [
                'num'    => '1',
                'icon'   => 'KeyRound',
                'title'  => 'Generate your first key',
                'detail' => 'Open the YT Builder MCP dashboard and mint a Bearer key for your AI client.',
                'href'   => $keysUrl,
                'target' => '',
                'cmd'    => '',
            ],
            [
                'num'    => '2',
                'icon'   => 'Terminal',
                'title'  => 'Connect your AI client',
                'detail' => 'Run the setup wizard — it configures Claude Desktop, Cursor, Continue, Zed, Cline or Roo Code for you.',
                'href'   => '',
                'target' => '',
                'cmd'    => self::SETUP_CMD,
            ],
            [
                'num'    => '3',
                'icon'   => 'BookOpen',
                'title'  => 'Documentation',
                'detail' => 'Tool surface, ETag-locked write workflow, scope hierarchy and troubleshooting.',
                'href'   => self::DOCS_URL,
                'target' => '_blank',
                'cmd'    => '',
            ],
            [
                'num'    => '4',
                'icon'   => 'Globe',
                'title'  => 'wootsup.com',
                'detail' => 'More YOOtheme tooling from WootsUp, including API Mapper.',
                'href'   => self::HOME_URL,
                'target' => '_blank',
                'cmd'    => '',
            ],
        ];

        $html = '<div class="ytbmcp-tiles">';
        foreach ($tiles as $tile) {
            $tag    = $tile['href'] !== '' ? 'a' : 'div';
            $attrs  = '';
            if ($tile['href'] !== '') {
                $attrs = ' href="' . htmlspecialchars($tile['href']) . '"';
                if ($tile['target'] !== '') {
                    $attrs .= ' target="' . $tile['target'] . '" rel="noopener noreferrer"';
                }
            }

            $html .= '<' . $tag . ' class="ytbmcp-tile"' . $attrs . '>';
            $html .= '<div class="ytbmcp-tile-head">';
            $html .= '<span class="ytbmcp-tile-icon">' . $this->icon($tile['icon'], 18) . '</span>';
            $html .= '<span class="ytbmcp-tile-num">' . htmlspecialchars($tile['num']) . '.</span>';
            $html .= '<span class="ytbmcp-tile-title">' . htmlspecialchars($tile['title']) . '</span>';
            $html .= '</div>';
            $html .= '<p class="ytbmcp-tile-detail">' . htmlspecialchars($tile['detail']) . '</p>';
            if ($tile['cmd'] !== '') {
                $html .= '<code class="ytbmcp-cmd">' . htmlspecialchars($tile['cmd']) . '</code>';
            }
            $html .= '</' . $tag . '>';
        }
        $html .= '</div>';

        return $html;
    }

    private function renderFooter(): string
    {
        $html  = '<div class="ytbmcp-footer">';
        $html .= 'YT Builder MCP is an independent third-party project by '
            . '<a href="' . htmlspecialchars(self::HOME_URL) . '" target="_blank" rel="noopener noreferrer">WootsUp</a>. '
            . 'YOOtheme&reg; is a registered trademark of YOOtheme GmbH; this project is not affiliated with or endorsed by YOOtheme.';
        $html .= '</div>';

        return $html;
    }

    /**
     * Route a Joomla admin URL, degrading to the raw URL when the router is
     * unavailable in the install context.
     */
    private function safeRoute(string $url): string
    {
        try {
            if (class_exists(Route::class)) {
                return (string) Route::_($url);
            }
        } catch (\Throwable $e) {
            // Fall through to the raw URL.
        }
        return $url;
    }

    /**
     * Render the WootsUp logo as an inline SVG. Markup mirrors
     * {@see \WootsUp\BuilderMcp\Platform\Joomla\Settings\JoomlaBrandAssets::renderLogo()};
     * inlined because the platform-joomla PSR-4 autoloader is not guaranteed
     * to be registered during the package installer's postflight.
     */
    private function renderLogo(int $size = 52, string $cssClass = ''): string
    {
        $size      = max(1, min(512, $size));
        $sizeAttr  = (string) $size;
        $classAttr = $cssClass !== '' ? ' class="' . htmlspecialchars($cssClass) . '"' : '';
        $teal      = self::COLOR_TEAL;

        return '<svg width="' . $sizeAttr . '" height="' . $sizeAttr . '"'
            . ' viewBox="0 0 33.866666 33.866666"'
            . $classAttr
            . ' aria-label="WootsUp" role="img"'
            . ' xmlns="http://www.w3.org/2000/svg">'
            . '<rect fill="' . $teal . '" width="33.896137" height="33.959145"'
            . ' x="-0.063003972" y="-0.063003972" rx="4" />'
            . '<path fill="#ffffff" d="m 22.076252,27.633291 c 0,1.712604 -1.382772,3.095375 -3.089034,3.095375'
            . ' -1.712606,0 -3.095377,-1.382771 -3.095377,-3.095375 0,-1.706265 1.382771,-3.089035 3.095377,-3.089035'
            . ' 1.706262,0 3.089034,1.38277 3.089034,3.089035 z" />'
            . '<path fill="#ffffff" d="m 15.860126,25.812852 c 0,1.630146 -1.325683,2.949488 -2.955831,2.949488'
            . ' -1.623804,0 -2.9494878,-1.319342 -2.9494878,-2.949488 0,-1.630146 1.3256838,-2.949487 2.9494878,-2.949487'
            . ' 1.630148,0 2.955831,1.319341 2.955831,2.949487 z" />'
            . '<path fill="#ffffff" d="m 19.761125,4.5004133 c -0.685041,0 -1.45267,0.031755 -1.712732,0.069815'
            . ' -1.560374,0.2346901 -3.031921,0.8373131 -3.938968,1.6111546 -0.773845,0.6596679 -1.344739,1.325591 -1.649202,1.9218318'
            . ' -0.253719,0.5074365 -0.272621,0.5773159 -0.272621,1.0657252 0,0.4947507 0.01256,0.5453789 0.196531,0.773724'
            . ' 0.285434,0.3552068 0.475723,0.4631228 1.579378,0.9007888 0.862645,0.348861 1.072027,0.405909 1.516037,0.43128'
            . ' 0.799215,0.05075 1.0719,-0.120578 1.566654,-0.99591 0.367892,-0.6533252 0.526492,-0.8499449 0.869014,-1.0782918'
            . ' 0.513781,-0.3361755 1.052973,-0.4629962 1.871218,-0.4312815 0.602583,0.025374 0.767425,0.05081 1.084574,0.1967038'
            . ' 1.090994,0.5010928 1.725431,1.5793965 1.674688,2.8226185 -0.01903,0.513779 -0.04439,0.608783 -0.298108,1.128907'
            . ' -0.241034,0.488407 -0.380642,0.659723 -1.014942,1.294021 -0.40595,0.405948 -1.211445,1.122689 -1.788658,1.598413'
            . ' -1.471571,1.198822 -2.118646,1.979047 -2.404079,2.892433 -0.145889,0.456696 -0.38049,2.124928 -0.38049,2.670423'
            . ' 0,0.437664 0.139418,0.938839 0.329708,1.179873 0.241034,0.310807 0.570957,0.405855 1.382859,0.418541'
            . ' 0.399608,0.0063 1.027526,0.03176 1.395419,0.05079 1.097337,0.06977 1.414434,0.0063 1.731584,-0.374209'
            . ' 0.209318,-0.241033 0.29812,-0.558291 0.29812,-1.046701 0,-0.545495 0.158537,-1.192351 0.374199,-1.57293'
            . ' 0.241033,-0.418638 0.793,-0.951583 1.75079,-1.693713 1.649176,-1.274937 2.44201,-2.042467 3.031883,-2.930483'
            . ' 0.666013,-0.995845 1.046568,-2.112135 1.154398,-3.387073 C 28.190838,11.05908 27.861065,9.505003 27.340941,8.4013266'
            . ' 26.776416,7.2088463 25.55227,6.0799067 24.048983,5.3631518 22.64084,4.6971385 21.664021,4.5004133 19.761125,4.5004133 Z" />'
            . '<path fill="#ffffff" d="M 10.189498,21.696256 C 9.7835468,21.62014 9.4790836,21.410821 9.3141658,21.08733'
            . ' 9.2761081,21.017556 9.0731324,20.376914 8.8701569,19.660157 8.6608384,18.949744 8.3310033,17.884122 8.1407136,17.287882'
            . ' 7.9504243,16.697984 7.6079032,15.581618 7.3795558,14.814118 6.8848029,13.145913 5.9650701,10.234484 5.4386025,8.6931391'
            . ' 4.9945937,7.3928271 4.9248208,6.9678471 5.0833953,6.4413792 c 0.3171491,-1.0909934 2.2454165,-1.99804 4.2434567,-1.99804'
            . ' 0.6406415,0 0.95779,0.088802 1.243225,0.329835 0.202975,0.1649179 0.431323,0.532811 0.564525,0.8943611'
            . ' 0.164918,0.4440088 1.687235,8.0302177 2.194673,10.9226177 0.09514,0.526468 0.253721,1.338371 0.348864,1.80775'
            . ' 0.272749,1.281283 0.29812,1.630147 0.139547,1.947297 -0.228348,0.444008 -0.539156,0.653327 -1.376429,0.932419'
            . ' -1.052936,0.348864 -1.845808,0.501095 -2.251759,0.418637 z" />'
            . '</svg>';
    }

    /**
     * Return an inline Lucide icon SVG by name. Unknown names render an empty
     * (but valid) SVG so a typo never breaks the panel.
     */
    private function icon(string $name, int $size = 20): string
    {
        $paths = [
            'PartyPopper'   => '<path d="M5.8 11.3 2 22l10.7-3.79"/><path d="M4 3h.01"/><path d="M22 8h.01"/><path d="M15 2h.01"/><path d="M22 20h.01"/><path d="m22 2-2.24.75a2.9 2.9 0 0 0-1.96 3.12c.1.86-.57 1.63-1.45 1.63h-.38c-.86 0-1.6.6-1.76 1.44L14 10"/><path d="m22 13-.82-.33c-.86-.34-1.82.2-1.98 1.11c-.11.7-.72 1.22-1.43 1.22H17"/><path d="m11 2 .33.82c.34.86-.2 1.82-1.11 1.98C9.52 4.9 9 5.52 9 6.23V7"/><path d="M11 13c1.93 1.93 2.83 4.17 2 5-.83.83-3.07-.07-5-2-1.93-1.93-2.83-4.17-2-5 .83-.83 3.07.07 5 2Z"/>',
            'Palette'       => '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
            'Code'          => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
            'Layers'        => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
            'CheckCircle2'  => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
            'AlertTriangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'KeyRound'      => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
            'Terminal'      => '<polyline points="4 17 10 11 4 5"/><line x1="12" x2="20" y1="19" y2="19"/>',
            'BookOpen'      => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
            'Globe'         => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        ];

        $path = $paths[$name] ?? '';
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 24 24" fill="none" '
            . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" '
            . 'style="display:inline-block;vertical-align:middle;">%s</svg>',
            $size,
            $size,
            $path
        );
    }

    /**
     * @param array<string,array{label:string,status:bool,detail:string,icon:string}> $checks
     */
    private function enqueueCriticalIssues(array $checks): void
    {
        $issues = [];
        foreach (['php', 'joomla'] as $key) {
            if (isset($checks[$key]) && !$checks[$key]['status']) {
                $issues[] = $checks[$key]['detail'];
            }
        }
        if (isset($checks['yootheme']) && !$checks['yootheme']['status']) {
            $issues[] = $checks['yootheme']['detail'];
        }

        if ($issues !== []) {
            $this->safeEnqueue(
                '<strong>YT Builder MCP — please review:</strong><br>' . implode('<br>', $issues),
                'warning'
            );
        }
    }

    /** Enqueue a Joomla message, no-op if the application is unavailable. */
    private function safeEnqueue(string $message, string $type = 'message'): void
    {
        try {
            $app = Factory::getApplication();
            if ($app !== null && method_exists($app, 'enqueueMessage')) {
                $app->enqueueMessage($message, $type);
            }
        } catch (\Throwable $e) {
            // No application context (CLI/discover) — silently skip.
        }
    }
}
