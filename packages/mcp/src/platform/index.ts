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

// Local import keeps the symbol in scope for `platformForUrl` below;
// the re-export immediately after surfaces it to outside callers so
// the platform barrel remains the single import point (cookbook
// Ch1 Finding 2).
import { JoomlaPlatform } from './joomla.js';
export { JoomlaPlatform, JOOMLA_REST_NAMESPACE_PATH } from './joomla.js';

/**
 * Detect which {@link Platform} a base URL targets based on the URL
 * shape alone (no network call).
 *
 *   - pathname starts with `/wp-json/` → `'wordpress'`
 *   - pathname starts with `/api/index.php/` → `'joomla'`
 *   - otherwise → `null` (ambiguous — caller may probe `/identity`)
 *
 * Round-6 A1 polish: detection switched from `String.includes` to a
 * proper `URL`-parse + `pathname.startsWith` so query-string payloads
 * cannot cause mis-detection (e.g. a Joomla site URL like
 * `https://example.com/?redirect=/wp-json/foo` no longer pretends to
 * be WordPress). Malformed URLs that the `URL` constructor rejects
 * gracefully fall back to the legacy substring scan so callers that
 * accidentally pass a host-only string (e.g. `example.com`) keep
 * working — they would have already failed the actual network call
 * with a clearer error before this function was reached in practice.
 *
 * Cookbook §3.7.1 — the runtime tie-breaker (when this returns `null`)
 * is the `/identity` endpoint, which returns
 * `{ product: 'yt-builder-mcp', platform: 'wordpress'|'joomla', ... }`
 * on both platforms.
 */
export function detectPlatformFromUrl(url: string): PlatformKind | null {
    let pathname: string;
    try {
        pathname = new URL(url).pathname;
    } catch {
        // Fallback for inputs the URL constructor rejects (host-only
        // strings, relative paths). Preserves the legacy substring
        // behaviour as a defensive net.
        if (url.includes('/wp-json/')) return 'wordpress';
        if (url.includes('/api/index.php/')) return 'joomla';
        return null;
    }
    if (pathname.startsWith('/wp-json/')) return 'wordpress';
    if (pathname.startsWith('/api/index.php/')) return 'joomla';
    return null;
}

/**
 * Construct the right {@link Platform} for a base URL.
 *
 * When the URL shape gives an unambiguous hint via
 * {@link detectPlatformFromUrl}, that wins. Otherwise the caller can
 * pass an explicit `hint` (e.g. from a config flag) or fall back to
 * the default (`'wordpress'` for back-compat — Joomla support landed
 * in Wave 7, so existing WordPress installs never need to flag).
 *
 * Synchronous variant — kept for back-compat with callers that cannot
 * (or do not need to) probe over the network. Prefer
 * {@link platformForUrlAsync} when running in the server boot path:
 * it adds a runtime probe that disambiguates origin-only / subfolder
 * URLs (e.g. `https://example.com/joomla`) where the URL shape alone
 * is silent.
 */
export function platformForUrl(
    baseUrl: string,
    hint?: PlatformKind,
): Platform {
    const detected = detectPlatformFromUrl(baseUrl);
    const kind: PlatformKind = detected ?? hint ?? 'wordpress';
    return kind === 'joomla'
        ? new JoomlaPlatform(baseUrl)
        : new WordPressPlatform(baseUrl);
}

/**
 * Async variant of {@link platformForUrl} that adds a runtime probe
 * step for URLs the URL-shape heuristic cannot disambiguate.
 *
 * Resolution order (first match wins):
 *   1. Explicit `hint` from `YTB_MCP_PLATFORM` env var → no probe.
 *   2. URL-shape via {@link detectPlatformFromUrl} (clear `/wp-json/`
 *      or `/api/index.php/` marker) → no probe.
 *   3. Runtime probe via `detectPlatformAtRuntime` (the F-Platform-
 *      Detect-Probe fix — handles subfolder installs like
 *      `https://dev.example.com/joomla` where steps 1+2 return null).
 *   4. Final fallback: `'wordpress'` (back-compat).
 *
 * The probe step is what unblocks origin-only Joomla customers without
 * forcing them to manually set `YTB_MCP_PLATFORM=joomla` — the DXT
 * default of `auto` finally lives up to its name.
 *
 * If both probes fail (server unreachable, plugin not installed, etc.)
 * we fall back to the wordpress default and emit a single stderr note
 * so the customer at least sees *why* the next authenticated call may
 * 404; the real diagnosis still belongs to the first authenticated
 * tool invocation.
 *
 * @param opts.probe Optional probe override (tests / DI).
 * @param opts.logger Optional stderr writer (defaults to `process.stderr.write`).
 */
export async function platformForUrlAsync(
    baseUrl: string,
    hint?: PlatformKind,
    opts: {
        readonly probe?: (
            url: string,
        ) => Promise<PlatformKind | null>;
        readonly logger?: (msg: string) => void;
    } = {},
): Promise<Platform> {
    // 1. Explicit hint wins — probe-less, by design (user override).
    if (hint !== undefined) {
        return hint === 'joomla'
            ? new JoomlaPlatform(baseUrl)
            : new WordPressPlatform(baseUrl);
    }
    // 2. Static URL-shape heuristic.
    const detected = detectPlatformFromUrl(baseUrl);
    if (detected !== null) {
        return detected === 'joomla'
            ? new JoomlaPlatform(baseUrl)
            : new WordPressPlatform(baseUrl);
    }
    // 3. Runtime probe. Lazy-imported so the sync `platformForUrl`
    //    barrel above stays free of any fetch dependency for callers
    //    that do not opt-in.
    const probe =
        opts.probe
        ?? (async (url) => {
            const mod = await import('./detect.js');
            return mod.detectPlatformAtRuntime(url);
        });
    const probed = await probe(baseUrl);
    if (probed !== null) {
        return probed === 'joomla'
            ? new JoomlaPlatform(baseUrl)
            : new WordPressPlatform(baseUrl);
    }
    // 4. Final fallback — emit a single soft note so the user knows
    //    we tried and that explicit YTB_MCP_PLATFORM may rescue.
    const logger =
        opts.logger
        ?? ((msg: string) => {
            process.stderr.write(msg);
        });
    logger(
        '[yt-builder-mcp] notice: platform auto-detection probe failed for '
            + `${baseUrl} — falling back to wordpress. Set YTB_MCP_PLATFORM=joomla `
            + 'if this is a Joomla install.\n',
    );
    return new WordPressPlatform(baseUrl);
}
