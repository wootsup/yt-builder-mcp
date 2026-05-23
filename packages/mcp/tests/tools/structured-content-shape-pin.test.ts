/**
 * structuredContent shape-pin — Wave G.8 §E.
 *
 * Every tool that declares an `outputSchema` (Wave G.2) MUST produce a
 * `structuredContent` payload that passes `outputSchema.parse()` on the
 * success path. This guards against a regression where a tool author
 * tightens the schema but forgets to update the handler (or vice versa).
 *
 * Coverage: 13 tools (health × 2, pages × 3, elements × 2, sources × 2,
 * inspection × 2, multi-items × 2). Write-tools without success-shaped
 * output schemas (page_save, page_publish, element_add, …) are excluded —
 * those return free-form `jsonResult`. `clean_implode_directives` IS
 * included: it is a write tool, but it declares an outputSchema and so
 * MUST emit structuredContent (audit v4 N-03).
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';
import { z } from 'zod';

import { RestClient } from '../../src/client.js';
import { buildElementsTools } from '../../src/tools/elements.js';
import { buildHealthTools } from '../../src/tools/health.js';
import { buildInspectionTools } from '../../src/tools/inspection.js';
import { buildMultiItemsTools } from '../../src/tools/multi-items/index.js';
import { buildPagesTools } from '../../src/tools/pages.js';
import { buildSourcesTools } from '../../src/tools/sources.js';
import type { AnyToolDefinition } from '../../src/tools/tool-builder.js';

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

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

interface ShapePinCase {
    readonly toolName: string;
    readonly args: Record<string, unknown>;
    readonly responseBody: unknown;
    readonly group: 'health' | 'pages' | 'elements' | 'sources' | 'inspection' | 'multi-items';
}

const HEALTH_BODY = {
    plugin_version: '0.1.0-alpha.1',
    yootheme_version: '4.5.33',
    wp_version: '6.8',
    php_version: '8.3',
    storage_type: 'wp_option',
    storage_target: 'yootheme',
    yootheme_loaded: true,
    available_endpoints: ['/health', '/pages'],
    // 1.0.1 — site_url / home_url added to authenticated health payload.
    site_url: 'https://example.test/wordpress',
    home_url: 'https://example.test',
};

const CASES: readonly ShapePinCase[] = [
    {
        toolName: 'yootheme_builder_health',
        args: {},
        responseBody: HEALTH_BODY,
        group: 'health',
    },
    {
        toolName: 'yootheme_builder_diagnose',
        args: {},
        responseBody: HEALTH_BODY,
        group: 'health',
    },
    {
        toolName: 'yootheme_builder_pages_list',
        args: {},
        responseBody: {
            pages: [{ id: 'home', label: 'Home', type: 'page', elements_count: 5 }],
            etag: '"e0"',
        },
        group: 'pages',
    },
    {
        toolName: 'yootheme_builder_page_get_schema',
        args: { template_id: 'home' },
        responseBody: {
            nodes: [{ path: '/0', element_type: 'section' }],
            etag: '"e0"',
        },
        group: 'pages',
    },
    {
        toolName: 'yootheme_builder_get_etag',
        args: {},
        responseBody: { etag: '"e123"' },
        group: 'pages',
    },
    {
        toolName: 'yootheme_builder_element_list',
        args: { template_id: 'home' },
        responseBody: {
            elements: [
                { path: '/0', element_type: 'section', label: 'Hero' },
            ],
        },
        group: 'elements',
    },
    {
        toolName: 'yootheme_builder_element_get',
        args: { template_id: 'home', element_path: '/0' },
        responseBody: {
            type: 'section',
            label: 'Hero',
            props: { background: 'primary' },
            children: [],
        },
        group: 'elements',
    },
    {
        toolName: 'yootheme_builder_sources_list',
        args: {},
        responseBody: {
            sources: [
                { name: 'wp_posts', label: 'Posts', origin: 'wordpress' },
            ],
        },
        group: 'sources',
    },
    {
        toolName: 'yootheme_builder_element_get_binding',
        args: { template_id: 'home', element_path: '/0' },
        responseBody: { binding: { source: 'wp_posts' } },
        group: 'sources',
    },
    {
        toolName: 'yootheme_builder_element_types_list',
        args: {},
        responseBody: {
            types: [
                { name: 'headline', label: 'Headline', origin: 'core' },
            ],
        },
        group: 'inspection',
    },
    {
        toolName: 'yootheme_builder_element_type_get_schema',
        args: { element_type: 'headline' },
        // Real REST shape: the schema is nested under `schema`, and
        // `fields` is a LIST of {name,type,label?} descriptors (audit v4 F-05).
        responseBody: {
            type_name: 'headline',
            schema: {
                name: 'headline',
                label: 'Headline',
                origin: 'core',
                has_children: false,
                fields: [
                    { name: 'content', type: 'editor', label: 'Content' },
                    { name: 'link', type: 'link', label: 'Link' },
                ],
            },
        },
        group: 'inspection',
    },
    {
        toolName: 'yootheme_builder_inspect_multi_items_binding',
        args: { template_id: 'home', element_path: '/0' },
        responseBody: {
            template_id: 'home',
            report: {
                element_path: '/0',
                element_type: 'grid',
                is_container: true,
                is_item: false,
                container_type: 'grid',
                item_type: 'grid_item',
                current_binding_level: 'none',
                has_implode_directives: false,
            },
            etag: '"e0"',
        },
        group: 'multi-items',
    },
    {
        toolName: 'yootheme_builder_clean_implode_directives',
        args: { template_id: 'home', element_path: '/0', etag: '"e0"' },
        responseBody: {
            template_id: 'home',
            element_path: '/0',
            cleaned_count: 0,
            removed_directives: [],
            new_etag: '"e1"',
        },
        group: 'multi-items',
    },
];

function buildToolsByGroup(group: ShapePinCase['group'], client: RestClient): readonly AnyToolDefinition[] {
    switch (group) {
        case 'health':
            return buildHealthTools(client);
        case 'pages':
            return buildPagesTools(client);
        case 'elements':
            return buildElementsTools(client);
        case 'sources':
            return buildSourcesTools(client);
        case 'inspection':
            return buildInspectionTools(client);
        case 'multi-items':
            return buildMultiItemsTools(client);
    }
}

describe('structuredContent shape-pin — every outputSchema-declaring tool', () => {
    for (const c of CASES) {
        it(`${c.toolName} produces structuredContent that passes outputSchema.parse`, async () => {
            const client = fakeClient(() => jsonResponse(c.responseBody));
            const tools = buildToolsByGroup(c.group, client);
            const t = tools.find((x) => x.name === c.toolName);
            expect(t, `tool ${c.toolName} not built`).toBeDefined();
            if (!t) return;
            expect(t.outputSchema, `tool ${c.toolName} missing outputSchema (Wave G.2)`).toBeDefined();
            const handler = t.handler as (a: Record<string, unknown>) => Promise<{
                structuredContent?: Record<string, unknown>;
                isError?: boolean;
            }>;
            const result = await handler(c.args);
            expect(result.isError, `tool ${c.toolName} should succeed but returned isError`).not.toBe(true);
            expect(result.structuredContent, `tool ${c.toolName} missing structuredContent`).toBeDefined();
            // outputSchema is a z.ZodTypeAny; safeParse should succeed.
            const schema = t.outputSchema as z.ZodTypeAny;
            const parsed = schema.safeParse(result.structuredContent);
            if (!parsed.success) {
                throw new Error(
                    `tool ${c.toolName} structuredContent failed outputSchema:\n${JSON.stringify(parsed.error.issues, null, 2)}\n` +
                    `payload: ${JSON.stringify(result.structuredContent, null, 2)}`,
                );
            }
            expect(parsed.success).toBe(true);
        });
    }

    it('exactly 13 tools are pinned (G.2 read surface + multi-items)', () => {
        expect(CASES.length).toBe(13);
    });
});
