/**
 * Tests for health + diagnose tools.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { RestClient } from '../../src/client.js';
import { buildHealthTools } from '../../src/tools/health.js';

function fakeClient(handler: (url: string) => Response | Promise<Response>): RestClient {
    return new RestClient({
        baseUrl: 'https://example.com',
        bearerToken: 't',
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
        const parsed = JSON.parse(result.content[0]!.text) as Record<string, unknown>;
        expect(parsed.plugin_reachable).toBe(true);
        expect(parsed.bearer_valid).toBe(false);
    });
});
