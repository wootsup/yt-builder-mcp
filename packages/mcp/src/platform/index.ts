/**
 * Platform abstraction — Wave G.0.
 *
 * A `Platform` is the seam between the MCP server and a host CMS. Today
 * the only concrete impl is `WordPressPlatform`; `joomla` is reserved
 * as a typed seam-marker so Wave G.4+ can land Joomla without rippling
 * through every call site.
 *
 * Why an interface instead of a tagged union?
 *   - Each platform owns its REST-namespace path verbatim (WP uses
 *     `/wp-json/<ns>`, Joomla will use `/index.php?option=com_ajax&…`).
 *   - Adding a platform = implement the interface + add a `PlatformKind`
 *     member. No switch statements to update.
 *
 * The `RestClient` accepts EITHER a `Platform` OR the legacy
 * `{baseUrl}` form (which internally builds a `WordPressPlatform`),
 * so existing callers keep working unchanged.
 *
 * @license MIT
 */

/** Tagged union of supported host platforms. */
export type PlatformKind = 'wordpress' | 'joomla';

/**
 * A `Platform` describes WHERE and HOW the MCP server reaches the
 * REST surface of the host CMS.
 */
export interface Platform {
    /** Discriminator for runtime checks. */
    readonly kind: PlatformKind;
    /** Origin (scheme + host + optional port). No trailing-slash normalisation here. */
    readonly baseUrl: string;
    /** Path prefix appended to baseUrl to form the REST root (must start with `/`). */
    readonly restNamespacePath: string;
}

/** Canonical WordPress REST-namespace path for this plugin. */
export const WORDPRESS_REST_NAMESPACE_PATH = '/wp-json/yt-builder-mcp/v1';

/**
 * WordPress platform implementation.
 *
 * `restNamespacePath` is fixed to the plugin's namespace; the caller
 * supplies only the site origin.
 */
export class WordPressPlatform implements Platform {
    public readonly kind = 'wordpress' as const;
    public readonly baseUrl: string;
    public readonly restNamespacePath: string;

    constructor(baseUrl: string) {
        this.baseUrl = baseUrl;
        this.restNamespacePath = WORDPRESS_REST_NAMESPACE_PATH;
    }
}
