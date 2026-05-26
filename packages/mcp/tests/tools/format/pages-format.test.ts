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

    it('SCHEMA_TABLE_COLUMNS includes rel_path (llmOnly) + element_type + label + has_binding (F-006)', () => {
        const relPath = SCHEMA_TABLE_COLUMNS.find((c) => c.key === 'rel_path');
        expect(relPath?.llmOnly).toBe(true);
        const keys = SCHEMA_TABLE_COLUMNS.map((c) => c.key);
        expect(keys).toEqual(['rel_path', 'element_type', 'label', 'has_binding']);
    });

    it('SCHEMA_COMPACT_COLUMNS is strictly shorter than SCHEMA_TABLE_COLUMNS (F-006: rel_path)', () => {
        expect(SCHEMA_COMPACT_COLUMNS.length).toBeLessThan(SCHEMA_TABLE_COLUMNS.length);
        const keys = SCHEMA_COMPACT_COLUMNS.map((c) => c.key);
        expect(keys).toEqual(['rel_path', 'element_type', 'label']);
    });

    it('mapPageRow handles missing fields gracefully', () => {
        // F-Frontend-URL (2026-05-25): the row now also carries three
        // nullable frontend-URL hint keys (frontend_url,
        // frontend_url_template, frontend_url_description). When the
        // server returns nothing, all three default to null — distinct
        // from `''` so an agent can tell "no key" (missing) apart from
        // "not applicable" (null).
        expect(mapPageRow({})).toEqual({
            id: '',
            label: '',
            type: '',
            elements_count: 0,
            modified_at: '',
            frontend_url: null,
            frontend_url_template: null,
            frontend_url_description: null,
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
            frontend_url: null,
            frontend_url_template: null,
            frontend_url_description: null,
        });
    });

    it('mapPageRow maps REST "name" field to "label" (YT 4.x backend shape)', () => {
        // Real /pages REST endpoint returns {id, name, etag} — surface "name"
        // as "label" so LLM-friendly schemas stay consistent with other tools.
        expect(
            mapPageRow({
                id: 'I99YS8Ii',
                name: 'Post',
                etag: 'abc',
            }),
        ).toEqual({
            id: 'I99YS8Ii',
            label: 'Post',
            type: '',
            elements_count: 0,
            modified_at: '',
            frontend_url: null,
            frontend_url_template: null,
            frontend_url_description: null,
        });
    });

    it('mapPageRow prefers "name" over legacy "label" if both present', () => {
        expect(
            mapPageRow({ id: 'x', name: 'NewName', label: 'OldLabel' }),
        ).toEqual({
            id: 'x',
            label: 'NewName',
            type: '',
            elements_count: 0,
            modified_at: '',
            frontend_url: null,
            frontend_url_template: null,
            frontend_url_description: null,
        });
    });

    // F-Frontend-URL pin-tests — verify the new fields are emitted
    // verbatim when the server includes them, and preserved as null
    // (NOT empty string) when irrelevant. Wire-shape pin so downstream
    // consumers can rely on the three-key triple.

    it('mapPageRow surfaces frontend_url + template + description verbatim from REST', () => {
        const row = mapPageRow({
            id: 'S12MqLbP',
            name: '404',
            type: 'error-404',
            frontend_url: null,
            frontend_url_template: 'https://example.test/<any-nonexistent-path>',
            frontend_url_description: 'Append any non-existent path to test.',
        });
        expect(row.frontend_url).toBeNull();
        expect(row.frontend_url_template).toBe('https://example.test/<any-nonexistent-path>');
        expect(row.frontend_url_description).toBe('Append any non-existent path to test.');
    });

    it('mapPageRow preserves a populated frontend_url permalink', () => {
        const row = mapPageRow({
            id: 'tpl',
            name: 'Post',
            type: 'single-post',
            frontend_url: 'https://example.test/hello-world/',
            frontend_url_template: null,
            frontend_url_description: 'Latest published post — rendered with this template.',
        });
        expect(row.frontend_url).toBe('https://example.test/hello-world/');
        expect(row.frontend_url_template).toBeNull();
    });

    it('mapPageRow normalises a non-string frontend_url to null (defensive)', () => {
        // The server emits null deliberately for "not applicable" rows
        // (layout/internal templates). A defensive cast keeps the wire
        // shape stable when an upstream proxy munges the JSON encoding.
        const row = mapPageRow({
            id: 'tpl',
            type: 'layout',
            frontend_url: 42,
            frontend_url_template: undefined,
        });
        expect(row.frontend_url).toBeNull();
        expect(row.frontend_url_template).toBeNull();
        expect(row.frontend_url_description).toBeNull();
    });

    it('mapSchemaNodeRow normalizes has_binding to boolean', () => {
        expect(mapSchemaNodeRow({ path: '/0', element_type: 's', label: 'l' })).toEqual({
            path: '/0',
            rel_path: '/0',
            element_type: 's',
            label: 'l',
            has_binding: false,
        });
        expect(
            mapSchemaNodeRow({ path: '/1', element_type: 'g', label: '', has_binding: true }),
        ).toEqual({
            path: '/1',
            rel_path: '/1',
            element_type: 'g',
            label: '',
            has_binding: true,
        });
    });

    it('mapSchemaNodeRow derives rel_path by stripping /templates/<id>/layout prefix (F-006)', () => {
        const row = mapSchemaNodeRow({
            path: '/templates/I99YS8Ii/layout/children/0/children/2',
            element_type: 'headline',
            label: 'Title',
        });
        expect(row.path).toBe('/templates/I99YS8Ii/layout/children/0/children/2');
        expect(row.rel_path).toBe('/children/0/children/2');
    });

    it('mapSchemaNodeRow rel_path falls back to "/" when path equals the prefix itself', () => {
        const row = mapSchemaNodeRow({
            path: '/templates/abc/layout',
            element_type: 'section',
            label: 'Root',
        });
        expect(row.rel_path).toBe('/');
    });

    it('buildSchemaRows accepts the REST `{nodes}` shape + maps each', () => {
        const rows = buildSchemaRows([
            { path: '/0', element_type: 'section', label: 'Hero' },
            { path: '/0/children/0', element_type: 'row' },
        ]);
        expect(rows).toHaveLength(2);
        expect(rows[0]).toMatchObject({ path: '/0', rel_path: '/0', element_type: 'section', label: 'Hero' });
        expect(rows[1]).toMatchObject({ path: '/0/children/0', rel_path: '/0/children/0', element_type: 'row' });
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
