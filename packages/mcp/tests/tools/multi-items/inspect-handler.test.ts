/**
 * inspect_multi_items_binding handler — wraps the
 * GET /pages/{id}/elements/{path}/multi-items/inspect REST endpoint.
 *
 * @license MIT
 */

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import { describe, expect, it, vi } from 'vitest';

import type { ClientPool } from '../../../src/sites/client-pool.js';
import { buildMultiItemsTools } from '../../../src/tools/multi-items/index.js';
import { makeTestPool, stripSitePrefix } from '../../helpers/test-pool.js';

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

function findTool(tools: ReturnType<typeof buildMultiItemsTools>, name: string) {
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

describe('inspect_multi_items_binding', () => {
    it('GETs the multi-items/inspect endpoint', async () => {
        let seenUrl: string | undefined;
        const tools = buildMultiItemsTools(
            fakeClient((url) => {
                seenUrl = url;
                return jsonResponse({
                    template_id: 'tpl',
                    report: {
                        element_path: '/templates/tpl/layout/children/0',
                        element_type: 'grid_item',
                        is_container: false,
                        is_item: true,
                        container_type: 'grid',
                        item_type: 'grid_item',
                        current_binding_level: 'item',
                        has_implode_directives: false,
                    },
                    etag: '"e1"',
                });
            }),
        );

        await findTool(tools, 'yootheme_builder_inspect_multi_items_binding').handler({
            template_id: 'tpl',
            element_path: '/templates/tpl/layout/children/0',
        });
        expect(seenUrl).toContain('/multi-items/inspect');
    });

    it('surfaces the report verbatim in the structured payload', async () => {
        const tools = buildMultiItemsTools(
            fakeClient(() =>
                jsonResponse({
                    template_id: 'tpl',
                    report: {
                        element_path: '/templates/tpl/layout/children/1',
                        element_type: 'slideshow',
                        is_container: true,
                        is_item: false,
                        container_type: 'slideshow',
                        item_type: 'slideshow_item',
                        current_binding_level: 'container',
                        has_implode_directives: true,
                        warning: 'Binding lives on the container.',
                        recommended_fix: 'Re-bind on the first "slideshow_item" child.',
                    },
                    etag: '"e2"',
                }),
            ),
        );

        const result = await findTool(
            tools,
            'yootheme_builder_inspect_multi_items_binding',
        ).handler({
            template_id: 'tpl',
            element_path: '/templates/tpl/layout/children/1',
        });

        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as {
            report: { current_binding_level: string; warning?: string };
        };
        expect(parsed.report.current_binding_level).toBe('container');
        expect(parsed.report.warning).toContain('container');
    });
});

describe('clean_implode_directives', () => {
    it('POSTs to the multi-items/clean-implode endpoint with ETag', async () => {
        let seenMethod: string | undefined;
        let seenHeaders: Record<string, string> | undefined;
        const tools = buildMultiItemsTools(
            fakeClient((_url, init) => {
                seenMethod = (init.method ?? 'GET').toUpperCase();
                seenHeaders = init.headers as Record<string, string>;
                return jsonResponse({
                    template_id: 'tpl',
                    element_path: '/0',
                    cleaned_count: 2,
                    removed_directives: [
                        { prop_name: 'title', directive: { join: ',' } },
                        { prop_name: 'subtitle', directive: { join: ' ' } },
                    ],
                    new_etag: '"e3"',
                });
            }),
        );

        await findTool(tools, 'yootheme_builder_clean_implode_directives').handler({
            template_id: 'tpl',
            element_path: '/templates/tpl/layout/children/1',
            etag: '"e2"',
        });
        expect(seenMethod).toBe('POST');
        // RestClient sends If-Match header for write methods.
        expect(JSON.stringify(seenHeaders)).toContain('e2');
    });

    it('surfaces removed_directives audit log in the payload', async () => {
        const tools = buildMultiItemsTools(
            fakeClient(() =>
                jsonResponse({
                    template_id: 'tpl',
                    element_path: '/0',
                    cleaned_count: 1,
                    removed_directives: [{ prop_name: 'title', directive: { join: ',' } }],
                    new_etag: '"e3"',
                }),
            ),
        );

        const result = await findTool(
            tools,
            'yootheme_builder_clean_implode_directives',
        ).handler({
            template_id: 'tpl',
            element_path: '/0',
            etag: '"e2"',
        });
        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as {
            cleaned_count: number;
            removed_directives: Array<{ prop_name: string }>;
        };
        expect(parsed.cleaned_count).toBe(1);
        expect(parsed.removed_directives[0]!.prop_name).toBe('title');
    });
});
