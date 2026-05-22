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
});
