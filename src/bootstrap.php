<?php
/**
 * yt-builder-mcp module loader.
 *
 * Pattern inspired by uEssentials v3 / api-mapper (independent reimplementation,
 * no code copied from third-party GPL sources).
 *
 * Each module under modules/<name>/bootstrap.php returns an array with optional keys:
 *  - 'config'   => array — config-file paths for YOOtheme service classes
 *  - 'routes'   => array — REST/HTTP routes [method, path, controller@method]
 *  - 'events'   => array — YOOtheme event listeners
 *  - 'extend'   => array — service-container decorators
 *  - 'services' => array — DI-container service definitions
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp
 */

// Cross-platform entry guard: WordPress defines ABSPATH; Joomla defines
// _JEXEC. Either MUST be present — refuse direct web access.
if (!\defined('ABSPATH') && !\defined('_JEXEC')) {
    exit;
}

if (!function_exists('YOOtheme\\app')) {
    return;
}

// Composer autoloader — required for PSR-4 namespace resolution
// (WootsUp\BuilderMcp\*). Without this the YOOtheme module loader cannot
// instantiate our controllers/services. Guarded with file_exists so a
// vendor-less checkout (e.g. fresh git clone before composer install)
// produces an actionable PHP error rather than a silent fatal.
$ytbMcpAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($ytbMcpAutoload)) {
    require_once $ytbMcpAutoload;
}

// The plugin entry-point requires this file from inside an
// `after_setup_theme` (priority 5) callback — by that point YOOtheme has
// registered its DI container, so we can call `app()->load(...)` directly.
// No extra hook wrapping needed.
\YOOtheme\Path::setAlias('~ytb-mcp', __DIR__);

// Brace-glob module loader. Each module's bootstrap.php self-guards on
// the runtime platform (WP `ABSPATH` vs Joomla `_JEXEC`) so loading both
// platform-* modules is safe in either host — only the matching one
// activates side-effects.
\YOOtheme\app()->load(__DIR__ . '/modules/{core-util,core-storage,core-yootheme,core-auth,builder-state,builder-pages,builder-elements,builder-inspection,builder-source-binding,builder-cache,rest-bridge,platform-wordpress,platform-joomla}/bootstrap.php');
