/**
 * Tests for the page tools — URL composition + error mapping.
 *
 * W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import type { ClientPool } from '../../src/sites/client-pool.js';
import { buildPagesTools } from '../../src/tools/pages.js';
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

function findTool(tools: ReturnType<typeof buildPagesTools>, name: string) {
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

describe('buildPagesTools', () => {
    it('pages_list calls GET /pages and projects items + etag into structuredContent', async () => {
        const seen: string[] = [];
        const tools = buildPagesTools(
            fakeClient((url) => {
                seen.push(url);
                return jsonResponse({
                    pages: [
                        { id: 'home', label: 'Home', type: 'page', elements_count: 5 },
                    ],
                    etag: 'e0',
                });
            }),
        );
        const result = await findTool(tools, 'yootheme_builder_pages_list').handler({});
        expect(seen[0]).toContain('/wp-json/yt-builder-mcp/v1/pages');
        expect(result.structuredContent).toMatchObject({
            items: [{ id: 'home', label: 'Home', type: 'page', elements_count: 5 }],
            total: 1,
            etag: 'e0',
        });
    });

    it('page_get_layout encodes template_id into the URL', async () => {
        const seen: string[] = [];
        const tools = buildPagesTools(
            fakeClient((url) => {
                seen.push(url);
                return jsonResponse({ layout: {}, etag: 'e0' });
            }),
        );
        await findTool(tools, 'yootheme_builder_page_get_layout').handler({
            template_id: 'post-archive',
        });
        expect(seen[0]).toContain('/pages/post-archive/layout');
    });

    // ─── F-208 (Audit 2026-05-26) — flat mode rel_path consistency ──
    // REST `/pages/{id}/layout` returns the WHOLE template object as
    // `data.layout` — that object has its OWN `layout` key carrying
    // the actual node tree. Naively calling flattenLayout(data.layout)
    // emits paths like `/layout/layout/children/0` (double prefix),
    // inconsistent with element_list (`/children/0`). Unwrap the
    // template envelope so flat-mode emits the SAME rel_path shape
    // element_list does.

    it('page_get_layout(flat:true) emits rel_path stripped of /layout/layout double-prefix', async () => {
        const tools = buildPagesTools(
            fakeClient(() =>
                jsonResponse({
                    template_id: 'home',
                    // The PHP returns the WHOLE template object here:
                    // {layout: <node-tree>, name, ...}. The TS handler
                    // must descend one extra hop.
                    layout: {
                        name: 'home',
                        layout: {
                            '0': {
                                type: 'section',
                                children: [{ type: 'text' }],
                            },
                        },
                    },
                    etag: 'e0',
                    layout_root_pointer: '/templates/home/layout',
                }),
            ),
        );
        const result = await findTool(tools, 'yootheme_builder_page_get_layout').handler({
            template_id: 'home',
            flat: true,
        });
        const body = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as {
            elements?: Array<{ path?: string; rel_path?: string }>;
        };
        expect(Array.isArray(body.elements)).toBe(true);
        const paths = (body.elements ?? []).map((e) => e.rel_path);
        // No element may carry the double-prefix /layout/layout/...
        for (const p of paths) {
            expect(p).not.toMatch(/\/layout\/layout/);
        }
        // rel_path must match element_list's shape (no `/layout` prefix).
        expect(paths).toContain('/0');
        expect(paths).toContain('/0/children/0');
    });

    it('page_get_layout(flat:true) tolerates the legacy un-wrapped layout payload', async () => {
        // Back-compat path: if REST ever returns the plain layout tree
        // (no template-envelope), the handler must still produce clean
        // rel_paths. flattenLayout already handles this shape directly.
        const tools = buildPagesTools(
            fakeClient(() =>
                jsonResponse({
                    layout: {
                        '0': { type: 'section', children: [{ type: 'text' }] },
                    },
                    etag: 'e0',
                }),
            ),
        );
        const result = await findTool(tools, 'yootheme_builder_page_get_layout').handler({
            template_id: 'home',
            flat: true,
        });
        const body = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as {
            elements?: Array<{ rel_path?: string }>;
        };
        const paths = (body.elements ?? []).map((e) => e.rel_path);
        expect(paths).toContain('/0');
        expect(paths).toContain('/0/children/0');
    });

    it('page_save sends If-Match when etag is provided', async () => {
        let seenIfMatch: string | null = null;
        const tools = buildPagesTools(
            fakeClient((_url, init) => {
                seenIfMatch = new Headers(init.headers).get('If-Match');
                return jsonResponse({ saved: true, etag: 'e1' });
            }),
        );
        await findTool(tools, 'yootheme_builder_page_save').handler({
            template_id: 'default',
            etag: '"e0"',
        });
        expect(seenIfMatch).toBe('"e0"');
    });

    it('returns a structured error on REST failure', async () => {
        const tools = buildPagesTools(
            fakeClient(() => jsonResponse({ code: 'x', message: 'nope' }, 404)),
        );
        const result = await findTool(tools, 'yootheme_builder_page_get_layout').handler({
            template_id: 'missing',
        });
        expect(result.isError).toBe(true);
        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as Record<string, unknown>;
        expect(parsed.error).toBe('nope');
        expect(parsed.status).toBe(404);
        expect(parsed.context).toMatchObject({ template_id: 'missing' });
        expect(parsed.hint).toBeTypeOf('string');
    });

    // ─── T9 (Audit-v3 B.5) — template_summary ─────────────────────────

    it('template_summary GETs /pages/{id}/summary and passes through the body', async () => {
        const seen: string[] = [];
        const tools = buildPagesTools(
            fakeClient((url) => {
                seen.push(url);
                return jsonResponse({
                    template_id: 'home',
                    counts_by_type: { section: 2, headline: 1 },
                    bound_count: 1,
                    max_depth: 3,
                    total: 4,
                    named_sections: [{ path: '/templates/home/layout/children/0', name: 'Hero' }],
                    etag: 'e9',
                });
            }),
        );
        const result = await findTool(tools, 'yootheme_builder_template_summary').handler({
            template_id: 'home',
        });
        expect(seen[0]).toContain('/pages/home/summary');
        const body = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as Record<string, unknown>;
        expect(body.counts_by_type).toMatchObject({ section: 2, headline: 1 });
        expect(body.bound_count).toBe(1);
        expect(body.max_depth).toBe(3);
        expect(body.named_sections).toHaveLength(1);
    });

    it('template_summary returns a structured error when the template is missing', async () => {
        const tools = buildPagesTools(
            fakeClient(() => jsonResponse({ code: 'x', message: 'not found' }, 404)),
        );
        const result = await findTool(tools, 'yootheme_builder_template_summary').handler({
            template_id: 'ghost',
        });
        expect(result.isError).toBe(true);
        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as Record<string, unknown>;
        expect(parsed.status).toBe(404);
        expect(parsed.context).toMatchObject({ template_id: 'ghost' });
    });
});
