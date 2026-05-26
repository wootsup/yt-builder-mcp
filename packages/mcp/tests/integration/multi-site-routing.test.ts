/**
 * W12-R3 (F-A3-1) — Multi-Site Routing Integration Test.
 *
 * In-process integration test that wires a real two-site
 * {@link ClientPool} through the W7 `sites_list` tool and the W6.3
 * `yootheme_builder_health` tool. The tests exercise the full
 * registry → pool → handler → withSiteMeta pipeline against a mocked
 * fetch surface (NEVER touches the network).
 *
 * Why this exists: the unit-suite covered each layer in isolation but
 * did not pin the cross-layer guarantee that a `site_id` parameter
 * actually routes the call to the addressed site's RestClient AND
 * stamps the result with the matching `_meta` + text-prefix. A future
 * refactor that breaks the routing seam (e.g. always uses the default
 * site silently) would have slipped past the unit suite.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { buildHealthTools } from '../../src/tools/health.js';
import { ClientPool, NoDefaultSiteError, UnknownSiteError } from '../../src/sites/client-pool.js';
import { buildSitesListTool } from '../../src/sites/tools/sites-list.js';
import { makeMultiTestPool } from '../helpers/test-pool.js';

interface HealthStructured {
    plugin_version?: string;
    yootheme_loaded?: boolean;
    _meta?: {
        site_id?: string;
        site_url?: string;
        platform?: 'wordpress' | 'joomla';
    };
}

interface SitesListStructured {
    items: Array<{
        site_id: string;
        url: string;
        platform_hint: 'wordpress' | 'joomla' | 'auto';
        is_default: boolean;
        label?: string;
    }>;
    total: number;
    default_site_id: string | null;
}

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

/**
 * Build a mock fetch that routes per host: requests to acme.com get
 * `wpBody`, requests to beta.example.org get `joomlaBody`. Path is
 * ignored — this is enough to drive the health endpoint on both sides.
 */
function dualHostFetch(
    wpBody: Record<string, unknown>,
    joomlaBody: Record<string, unknown>,
): typeof fetch {
    return vi.fn(async (input: RequestInfo | URL): Promise<Response> => {
        const url = typeof input === 'string' ? input : input.toString();
        if (url.includes('acme.com')) return jsonResponse(wpBody);
        if (url.includes('beta.example.org')) return jsonResponse(joomlaBody);
        return jsonResponse({}, 404);
    }) as unknown as typeof fetch;
}

function poolOfTwo(opts?: { withDefault?: boolean; fetch?: typeof fetch }): ClientPool {
    const withDefault = opts?.withDefault ?? true;
    return makeMultiTestPool({
        sites: [
            {
                site_id: 'wp-acme',
                url: 'https://acme.com',
                bearer: 'ytb_live_PAYLOAD.SIG1',
                platform: 'wordpress',
                is_default: withDefault,
                label: 'Acme Production',
            },
            {
                site_id: 'joomla-beta',
                url: 'https://beta.example.org/joomla',
                bearer: 'ytb_live_PAYLOAD.SIG2',
                platform: 'joomla',
                is_default: false,
                label: 'Beta Staging',
            },
        ],
        defaultSiteId: withDefault ? 'wp-acme' : null,
        ...(opts?.fetch !== undefined ? { fetch: opts.fetch } : {}),
    });
}

describe('W12-R3 — Multi-Site Routing Integration', () => {
    it('sites_list returns BOTH sites with correct platform_hints + default-marker on wp-acme', async () => {
        const pool = poolOfTwo();
        const tool = buildSitesListTool(pool.registry);
        const result = await tool.handler({});
        const sc = result.structuredContent as unknown as SitesListStructured;

        expect(sc.total).toBe(2);
        expect(sc.default_site_id).toBe('wp-acme');
        expect(sc.items).toHaveLength(2);

        const acme = sc.items.find((i) => i.site_id === 'wp-acme');
        const beta = sc.items.find((i) => i.site_id === 'joomla-beta');
        expect(acme).toBeDefined();
        expect(beta).toBeDefined();
        expect(acme?.platform_hint).toBe('wordpress');
        expect(beta?.platform_hint).toBe('joomla');
        expect(acme?.is_default).toBe(true);
        expect(beta?.is_default).toBe(false);
    });

    it('health with site_id:"wp-acme" → _meta.site_id wp-acme + platform wordpress + text-prefix Acme Production @ acme.com', async () => {
        const pool = poolOfTwo({
            fetch: dualHostFetch(
                { plugin_version: '1.0.1', yootheme_loaded: true },
                { plugin_version: '1.0.1', yootheme_loaded: true, cms: 'joomla' },
            ),
        });
        const tools = buildHealthTools(pool);
        const health = tools.find((t) => t.name === 'yootheme_builder_health');
        if (!health) throw new Error('health tool not found');

        const result = await health.handler({ site_id: 'wp-acme' });
        // W12-R2: site meta is on the RESULT-level _meta, not structuredContent.
        const meta = (result._meta ?? {}) as NonNullable<HealthStructured['_meta']>;
        expect(meta.site_id).toBe('wp-acme');
        expect(meta.platform).toBe('wordpress');
        expect(meta.site_url).toBe('https://acme.com');
        // structuredContent stays schema-pure (no _meta key).
        expect((result.structuredContent as Record<string, unknown>)._meta).toBeUndefined();

        const text = result.content[0]?.text ?? '';
        expect(text).toMatch(/^\[Acme Production @ acme\.com\] /);
    });

    it('health with site_id:"joomla-beta" → _meta.site_id joomla-beta + platform joomla + text-prefix Beta Staging @ beta.example.org', async () => {
        const pool = poolOfTwo({
            fetch: dualHostFetch(
                { plugin_version: '1.0.1', yootheme_loaded: true },
                { plugin_version: '1.0.1', yootheme_loaded: true, cms: 'joomla' },
            ),
        });
        const tools = buildHealthTools(pool);
        const health = tools.find((t) => t.name === 'yootheme_builder_health');
        if (!health) throw new Error('health tool not found');

        const result = await health.handler({ site_id: 'joomla-beta' });
        // W12-R2: site meta is on the RESULT-level _meta, not structuredContent.
        const meta = (result._meta ?? {}) as NonNullable<HealthStructured['_meta']>;
        expect(meta.site_id).toBe('joomla-beta');
        expect(meta.platform).toBe('joomla');
        expect(meta.site_url).toBe('https://beta.example.org/joomla');
        // structuredContent stays schema-pure (no _meta key).
        expect((result.structuredContent as Record<string, unknown>)._meta).toBeUndefined();

        const text = result.content[0]?.text ?? '';
        expect(text).toMatch(/^\[Beta Staging @ beta\.example\.org\] /);
    });

    it('ClientPool serves DIFFERENT RestClient instances per site_id (identity-distinct)', async () => {
        const pool = poolOfTwo();
        const r1 = await pool.resolve('wp-acme');
        const r2 = await pool.resolve('joomla-beta');
        // The two sites MUST never share the same client — that would
        // mean the per-site bearer was leaking across sites.
        expect(r1.client).not.toBe(r2.client);
        // And the site descriptors are different.
        expect(r1.site.id).toBe('wp-acme');
        expect(r2.site.id).toBe('joomla-beta');
        expect(r1.site.platform.kind).toBe('wordpress');
        expect(r2.site.platform.kind).toBe('joomla');
    });

    it('pool.invalidate("wp-acme") does NOT affect joomla-beta cache (per-site invalidation isolation)', async () => {
        const pool = poolOfTwo();
        // Warm both sites.
        const acme1 = await pool.resolve('wp-acme');
        const beta1 = await pool.resolve('joomla-beta');

        // Invalidate ONLY the WP site.
        pool.invalidate('wp-acme');

        const acme2 = await pool.resolve('wp-acme');
        const beta2 = await pool.resolve('joomla-beta');

        // WP client is a fresh instance (cache was dropped).
        expect(acme2.client).not.toBe(acme1.client);
        // Joomla client identity is stable — its cache entry was NOT touched.
        expect(beta2.client).toBe(beta1.client);
    });

    it('NoDefaultSiteError when site_id omitted and no default configured on a multi-site registry', async () => {
        const pool = poolOfTwo({ withDefault: false });
        await expect(pool.resolve(undefined)).rejects.toBeInstanceOf(NoDefaultSiteError);
    });

    it('UnknownSiteError with available[] hint when site_id is not in the registry', async () => {
        const pool = poolOfTwo();
        try {
            await pool.resolve('does-not-exist');
            throw new Error('expected UnknownSiteError');
        } catch (e) {
            expect(e).toBeInstanceOf(UnknownSiteError);
            const err = e as UnknownSiteError;
            expect(err.siteId).toBe('does-not-exist');
            // available[] must list BOTH configured sites in registration order.
            expect([...err.available]).toEqual(['wp-acme', 'joomla-beta']);
        }
    });

    it('cross-call ordering A→B→A: warm A returns identity-stable client across intervening B (cache is per-site, not LRU)', async () => {
        const pool = poolOfTwo();
        const firstA = await pool.resolve('wp-acme');
        const _interveningB = await pool.resolve('joomla-beta');
        const secondA = await pool.resolve('wp-acme');
        // A second resolve('wp-acme') after intervening B MUST hit the
        // same cached RestClient — proves the per-site cache is not a
        // single-slot or LRU that gets evicted by B.
        expect(secondA.client).toBe(firstA.client);
    });
});
