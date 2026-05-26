/**
 * W4 — ClientPool: caches one {@link RestClient} per `site_id`.
 *
 * The pool sits between the W3 {@link SiteRegistry} (which maps a
 * `site_id` → entry + memoised {@link Platform}) and the W2
 * {@link SecretResolver} (which produces a bearer token at call time)
 * and emits {@link PoolResolution} bundles ready for tool handlers.
 *
 * Design choices (per plan §W4, Z.562-644):
 *
 *  - **One client per site_id.** The first `resolve(id)` runs bearer +
 *    platform resolve in parallel via `Promise.all` (saves one
 *    round-trip-time per cold site) and stores the constructed
 *    `RestClient`. All subsequent calls reuse the same instance —
 *    identity-equal across the process lifetime.
 *
 *  - **Cold parallel calls are racey-but-safe.** When N callers arrive
 *    for the same uncached site_id simultaneously, the plan §W4
 *    explicitly accepts N first-time `secretResolver.resolve()` calls
 *    (Z.630): `op read` is idempotent + latency dominates so a
 *    hard-once Promise-cache would not materially help. The W3
 *    registry's Promise-cache handles the platform side. The last
 *    setter wins the `cache.set(id, client)` race; subsequent
 *    `resolve()` calls see a stable single instance. Identity is
 *    eventual, not instantaneous — but stable from call N+1 onwards.
 *
 *  - **`invalidate(siteId)` for post-401 force-re-resolve.** When the
 *    auth layer detects a `401 invalid_kid`, it can drop the cached
 *    client so the next call re-fetches the bearer through `op` and
 *    constructs a fresh `RestClient`.
 *
 *  - **Structured errors.** `NoDefaultSiteError` and `UnknownSiteError`
 *    carry `code` + (for unknown) `siteId` + `available[]` so the
 *    auth/tool layer can branch on `error.code` instead of substring
 *    matching the message.
 *
 * @license MIT
 */

import { RestClient } from '../client.js';

import type { SiteRegistry, ResolvedSite } from './registry.js';
import type { SecretResolver } from './secret-resolver.js';

/**
 * Thrown when `resolve()` is called without a `siteId` AND the
 * registry has no default configured (neither `default_site_id` nor a
 * flagged `is_default:true` entry). The auth layer surfaces this as a
 * `no_default_site` MCP error with a hint to run
 * `npx -y @wootsup/yt-builder-mcp add-site --default`.
 */
export class NoDefaultSiteError extends Error {
    public readonly code = 'no_default_site' as const;

    constructor(
        message =
            'No site_id supplied and no default site is configured. '
            + 'Add a site via `npx -y @wootsup/yt-builder-mcp add-site --default --url ... --token ...` '
            + 'or pass site_id explicitly. Use yootheme_builder_sites_list to discover IDs.',
    ) {
        super(message);
        this.name = 'NoDefaultSiteError';
    }
}

/**
 * Thrown when `resolve('some-id')` cannot find `some-id` in the
 * registry. Carries the requested `siteId` and the (insertion-order)
 * list of configured ids so the caller can render a friendly
 * "did-you-mean" hint.
 */
export class UnknownSiteError extends Error {
    public readonly code = 'unknown_site' as const;

    constructor(
        public readonly siteId: string,
        public readonly available: readonly string[],
    ) {
        super(
            `Site "${siteId}" not found. Available: `
            + (available.length > 0 ? available.join(', ') : '(none configured)'),
        );
        this.name = 'UnknownSiteError';
    }
}

/**
 * Bundle returned by {@link ClientPool.resolve}. Carries the cached
 * REST client together with the fully-resolved site descriptor for
 * downstream logging / display.
 */
export interface PoolResolution {
    readonly client: RestClient;
    readonly site: ResolvedSite;
}

/**
 * Process-local cache of {@link RestClient} instances keyed by
 * `site_id`. Constructed once at server boot (W6) and shared across
 * all tool handlers.
 *
 * The pool is NOT thread-safe in the strict sense (Node is
 * single-threaded but `await` can interleave); the cold-parallel-call
 * behaviour is documented at the top of this file.
 */
export class ClientPool {
    private readonly cache = new Map<string, RestClient>();

    constructor(
        private readonly _registry: SiteRegistry,
        private readonly secretResolver: SecretResolver,
        private readonly timeoutMs: number = 15_000,
    ) {}

    /**
     * Read-only accessor for the underlying {@link SiteRegistry}. Used
     * internally by the W7 `buildSitesTools(pool)` aggregator so the
     * `sites_list` tool can render the configured-sites table without
     * requiring callers to thread the registry separately. The registry
     * itself is immutable from the outside (no mutator surface) so
     * exposing it here is safe — `listForDisplay()` / `defaultSiteId()` /
     * `listIds()` are all read-only operations.
     *
     * @internal Internal accessor for buildSitesTools; do not consume
     * from tool handlers. Handlers should call `pool.resolve(siteId)`
     * to get a `{client, site}` bundle instead.
     */
    get registry(): SiteRegistry {
        return this._registry;
    }

    /**
     * Resolve a site_id to its cached {@link RestClient} + descriptor.
     *
     * @param siteId Explicit site_id from the tool param. When
     *   `undefined`, the registry's `defaultSiteId()` is used.
     * @throws {NoDefaultSiteError} when `siteId` is omitted and no
     *   default is configured.
     * @throws {UnknownSiteError} when the resolved id is not in the
     *   registry (either the explicit id is wrong, or the registry's
     *   `default_site_id` points at a stale entry).
     */
    async resolve(siteId?: string): Promise<PoolResolution> {
        const id = siteId ?? this.registry.defaultSiteId();
        if (id === null) {
            throw new NoDefaultSiteError();
        }

        const entry = this.registry.get(id);
        if (entry === null) {
            throw new UnknownSiteError(id, this.registry.listIds());
        }

        let client = this.cache.get(id);
        if (client === undefined) {
            // Plan §W4 Z.604-608: parallelise bearer + platform on a
            // cold call. The W3 registry's Promise-cache de-dupes the
            // platform probe across N parallel callers; the bearer
            // side is allowed N first-time calls (op read idempotent).
            const [bearer, platform] = await Promise.all([
                this.secretResolver.resolve(entry),
                this.registry.platformFor(id),
            ]);
            client = new RestClient({
                platform,
                bearerToken: bearer.token,
                timeoutMs: this.timeoutMs,
            });
            this.cache.set(id, client);
        }

        // `platformFor` is memoised in the registry; calling it a
        // second time here is a O(1) Promise.resolve from the W3
        // Promise-cache, NOT a fresh probe. Keeps the `ResolvedSite`
        // synthesis self-contained even when the client was warm.
        const platform = await this.registry.platformFor(id);
        const resolved: ResolvedSite = {
            id,
            url: entry.url,
            platform,
            isDefault: entry.is_default,
            bearerSource: entry.bearer_ref !== undefined ? 'op' : 'plain',
            ...(entry.label !== undefined ? { label: entry.label } : {}),
        };

        return { client, site: resolved };
    }

    /**
     * Drop the cached client for `siteId` — used by the auth layer
     * after a `401 invalid_kid` so the next call re-runs the bearer
     * resolve and builds a fresh {@link RestClient}.
     *
     * No-op when `siteId` is not in the cache.
     */
    invalidate(siteId: string): void {
        this.cache.delete(siteId);
    }
}
