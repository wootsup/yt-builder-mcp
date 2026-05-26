/**
 * Joomla 5/6 platform implementation — Wave 7 prep.
 *
 * Mirrors {@link WordPressPlatform} but targets the Joomla Web Services
 * API surface registered by `plg_system_ytbmcp` (see
 * `src/packaging/joomla/extensions/plg_system_ytbmcp/src/Extension/Ytbmcp.php`
 * — `onBeforeApiRoute()` mounts the routes under
 * `v1/yt-builder-mcp/...` against `com_ytbmcp`).
 *
 * The full request URL composed by {@link RestClient} is:
 *
 *     {baseUrl}/api/index.php/v1/yt-builder-mcp/{path}
 *
 * — where `{baseUrl}` is the Joomla site origin (e.g. `https://example.com`
 * or `https://example.com/joomla` for a subfolder install) and `{path}`
 * is the per-tool REST path (e.g. `/health`, `/pages`, `/etag`).
 *
 * Cookbook Ch1 Finding 2: the `PlatformKind` union already declared
 * `'joomla'` in Wave G.0; this class fills the seam so no tool handler
 * has to change.
 *
 * Cookbook §3.7 (cross-platform parity): the REST byte-shape returned
 * by every endpoint is identical across platforms — only the route
 * prefix differs.
 *
 * @license MIT
 */

import type { Platform } from './index.js';

/** Canonical Joomla REST namespace prefix for this plugin. */
export const JOOMLA_REST_NAMESPACE_PATH = '/api/index.php/v1/yt-builder-mcp';

/**
 * Joomla platform implementation.
 *
 * `restNamespacePath` is fixed to the Web Services API mount point that
 * `plg_system_ytbmcp::onBeforeApiRoute()` registers; the caller supplies
 * only the site origin.
 */
export class JoomlaPlatform implements Platform {
    public readonly kind = 'joomla' as const;
    public readonly baseUrl: string;
    public readonly restNamespacePath: string;

    constructor(baseUrl: string) {
        this.baseUrl = baseUrl;
        this.restNamespacePath = JOOMLA_REST_NAMESPACE_PATH;
    }
}
