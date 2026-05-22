/**
 * Elements format-sidecar tests (G.2.5).
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    ELEMENTS_TABLE_COLUMNS,
    ELEMENTS_COMPACT_COLUMNS,
    mapElementRow,
    buildElementDetail,
} from '../../../src/tools/format/elements-format.js';

describe('elements-format sidecar', () => {
    it('ELEMENTS_TABLE_COLUMNS keys = [path(llmOnly), element_type, label, has_binding]', () => {
        const path = ELEMENTS_TABLE_COLUMNS.find((c) => c.key === 'path');
        expect(path?.llmOnly).toBe(true);
        const keys = ELEMENTS_TABLE_COLUMNS.map((c) => c.key);
        expect(keys).toEqual(['path', 'element_type', 'label', 'has_binding']);
    });

    it('ELEMENTS_COMPACT_COLUMNS keeps path + element_type only', () => {
        const keys = ELEMENTS_COMPACT_COLUMNS.map((c) => c.key);
        expect(keys).toEqual(['path', 'element_type']);
    });

    it('mapElementRow handles `label` and `title` aliases', () => {
        expect(mapElementRow({ path: '/0', element_type: 's' })).toEqual({
            path: '/0',
            element_type: 's',
            label: '',
            has_binding: false,
        });
        expect(mapElementRow({ path: '/1', element_type: 'h', label: 'Hello' }).label).toBe('Hello');
        // Fallback to `title` when `label` is missing (REST may surface either).
        expect(
            mapElementRow({ path: '/2', element_type: 'h', title: 'Fallback Title' }).label,
        ).toBe('Fallback Title');
    });

    it('mapElementRow marks has_binding=true when source is set', () => {
        expect(
            mapElementRow({
                path: '/0',
                element_type: 'g',
                props: { source: 'wp_posts' },
            }).has_binding,
        ).toBe(true);
        // Explicit boolean wins
        expect(
            mapElementRow({
                path: '/0',
                element_type: 'g',
                has_binding: true,
            }).has_binding,
        ).toBe(true);
    });

    it('buildElementDetail produces 4 groups (Identity / Props / Children / Next steps)', () => {
        const detail = buildElementDetail({
            path: '/0',
            element_type: 'section',
            props: { title: 'Hero', margin: 'default' },
            children: [
                { type: 'row', children: [] },
                { type: 'text' },
            ],
        });
        expect(detail.groups).toHaveLength(4);
        expect(detail.groups[0]?.label).toMatch(/identity/i);
        expect(detail.groups[1]?.label).toMatch(/props/i);
        expect(detail.groups[2]?.label).toMatch(/children/i);
        expect(detail.groups[3]?.label).toMatch(/next/i);
    });

    it('buildElementDetail counts children + lists their types', () => {
        const detail = buildElementDetail({
            path: '/0',
            element_type: 'section',
            children: [
                { type: 'row' },
                { type: 'row' },
                { type: 'text' },
            ],
        });
        const childGroup = detail.groups[2];
        const countEntry = childGroup?.entries.find((e) => e.key === 'count');
        expect(countEntry?.value).toBe(3);
        const typesEntry = childGroup?.entries.find((e) => e.key === 'types');
        expect(typesEntry?.value).toContain('row');
        expect(typesEntry?.value).toContain('text');
    });

    it('buildElementDetail tolerates missing props / children', () => {
        const detail = buildElementDetail({ path: '/0', element_type: 'section' });
        expect(detail.groups).toHaveLength(4);
        const countEntry = detail.groups[2]?.entries.find((e) => e.key === 'count');
        expect(countEntry?.value).toBe(0);
    });
});
