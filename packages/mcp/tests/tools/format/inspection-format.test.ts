/**
 * Inspection format-sidecar tests (G.2.9.format).
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    TYPES_TABLE_COLUMNS,
    TYPES_COMPACT_COLUMNS,
    mapTypeRow,
    flattenTypesPayload,
    buildTypeSchemaDetail,
} from '../../../src/tools/format/inspection-format.js';

describe('inspection-format sidecar', () => {
    it('TYPES_TABLE_COLUMNS keys = [name(llmOnly), label, origin, has_children_support]', () => {
        const name = TYPES_TABLE_COLUMNS.find((c) => c.key === 'name');
        expect(name?.llmOnly).toBe(true);
        const keys = TYPES_TABLE_COLUMNS.map((c) => c.key);
        expect(keys).toEqual(['name', 'label', 'origin', 'has_children_support']);
    });

    it('TYPES_COMPACT_COLUMNS drops the boolean column', () => {
        const keys = TYPES_COMPACT_COLUMNS.map((c) => c.key);
        expect(keys).not.toContain('has_children_support');
    });

    it('mapTypeRow handles missing fields gracefully', () => {
        expect(mapTypeRow({ name: 'section' })).toEqual({
            name: 'section',
            label: '',
            origin: '',
            has_children_support: false,
        });
    });

    it('flattenTypesPayload accepts a flat array or {element_types:[…]}', () => {
        expect(flattenTypesPayload([{ name: 'a' }, { name: 'b' }])).toHaveLength(2);
        expect(
            flattenTypesPayload({ element_types: [{ name: 'a' }, { name: 'b' }] }),
        ).toHaveLength(2);
    });

    it('flattenTypesPayload tolerates name-only string entries', () => {
        const rows = flattenTypesPayload({ element_types: ['headline', 'text'] });
        expect(rows).toHaveLength(2);
        expect(rows[0]?.name).toBe('headline');
    });

    it('buildTypeSchemaDetail produces ≥ 2 groups (Identity + Fields summary)', () => {
        const detail = buildTypeSchemaDetail({
            name: 'section',
            label: 'Section',
            fields: { width: { type: 'text' }, style: { type: 'text', default: 'default' } },
        });
        expect(detail.groups.length).toBeGreaterThanOrEqual(2);
        expect(detail.groups[0]?.label).toMatch(/identity/i);
        expect(detail.groups[1]?.label).toMatch(/fields/i);
    });

    it('buildTypeSchemaDetail counts fields in the summary', () => {
        const detail = buildTypeSchemaDetail({
            name: 'section',
            fields: { a: { type: 'text' }, b: { type: 'number' }, c: { type: 'boolean' } },
        });
        const countEntry = detail.groups[1]?.entries.find((e) => e.key === 'count');
        expect(countEntry?.value).toBe(3);
    });

    // -------------------------------------------------------------
    // F-03 v2 — Maria-Audit Stream C2 (2026-05-22).
    // -------------------------------------------------------------

    it('flattenTypesPayload prefers items[] over element_types[] when both are present', () => {
        // F-03 v2: REST envelope surfaces BOTH a structured `items` array
        // and a legacy `element_types` (name-only) array. Reader MUST take
        // the structured one — falling back to the name-only list drops
        // label/origin/has_children before mapTypeRow can read them.
        const payload = {
            items: [
                { name: 'grid', label: 'Grid', origin: 'builtin', has_children: true, has_children_support: true },
                { name: 'headline', label: 'Headline', origin: 'builtin', has_children: false, has_children_support: false },
            ],
            total: 2,
            element_types: ['grid', 'headline'],
        };
        const rows = flattenTypesPayload(payload);
        expect(rows).toHaveLength(2);
        expect(rows[0]?.name).toBe('grid');
        expect(rows[0]?.label).toBe('Grid');
        expect(rows[0]?.has_children_support).toBe(true);
    });

    it('flattenTypesPayload falls back to element_types[] when items[] missing', () => {
        // Back-compat: older yt-builder-mcp wp-plugin builds emit only
        // `element_types` (name-only). MCP-TS still produces TypeRows
        // with blank label/origin — but the catalog is at least visible.
        const payload = { element_types: ['grid', 'headline'] };
        const rows = flattenTypesPayload(payload);
        expect(rows).toHaveLength(2);
        expect(rows[0]?.name).toBe('grid');
        expect(rows[0]?.label).toBeUndefined();
    });

    it('mapTypeRow reads has_children as a synonym for has_children_support', () => {
        // F-03 v2: PHP REST envelope canonical key is `has_children`. The
        // MCP-TS table column key is `has_children_support`. mapTypeRow
        // MUST accept either so a deploy-skew between wp-plugin and
        // npm-package versions does not blank the CHILDREN column.
        const onlyPhpKey = mapTypeRow({ name: 'grid', label: 'Grid', origin: 'builtin', has_children: true });
        expect(onlyPhpKey.has_children_support).toBe(true);

        const onlyTsKey = mapTypeRow({ name: 'grid', label: 'Grid', origin: 'builtin', has_children_support: true });
        expect(onlyTsKey.has_children_support).toBe(true);

        // Explicit TS key wins when both are present.
        const both = mapTypeRow({
            name: 'grid',
            label: 'Grid',
            origin: 'builtin',
            has_children_support: false,
            has_children: true,
        });
        expect(both.has_children_support).toBe(false);
    });

    it('mapTypeRow preserves label/origin from the structured F-03 v2 row', () => {
        // Regression pin against the audit-finding "label=""/origin="".
        // The PHP-side surfaces label/origin/has_children_support on every
        // row; mapTypeRow must relay them as-is, never blanking.
        const row = mapTypeRow({
            name: 'grid_item',
            label: 'Item',
            origin: 'builtin',
            has_children_support: true,
        });
        expect(row.label).toBe('Item');
        expect(row.origin).toBe('builtin');
        expect(row.has_children_support).toBe(true);
    });

    // ─── F-201 (Audit 2026-05-26) — tabular field descriptors ──────────
    // Thomas-repro: the text-leg of element_type_get_schema previously
    // emitted only `Count` + `Keys: a, b, …(+30 more)` — agents that
    // read the text-leg (not structuredContent) only saw NAMES, not
    // TYPES, so they had to guess the wire-type of every prop. The
    // detail builder must now produce an `appendText` block carrying a
    // `name | type | label` table for every field, with pagination
    // footer when count > 50.

    it('buildTypeSchemaDetail emits an appendText table with name | type | label', () => {
        const detail = buildTypeSchemaDetail({
            name: 'headline',
            fields: [
                { name: 'content', type: 'editor', label: 'Content' },
                { name: 'link', type: 'link', label: 'Link' },
                { name: 'link_target', type: 'checkbox' },
            ],
        });
        expect(typeof detail.appendText).toBe('string');
        const txt = detail.appendText ?? '';
        // Header carries column names.
        expect(txt).toMatch(/NAME/);
        expect(txt).toMatch(/TYPE/);
        expect(txt).toMatch(/LABEL/);
        // Each field rendered with its type.
        expect(txt).toContain('content');
        expect(txt).toContain('editor');
        expect(txt).toContain('link_target');
        expect(txt).toContain('checkbox');
    });

    it('buildTypeSchemaDetail paginates the field table when count > 50 and emits a footer', () => {
        // Grid has 148 fields on the live REST — must not blow the
        // 8000-char detail budget.
        const manyFields = Array.from({ length: 148 }, (_, i) => ({
            name: `field_${String(i)}`,
            type: 'text',
            label: `Field ${String(i)}`,
        }));
        const detail = buildTypeSchemaDetail({ name: 'grid', fields: manyFields });
        const txt = detail.appendText ?? '';
        // First 50 fields rendered.
        expect(txt).toContain('field_0');
        expect(txt).toContain('field_49');
        // Footer mentions remaining + structuredContent pointer.
        expect(txt).toMatch(/and 98 more/);
        expect(txt).toMatch(/structuredContent/);
        // Fields beyond cap are NOT in the text (50-indexed not present).
        expect(txt).not.toContain('field_51 ');
    });

    it('buildTypeSchemaDetail keeps Fields (summary) group as before for back-compat', () => {
        const detail = buildTypeSchemaDetail({
            name: 'section',
            fields: [{ name: 'a', type: 'text' }],
        });
        // The summary group remains (UI rendering relies on it).
        const summaryGroup = detail.groups.find((g) => /fields/i.test(g.label));
        expect(summaryGroup).toBeDefined();
        const countEntry = summaryGroup?.entries.find((e) => e.key === 'count');
        expect(countEntry?.value).toBe(1);
    });

    it('buildTypeSchemaDetail emits no appendText when there are no fields', () => {
        const detail = buildTypeSchemaDetail({ name: 'unknown' });
        expect(detail.appendText).toBeUndefined();
    });

    // F-201 follow-up (Audit 2026-05-26 reviewer Gap 1): when the
    // descriptor carries `required: true`, the rendered field table
    // must mark the row with `*` next to the NAME. Live YT upstream
    // does NOT surface a `required` flag today (Inspector::projectField
    // emits only {name,type,label?,default?,enum?,group?}); the marker
    // is opt-in for back-ends that DO surface one. This test pins the
    // renderer behaviour so the moment upstream adds `required` the
    // marker shows up automatically.
    it('marks required fields in the rendered table', () => {
        const detail = buildTypeSchemaDetail({
            name: 'headline',
            fields: [
                { name: 'content', type: 'editor', label: 'Content', required: true },
                { name: 'link', type: 'link', label: 'Link' },
            ],
        });
        const txt = detail.appendText ?? '';
        const contentLine = txt.split('\n').find((l) => l.startsWith('* content') || /^\* content/.test(l));
        const linkLine = txt.split('\n').find((l) => /\blink\s/.test(l) && !/\bcontent\b/.test(l));
        expect(contentLine).toBeDefined();
        expect(linkLine).toBeDefined();
        // Required marker on `content`, none on `link`.
        expect(contentLine).toMatch(/^\*/);
        expect(linkLine).not.toMatch(/^\*/);
        // Legend explains the marker so the LLM doesn't have to guess.
        expect(txt).toMatch(/\* = required/);
    });
});
