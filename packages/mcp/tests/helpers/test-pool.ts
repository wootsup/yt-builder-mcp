/**
 * W6 test helper — synthesise a `ClientPool` for unit tests.
 *
 * Pre-W6, every test constructed a bare `RestClient` and passed it to
 * `createServer({ client })`. W6 changed the server contract to
 * `createServer({ pool })`. To keep migrations mechanical we expose
 * two factories here:
 *
 *  - {@link makeTestPool} — single-site convenience for the vast
 *    majority of existing tests. Drop-in replacement: same `baseUrl` +
 *    `bearer` knobs you would have passed to `new RestClient(...)`.
 *  - {@link makeMultiTestPool} — multi-site variant for the W6/W7 site
 *    awareness tests that need to drive several entries at once.
 *
 * Both factories build an in-memory `SitesFileT` (NEVER touching disk)
 * and wire it through a real `SiteRegistry` + `PlainSecretResolver` +
 * `ClientPool`. The platform layer is short-circuited with a stub
 * `platformForUrlAsync` so tests don't probe the network.
 *
 * @license MIT
 */

import { RestClient } from '../../src/client.js';
import {
    type Platform,
    type PlatformKind,
    WordPressPlatform,
} from '../../src/platform/index.js';
import { ClientPool, type PoolResolution } from '../../src/sites/client-pool.js';
import { SiteRegistry, type ResolvedSite } from '../../src/sites/registry.js';
import type { SiteEntryT, SitesFileT } from '../../src/sites/schema.js';
import {
    PlainSecretResolver,
    type ResolvedBearer,
    type SecretResolver,
} from '../../src/sites/secret-resolver.js';

/**
 * Minimal Joomla `Platform` stub for tests — we only need the three
 * fields the `RestClient` reads (`kind` / `baseUrl` /
 * `restNamespacePath`). Production Joomla lives in a separate file
 * but we don't want to import it here just for the kind discriminator.
 */
class JoomlaPlatformTestStub implements Platform {
    public readonly kind: PlatformKind = 'joomla';
    public readonly restNamespacePath = '/api/index.php/v1/yt-builder-mcp';
    constructor(public readonly baseUrl: string) {}
}

function makePlatform(url: string, kind: 'wordpress' | 'joomla'): Platform {
    return kind === 'joomla'
        ? new JoomlaPlatformTestStub(url)
        : new WordPressPlatform(url);
}

/**
 * Test secret-resolver that returns the bearer verbatim from the entry,
 * never shells out to `op`. Mirrors {@link PlainSecretResolver} but
 * accepts entries that lack a `bearer` field by falling through to a
 * stub token (the tests never assert on the token value itself).
 */
class TestPlainResolver implements SecretResolver {
    async resolve(site: SiteEntryT): Promise<ResolvedBearer> {
        const token = site.bearer ?? '__test_stub_token__';
        return { token, source: 'plain' };
    }
}

export interface TestSiteSpec {
    readonly site_id: string;
    readonly url: string;
    readonly bearer: string;
    readonly platform?: 'wordpress' | 'joomla' | 'auto';
    readonly is_default?: boolean;
    readonly label?: string;
}

export interface MakeTestPoolOptions {
    readonly baseUrl: string;
    readonly bearer: string;
    readonly platform?: 'wordpress' | 'joomla' | 'auto';
    readonly siteId?: string;
    readonly label?: string;
    /**
     * Override the secret resolver — tests that need to drive
     * `bearer_ref` paths inject an `OpSecretResolver` mock here.
     */
    readonly secretResolver?: SecretResolver;
    /**
     * Test-only `fetch` override threaded into the constructed
     * `RestClient`. Pre-W6, tests built `new RestClient({fetch: ...})`
     * directly; the pool wiring needs this seam so existing mock-fetch
     * tests can drive the handlers without rewriting their probes.
     */
    readonly fetch?: typeof fetch;
}

/**
 * Single-site pool factory. Returns a fully wired `ClientPool` that
 * `pool.resolve(undefined)` and `pool.resolve(siteId)` both resolve to
 * the same entry.
 *
 * The platform probe is short-circuited to a deterministic stub: when
 * `platform` is `'auto'` we default to WordPress (the existing test
 * surface is WordPress-shaped).
 */
export function makeTestPool(opts: MakeTestPoolOptions): ClientPool {
    const siteId = opts.siteId ?? 'default';
    const kind: 'wordpress' | 'joomla' = opts.platform === 'joomla' ? 'joomla' : 'wordpress';
    return makeMultiTestPool({
        sites: [
            {
                site_id: siteId,
                url: opts.baseUrl,
                bearer: opts.bearer,
                platform: opts.platform ?? 'auto',
                is_default: true,
                ...(opts.label !== undefined ? { label: opts.label } : {}),
            },
        ],
        defaultSiteId: siteId,
        platformOverride: kind,
        ...(opts.secretResolver !== undefined ? { secretResolver: opts.secretResolver } : {}),
        ...(opts.fetch !== undefined ? { fetch: opts.fetch } : {}),
    });
}

export interface MakeMultiTestPoolOptions {
    readonly sites: readonly TestSiteSpec[];
    readonly defaultSiteId?: string;
    readonly secretResolver?: SecretResolver;
    /**
     * When set, every `auto` platform-hint in the input collapses to
     * this kind. Useful for makeTestPool's single-site shortcut; the
     * multi-site tests usually leave it unset and rely on per-entry
     * `platform` hints.
     */
    readonly platformOverride?: 'wordpress' | 'joomla';
    /**
     * Test-only `fetch` override threaded into every constructed
     * `RestClient`. The same instance is reused across all sites —
     * tests that want per-site fetch behaviour can switch on
     * `init.url`. See {@link TestClientPool} for the override seam.
     */
    readonly fetch?: typeof fetch;
}

/**
 * Test variant of {@link ClientPool} that lets tests inject a custom
 * `fetch` into every constructed {@link RestClient}. Production
 * `ClientPool` never accepts a fetch override (the real
 * `RestClient` uses `globalThis.fetch`); this subclass is the SOLE
 * test seam for mocking the HTTP layer through the pool surface.
 */
class TestClientPool extends ClientPool {
    private readonly testFetch?: typeof fetch;
    private readonly testCache = new Map<string, RestClient>();

    constructor(
        registry: SiteRegistry,
        resolver: SecretResolver,
        fetchImpl?: typeof fetch,
    ) {
        super(registry, resolver);
        if (fetchImpl !== undefined) this.testFetch = fetchImpl;
    }

    override async resolve(siteId?: string): Promise<PoolResolution> {
        // When no fetch override is configured, fall through to the
        // base behaviour — the production code path covers itself.
        if (this.testFetch === undefined) return super.resolve(siteId);

        // Mirror the parent's resolution chain but slot the test
        // fetch into the RestClient constructor. Re-entering the
        // parent's resolve() to satisfy the structural contract is
        // intentional: we rely on it for the error mapping
        // (NoDefaultSiteError / UnknownSiteError) so the test surface
        // stays parity with production failure modes.
        const baseResolution = await super.resolve(siteId);
        const cacheKey = baseResolution.site.id;
        const cached = this.testCache.get(cacheKey);
        if (cached !== undefined) {
            return { client: cached, site: baseResolution.site };
        }
        const testClient = new RestClient({
            platform: baseResolution.site.platform,
            bearerToken: this.extractTokenFromBase(baseResolution),
            fetch: this.testFetch,
        });
        this.testCache.set(cacheKey, testClient);
        return { client: testClient, site: baseResolution.site };
    }

    /**
     * The base `ClientPool.resolve` already produced a `RestClient`
     * with the resolved bearer — we don't re-run the secret resolver
     * here. The bearer lives inside the base RestClient as a private
     * field; for the test seam we use a stub since fetch is mocked
     * anyway. A real fetch sees `Authorization: Bearer <stub>` which
     * the mock never reads.
     */
    private extractTokenFromBase(_resolution: PoolResolution): string {
        return '__test_pool_fetch_stub__';
    }
}

/**
 * Re-export of {@link ResolvedSite} for tests that synthesise the
 * `site` field directly when stubbing {@link withSiteMeta}.
 */
export type { ResolvedSite };

/**
 * Strip the W6 `withSiteMeta` text-prefix `[<id-or-label> @ <host>] `
 * from a tool result's first text block. Pre-W6 tests called
 * `JSON.parse(result.content[0].text)` directly; W6.3 wires
 * `withSiteMeta` into every handler so the same text now starts with
 * the site-awareness prefix and JSON.parse fails. Use this helper at
 * the call-site instead of duplicating the regex in every test file.
 *
 * Regex: any leading `[…] ` (single-bracket pair, then exactly one
 * space). Conservative on purpose so any legitimate JSON that
 * happened to start with `[`-bracket text isn't accidentally
 * stripped — the W6 prefix never contains a closing `]` inside the
 * label or host segments.
 */
export function stripSitePrefix(text: string): string {
    return text.replace(/^\[[^\]]+\] /, '');
}

/**
 * Multi-site pool factory. Builds an in-memory {@link SitesFileT} from
 * `opts.sites`, wires a {@link SiteRegistry} with a stub platform
 * probe (no network), and returns a {@link ClientPool} ready for tool
 * handlers.
 */
export function makeMultiTestPool(
    opts: MakeMultiTestPoolOptions,
): ClientPool {
    const entries: SiteEntryT[] = opts.sites.map((s) => ({
        site_id: s.site_id,
        url: s.url,
        platform: s.platform ?? 'auto',
        bearer: s.bearer,
        is_default: s.is_default ?? false,
        ...(s.label !== undefined ? { label: s.label } : {}),
    }));

    const file: SitesFileT = {
        schema_version: 1,
        default_site_id: opts.defaultSiteId ?? null,
        sites: entries,
    };

    const registry = new SiteRegistry(file, {
        platformForUrlAsync: async (url, hint) => {
            // Tests must NEVER hit the network. Resolution order:
            //   1. explicit hint (entry.platform === 'wordpress'/'joomla')
            //   2. platformOverride from the factory caller
            //   3. WordPress fallback (legacy single-site shape)
            const effective: 'wordpress' | 'joomla' =
                hint === 'joomla' || hint === 'wordpress'
                    ? hint
                    : (opts.platformOverride ?? 'wordpress');
            return makePlatform(url, effective);
        },
    });

    const resolver = opts.secretResolver ?? new TestPlainResolver();
    return new TestClientPool(registry, resolver, opts.fetch);
}

// Re-export the underlying secret-resolver so tests that wire custom
// behaviour (e.g. an `op` mock) can compose without reaching into the
// sites/ directory hierarchy.
export { PlainSecretResolver };
