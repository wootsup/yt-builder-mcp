/**
 * End-to-end tests for sparse-fields wiring on the 4 migrated read tools
 * plus the `flat: true` switch on `page_get_layout`.
 *
 * Per Design §3.5 + Plan G.3.5 — each tool:
 *   - returns the default-fields echo when called without `fields[]`,
 *   - projects to the requested fields when `fields[]` is passed,
 *   - exposes `projected_fields: string[]` on structuredContent.
 *
 * Per Plan G.3.5 (flat: true tests on page_get_layout):
 *   - flat:false (default) keeps the nested response unchanged,
 *   - flat:true returns `{elements: [...], etag}` with depth-first ordering.
 *
 * @license MIT
 */

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import { describe, expect, it, vi } from 'vitest';

import type { ClientPool } from '../../src/sites/client-pool.js';
import { buildElementsTools } from '../../src/tools/elements.js';
import { buildInspectionTools } from '../../src/tools/inspection.js';
import { buildPagesTools } from '../../src/tools/pages.js';
import { buildSourcesTools } from '../../src/tools/sources.js';
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

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

// ─── pages_list ──────────────────────────────────────────────────────

describe('yootheme_builder_pages_list — sparse fields', () => {
    it('echoes default fields when omitted', async () => {
        const tools = buildPagesTools(
            fakeClient(() =>
                jsonResponse({
                    pages: [{ id: 'home', label: 'Home', type: 'page', elements_count: 5, modified_at: 't0', extra: 'gone' }],
                    etag: 'e0',
                }),
            ),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_pages_list')!;
        const result = await tool.handler({});
        expect(result.structuredContent?.projected_fields).toEqual([
            'id',
            'label',
            'type',
            'elements_count',
            'modified_at',
        ]);
        // Default-set: items pass through unchanged
        const items = result.structuredContent?.items as Record<string, unknown>[];
        expect(items[0]).toMatchObject({ id: 'home', label: 'Home', type: 'page' });
    });

    it('projects to requested fields when provided', async () => {
        const tools = buildPagesTools(
            fakeClient(() =>
                jsonResponse({
                    pages: [{ id: 'home', label: 'Home', type: 'page', elements_count: 5 }],
                    etag: 'e0',
                }),
            ),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_pages_list')!;
        const result = await tool.handler({ fields: ['id', 'label'] });
        const items = result.structuredContent?.items as Record<string, unknown>[];
        expect(items[0]).toEqual({ id: 'home', label: 'Home' });
        expect(result.structuredContent?.projected_fields).toEqual(['id', 'label']);
    });
});

// ─── element_list ────────────────────────────────────────────────────

describe('yootheme_builder_element_list — sparse fields', () => {
    it('echoes default fields when omitted', async () => {
        const tools = buildElementsTools(
            fakeClient(() =>
                jsonResponse({
                    elements: [{ path: '/0', element_type: 'section', label: 'Hero', has_binding: false }],
                }),
            ),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_list')!;
        const result = await tool.handler({ template_id: 'home' });
        expect(result.structuredContent?.projected_fields).toEqual([
            'path',
            'element_type',
            'label',
        ]);
    });

    it('drops fields not on the whitelist', async () => {
        const tools = buildElementsTools(
            fakeClient(() =>
                jsonResponse({
                    elements: [{ path: '/0', element_type: 'section', label: 'Hero', has_binding: false }],
                }),
            ),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_list')!;
        const result = await tool.handler({ template_id: 'home', fields: ['path', 'element_type'] });
        const items = result.structuredContent?.items as Record<string, unknown>[];
        expect(items[0]).toEqual({ path: '/0', element_type: 'section' });
        expect(result.structuredContent?.projected_fields).toEqual(['path', 'element_type']);
    });
});

// ─── sources_list ────────────────────────────────────────────────────

describe('yootheme_builder_sources_list — sparse fields', () => {
    it('echoes default fields when omitted', async () => {
        const tools = buildSourcesTools(
            fakeClient(() =>
                jsonResponse({
                    sources: { apimapper: [{ name: 'wp_posts', label: 'Posts', kind: 'wp', extra: 'gone' }] },
                }),
            ),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_sources_list')!;
        const result = await tool.handler({});
        expect(result.structuredContent?.projected_fields).toEqual([
            'name',
            'label',
            'origin',
            'kind',
        ]);
    });

    it('projects to requested fields when provided', async () => {
        const tools = buildSourcesTools(
            fakeClient(() =>
                jsonResponse({
                    sources: { apimapper: [{ name: 'wp_posts', label: 'Posts', kind: 'wp' }] },
                }),
            ),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_sources_list')!;
        const result = await tool.handler({ fields: ['name', 'origin'] });
        const items = result.structuredContent?.items as Record<string, unknown>[];
        expect(items[0]).toEqual({ name: 'wp_posts', origin: 'apimapper' });
        expect(result.structuredContent?.projected_fields).toEqual(['name', 'origin']);
    });
});

// ─── element_types_list ──────────────────────────────────────────────

describe('yootheme_builder_element_types_list — sparse fields', () => {
    it('echoes default fields when omitted', async () => {
        const tools = buildInspectionTools(
            fakeClient(() =>
                jsonResponse({
                    element_types: [{ name: 'headline', label: 'Headline', has_children_support: false }],
                }),
            ),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_types_list')!;
        const result = await tool.handler({});
        expect(result.structuredContent?.projected_fields).toEqual([
            'name',
            'label',
            'origin',
            'has_children_support',
        ]);
    });

    it('projects to requested fields when provided', async () => {
        const tools = buildInspectionTools(
            fakeClient(() =>
                jsonResponse({
                    element_types: [{ name: 'headline', label: 'Headline' }],
                }),
            ),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_types_list')!;
        const result = await tool.handler({ fields: ['name', 'label'] });
        const items = result.structuredContent?.items as Record<string, unknown>[];
        expect(items[0]).toEqual({ name: 'headline', label: 'Headline' });
        expect(result.structuredContent?.projected_fields).toEqual(['name', 'label']);
    });
});

// ─── page_get_layout flat: true ──────────────────────────────────────

describe('yootheme_builder_page_get_layout — flat:true switch', () => {
    it('returns nested layout shape when flat is omitted (default false)', async () => {
        const tools = buildPagesTools(
            fakeClient(() =>
                jsonResponse({
                    layout: { '0': { type: 'section', children: [{ type: 'text' }] } },
                    etag: 'e0',
                }),
            ),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_page_get_layout')!;
        const result = await tool.handler({ template_id: 'home' });
        // No structuredContent (legacy jsonResult), but parsed text should
        // include the nested layout.
        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as Record<string, unknown>;
        expect(parsed.layout).toBeDefined();
        expect(parsed.elements).toBeUndefined();
    });

    it('returns flat {elements,etag} shape when flat:true', async () => {
        const tools = buildPagesTools(
            fakeClient(() =>
                jsonResponse({
                    layout: { '0': { type: 'section', children: [{ type: 'text' }] } },
                    etag: 'e0',
                }),
            ),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_page_get_layout')!;
        const result = await tool.handler({ template_id: 'home', flat: true });
        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as Record<string, unknown>;
        const elements = parsed.elements as Array<Record<string, unknown>>;
        expect(elements).toHaveLength(2);
        expect(elements[0]?.path).toBe('/layout/0');
        expect(elements[1]?.path).toBe('/layout/0/children/0');
        expect(parsed.etag).toBe('e0');
    });

    it('flat:true + fields[] projects each element', async () => {
        const tools = buildPagesTools(
            fakeClient(() =>
                jsonResponse({
                    layout: { '0': { type: 'section', children: [{ type: 'text' }] } },
                    etag: 'e0',
                }),
            ),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_page_get_layout')!;
        const result = await tool.handler({
            template_id: 'home',
            flat: true,
            fields: ['path', 'element_type'],
        });
        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as Record<string, unknown>;
        const elements = parsed.elements as Array<Record<string, unknown>>;
        expect(elements[0]).toEqual({ path: '/layout/0', element_type: 'section' });
        expect(parsed.projected_fields).toEqual(['path', 'element_type']);
    });
});
