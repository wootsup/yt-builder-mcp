/**
 * Sources format-sidecar tests (G.2.7).
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    SOURCES_TABLE_COLUMNS,
    mapSourceRow,
    flattenSourcesPayload,
    buildBindingDetail,
} from '../../../src/tools/format/sources-format.js';

describe('sources-format sidecar', () => {
    it('SOURCES_TABLE_COLUMNS keys = [name(llmOnly), label, origin, kind]', () => {
        const name = SOURCES_TABLE_COLUMNS.find((c) => c.key === 'name');
        expect(name?.llmOnly).toBe(true);
        const keys = SOURCES_TABLE_COLUMNS.map((c) => c.key);
        expect(keys).toEqual(['name', 'label', 'origin', 'kind']);
    });

    it('mapSourceRow maps verbatim with defaults for missing fields', () => {
        expect(mapSourceRow({ name: 'wp_posts', label: 'Posts', origin: 'wordpress' })).toEqual({
            name: 'wp_posts',
            label: 'Posts',
            origin: 'wordpress',
            kind: '',
        });
    });

    it('flattenSourcesPayload accepts a grouped {apimapper, wordpress, essentials} object', () => {
        const flat = flattenSourcesPayload({
            apimapper: [{ name: 'api_a', label: 'A' }],
            wordpress: [{ name: 'wp_posts', label: 'Posts' }],
            essentials: [],
        });
        expect(flat).toHaveLength(2);
        const a = flat.find((s) => s.name === 'api_a');
        expect(a?.origin).toBe('apimapper');
    });

    it('flattenSourcesPayload accepts a flat array — passes through', () => {
        const flat = flattenSourcesPayload([
            { name: 's1', label: 'S1', origin: 'wordpress' },
        ]);
        expect(flat).toHaveLength(1);
        expect(flat[0]?.origin).toBe('wordpress');
    });

    it('buildBindingDetail returns 2 groups (Element / Binding) when source is set', () => {
        const detail = buildBindingDetail({
            element_path: '/0',
            template_id: 'home',
            binding: { source_name: 'wp_posts', source_config: { post_type: 'page' } },
        });
        expect(detail.groups).toHaveLength(2);
        expect(detail.groups[0]?.label).toMatch(/element/i);
        expect(detail.groups[1]?.label).toMatch(/binding/i);
    });

    it('buildBindingDetail handles empty binding gracefully', () => {
        const detail = buildBindingDetail({
            element_path: '/0',
            template_id: 'home',
            binding: {},
        });
        expect(detail.groups).toHaveLength(2);
        const bound = detail.groups[1]?.entries.find((e) => e.key === 'source_name');
        expect(bound?.value).toBeNull();
    });
});
