/**
 * Tests for element tools — focus on the confirm-guard on destructive ops
 * and the verb dispatch for write endpoints.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { RestClient } from '../../src/client.js';
import { buildElementsTools } from '../../src/tools/elements.js';

function fakeClient(handler: (url: string, init: RequestInit) => Response | Promise<Response>): RestClient {
    return new RestClient({
        baseUrl: 'https://example.com',
        bearerToken: 't',
        fetch: vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = typeof input === 'string' ? input : input.toString();
            return handler(url, init ?? {});
        }) as unknown as typeof fetch,
    });
}

function findTool(tools: ReturnType<typeof buildElementsTools>, name: string) {
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

describe('buildElementsTools — destructive confirm-guard', () => {
    it('element_delete returns a preview (no REST call) when confirm=false', async () => {
        let restCalled = false;
        const tools = buildElementsTools(
            fakeClient(() => {
                restCalled = true;
                return jsonResponse({});
            }),
        );
        const result = await findTool(tools, 'yootheme_builder_element_delete').handler({
            template_id: 'default',
            element_path: '/0/children/1',
            etag: '"e0"',
            confirm: false,
        });
        expect(restCalled).toBe(false);
        const parsed = JSON.parse(result.content[0]!.text) as Record<string, unknown>;
        expect(parsed.preview).toBe(true);
        expect(parsed.warning).toContain('DESTRUCTIVE');
    });

    it('element_delete actually deletes when confirm=true', async () => {
        let seenMethod: string | undefined;
        const tools = buildElementsTools(
            fakeClient((_url, init) => {
                seenMethod = (init.method ?? 'GET').toUpperCase();
                return jsonResponse({ deleted: true, etag: 'e1' });
            }),
        );
        await findTool(tools, 'yootheme_builder_element_delete').handler({
            template_id: 'default',
            element_path: '/0/children/1',
            etag: '"e0"',
            confirm: true,
        });
        expect(seenMethod).toBe('DELETE');
    });
});

describe('buildElementsTools — write endpoints', () => {
    it('element_add POSTs to /pages/{id}/elements with the body', async () => {
        let seenBody: string | undefined;
        let seenMethod: string | undefined;
        const tools = buildElementsTools(
            fakeClient((_url, init) => {
                seenMethod = (init.method ?? 'GET').toUpperCase();
                seenBody = init.body as string | undefined;
                return jsonResponse({ element_path: '/2', etag: 'e1' });
            }),
        );
        await findTool(tools, 'yootheme_builder_element_add').handler({
            template_id: 'default',
            parent_path: '',
            element_type: 'headline',
            props: { title: 'Hi' },
            etag: '"e0"',
        });
        expect(seenMethod).toBe('POST');
        const body = JSON.parse(seenBody ?? '{}') as Record<string, unknown>;
        expect(body.element_type).toBe('headline');
        expect(body.props).toEqual({ title: 'Hi' });
    });

    it('element_update_settings PUTs to /elements/{path}/settings', async () => {
        let seenUrl: string | undefined;
        let seenMethod: string | undefined;
        const tools = buildElementsTools(
            fakeClient((url, init) => {
                seenUrl = url;
                seenMethod = (init.method ?? 'GET').toUpperCase();
                return jsonResponse({ etag: 'e1' });
            }),
        );
        await findTool(tools, 'yootheme_builder_element_update_settings').handler({
            template_id: 'default',
            element_path: '/0/children/2',
            props: { foo: 'bar' },
            etag: '"e0"',
        });
        expect(seenMethod).toBe('PUT');
        expect(seenUrl).toContain('/elements/0/children/2/settings');
    });

    // T5 / F-12 — merge:true forwards an explicit body flag.
    it('element_update_settings forwards merge:true into the PUT body', async () => {
        let seenBody: string | undefined;
        const tools = buildElementsTools(
            fakeClient((_url, init) => {
                seenBody = init.body as string | undefined;
                return jsonResponse({ etag: 'e1' });
            }),
        );
        await findTool(tools, 'yootheme_builder_element_update_settings').handler({
            template_id: 'default',
            element_path: '/0/children/2',
            props: { source: { props: { title: 'new' } } },
            merge: true,
            etag: '"e0"',
        });
        const body = JSON.parse(seenBody ?? '{}') as Record<string, unknown>;
        expect(body.merge).toBe(true);
        expect(body.props).toEqual({ source: { props: { title: 'new' } } });
    });

    it('element_update_settings omits merge when not specified (back-compat)', async () => {
        let seenBody: string | undefined;
        const tools = buildElementsTools(
            fakeClient((_url, init) => {
                seenBody = init.body as string | undefined;
                return jsonResponse({ etag: 'e1' });
            }),
        );
        await findTool(tools, 'yootheme_builder_element_update_settings').handler({
            template_id: 'default',
            element_path: '/0/children/2',
            props: { foo: 'bar' },
            etag: '"e0"',
        });
        const body = JSON.parse(seenBody ?? '{}') as Record<string, unknown>;
        expect(body).not.toHaveProperty('merge');
    });

    it('element_update_settings input-schema exposes a merge field', () => {
        const tools = buildElementsTools(fakeClient(() => jsonResponse({})));
        const tool = findTool(tools, 'yootheme_builder_element_update_settings');
        expect(tool.inputSchema).toHaveProperty('merge');
    });

    it('element_move POSTs to /move with to_parent_path + to_index', async () => {
        let seenBody: string | undefined;
        const tools = buildElementsTools(
            fakeClient((_url, init) => {
                seenBody = init.body as string | undefined;
                return jsonResponse({ element_path: '/3', etag: 'e1' });
            }),
        );
        await findTool(tools, 'yootheme_builder_element_move').handler({
            template_id: 'default',
            element_path: '/1',
            to_parent_path: '/0',
            to_index: 0,
            etag: '"e0"',
        });
        const body = JSON.parse(seenBody ?? '{}') as Record<string, unknown>;
        expect(body.to_parent_path).toBe('/0');
        expect(body.to_index).toBe(0);
    });
});

describe('buildElementsTools — input schema', () => {
    it('every write tool requires an etag', () => {
        const tools = buildElementsTools(
            fakeClient(() => jsonResponse({})),
        );
        const writes = tools.filter((t) =>
            ['add', 'update_settings', 'move', 'clone', 'delete'].some((v) =>
                t.name.endsWith(`_${v}`),
            ),
        );
        for (const t of writes) {
            expect(t.inputSchema, `${t.name} missing etag schema`).toHaveProperty('etag');
        }
    });
});
