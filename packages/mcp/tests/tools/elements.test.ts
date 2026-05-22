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

describe('buildElementsTools — element_list pagination (T2 / N-01)', () => {
    it('forwards limit + surfaces next_cursor from the paginated envelope', async () => {
        let seenUrl: string | undefined;
        const tools = buildElementsTools(
            fakeClient((url) => {
                seenUrl = url;
                return jsonResponse({
                    items: [
                        { path: '/0', element_type: 'section' },
                        { path: '/1', element_type: 'headline' },
                    ],
                    next_cursor: 'o:5',
                    total: 96,
                });
            }),
        );
        const result = await findTool(tools, 'yootheme_builder_element_list').handler({
            template_id: 'tpl',
            limit: 5,
        });
        expect(seenUrl).toContain('limit=5');
        expect(result.structuredContent?.next_cursor).toBe('o:5');
        expect(result.structuredContent?.total).toBe(96);
    });

    it('forwards root_path + depth as query params', async () => {
        let seenUrl: string | undefined;
        const tools = buildElementsTools(
            fakeClient((url) => {
                seenUrl = url;
                return jsonResponse({ items: [], total: 0 });
            }),
        );
        await findTool(tools, 'yootheme_builder_element_list').handler({
            template_id: 'tpl',
            root_path: '/templates/tpl/layout/children/0',
            depth: 2,
        });
        expect(seenUrl).toContain('root_path=');
        expect(seenUrl).toContain('depth=2');
    });

    it('handles the flat (non-paginated) shape with no next_cursor', async () => {
        const tools = buildElementsTools(
            fakeClient(() =>
                jsonResponse({
                    elements: [
                        { path: '/0', element_type: 'section' },
                        { path: '/1', element_type: 'headline' },
                        { path: '/2', element_type: 'image' },
                    ],
                    total: 3,
                }),
            ),
        );
        const result = await findTool(tools, 'yootheme_builder_element_list').handler({
            template_id: 'tpl',
        });
        expect(result.structuredContent?.next_cursor).toBeUndefined();
        expect(result.structuredContent?.total).toBe(3);
    });

    // ─── N-01 (Audit v4) — narrow fields[] projection fits in text ────
    // A 96-node template truncates the compact text table at 2000 chars
    // (~26 of 96 rows visible). When the caller passes a narrow
    // `fields[]` projection, the text table must render those projected
    // (slim) rows in full so a text-only reader sees EVERY node.

    it('renders every node in the text table when a narrow fields[] is given', async () => {
        const manyNodes = Array.from({ length: 96 }, (_, i) => ({
            path: `/${String(i)}`,
            element_type: i % 2 === 0 ? 'section' : 'headline',
        }));
        const tools = buildElementsTools(
            fakeClient(() => jsonResponse({ elements: manyNodes, total: 96 })),
        );
        const result = await findTool(tools, 'yootheme_builder_element_list').handler({
            template_id: 'tpl',
            fields: ['rel_path', 'element_type'],
        });
        const text = result.content[0]?.text ?? '';
        // The last node's relative path must be present — proves the
        // whole list survived (no 2000-char compact truncation).
        expect(text).toContain('/95');
        expect(text).not.toMatch(/TRUNCATED/i);
        // structuredContent still carries all 96 projected rows.
        expect(result.structuredContent?.total).toBe(96);
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
