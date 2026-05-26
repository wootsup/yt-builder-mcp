<?php
/**
 * platform-joomla module bootstrap.
 *
 * Registers Joomla 5/6 integration for yt-builder-mcp:
 *  - Web Services API route registration (onBeforeApiRoute event handler)
 *  - System-plugin lifecycle hooks (onAfterRoute for late bootstrap)
 *  - Settings UI mount-point (com_ytbmcp admin component)
 *
 * Twin module of {@see platform-wordpress}. Every load-bearing pure-PHP
 * class in core-*, builder-*, rest-bridge ports byte-for-byte; this
 * module supplies the Joomla-specific Storage / Lock / Cache / REST
 * adapters that satisfy the same interfaces.
 *
 * Loaded via `\YOOtheme\app()->load(brace-glob)` from src/bootstrap.php
 * — see src/bootstrap.php:41 for the canonical loader call. YOOtheme's
 * own brace-glob loader is cross-platform; only platform-detection
 * guards differ.
 *
 * Cookbook reference: §1.3 (brace-glob module loader contract) +
 * §1.3.4 (platform-adapter no-op-return convention).
 *
 * @package WootsUp\BuilderMcp\Platform\Joomla
 */

declare(strict_types=1);

// Joomla-port guard: this bootstrap is Joomla-only — no-op when the host
// is WordPress (or any non-Joomla environment). Detection via the
// `_JEXEC` constant is canonical Joomla — defined by every Joomla entry
// point (index.php, administrator/index.php, api/index.php, CLI).
if (!\defined('_JEXEC')) {
    return [];
}

// Actual registration is driven by the plg_system_ytbmcp system plugin
// (see src/packaging/joomla/extensions/plg_system_ytbmcp/ytbmcp.php).
// The plugin subscribes to onBeforeApiRoute + onAfterRoute and wires
// the same WootsUp\BuilderMcp\Platform\Joomla services that this
// brace-glob load makes available via the composer autoloader.
//
// Returning empty here matches the platform-agnostic core/builder-*
// modules: they expose their services through Container::get() lookups
// rather than YT-DI registration (Container.php documents the rationale).
return [];
