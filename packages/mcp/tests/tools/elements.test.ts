/**
 * Tests for element tools — focus on the confirm-guard on destructive ops
 * and the verb dispatch for write endpoints.
 *
 * @license MIT
 */

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import { describe, expect, it, vi } from 'vitest';

import type { ClientPool } from '../../src/sites/client-pool.js';
import { buildElementsTools } from '../../src/tools/elements.js';
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
        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as Record<string, unknown>;
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

    // ─── F-205 (Audit 2026-05-26) — invalid cursor → structured error ──
    // Previously the handler silently forwarded any cursor string to the
    // REST layer; an invalid token (`garbage`) was decoded to `null` on
    // the PHP side and the handler returned page 1 with no signal.
    // Cold-agents iterating with a typo'd cursor would loop. Shape-
    // validate up-front and return a structured 400 instead.

    it('element_list rejects a malformed cursor with a structured 400 + hint', async () => {
        let restCalled = false;
        const tools = buildElementsTools(
            fakeClient(() => {
                restCalled = true;
                return jsonResponse({ items: [], total: 0 });
            }),
        );
        const result = await findTool(tools, 'yootheme_builder_element_list').handler({
            template_id: 'tpl',
            cursor: 'garbage',
        });
        expect(restCalled).toBe(false);
        expect(result.isError).toBe(true);
        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as Record<string, unknown>;
        expect(parsed.error).toMatch(/cursor/i);
        expect(parsed.context).toMatchObject({ cursor: 'garbage' });
        expect(parsed.hint).toMatch(/next_cursor/);
    });

    it('element_list accepts a well-formed cursor (raw o:<digits>)', async () => {
        let restCalled = false;
        const tools = buildElementsTools(
            fakeClient(() => {
                restCalled = true;
                return jsonResponse({ items: [], total: 0 });
            }),
        );
        const result = await findTool(tools, 'yootheme_builder_element_list').handler({
            template_id: 'tpl',
            cursor: 'o:5',
        });
        expect(restCalled).toBe(true);
        expect(result.isError).toBeUndefined();
    });

    it('element_list accepts a well-formed base64url cursor', async () => {
        let restCalled = false;
        const tools = buildElementsTools(
            fakeClient(() => {
                restCalled = true;
                return jsonResponse({ items: [], total: 0 });
            }),
        );
        // base64url('o:5') = 'bzo1'
        const result = await findTool(tools, 'yootheme_builder_element_list').handler({
            template_id: 'tpl',
            cursor: 'bzo1',
        });
        expect(restCalled).toBe(true);
        expect(result.isError).toBeUndefined();
    });

    // ─── F-206 (Audit 2026-05-26) — unknown root_path → structured 404 ──
    // Previously `element_list({ root_path: '/does/not/exist' })` returned
    // `0 elements` silently — no signal whether the template is empty or
    // the pointer is wrong. Distinguish: explicit `root_path` supplied AND
    // 0 hits == error; `root_path` omitted AND 0 hits == legitimate empty.

    it('element_list returns a structured 404 when an explicit root_path matches nothing', async () => {
        const tools = buildElementsTools(
            fakeClient(() => jsonResponse({ items: [], total: 0 })),
        );
        const result = await findTool(tools, 'yootheme_builder_element_list').handler({
            template_id: 'tpl',
            root_path: '/does/not/exist',
        });
        expect(result.isError).toBe(true);
        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as Record<string, unknown>;
        expect(parsed.error).toMatch(/root_path/);
        expect(parsed.context).toMatchObject({
            root_path: '/does/not/exist',
            template_id: 'tpl',
        });
        expect(parsed.hint).toMatch(/element_list/);
        expect(parsed.code).toMatch(/unknown_root_path/);
    });

    it('element_list returns success (empty items) when root_path is OMITTED and template is truly empty', async () => {
        const tools = buildElementsTools(
            fakeClient(() => jsonResponse({ elements: [], total: 0 })),
        );
        const result = await findTool(tools, 'yootheme_builder_element_list').handler({
            template_id: 'empty-tpl',
        });
        expect(result.isError).toBeUndefined();
        expect(result.structuredContent?.total).toBe(0);
        expect((result.structuredContent?.items as unknown[]).length).toBe(0);
    });

    it('element_list does NOT 404 when an explicit root_path matches but the subtree has zero descendants beyond itself', async () => {
        // Distinguish empty-subtree from miss: when REST returns an item
        // that itself matches root_path (or any non-empty list), no 404.
        const tools = buildElementsTools(
            fakeClient(() =>
                jsonResponse({
                    elements: [{ path: '/0/children/3', element_type: 'text' }],
                    total: 1,
                }),
            ),
        );
        const result = await findTool(tools, 'yootheme_builder_element_list').handler({
            template_id: 'tpl',
            root_path: '/0/children/3',
        });
        expect(result.isError).toBeUndefined();
    });

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
