/**
 * Tests for source binding tools.
 *
 * W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import type { ClientPool } from '../../src/sites/client-pool.js';
import { buildSourcesTools } from '../../src/tools/sources.js';
import { makeTestPool, stripSitePrefix } from '../helpers/test-pool.js';

function fakeClient(handler: (url: string, init: RequestInit) => Response | Promise<Response>): ClientPool {
    return makeTestPool({
        baseUrl: 'https://example.com',
        bearer: 't',
        fetch: vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = typeof input === 'string' ? input : input.toString();
            return handler(url, init ?? {});
        }) as unknown as typeof fetch,
    });
}

function findTool(tools: ReturnType<typeof buildSourcesTools>, name: string) {
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

describe('buildSourcesTools', () => {
    it('sources_list GETs /sources', async () => {
        let seenUrl: string | undefined;
        const tools = buildSourcesTools(
            fakeClient((url) => {
                seenUrl = url;
                return jsonResponse({ sources: { apimapper: [], wordpress: [], essentials: [] } });
            }),
        );
        await findTool(tools, 'yootheme_builder_sources_list').handler({});
        expect(seenUrl).toContain('/v1/sources');
    });

    it('bind_source PUTs with source_name body', async () => {
        let seenBody: string | undefined;
        let seenMethod: string | undefined;
        const tools = buildSourcesTools(
            fakeClient((_url, init) => {
                seenBody = init.body as string | undefined;
                seenMethod = (init.method ?? 'GET').toUpperCase();
                return jsonResponse({ etag: 'e1' });
            }),
        );
        await findTool(tools, 'yootheme_builder_element_bind_source').handler({
            template_id: 'default',
            element_path: '/0',
            source_name: 'wp_posts',
            etag: '"e0"',
        });
        expect(seenMethod).toBe('PUT');
        const body = JSON.parse(seenBody ?? '{}') as Record<string, unknown>;
        expect(body.source_name).toBe('wp_posts');
    });

    it('unbind_source has confirm-guard', async () => {
        let restCalled = false;
        const tools = buildSourcesTools(
            fakeClient(() => {
                restCalled = true;
                return jsonResponse({});
            }),
        );
        const result = await findTool(tools, 'yootheme_builder_element_unbind_source').handler({
            template_id: 'default',
            element_path: '/0',
            etag: '"e0"',
            confirm: false,
        });
        expect(restCalled).toBe(false);
        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as Record<string, unknown>;
        expect(parsed.preview).toBe(true);
    });
});
