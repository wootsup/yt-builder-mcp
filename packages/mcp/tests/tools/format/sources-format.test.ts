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
    remapOriginForPlatform,
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

    it('flattenSourcesPayload picks up the joomla group key (F-A5-1)', () => {
        const flat = flattenSourcesPayload({
            joomla: [{ name: 'articles.article', label: 'Article' }],
            essentials: [],
        });
        expect(flat).toHaveLength(1);
        expect(flat[0]?.origin).toBe('joomla');
    });

    // ─── F-A5-1 (v1.1.5) ────────────────────────────────────────────
    // Joomla content sources arrive tagged with `origin: "wordpress"`
    // because YT Pro historically uses that group key for host-native
    // sources. The MCP server remaps them based on the bound client's
    // platform.

    it('remapOriginForPlatform: leaves WordPress rows untouched on WordPress', () => {
        const rows = [
            { name: 'wp_posts', origin: 'wordpress' },
            { name: 'api_a', origin: 'apimapper' },
        ];
        const out = remapOriginForPlatform(rows, 'wordpress');
        expect(out).toEqual(rows);
    });

    it('remapOriginForPlatform: remaps wordpress→joomla on Joomla', () => {
        const rows = [
            { name: 'articles.article', origin: 'wordpress', label: 'Article' },
            { name: 'tags.tag', origin: 'wordpress', label: 'Tag' },
            { name: 'api_a', origin: 'apimapper', label: 'A' },
        ];
        const out = remapOriginForPlatform(rows, 'joomla');
        expect(out[0]?.origin).toBe('joomla');
        expect(out[1]?.origin).toBe('joomla');
        expect(out[2]?.origin).toBe('apimapper');
        // Non-origin fields preserved
        expect(out[0]?.name).toBe('articles.article');
        expect(out[0]?.label).toBe('Article');
    });

    it('remapOriginForPlatform: does not mutate inputs', () => {
        const rows = [{ name: 'articles.article', origin: 'wordpress' }];
        const out = remapOriginForPlatform(rows, 'joomla');
        expect(rows[0]?.origin).toBe('wordpress');
        expect(out[0]?.origin).toBe('joomla');
    });

    it('buildBindingDetail returns 2 groups (Element / Binding) when source is set', () => {
        const detail = buildBindingDetail({
            element_path: '/0',
            template_id: 'home',
            binding: { source_name: 'wp_posts' },
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

    // ─── F-01-Mapping (Audit v4) ─────────────────────────────────────
    // The REST plugin surfaces field-mappings under `field_mappings`
    // (dict) + `field_mappings_structured` (list) and query arguments
    // under `query_arguments`. The detail-builder previously read the
    // non-existent keys `source_config` / `source_args`, so a bound
    // element always rendered "Config keys: 0 / Args keys: 0".

    it('buildBindingDetail surfaces field_mappings as one entry per mapping', () => {
        const detail = buildBindingDetail({
            element_path: '/0',
            template_id: 'home',
            binding: {
                source_name: 'posts.singlePost',
                field_mappings: {
                    content: 'metaString',
                    title: 'title',
                },
            },
        });
        const mappingGroup = detail.groups.find((g) => /mapping/i.test(g.label));
        expect(mappingGroup).toBeDefined();
        const content = mappingGroup?.entries.find((e) => e.label === 'content');
        expect(content?.value).toBe('metaString');
        const title = mappingGroup?.entries.find((e) => e.label === 'title');
        expect(title?.value).toBe('title');
    });

    it('buildBindingDetail prefers field_mappings_structured (list) when present', () => {
        const detail = buildBindingDetail({
            element_path: '/0',
            template_id: 'home',
            binding: {
                source_name: 'posts.singlePost',
                field_mappings_structured: [
                    { element_prop: 'image', source_field: 'featuredImage.url' },
                ],
            },
        });
        const mappingGroup = detail.groups.find((g) => /mapping/i.test(g.label));
        const image = mappingGroup?.entries.find((e) => e.label === 'image');
        expect(image?.value).toBe('featuredImage.url');
    });

    it('buildBindingDetail surfaces query_arguments as entries', () => {
        const detail = buildBindingDetail({
            element_path: '/0',
            template_id: 'home',
            binding: {
                source_name: 'posts.posts',
                query_field: 'posts',
                query_arguments: { limit: 5, orderby: 'date' },
            },
        });
        const argsGroup = detail.groups.find((g) => /argument/i.test(g.label));
        expect(argsGroup).toBeDefined();
        const limit = argsGroup?.entries.find((e) => e.label === 'limit');
        expect(limit?.value).toBe(5);
        const orderby = argsGroup?.entries.find((e) => e.label === 'orderby');
        expect(orderby?.value).toBe('date');
    });

    it('buildBindingDetail counts mappings/args correctly in the Binding group', () => {
        const detail = buildBindingDetail({
            element_path: '/0',
            template_id: 'home',
            binding: {
                source_name: 'posts.posts',
                field_mappings: { content: 'metaString', title: 'title' },
                query_arguments: { limit: 5 },
            },
        });
        const bindingGroup = detail.groups.find(
            (g) => g.label.toLowerCase() === 'binding',
        );
        const mapCount = bindingGroup?.entries.find((e) => e.key === 'mapping_count');
        expect(mapCount?.value).toBe(2);
        const argCount = bindingGroup?.entries.find((e) => e.key === 'arg_count');
        expect(argCount?.value).toBe(1);
    });
});
