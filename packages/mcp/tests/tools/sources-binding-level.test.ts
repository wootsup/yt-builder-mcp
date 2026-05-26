/**
 * element_bind_source — bindingLevel + field_mappings parameter passthrough.
 *
 * Pins the body shape produced by the handler so the REST plugin's
 * bindingLevel resolver receives the expected payload.
 *
 * @license MIT
 */

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import { describe, expect, it, vi } from 'vitest';

import type { ClientPool } from '../../src/sites/client-pool.js';
import { buildSourcesTools } from '../../src/tools/sources.js';
import { makeTestPool } from '../helpers/test-pool.js';

function fakeClient(
    handler: (url: string, init: RequestInit) => Response | Promise<Response>,
): ClientPool {
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

describe('bind_source — bindingLevel parameter', () => {
    it('passes bindingLevel through to the REST body when set', async () => {
        let seenBody: string | undefined;
        const tools = buildSourcesTools(
            fakeClient((_url, init) => {
                seenBody = init.body as string | undefined;
                return jsonResponse({ etag: 'e1', binding_level: 'item' });
            }),
        );

        await findTool(tools, 'yootheme_builder_element_bind_source').handler({
            template_id: 'tpl',
            element_path: '/templates/tpl/layout/children/0',
            source_name: 'wp_posts',
            etag: '"e0"',
            bindingLevel: 'item',
        });

        const body = JSON.parse(seenBody ?? '{}') as Record<string, unknown>;
        expect(body.bindingLevel).toBe('item');
        expect(body.source_name).toBe('wp_posts');
    });

    it('omits bindingLevel from the body when not set (default auto on server)', async () => {
        let seenBody: string | undefined;
        const tools = buildSourcesTools(
            fakeClient((_url, init) => {
                seenBody = init.body as string | undefined;
                return jsonResponse({ etag: 'e1' });
            }),
        );

        await findTool(tools, 'yootheme_builder_element_bind_source').handler({
            template_id: 'tpl',
            element_path: '/0',
            source_name: 'wp_posts',
            etag: '"e0"',
        });

        const body = JSON.parse(seenBody ?? '{}') as Record<string, unknown>;
        expect(body).not.toHaveProperty('bindingLevel');
    });

    it('passes field_mappings through to the REST body', async () => {
        let seenBody: string | undefined;
        const tools = buildSourcesTools(
            fakeClient((_url, init) => {
                seenBody = init.body as string | undefined;
                return jsonResponse({ etag: 'e1' });
            }),
        );

        await findTool(tools, 'yootheme_builder_element_bind_source').handler({
            template_id: 'tpl',
            element_path: '/0',
            source_name: 'wp_posts',
            etag: '"e0"',
            field_mappings: { title: 'post_title' },
            bindingLevel: 'item',
        });

        const body = JSON.parse(seenBody ?? '{}') as Record<string, unknown>;
        expect(body.field_mappings).toEqual({ title: 'post_title' });
        expect(body.bindingLevel).toBe('item');
    });
});
