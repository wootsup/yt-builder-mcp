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
});
