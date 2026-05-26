/**
 * Tests for health + diagnose tools.
 *
 * W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import type { ClientPool } from '../../src/sites/client-pool.js';
import { buildHealthTools } from '../../src/tools/health.js';
import { makeTestPool, stripSitePrefix } from '../helpers/test-pool.js';

function fakeClient(handler: (url: string) => Response | Promise<Response>): ClientPool {
    return makeTestPool({
        baseUrl: 'https://example.com',
        bearer: 't',
        fetch: vi.fn(async (input: RequestInfo | URL) => {
            const url = typeof input === 'string' ? input : input.toString();
            return handler(url);
        }) as unknown as typeof fetch,
    });
}

function findTool(tools: ReturnType<typeof buildHealthTools>, name: string) {
    const t = tools.find((x) => x.name === name);
    if (!t) throw new Error(`Tool ${name} not found`);
    return t;
}

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('buildHealthTools', () => {
    it('health returns plugin payload in structuredContent + detail text', async () => {
        const tools = buildHealthTools(
            fakeClient(() =>
                jsonResponse({
                    plugin_version: '0.1.0',
                    yootheme_version: '4.5',
                    wp_version: '6.7',
                    php_version: '8.2',
                    storage_type: 'wp_option',
                    storage_target: 'yootheme',
                    yootheme_loaded: true,
                    available_endpoints: ['/health', '/pages'],
                }),
            ),
        );
        const result = await findTool(tools, 'yootheme_builder_health').handler({});
        // structuredContent — domain-typed payload, validated against outputSchema by hosts.
        expect(result.structuredContent).toMatchObject({
            plugin_version: '0.1.0',
            yootheme_loaded: true,
            yootheme_version: '4.5',
            available_endpoints: ['/health', '/pages'],
        });
        // text leg — Round-1 audit I1 fix: now stats-display flat layout
        // (`statsResult`), no longer grouped Detail-Card. Boolean values
        // render as "OK" / "Error" via the toolkit's stats formatter.
        const text = result.content[0]!.text as string;
        expect(text).toContain('Plugin version: 0.1.0');
        expect(text).toContain('YOOtheme loaded: OK');
        expect(text).toContain('Endpoint count: 2');
    });

    it('diagnose passes when both probes succeed', async () => {
        const tools = buildHealthTools(
            fakeClient((url) => {
                if (url.endsWith('/health')) {
                    return jsonResponse({
                        plugin_version: '0.1.0',
                        yootheme_version: null,
                        wp_version: '6.7',
                        php_version: '8.2',
                        storage_type: 'wp_option',
                        storage_target: 'yootheme',
                        yootheme_loaded: false,
                        available_endpoints: [],
                    });
                }
                return jsonResponse({ etag: 'e0' });
            }),
        );
        const result = await findTool(tools, 'yootheme_builder_diagnose').handler({});
        expect(result.isError).toBeUndefined();
        expect(result.structuredContent).toMatchObject({
            plugin_reachable: true,
            bearer_valid: true,
        });
    });

    it('diagnose flags an auth failure when /etag returns 401', async () => {
        const tools = buildHealthTools(
            fakeClient((url) => {
                if (url.endsWith('/health')) {
                    return jsonResponse({
                        plugin_version: '0.1.0',
                        yootheme_version: '4.5',
                        wp_version: '6.7',
                        php_version: '8.2',
                        storage_type: 'wp_option',
                        storage_target: 'yootheme',
                        yootheme_loaded: true,
                        available_endpoints: [],
                    });
                }
                return jsonResponse({ code: 'unauth', message: 'bad key' }, 401);
            }),
        );
        const result = await findTool(tools, 'yootheme_builder_diagnose').handler({});
        expect(result.isError).toBe(true);
        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as Record<string, unknown>;
        expect(parsed.plugin_reachable).toBe(true);
        expect(parsed.bearer_valid).toBe(false);
    });

    // 1.0.1 — health + diagnose must surface site_url / home_url so an
    // agent can deep-link the customer to the live site without making a
    // separate WP-REST round-trip.
    it('health surfaces site_url and home_url when emitted by the PHP server', async () => {
        const tools = buildHealthTools(
            fakeClient(() =>
                jsonResponse({
                    plugin_version: '1.0.1',
                    yootheme_version: '4.5.33',
                    wp_version: '6.8',
                    php_version: '8.3',
                    storage_type: 'wp_option',
                    storage_target: 'yootheme',
                    yootheme_loaded: true,
                    available_endpoints: ['/health'],
                    site_url: 'https://example.test/wordpress',
                    home_url: 'https://example.test',
                }),
            ),
        );
        const result = await findTool(tools, 'yootheme_builder_health').handler({});
        expect(result.structuredContent).toMatchObject({
            site_url: 'https://example.test/wordpress',
            home_url: 'https://example.test',
        });
    });

    it('diagnose mirrors site_url and home_url into the structuredContent payload', async () => {
        const tools = buildHealthTools(
            fakeClient((url) => {
                if (url.endsWith('/health')) {
                    return jsonResponse({
                        plugin_version: '1.0.1',
                        yootheme_version: '4.5.33',
                        wp_version: '6.8',
                        php_version: '8.3',
                        storage_type: 'wp_option',
                        storage_target: 'yootheme',
                        yootheme_loaded: true,
                        available_endpoints: [],
                        site_url: 'https://example.test/wordpress',
                        home_url: 'https://example.test',
                    });
                }
                return jsonResponse({ etag: 'e0' });
            }),
        );
        const result = await findTool(tools, 'yootheme_builder_diagnose').handler({});
        expect(result.structuredContent).toMatchObject({
            site_url: 'https://example.test/wordpress',
            home_url: 'https://example.test',
            bearer_valid: true,
        });
    });

    // Wave 1.5 B9 (audit) — Identity pin. The audit verdict was "both have
    // the field, but identical-value not pinned." A future refactor that
    // re-derives diagnose's URLs from a different source (e.g. a parallel
    // WP REST call) would silently diverge from health's values. Pin by
    // calling BOTH tools against the SAME fake server and asserting the
    // structuredContent URLs match byte-for-byte.
    it('diagnose and health return byte-identical site_url + home_url against the same server', async () => {
        const healthBody = {
            plugin_version: '1.0.1',
            yootheme_version: '4.5.33',
            wp_version: '6.8',
            php_version: '8.3',
            storage_type: 'wp_option',
            storage_target: 'yootheme',
            yootheme_loaded: true,
            available_endpoints: ['/health'],
            site_url: 'https://example.test/sub/path',
            home_url: 'https://example.test',
        };
        const tools = buildHealthTools(
            fakeClient((url) => {
                if (url.endsWith('/health')) return jsonResponse(healthBody);
                return jsonResponse({ etag: 'e0' });
            }),
        );

        const healthResult = await findTool(tools, 'yootheme_builder_health').handler({});
        const diagnoseResult = await findTool(tools, 'yootheme_builder_diagnose').handler({});

        const healthSc = healthResult.structuredContent as { site_url?: unknown; home_url?: unknown };
        const diagnoseSc = diagnoseResult.structuredContent as { site_url?: unknown; home_url?: unknown };

        expect(diagnoseSc.site_url).toBe(healthSc.site_url);
        expect(diagnoseSc.home_url).toBe(healthSc.home_url);
        // Sanity: not undefined.
        expect(healthSc.site_url).toBe('https://example.test/sub/path');
        expect(healthSc.home_url).toBe('https://example.test');
    });

    // R8-A3 #6b — cross-platform health schema. The Joomla server emits a
    // DIFFERENT shape than WP: `cms`/`cms_version` (e.g. "Joomla" / "6.0.2")
    // and NEITHER `wp_version` NOR `yootheme_version`. Wave-7 made every
    // platform-/tier-variable field optional so the SAME outputSchema
    // validates both platforms. Pre-fix the schema required `wp_version`
    // (string, non-optional) → the Joomla payload failed host-side schema
    // validation and the tool result was rejected. These tests pin both the
    // surfaced fields and a direct schema-validation of the Joomla shape.
    it('health surfaces cms/cms_version for the Joomla payload (no wp_version)', async () => {
        const tools = buildHealthTools(
            fakeClient(() =>
                jsonResponse({
                    plugin_version: '1.0.1',
                    cms: 'Joomla',
                    cms_version: '6.0.2',
                    php_version: '8.3',
                    storage_type: 'joomla_extensions',
                    storage_target: 'yootheme',
                    yootheme_loaded: true,
                    available_endpoints: ['/health', '/pages'],
                }),
            ),
        );
        const result = await findTool(tools, 'yootheme_builder_health').handler({});
        const sc = result.structuredContent as Record<string, unknown>;
        expect(sc).toMatchObject({
            plugin_version: '1.0.1',
            cms: 'Joomla',
            cms_version: '6.0.2',
            yootheme_loaded: true,
        });
        // Joomla omits these WP-only fields — they must NOT appear.
        expect(sc.wp_version).toBeUndefined();
        expect(sc.yootheme_version).toBeUndefined();
    });

    it('the health outputSchema validates the Joomla-shaped payload (cms/cms_version, no wp_version)', () => {
        const tools = buildHealthTools(fakeClient(() => jsonResponse({})));
        const healthTool = findTool(tools, 'yootheme_builder_health');
        const schema = healthTool.outputSchema;
        expect(schema, 'health tool must declare an outputSchema').toBeDefined();

        // The Joomla authenticated payload — cms/cms_version present, the
        // WP-only fields absent. This MUST pass the (now-optional) schema.
        const joomlaPayload = {
            plugin_version: '1.0.1',
            cms: 'Joomla',
            cms_version: '6.0.2',
            php_version: '8.3',
            storage_type: 'joomla_extensions',
            storage_target: 'yootheme',
            yootheme_loaded: true,
            available_endpoints: ['/health'],
        };
        const parsed = schema!.safeParse(joomlaPayload);
        expect(parsed.success, JSON.stringify('error' in parsed ? parsed.error.issues : [])).toBe(true);

        // The anonymous Joomla tier (only the three always-present fields)
        // must also validate — every other field is optional.
        const anonPayload = {
            plugin_version: '1.0.1',
            yootheme_loaded: false,
        };
        expect(schema!.safeParse(anonPayload).success).toBe(true);
    });

    // Back-compat: when an older PHP server doesn't yet emit the URL
    // fields, the structuredContent payload simply omits them — the rest
    // of the shape stays valid.
    it('health omits site_url/home_url cleanly when the PHP server is pre-1.0.1', async () => {
        const tools = buildHealthTools(
            fakeClient(() =>
                jsonResponse({
                    plugin_version: '1.0.0',
                    yootheme_version: '4.5.33',
                    wp_version: '6.8',
                    php_version: '8.3',
                    storage_type: 'wp_option',
                    storage_target: 'yootheme',
                    yootheme_loaded: true,
                    available_endpoints: ['/health'],
                }),
            ),
        );
        const result = await findTool(tools, 'yootheme_builder_health').handler({});
        const sc = result.structuredContent as Record<string, unknown>;
        expect(sc).toMatchObject({ plugin_version: '1.0.0' });
        expect(sc.site_url).toBeUndefined();
        expect(sc.home_url).toBeUndefined();
    });
});
