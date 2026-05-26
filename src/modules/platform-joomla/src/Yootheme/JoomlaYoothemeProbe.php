<?php
/**
 * Joomla-native YOOtheme Pro presence + version detector.
 *
 * W11-T4 (2026-05-24): fixes the false "YOOtheme Pro is required" notice
 * (and Diagnostics "—") on the admin Dashboard.
 *
 * ROOT CAUSE — the admin View used to derive `$ytPresent` from a RUNTIME
 * bootstrap of YOOtheme Pro ({@see YtBootstrapper::ensure()} +
 * {@see \WootsUp\BuilderMcp\Yootheme\YoothemeAdapter::getVersion()}). But
 * YT Pro is the SITE template — it only lazy-bootstraps on the REST/API +
 * frontend surface (ADR-001: com_api's `template_bootstrap.php` allowlist
 * excludes the administrator application). In the admin component context
 * `YtBootstrapper::ensure()` can never load YT, so `getVersion()` returned
 * null EVEN WHEN YT Pro was installed + active → false notice.
 *
 * THE FIX — detect YT Pro through the Joomla framework itself, with no
 * runtime YT load. This mirrors how api-mapper's Joomla side detects YT
 * (see `ApiMapper\Core\Compat\YOOthemeCompat::readFromJoomlaManifest()` and
 * `ApiMapper\Core\Update\CompatibilityChecker::getYoothemeVersion()`, both
 * of which read the Joomla template manifest). We extend that approach with
 * the more authoritative `#__extensions` row:
 *
 *   1. Query `#__extensions` for the site `yootheme` template
 *      (`type='template' AND element='yootheme' AND client_id=0`). The row's
 *      `manifest_cache` JSON carries the installed `version`. Presence of
 *      this row = YT installed; we additionally gate on `enabled=1` so a
 *      disabled template reports absent.
 *   2. Fall back to parsing `JPATH_SITE/templates/yootheme/templateDetails.xml`
 *      `<version>` when the row exists but `manifest_cache` is empty (older
 *      installs), or when the extensions query is unavailable.
 *
 * The free YOOtheme has no page builder; the Pro template element is also
 * `yootheme`, so — exactly like the rest of this codebase — we treat
 * "the `yootheme` template present + version >= MIN_YT_VERSION (4.0)" as the
 * gate (the WP side gates the same way via `YTB_MCP_MIN_YT_VERSION`).
 *
 * SSoT (Audit A1-M1, 2026-05-25): the actual detection SQL + manifest-file
 * parse live in ONE dependency-free file,
 * `platform-joomla/src/Shared/ytbmcp-joomla-detect.php`, so the package
 * installer script (which cannot reach this PSR-4 class at postflight) and
 * this probe never silently diverge. This class is the namespaced façade that
 * delegates to that shared logic.
 *
 * Every method is fail-safe: any DB/filesystem error degrades to "absent"
 * (null version) rather than throwing — the admin page must never fatal on
 * detection.
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Yootheme
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Yootheme;

defined('_JEXEC') or die;

// SSoT (Audit A1-M1): the detection SQL + manifest parse live in this
// dependency-free shared file so the package installer script and this probe
// cannot diverge. Required by computed path — it ships alongside this class
// inside plg_system_ytbmcp.zip at `modules/platform-joomla/src/Shared/`.
require_once __DIR__ . '/../Shared/ytbmcp-joomla-detect.php';

final class JoomlaYoothemeProbe
{
    /** The Joomla extension `element` for the YOOtheme Pro template. */
    public const YT_TEMPLATE_ELEMENT = 'yootheme';

    /**
     * Return the installed YOOtheme Pro version string, or null when YT is
     * not present (no enabled `yootheme` template row + no manifest on disk).
     *
     * Detection order: `#__extensions.manifest_cache` → `templateDetails.xml`.
     * Delegates to the shared SSoT helper.
     */
    public function detectVersion(): ?string
    {
        return ytbmcp_shared_detect_yootheme_version();
    }

    /**
     * True when a usable YOOtheme Pro install is detected.
     *
     * Presence = a version was detected (the `yootheme` template exists +
     * is enabled). Callers that need the Pro-version floor compare
     * detectVersion() against MIN_YT_VERSION themselves; presence alone
     * drives the "required" notice (parity with WP).
     */
    public function isPresent(): bool
    {
        return $this->detectVersion() !== null;
    }
}
