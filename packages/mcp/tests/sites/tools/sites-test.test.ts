/**
 * W7 — Tests for `yootheme_builder_sites_test`.
 *
 * Connectivity-probe tool with REQUIRED `site_id`. Tests cover:
 *
 *  - Unknown site_id → structured error `code: 'unknown_site'` with
 *    `context.available[]` hint (routed through `resolveSiteOrError`).
 *  - Reachable site (mock fetch returns 200 for /health + /etag) →
 *    plugin_reachable:true + bearer_valid:true + etag_received:true.
 *  - Unreachable (mock fetch throws on /health) → plugin_reachable:false
 *    + the error message lands in plugin_error.
 *  - Bearer-401 on /etag with /health OK → plugin_reachable:true +
 *    bearer_valid:false + bearer_error string contains "401".
 *  - Bearer-403 mapped identically (plugin reachable, bearer rejected).
 *  - 500 on /etag with /health OK → plugin_reachable:true +
 *    bearer_valid:false + bearer_error contains "500".
 *  - Text-prefix `[<label or id> @ <host>] ` from withSiteMeta lands on
 *    the resolved site (W6.2 PRIMARY surface).
 *  - result-level _meta.site_id / site_url / platform stamped (NOT in
 *    structuredContent — that stays schema-pure for outputSchema).
 *  - isError:true when either probe fails (green probe ⇒ no isError).
 *  - site_id is REQUIRED in the inputSchema (Zod parse rejects empty).
 *  - description ends with the W5 SITE_ID_SCHEMA suffix.
 *  - readOnly annotation with descriptive title.
 *
 * W12-R3 (F-A3-3): every behavioural test is run twice — once for a
 * WordPress site, once for a Joomla site — via `describe.each`. The
 * mock fetch is platform-aware (matches `/wp-json/...` and
 * `/api/index.php/v1/...` URL shapes) so a platform-specific
 * routing regression in `sites_test` would now fail one branch and
 * pass the other instead of going undetected.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import type { ClientPool } from '../../../src/sites/client-pool.js';
import { buildSitesTestTool } from '../../../src/sites/tools/sites-test.js';
import { makeMultiTestPool } from '../../helpers/test-pool.js';

const TOOL_NAME = 'yootheme_builder_sites_test';

type PlatformKind = 'wordpress' | 'joomla';

interface PlatformFixture {
    readonly platform: PlatformKind;
    readonly url: string;
    readonly host: string;
    readonly restPathFragment: string;
}

const PLATFORM_FIXTURES: readonly PlatformFixture[] = [
    {
        platform: 'wordpress',
        url: 'https://acme.example.com',
        host: 'acme.example.com',
        restPathFragment: '/wp-json/yt-builder-mcp/v1',
    },
    {
        platform: 'joomla',
        url: 'https://joomla.example.org',
        host: 'joomla.example.org',
        restPathFragment: '/api/index.php/v1/yt-builder-mcp',
    },
];

interface SitesTestStructured {
    site_id: string;
    site_url: string;
    platform: PlatformKind;
    plugin_reachable: boolean;
    bearer_valid: boolean;
    etag_received: boolean;
    plugin_error?: string;
    bearer_error?: string;
    summary: string;
    _meta?: { site_id?: string; site_url?: string; platform?: string };
}

function structured(result: {
    structuredContent?: Record<string, unknown>;
    _meta?: { site_id?: string; site_url?: string; platform?: string };
}): SitesTestStructured {
    const sc = result.structuredContent;
    if (!sc) throw new Error('structuredContent missing');
    // W12-R2: site-awareness `_meta` now lives on the RESULT-level
    // `_meta`, NOT inside structuredContent (which must validate against
    // outputSchema with additionalProperties:false). Surface the
    // result-level meta under `_meta` here so the behavioural assertions
    // read the correct, spec-conform location. A separate test pins that
    // structuredContent itself carries NO `_meta` key.
    return {
        ...(sc as unknown as SitesTestStructured),
        _meta: result._meta,
    };
}

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function poolWithFetch(
    fx: PlatformFixture,
    handler: (url: string) => Response | Promise<Response>,
): ClientPool {
    return makeMultiTestPool({
        sites: [{
            site_id: 'wp-acme',
            url: fx.url,
            bearer: 'ytb_live_PAYLOAD.SIG',
            platform: fx.platform,
            is_default: true,
            label: 'Acme Production',
        }],
        defaultSiteId: 'wp-acme',
        fetch: vi.fn(async (input: RequestInfo | URL) => {
            const url = typeof input === 'string' ? input : input.toString();
            // W12-R3: assert the URL routed to the expected REST-namespace
            // fragment for this platform — catches a future regression
            // where sites_test silently calls the WP namespace on a
            // Joomla site (or vice versa). The handler only cares
            // about endsWith('/health' | '/etag') so the fragment
            // assertion is the platform-routing pin.
            if (!url.includes(fx.restPathFragment)) {
                throw new Error(
                    `URL ${url} did not include expected ${fx.platform} fragment ${fx.restPathFragment}`,
                );
            }
            return handler(url);
        }) as unknown as typeof fetch,
    });
}

describe('W7 — yootheme_builder_sites_test', () => {
    describe('schema + metadata (platform-agnostic)', () => {
        const fx = PLATFORM_FIXTURES[0];
        if (fx === undefined) throw new Error('fixture missing');

        it('tool name is canonical', () => {
            const pool = poolWithFetch(fx, () => jsonResponse({}));
            const tool = buildSitesTestTool(pool);
            expect(tool.name).toBe(TOOL_NAME);
        });

        // W12-R1.2 (A6-F4): the W5 canonical suffix "Operates on the
        // default site unless site_id is provided." was misleading here
        // (site_id is REQUIRED on this tool, not optional). Replaced
        // with purpose-specific wording — the W5 site-id-schema pin
        // EXEMPTS this tool via DESCRIPTION_SUFFIX_EXEMPT.
        it('description states site_id is REQUIRED + points at sites_list', () => {
            const pool = poolWithFetch(fx, () => jsonResponse({}));
            const tool = buildSitesTestTool(pool);
            expect(tool.description).toMatch(/site_id` is REQUIRED/);
            expect(tool.description).toMatch(/sites_list/);
        });

        it('annotations declare read-only + the descriptive title', () => {
            const pool = poolWithFetch(fx, () => jsonResponse({}));
            const tool = buildSitesTestTool(pool);
            expect(tool.annotations?.readOnlyHint).toBe(true);
            expect(tool.annotations?.title).toBe('Sites — Test connectivity');
        });

        it('site_id input field is REQUIRED (not optional)', () => {
            const pool = poolWithFetch(fx, () => jsonResponse({}));
            const tool = buildSitesTestTool(pool);
            // The W5 site-id-schema pin EXEMPTS this tool from the
            // "optional" rule — verify the exemption holds at the
            // schema level: the field must NOT be `.optional()`.
            const siteIdSchema = tool.inputSchema.site_id;
            expect(siteIdSchema.isOptional()).toBe(false);
        });
    });

    // W12-R3 (F-A3-3): parametrize the behavioural surface across BOTH
    // platforms so a future routing regression in sites_test fails one
    // branch and passes the other instead of going undetected.
    describe.each(PLATFORM_FIXTURES)('platform: $platform', (fx) => {
        describe('unknown site_id', () => {
            it('returns structured error with code:"unknown_site" + available[]', async () => {
                const pool = poolWithFetch(fx, () => jsonResponse({}));
                const tool = buildSitesTestTool(pool);
                const result = await tool.handler({ site_id: 'does-not-exist' });
                expect(result.isError).toBe(true);
                const text = result.content[0]?.text ?? '';
                const parsed = JSON.parse(text) as {
                    code: string;
                    context: { available: string[]; site_id: string };
                    hint: string;
                };
                expect(parsed.code).toBe('unknown_site');
                expect(parsed.context.site_id).toBe('does-not-exist');
                expect(parsed.context.available).toEqual(['wp-acme']);
                expect(parsed.hint).toContain('yootheme_builder_sites_list');
            });
        });

        describe('reachable site', () => {
            it('returns green result with plugin_reachable + bearer_valid + etag_received + _meta.platform matches', async () => {
                const pool = poolWithFetch(fx, (url) => {
                    if (url.endsWith('/health')) return jsonResponse({ ok: true });
                    if (url.endsWith('/etag')) return jsonResponse({ etag: 'abc' });
                    return jsonResponse({}, 404);
                });
                const tool = buildSitesTestTool(pool);
                const result = await tool.handler({ site_id: 'wp-acme' });
                const sc = structured(result);
                expect(sc.plugin_reachable).toBe(true);
                expect(sc.bearer_valid).toBe(true);
                expect(sc.etag_received).toBe(true);
                expect(sc.summary).toContain('OK');
                expect(result.isError).toBeUndefined();
                // Platform-routing pin.
                expect(sc._meta?.platform).toBe(fx.platform);
            });

            it(`text-prefix [Acme Production @ ${fx.host}] is stamped by withSiteMeta`, async () => {
                const pool = poolWithFetch(fx, (url) => {
                    if (url.endsWith('/health')) return jsonResponse({ ok: true });
                    if (url.endsWith('/etag')) return jsonResponse({ etag: 'abc' });
                    return jsonResponse({}, 404);
                });
                const tool = buildSitesTestTool(pool);
                const result = await tool.handler({ site_id: 'wp-acme' });
                const text = result.content[0]?.text ?? '';
                const expectedPrefix = new RegExp(
                    `^\\[Acme Production @ ${fx.host.replace(/\./g, '\\.')}\\] `,
                );
                expect(text).toMatch(expectedPrefix);
            });

            it('result-level _meta carries site_id + site_url + platform (NOT in structuredContent)', async () => {
                const pool = poolWithFetch(fx, (url) => {
                    if (url.endsWith('/health')) return jsonResponse({ ok: true });
                    if (url.endsWith('/etag')) return jsonResponse({ etag: 'abc' });
                    return jsonResponse({}, 404);
                });
                const tool = buildSitesTestTool(pool);
                const result = await tool.handler({ site_id: 'wp-acme' });
                const sc = structured(result);
                expect(sc._meta?.site_id).toBe('wp-acme');
                expect(sc._meta?.site_url).toBe(fx.url);
                expect(sc._meta?.platform).toBe(fx.platform);
                // W12-R2 ANTI-REGRESSION: structuredContent MUST stay
                // schema-pure — no `_meta` key, or Claude Desktop rejects
                // the result with -32602 "Failed to call tool".
                expect(
                    (result.structuredContent as Record<string, unknown>)._meta,
                ).toBeUndefined();
            });
        });

        describe('unreachable plugin (network throw)', () => {
            it('returns red result with plugin_reachable:false + plugin_error', async () => {
                const pool = poolWithFetch(fx, () => {
                    throw new Error('ECONNREFUSED');
                });
                const tool = buildSitesTestTool(pool);
                const result = await tool.handler({ site_id: 'wp-acme' });
                const sc = structured(result);
                expect(sc.plugin_reachable).toBe(false);
                expect(sc.bearer_valid).toBe(false);
                expect(sc.etag_received).toBe(false);
                expect(sc.plugin_error).toBeDefined();
                expect(sc.summary).toMatch(/FAIL/i);
                expect(result.isError).toBe(true);
                expect(sc._meta?.platform).toBe(fx.platform);
            });
        });

        describe('bearer rejected', () => {
            it('401 on /etag with /health OK → plugin_reachable:true + bearer_valid:false', async () => {
                const pool = poolWithFetch(fx, (url) => {
                    if (url.endsWith('/health')) return jsonResponse({ ok: true });
                    if (url.endsWith('/etag')) {
                        return jsonResponse(
                            { code: 'rest_forbidden', message: 'bearer rejected' },
                            401,
                        );
                    }
                    return jsonResponse({}, 404);
                });
                const tool = buildSitesTestTool(pool);
                const result = await tool.handler({ site_id: 'wp-acme' });
                const sc = structured(result);
                expect(sc.plugin_reachable).toBe(true);
                expect(sc.bearer_valid).toBe(false);
                expect(sc.etag_received).toBe(false);
                expect(sc.bearer_error).toContain('401');
                expect(sc.summary).toMatch(/Bearer/);
                expect(result.isError).toBe(true);
                expect(sc._meta?.platform).toBe(fx.platform);
            });

            it('403 on /etag is treated identically to 401', async () => {
                const pool = poolWithFetch(fx, (url) => {
                    if (url.endsWith('/health')) return jsonResponse({ ok: true });
                    if (url.endsWith('/etag')) {
                        return jsonResponse(
                            { code: 'rest_forbidden', message: 'scope insufficient' },
                            403,
                        );
                    }
                    return jsonResponse({}, 404);
                });
                const tool = buildSitesTestTool(pool);
                const result = await tool.handler({ site_id: 'wp-acme' });
                const sc = structured(result);
                expect(sc.plugin_reachable).toBe(true);
                expect(sc.bearer_valid).toBe(false);
                expect(sc.bearer_error).toContain('403');
                expect(result.isError).toBe(true);
                expect(sc._meta?.platform).toBe(fx.platform);
            });

            it('500 on /etag with /health OK → plugin_reachable:true + bearer_valid:false + status surfaced', async () => {
                const pool = poolWithFetch(fx, (url) => {
                    if (url.endsWith('/health')) return jsonResponse({ ok: true });
                    if (url.endsWith('/etag')) {
                        return jsonResponse(
                            { code: 'server_error', message: 'boom' },
                            500,
                        );
                    }
                    return jsonResponse({}, 404);
                });
                const tool = buildSitesTestTool(pool);
                const result = await tool.handler({ site_id: 'wp-acme' });
                const sc = structured(result);
                expect(sc.plugin_reachable).toBe(true);
                expect(sc.bearer_valid).toBe(false);
                expect(sc.bearer_error).toContain('500');
                expect(sc._meta?.platform).toBe(fx.platform);
            });
        });
    });
});
