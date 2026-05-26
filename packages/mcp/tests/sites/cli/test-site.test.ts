/**
 * W9 — tests for `testSiteCommand`.
 *
 * Drives the runtime probe pipeline with a stubbed ClientPool so we
 * can deterministically assert:
 *  - green path (both /health and /etag fulfilled) → pluginReachable +
 *    bearerValid + etagReceived all true, summary mentions "OK"
 *  - /health rejects with a generic error → pluginReachable=false +
 *    pluginError populated
 *  - /etag rejects with RestError 401 → bearerValid=false + summary
 *    mentions "Bearer key rejected"; 401 also flips pluginReachable=true
 *  - /etag rejects with a network error → bearer_error populated with
 *    the raw message
 *  - unknown site_id → result.unknownSite=true + available[] populated
 *  - renderTestSiteLines: unknown site → 2-line FAIL output; happy
 *    path → multi-line structured output
 *  - never throws on probe failure (only file-IO errors throw)
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import { NetworkError, RestError } from '../../../src/errors.js';
import {
    WordPressPlatform,
    type Platform,
    type PlatformKind,
} from '../../../src/platform/index.js';
import { RestClient } from '../../../src/client.js';
import { ClientPool, UnknownSiteError } from '../../../src/sites/client-pool.js';
import {
    renderTestSiteLines,
    testSiteCommand,
    type TestSiteDeps,
} from '../../../src/sites/cli/test-site.js';
import type { SiteRegistry } from '../../../src/sites/registry.js';
import type { SitesFileT } from '../../../src/sites/schema.js';
import {
    PlainSecretResolver,
    type SecretResolver,
} from '../../../src/sites/secret-resolver.js';

const BEARER = 'ytb_live_eyJhIjoiYSJ9.aaa-bbb_ccc';

function singleSiteFile(): SitesFileT {
    return {
        schema_version: 1,
        default_site_id: 'wp-acme',
        sites: [{
            site_id: 'wp-acme',
            url: 'https://acme.com',
            platform: 'wordpress',
            bearer: BEARER,
            is_default: true,
            label: 'Acme',
        }],
    };
}

/**
 * Build a pool factory that returns a ClientPool wired to a stub
 * RestClient with the supplied `fetch` implementation. The fetch impl
 * is what the test scripts to assert each /health + /etag branch.
 */
function makePoolBuilder(
    fetchImpl: typeof fetch,
): TestSiteDeps['buildPool'] {
    return (registry: SiteRegistry, resolver: SecretResolver) => {
        // Build a pool whose resolve() returns a RestClient with
        // the supplied fetch by sub-classing ClientPool inline.
        class StubPool extends ClientPool {
            override async resolve(siteId?: string) {
                const base = await super.resolve(siteId);
                const client = new RestClient({
                    platform: base.site.platform,
                    bearerToken: '__stub_token__',
                    fetch: fetchImpl,
                });
                return { client, site: base.site };
            }
        }
        return new StubPool(registry, resolver);
    };
}

/**
 * Stub platform-probe used in every test (no network). Always returns
 * a WordPressPlatform pointed at the entry's URL.
 */
const stubPlatformProbe = async (
    url: string,
    hint?: PlatformKind,
): Promise<Platform> => {
    // Honour the hint when present (we set 'wordpress' on the entry).
    void hint;
    return new WordPressPlatform(url);
};

describe('testSiteCommand — green path', () => {
    it('returns OK when both /health and /etag succeed', async () => {
        const fetchImpl = async (_url: string | URL | Request) => {
            return new Response('{"ok":true}', {
                status: 200,
                headers: { 'content-type': 'application/json', etag: 'W/"xyz"' },
            });
        };
        const deps: TestSiteDeps = {
            load: async () => singleSiteFile(),
            secretResolver: new PlainSecretResolver(),
            platformForUrlAsync: stubPlatformProbe,
            buildPool: makePoolBuilder(fetchImpl as unknown as typeof fetch),
        };
        const r = await testSiteCommand('wp-acme', '/x', deps);
        expect(r.unknownSite).toBe(false);
        expect(r.pluginReachable).toBe(true);
        expect(r.bearerValid).toBe(true);
        expect(r.etagReceived).toBe(true);
        expect(r.summary).toMatch(/^OK/);
        expect(r.platform).toBe('wordpress');
        expect(r.siteUrl).toBe('https://acme.com');
    });
});

describe('testSiteCommand — health-only failure', () => {
    it('reports pluginReachable=false + pluginError when /health rejects with NetworkError', async () => {
        const fetchImpl = async (url: string | URL | Request) => {
            const u = typeof url === 'string' ? url : url.toString();
            if (u.includes('/health')) {
                throw new NetworkError('connect ECONNREFUSED');
            }
            return new Response('{"ok":true}', {
                status: 200,
                headers: { 'content-type': 'application/json' },
            });
        };
        const deps: TestSiteDeps = {
            load: async () => singleSiteFile(),
            secretResolver: new PlainSecretResolver(),
            platformForUrlAsync: stubPlatformProbe,
            buildPool: makePoolBuilder(fetchImpl as unknown as typeof fetch),
        };
        const r = await testSiteCommand('wp-acme', '/x', deps);
        // /etag returning 200 does NOT bump pluginReachable (only 401/403
        // does, since they prove the plugin actually responded). With
        // /health rejected and /etag a plain 200, pluginReachable stays
        // false but bearerValid is true.
        expect(r.pluginReachable).toBe(false);
        expect(r.bearerValid).toBe(true);
        expect(r.pluginError).toMatch(/Network error/);
        expect(r.summary).toMatch(/plugin not reachable/);
    });
});

describe('testSiteCommand — bearer 401', () => {
    it('reports bearerValid=false + summary "Bearer key rejected" on 401', async () => {
        const fetchImpl = async (url: string | URL | Request) => {
            const u = typeof url === 'string' ? url : url.toString();
            if (u.includes('/etag')) {
                return new Response('{"code":"invalid_kid"}', {
                    status: 401,
                    headers: { 'content-type': 'application/json' },
                });
            }
            return new Response('{"ok":true}', {
                status: 200,
                headers: { 'content-type': 'application/json' },
            });
        };
        const deps: TestSiteDeps = {
            load: async () => singleSiteFile(),
            secretResolver: new PlainSecretResolver(),
            platformForUrlAsync: stubPlatformProbe,
            buildPool: makePoolBuilder(fetchImpl as unknown as typeof fetch),
        };
        const r = await testSiteCommand('wp-acme', '/x', deps);
        expect(r.pluginReachable).toBe(true);
        expect(r.bearerValid).toBe(false);
        expect(r.bearerError).toMatch(/HTTP 401/);
        expect(r.summary).toMatch(/Bearer key rejected/);
    });
});

describe('testSiteCommand — bearer network error', () => {
    it('reports bearerError when /etag throws a non-RestError', async () => {
        const fetchImpl = async (url: string | URL | Request) => {
            const u = typeof url === 'string' ? url : url.toString();
            if (u.includes('/etag')) {
                throw new Error('socket hang up');
            }
            return new Response('{"ok":true}', {
                status: 200,
                headers: { 'content-type': 'application/json' },
            });
        };
        const deps: TestSiteDeps = {
            load: async () => singleSiteFile(),
            secretResolver: new PlainSecretResolver(),
            platformForUrlAsync: stubPlatformProbe,
            buildPool: makePoolBuilder(fetchImpl as unknown as typeof fetch),
        };
        const r = await testSiteCommand('wp-acme', '/x', deps);
        expect(r.bearerValid).toBe(false);
        expect(r.bearerError).toMatch(/socket hang up/);
    });
});

describe('testSiteCommand — unknown site', () => {
    it('returns unknownSite:true with available[] populated, never throws', async () => {
        // Use a pool that throws UnknownSiteError on resolve — easier
        // than building a full registry, mirrors the production path.
        const stubPool: TestSiteDeps['buildPool'] = (registry, resolver) => {
            return new (class extends ClientPool {
                override async resolve(_siteId?: string) {
                    throw new UnknownSiteError('wp-nope', ['wp-acme']);
                }
            })(registry, resolver);
        };
        const deps: TestSiteDeps = {
            load: async () => singleSiteFile(),
            secretResolver: new PlainSecretResolver(),
            platformForUrlAsync: stubPlatformProbe,
            buildPool: stubPool,
        };
        const r = await testSiteCommand('wp-nope', '/x', deps);
        expect(r.unknownSite).toBe(true);
        expect(r.available).toEqual(['wp-acme']);
        expect(r.pluginReachable).toBe(false);
        expect(r.bearerValid).toBe(false);
    });
});

describe('renderTestSiteLines', () => {
    it('renders a 2-line FAIL block for unknown sites', () => {
        const lines = renderTestSiteLines({
            siteId: 'wp-nope',
            siteUrl: '',
            platform: 'unknown',
            pluginReachable: false,
            bearerValid: false,
            etagReceived: false,
            summary: 'FAIL — Site "wp-nope" not found.',
            unknownSite: true,
            available: ['wp-acme'],
        });
        expect(lines).toHaveLength(2);
        expect(lines[0]).toMatch(/FAIL/);
        expect(lines[1]).toContain('wp-acme');
    });

    it('renders a multi-line structured output for found sites', () => {
        const lines = renderTestSiteLines({
            siteId: 'wp-acme',
            siteUrl: 'https://acme.com',
            platform: 'wordpress',
            pluginReachable: true,
            bearerValid: true,
            etagReceived: true,
            summary: 'OK — plugin reachable and Bearer key accepted.',
            unknownSite: false,
        });
        expect(lines.length).toBeGreaterThan(5);
        expect(lines.some((l) => l.includes('https://acme.com'))).toBe(true);
        expect(lines.some((l) => l.includes('Plugin reachable: yes'))).toBe(true);
        expect(lines.at(-1)).toMatch(/^OK/);
    });
});
