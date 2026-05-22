/**
 * Tests for the sparse-fields helper module.
 *
 * Per Design §3.5: a small wrapper around the toolkit's `pickFields`
 * plus per-tool default-field-sets, plus an echo helper that returns the
 * actual field set used (for echoing in `structuredContent`).
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    DEFAULT_FIELDS_ELEMENT_GET,
    DEFAULT_FIELDS_ELEMENT_LIST,
    DEFAULT_FIELDS_PAGES_LIST,
    DEFAULT_FIELDS_SCHEMA,
    DEFAULT_FIELDS_SOURCES_LIST,
    DEFAULT_FIELDS_TYPES_LIST,
    FIELDS,
    projectFields,
    projectedFieldsEcho,
} from '../../src/tools/sparse-fields.js';

describe('FIELDS schema', () => {
    it('accepts an array of strings up to 40 items', () => {
        const parsed = FIELDS.parse(['a', 'b', 'c']);
        expect(parsed).toEqual(['a', 'b', 'c']);
    });

    it('treats undefined as a valid omission (optional)', () => {
        expect(FIELDS.parse(undefined)).toBeUndefined();
    });

    it('rejects empty-string entries', () => {
        expect(() => FIELDS.parse([''])).toThrow();
    });

    it('rejects more than 40 entries', () => {
        const tooMany = Array.from({ length: 41 }, (_, i) => `f${String(i)}`);
        expect(() => FIELDS.parse(tooMany)).toThrow();
    });
});

describe('projectFields', () => {
    it('returns items unchanged when fields is undefined (default-all)', () => {
        const items = [
            { id: 'a', name: 'A', extra: 1 },
            { id: 'b', name: 'B', extra: 2 },
        ];
        const result = projectFields(items, undefined, ['id']);
        // No `fields` requested AND defaults != undefined → no projection
        // (default-all preserved). The default-set is only the *echo*, not
        // a filter.
        expect(result).toEqual(items);
    });

    it('projects to requested fields when fields is provided', () => {
        const items = [
            { id: 'a', name: 'A', extra: 1 },
            { id: 'b', name: 'B', extra: 2 },
        ];
        const result = projectFields(items, ['id', 'name'], ['id']);
        expect(result).toEqual([
            { id: 'a', name: 'A' },
            { id: 'b', name: 'B' },
        ]);
    });

    it('supports nested-path projection (props.title)', () => {
        const items = [
            { id: 'a', props: { title: 'Hello', subtitle: 'gone' } },
        ];
        const result = projectFields(items, ['id', 'props.title'], ['id']);
        // pickFields flattens nested paths to leaf-keys (per its docstring)
        // → `title` rather than `props.title`. Verify the leaf key.
        expect(result[0]).toMatchObject({ id: 'a' });
        // 'title' surfaces as a leaf key
        expect((result[0] as Record<string, unknown>).title).toBe('Hello');
    });

    it('silently ignores unknown fields', () => {
        const items = [{ id: 'a', name: 'A' }];
        const result = projectFields(items, ['id', 'does_not_exist'], ['id']);
        expect(result[0]).toMatchObject({ id: 'a' });
    });

    it('returns empty array for empty input', () => {
        expect(projectFields([], ['id'], ['id'])).toEqual([]);
    });

    it('skips non-object items gracefully', () => {
        // Items shaped as Record<string, unknown> so we must coerce types
        // safely. The function must not crash on stray nulls.
        const items = [{ id: 'a' }, null as unknown as Record<string, unknown>];
        const result = projectFields(items, ['id'], ['id']);
        // First item projected; second preserved as-is (null)
        expect(result[0]).toEqual({ id: 'a' });
        expect(result[1]).toBeNull();
    });
});

describe('projectFieldsSingle', () => {
    it('projects a single object via the same pickFields rules', async () => {
        const { projectFieldsSingle } = await import('../../src/tools/sparse-fields.js');
        const obj = { id: 'a', name: 'A', extra: 1 };
        const result = projectFieldsSingle(obj, ['id', 'name'], ['id']);
        expect(result).toEqual({ id: 'a', name: 'A' });
    });

    it('returns the object unchanged when fields is undefined', async () => {
        const { projectFieldsSingle } = await import('../../src/tools/sparse-fields.js');
        const obj = { id: 'a', name: 'A' };
        const result = projectFieldsSingle(obj, undefined, ['id']);
        expect(result).toEqual(obj);
    });
});

describe('projectedFieldsEcho', () => {
    it('returns the requested fields when provided', () => {
        expect(projectedFieldsEcho(['id', 'name'], ['id'])).toEqual(['id', 'name']);
    });

    it('returns the defaults when nothing requested (so the AI knows the implicit set)', () => {
        expect(projectedFieldsEcho(undefined, ['id', 'name', 'type'])).toEqual([
            'id',
            'name',
            'type',
        ]);
    });
});

describe('DEFAULT_FIELDS constants', () => {
    it('exports the per-tool default sets named in Design §3.5', () => {
        expect(DEFAULT_FIELDS_PAGES_LIST).toEqual([
            'id',
            'label',
            'type',
            'elements_count',
            'modified_at',
        ]);
        expect(DEFAULT_FIELDS_ELEMENT_LIST).toEqual([
            'path',
            'element_type',
            'label',
        ]);
        expect(DEFAULT_FIELDS_SCHEMA).toEqual([
            'path',
            'element_type',
            'label',
        ]);
        expect(DEFAULT_FIELDS_SOURCES_LIST).toEqual([
            'name',
            'label',
            'origin',
            'kind',
        ]);
        expect(DEFAULT_FIELDS_TYPES_LIST).toEqual([
            'name',
            'label',
            'origin',
            'has_children_support',
        ]);
        // element_get defaults to "all" (undefined) per Design §4.10
        expect(DEFAULT_FIELDS_ELEMENT_GET).toBeUndefined();
    });
});
