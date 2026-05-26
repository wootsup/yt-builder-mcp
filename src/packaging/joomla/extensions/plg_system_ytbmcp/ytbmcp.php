<?php
/**
 * plg_system_ytbmcp — bootstrap entry.
 *
 * Joomla System Plugin for YT Builder MCP for YOOtheme Pro (unofficial).
 *
 * Responsibilities:
 *  - Subscribe to `onAfterRoute` → late-bootstrap the yt-builder-mcp
 *    shared PHP modules AFTER YOOtheme Pro has registered its DI
 *    container (cookbook §1.3 — equivalent of WP `after_setup_theme`
 *    priority 20).
 *  - Provide an admin sub-menu shortcut to the com_ytbmcp settings
 *    component.
 *
 * Route registration is NOT done here. The 31 Web Services API routes
 * (25 core L1 + 6 L2 article, Joomla-only) are registered by the
 * companion `plg_webservices_ytbmcp` plugin's `onBeforeApiRoute`
 * handler — Joomla's ApiApplication imports only webservices-group
 * plugins before dispatching that event, so a system-plugin listener
 * for it is never called (Wave-7 deploy-fix; see ADR-001).
 * The system plugin does NOT instantiate REST controllers itself —
 * controllers live in com_ytbmcp/api/ and are dispatched to by the
 * Web Services API router using the route's `component => com_ytbmcp`
 * default.
 *
 * ADR-001 (2026-05-24, Thomas-approved): com_api does NOT auto-bootstrap
 * YOOtheme Pro (its template_bootstrap.php allowlist excludes com_api).
 * Each REST controller therefore lazy-requires YT's bootstrap in its
 * constructor via {@see WootsUp\BuilderMcp\Platform\Joomla\Util\YtBootstrapper}.
 *
 * @package    WootsUp\Plugin\System\Ytbmcp
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

defined('_JEXEC') or die;

// Plugin entry-file MUST live at extension root with plugin slug as name
// (`ytbmcp.php` matches manifest `<filename plugin="ytbmcp">`). The
// CMSPlugin class lives in src/ under the namespace declared in the
// manifest. The service-provider (services/provider.php) wires it up.
//
// This file is intentionally minimal — Joomla's service-provider pattern
// resolves the actual subscriber class from src/Extension/Ytbmcp.php.
