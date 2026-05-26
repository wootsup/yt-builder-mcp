/**
 * Regression tests for F-004 / F-005 projection-feedback wiring.
 *
 * When the caller passes a `fields[]` whitelist that contains keys
 * which do not exist on the item shape (the audit-script case:
 * `fields: ["template_id","name"]` against `pages_list`), the
 * structuredContent leg MUST emit:
 *
 *   - `available_fields: string[]` — vocabulary discovered on items
 *   - `unknown_fields:   string[]` — caller-requested keys that did
 *                                    NOT appear in any item
 *
 * AND the text-leg header MUST carry an `(unknown fields ignored: ...)`
 * note so cold agents see the problem on first read.
 *
 * Default-compact callers (no `fields[]`) MUST NOT receive either key
 * — that preserves the legacy contract and keeps the response small.
 *
 * Covers all four read tools wired with projectionFeedback:
 *  - pages_list, element_list, sources_list, element_types_list.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import type { ClientPool } from '../../src/sites/client-pool.js';
import { buildElementsTools } from '../../src/tools/elements.js';
import { buildInspectionTools } from '../../src/tools/inspection.js';
import { buildPagesTools } from '../../src/tools/pages.js';
import { buildSourcesTools } from '../../src/tools/sources.js';
import { makeTestPool } from '../helpers/test-pool.js';

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

function getStructured(result: { structuredContent?: Record<string, unknown> }): Record<string, unknown> {
    expect(result.structuredContent).toBeDefined();
    return result.structuredContent ?? {};
}

function firstTextContent(result: { content: Array<{ text?: string }> }): string {
    const text = result.content[0]?.text;
    expect(typeof text).toBe('string');
    return typeof text === 'string' ? text : '';
}

// ─── pages_list ──────────────────────────────────────────────────────

describe('pages_list — projection feedback', () => {
    const PAGES_BODY = {
        pages: [
            { id: 'home', name: 'Home', type: 'page', elements_count: 5, modified_at: 't0' },
        ],
        etag: '"e0"',
    };

    it('emits empty unknown_fields when all requested keys are valid', async () => {
        const tools = buildPagesTools(fakeClient(() => jsonResponse(PAGES_BODY)));
        const tool = tools.find((t) => t.name === 'yootheme_builder_pages_list')!;
        const result = await tool.handler({ fields: ['id', 'label'] });
        const sc = getStructured(result);
        expect(sc.unknown_fields).toEqual([]);
        // available_fields is the union of top-level keys across items.
        // mapPageRow emits id/label/type/elements_count/modified_at + 3
        // frontend_url* fields — at minimum id and label MUST be present.
        const available = sc.available_fields as string[];
        expect(Array.isArray(available)).toBe(true);
        expect(available).toContain('id');
        expect(available).toContain('label');
    });

    it('flags unknown_fields when caller passes invalid keys (audit-script case)', async () => {
        const tools = buildPagesTools(fakeClient(() => jsonResponse(PAGES_BODY)));
        const tool = tools.find((t) => t.name === 'yootheme_builder_pages_list')!;
        // The exact audit-script case: `template_id` and `name` are NOT
        // top-level fields on a pages_list row (the id field is `id`, the
        // human-readable is `label`). Both must surface in unknown_fields.
        const result = await tool.handler({ fields: ['template_id', 'name'] });
        const sc = getStructured(result);
        expect(sc.unknown_fields).toEqual(['template_id', 'name']);
        const available = sc.available_fields as string[];
        expect(available.length).toBeGreaterThan(0);
        // Text-leg header MUST carry the deprecation/warning note so cold
        // agents see the problem without parsing structuredContent.
        const text = firstTextContent(result);
        expect(text).toMatch(/unknown fields ignored: template_id, name/);
    });

    it('omits feedback keys entirely when no fields[] passed (legacy contract)', async () => {
        const tools = buildPagesTools(fakeClient(() => jsonResponse(PAGES_BODY)));
        const tool = tools.find((t) => t.name === 'yootheme_builder_pages_list')!;
        const result = await tool.handler({});
        const sc = getStructured(result);
        expect(sc.unknown_fields).toBeUndefined();
        expect(sc.available_fields).toBeUndefined();
        // Header has no warning note either.
        const text = firstTextContent(result);
        expect(text).not.toMatch(/unknown fields ignored/);
    });

    it('treats dotted-path requests as known when the top-level key exists', async () => {
        // The toolkit `pickFields` walks dotted paths (`props.title`).
        // projectionFeedback agrees: a leaf-path counts as "known" iff
        // its top-level segment matches a key on any item. `id` is a
        // real top-level key on mapPageRow output, so a dotted-path
        // probe whose top segment is `id` MUST come back as known.
        const tools = buildPagesTools(fakeClient(() => jsonResponse(PAGES_BODY)));
        const tool = tools.find((t) => t.name === 'yootheme_builder_pages_list')!;
        const result = await tool.handler({ fields: ['id.nested', 'label'] });
        const sc = getStructured(result);
        expect(sc.unknown_fields).toEqual([]);
    });
});

// ─── element_list ────────────────────────────────────────────────────

describe('element_list — projection feedback (F-005 case)', () => {
    const ELEMENTS_BODY = {
        elements: [
            {
                path: '/templates/I99YS8Ii/layout/children/0',
                element_type: 'section',
                label: 'Hero',
                has_binding: false,
            },
        ],
    };

    it('flags `type` as unknown (the F-005 case — column is `element_type`)', async () => {
        const tools = buildElementsTools(fakeClient(() => jsonResponse(ELEMENTS_BODY)));
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_list')!;
        // `type` is the audit case — projection column is `element_type`,
        // not `type`. `rel_path` IS a real column on element_list (F-06)
        // so it must come back as known.
        const result = await tool.handler({
            template_id: 'I99YS8Ii',
            fields: ['type', 'rel_path'],
        });
        const sc = getStructured(result);
        expect(sc.unknown_fields).toEqual(['type']);
        expect(sc.available_fields as string[]).toContain('rel_path');
        // Text-leg header MUST carry the warning note.
        const text = firstTextContent(result);
        expect(text).toMatch(/unknown fields ignored: type/);
    });

    it('omits feedback keys when no fields[] passed', async () => {
        const tools = buildElementsTools(fakeClient(() => jsonResponse(ELEMENTS_BODY)));
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_list')!;
        const result = await tool.handler({ template_id: 'I99YS8Ii' });
        const sc = getStructured(result);
        expect(sc.unknown_fields).toBeUndefined();
        expect(sc.available_fields).toBeUndefined();
    });
});

// ─── sources_list ────────────────────────────────────────────────────

describe('sources_list — projection feedback', () => {
    const SOURCES_BODY = {
        sources: { apimapper: [{ name: 'wp_posts', label: 'Posts', kind: 'wp' }] },
    };

    it('flags unknown + known mix correctly', async () => {
        const tools = buildSourcesTools(fakeClient(() => jsonResponse(SOURCES_BODY)));
        const tool = tools.find((t) => t.name === 'yootheme_builder_sources_list')!;
        const result = await tool.handler({ fields: ['name', 'nonexistent_field'] });
        const sc = getStructured(result);
        expect(sc.unknown_fields).toEqual(['nonexistent_field']);
        expect(sc.available_fields as string[]).toContain('name');
    });

    it('omits feedback when no fields[] passed', async () => {
        const tools = buildSourcesTools(fakeClient(() => jsonResponse(SOURCES_BODY)));
        const tool = tools.find((t) => t.name === 'yootheme_builder_sources_list')!;
        const result = await tool.handler({});
        const sc = getStructured(result);
        expect(sc.unknown_fields).toBeUndefined();
        expect(sc.available_fields).toBeUndefined();
    });
});

// ─── element_types_list ──────────────────────────────────────────────

describe('element_types_list — projection feedback', () => {
    const TYPES_BODY = {
        element_types: [
            { name: 'headline', label: 'Headline', has_children_support: false },
        ],
    };

    it('flags unknown_fields and surfaces available_fields', async () => {
        const tools = buildInspectionTools(fakeClient(() => jsonResponse(TYPES_BODY)));
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_types_list')!;
        const result = await tool.handler({ fields: ['name', 'totally_made_up'] });
        const sc = getStructured(result);
        expect(sc.unknown_fields).toEqual(['totally_made_up']);
        expect(sc.available_fields as string[]).toContain('name');
    });

    it('omits feedback when no fields[] passed', async () => {
        const tools = buildInspectionTools(fakeClient(() => jsonResponse(TYPES_BODY)));
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_types_list')!;
        const result = await tool.handler({});
        const sc = getStructured(result);
        expect(sc.unknown_fields).toBeUndefined();
        expect(sc.available_fields).toBeUndefined();
    });
});
