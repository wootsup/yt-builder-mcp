<?php
/**
 * YOOtheme Pro lazy-bootstrap helper.
 *
 * ADR-001 (2026-05-24, Thomas-approved): Web Services API (com_api) does
 * NOT auto-bootstrap YOOtheme Pro — its `template_bootstrap.php`
 * allowlist explicitly excludes com_api. REST controllers in com_ytbmcp
 * therefore lazy-require YT's bootstrap on demand via this helper.
 *
 * The bootstrap is idempotent — `template_bootstrap.php` returns a
 * cached `\YOOtheme\Application` singleton, so multiple `ensure()` calls
 * within a request are safe (and effectively free after the first).
 *
 * Defense layers (cookbook §S2 risk matrix):
 *   - Typed exception {@see YTNotBootstrappedException} on failure
 *   - Pin-test (`tests/php/Joomla/YtBootstrapAvailabilityPinTest`)
 *     asserts the bootstrap file exists + `\YOOtheme\app('builder')`
 *     resolves after include
 *   - Sentinel-test (`tests/php/Joomla/YtTransformInventorySentinelTest`)
 *     snapshots count + class names of registered presave/save
 *     transforms; fails-loud on YT-update-drift
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Util
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Util;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Platform\Joomla\Exception\YTNotBootstrappedException;

final class YtBootstrapper
{
    /** @var bool Cache the result of the first ensure() call. */
    private static bool $bootstrapped = false;

    /**
     * Idempotently bootstrap YT for the current request.
     *
     * @throws YTNotBootstrappedException When YT cannot be located or
     *         the bootstrap file fails to register `\YOOtheme\app`.
     */
    public static function ensure(): void
    {
        if (self::$bootstrapped && \function_exists('\YOOtheme\app')) {
            return;
        }

        // Already loaded by a prior request-component (e.g. com_ajax),
        // a different plugin, or this request previously called ensure().
        if (\function_exists('\YOOtheme\app')) {
            self::$bootstrapped = true;
            return;
        }

        $bootstrap = self::locateBootstrap();
        if ($bootstrap === null) {
            throw new YTNotBootstrappedException(
                'YOOtheme Pro template_bootstrap.php could not be located. ' .
                'Searched: templates/yootheme/, templates/yootheme_<theme>/.'
            );
        }

        // F-001 deep fix (2026-05-26 audit): YOOtheme's template_bootstrap.php
        // has an EARLY-EXIT gate (templates/yootheme/template_bootstrap.php):
        //
        //     if (Factory::getApplication()->isClient('site') ||
        //         in_array(ApplicationHelper::getComponentName(), ['com_ajax', …], true)) {
        //         $app = require __DIR__ . '/bootstrap.php';
        //         $app->load(…modules-glob…);
        //     }
        //
        // The `com_api` client (our REST surface) matches NEITHER branch, so
        // requiring template_bootstrap.php under com_api is a NO-OP — the
        // inner bootstrap.php is never reached and `\YOOtheme\app` is never
        // registered. Before this fix the controller dispatch surfaced a
        // misleading "YT-version-skew" 503 even on a perfectly healthy
        // install (live evidence: dev.wootsup.com health endpoint showed
        // `yootheme_loaded: false` while `yootheme_version` resolved to
        // `4.5.33` via the file-system probe).
        //
        // Bypass: load the INNER bootstrap.php directly (the file
        // template_bootstrap.php conditionally invokes) and call `$app->load`
        // with the same module-glob template_bootstrap.php uses — verbatim,
        // so any YT-version drift in the module list automatically tracks
        // upstream. The template_bootstrap.php gate stays in effect for
        // every other (non-MCP) context — we only opt-in for this
        // controller-dispatch path.
        $bootstrapDir = \dirname($bootstrap);
        $innerBootstrap = $bootstrapDir . '/bootstrap.php';
        $moduleGlob = $bootstrapDir
            . '/{packages/{platform-joomla,'
            . 'theme{,-analytics,-cookie,-highlight,-settings},'
            . 'builder{,-source*,-templates,-newsletter},'
            . 'styler,theme-joomla*,builder-joomla*}'
            . '/bootstrap.php,config.php}';

        try {
            if (!\is_file($innerBootstrap)) {
                throw new YTNotBootstrappedException(
                    'YOOtheme Pro inner bootstrap (' . $innerBootstrap . ') is missing. ' .
                    'Re-install YOOtheme Pro from the original ZIP, then retry.'
                );
            }
            /** @var object{load: callable} $app */
            $app = require $innerBootstrap;
            if (!\is_object($app) || !\method_exists($app, 'load')) {
                throw new YTNotBootstrappedException(
                    'YOOtheme Pro inner bootstrap did not return a valid \YOOtheme\Application. ' .
                    'This indicates a YT-version-skew with yt-builder-mcp.',
                    'Pin to a YOOtheme Pro version that matches the yt-builder-mcp compatibility band.'
                );
            }
            $app->load($moduleGlob);
        } catch (YTNotBootstrappedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new YTNotBootstrappedException(
                'Failed to bootstrap YOOtheme Pro from ' . $innerBootstrap . ': ' . $e->getMessage(),
                'YOOtheme Pro may be partially installed or the theme files are corrupted. ' .
                'Re-install YOOtheme Pro from the original ZIP, then retry.'
            );
        }

        if (!\function_exists('\YOOtheme\app')) {
            throw new YTNotBootstrappedException(
                'YOOtheme Pro bootstrap completed but \\YOOtheme\\app was not registered. ' .
                'This indicates a YT-version-skew with yt-builder-mcp; see Sentinel-Test for details.',
                'Pin to a YOOtheme Pro version that matches the yt-builder-mcp compatibility band.'
            );
        }

        self::$bootstrapped = true;
    }

    /**
     * @internal Test-only reset hook — clears the bootstrap-cached flag
     *           so the next ensure() exercises the full code-path again.
     */
    public static function resetForTests(): void
    {
        self::$bootstrapped = false;
    }

    /**
     * Search for the YT template_bootstrap.php. Joomla allows site
     * operators to clone the YT template (e.g. `yootheme_wootsup`,
     * `yootheme_getimo`) — the BOOTSTRAP file lives in the parent
     * `yootheme` template only; clones just override view templates.
     *
     * Search-order:
     *   1. Active site template (if it's a yootheme clone)
     *   2. Default `yootheme` template
     */
    private static function locateBootstrap(): ?string
    {
        $jpathRoot = \defined('JPATH_ROOT') ? JPATH_ROOT : \dirname(__DIR__, 8);
        $candidates = [
            $jpathRoot . '/templates/yootheme/template_bootstrap.php',
        ];

        // Allow site-template clones (yootheme_<name>) to ship their
        // own bootstrap shim; rare but technically supported.
        $tpls = \glob($jpathRoot . '/templates/yootheme_*/template_bootstrap.php') ?: [];
        foreach ($tpls as $clone) {
            $candidates[] = $clone;
        }

        foreach ($candidates as $candidate) {
            if (\is_file($candidate) && \is_readable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
