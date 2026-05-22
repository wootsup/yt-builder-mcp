/**
 * Pages format-sidecar tests (G.2.1).
 *
 * Pin-tests for the pure transforms used by the migrated `pages_list`,
 * `page_get_schema` and `get_etag` handlers. The handlers themselves are
 * tested separately in `tests/tools/pages.test.ts` (shape pins).
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    PAGES_TABLE_COLUMNS,
    PAGES_COMPACT_COLUMNS,
    SCHEMA_TABLE_COLUMNS,
    SCHEMA_COMPACT_COLUMNS,
    mapPageRow,
    mapSchemaNodeRow,
    buildSchemaRows,
    buildEtagDetail,
} from '../../../src/tools/format/pages-format.js';

describe('pages-format sidecar', () => {
    it('PAGES_TABLE_COLUMNS marks `id` as llmOnly + includes label/type/elements_count/modified_at', () => {
        const id = PAGES_TABLE_COLUMNS.find((c) => c.key === 'id');
        expect(id?.llmOnly).toBe(true);
        const keys = PAGES_TABLE_COLUMNS.map((c) => c.key);
        expect(keys).toEqual(['id', 'label', 'type', 'elements_count', 'modified_at']);
    });

    it('PAGES_COMPACT_COLUMNS drops `modified_at` + `type`', () => {
        const compactKeys = PAGES_COMPACT_COLUMNS.map((c) => c.key);
        expect(compactKeys).not.toContain('modified_at');
        expect(compactKeys).not.toContain('type');
        expect(compactKeys).toContain('id');
        expect(compactKeys).toContain('label');
    });

    it('SCHEMA_TABLE_COLUMNS includes path (llmOnly) + element_type + label + has_binding', () => {
        const path = SCHEMA_TABLE_COLUMNS.find((c) => c.key === 'path');
        expect(path?.llmOnly).toBe(true);
        const keys = SCHEMA_TABLE_COLUMNS.map((c) => c.key);
        expect(keys).toEqual(['path', 'element_type', 'label', 'has_binding']);
    });

    it('SCHEMA_COMPACT_COLUMNS is strictly shorter than SCHEMA_TABLE_COLUMNS', () => {
        expect(SCHEMA_COMPACT_COLUMNS.length).toBeLessThan(SCHEMA_TABLE_COLUMNS.length);
        const keys = SCHEMA_COMPACT_COLUMNS.map((c) => c.key);
        expect(keys).toEqual(['path', 'element_type', 'label']);
    });

    it('mapPageRow handles missing fields gracefully', () => {
        expect(mapPageRow({})).toEqual({
            id: '',
            label: '',
            type: '',
            elements_count: 0,
            modified_at: '',
        });
    });

    it('mapPageRow extracts present fields verbatim', () => {
        expect(
            mapPageRow({
                id: 'home',
                label: 'Home',
                type: 'page',
                elements_count: 12,
                modified_at: '2026-05-22T10:00:00Z',
                extra: 'ignored',
            }),
        ).toEqual({
            id: 'home',
            label: 'Home',
            type: 'page',
            elements_count: 12,
            modified_at: '2026-05-22T10:00:00Z',
        });
    });

    it('mapSchemaNodeRow normalizes has_binding to boolean', () => {
        expect(mapSchemaNodeRow({ path: '/0', element_type: 's', label: 'l' })).toEqual({
            path: '/0',
            element_type: 's',
            label: 'l',
            has_binding: false,
        });
        expect(
            mapSchemaNodeRow({ path: '/1', element_type: 'g', label: '', has_binding: true }),
        ).toEqual({
            path: '/1',
            element_type: 'g',
            label: '',
            has_binding: true,
        });
    });

    it('buildSchemaRows accepts the REST `{nodes}` shape + maps each', () => {
        const rows = buildSchemaRows([
            { path: '/0', element_type: 'section', label: 'Hero' },
            { path: '/0/children/0', element_type: 'row' },
        ]);
        expect(rows).toHaveLength(2);
        expect(rows[0]).toMatchObject({ path: '/0', element_type: 'section', label: 'Hero' });
    });

    it('buildEtagDetail produces 2 groups (Identity + Freshness)', () => {
        const detail = buildEtagDetail({ etag: 'abc123', generated_at: '2026-05-22T10:00:00Z' });
        expect(detail.groups).toHaveLength(2);
        expect(detail.groups[0]?.label).toMatch(/identity/i);
        expect(detail.groups[1]?.label).toMatch(/freshness/i);
        const etagEntry = detail.groups[0]?.entries.find((e) => e.key === 'etag');
        expect(etagEntry?.value).toBe('abc123');
    });

    it('buildEtagDetail tolerates missing generated_at — entry value is null (toolkit renders as "—" in text)', () => {
        const detail = buildEtagDetail({ etag: 'x' });
        expect(detail.groups[1]?.entries[0]?.value).toBeNull();
    });
});
