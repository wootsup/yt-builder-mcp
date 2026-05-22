/**
 * Tests for the layout-flatten helper.
 *
 * Per Design §4.4.1: depth-first walk that emits every nested element as
 * its own flat record with `path`, `parent_path`, `depth`, `element_type`,
 * `props`. The walk preserves child order and strips nested `children`
 * arrays from the emitted records (children become their own entries with
 * their own `path`).
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import { flattenLayout, type FlatElement } from '../../src/tools/layout-flatten.js';

describe('flattenLayout', () => {
    it('depth-first walk produces parent-before-children order', () => {
        const layout = {
            '0': {
                type: 'section',
                props: { p: 1 },
                children: [{ type: 'text', props: { c: 'x' } }],
            },
        };
        const flat = flattenLayout(layout);
        expect(flat.map((n: FlatElement) => n.path)).toEqual([
            '/layout/0',
            '/layout/0/children/0',
        ]);
        expect(flat[0]?.parent_path).toBe(null);
        expect(flat[1]?.parent_path).toBe('/layout/0');
        expect(flat[0]?.depth).toBe(0);
        expect(flat[1]?.depth).toBe(1);
        expect(flat[0]?.element_type).toBe('section');
        expect(flat[1]?.element_type).toBe('text');
    });

    it('removes nested children field on emitted elements', () => {
        const layout = { '0': { type: 'section', children: [{ type: 'text' }] } };
        const flat = flattenLayout(layout);
        expect(flat[0]?.children).toBeUndefined();
        // text element should also have its (absent) children unset
        expect(flat[1]?.children).toBeUndefined();
    });

    it('walks 3 levels deep emitting parent before children', () => {
        const layout = {
            '0': {
                type: 'section',
                props: { padding: 'default' },
                children: [
                    {
                        type: 'row',
                        props: {},
                        children: [{ type: 'text', props: { content: 'Hello' } }],
                    },
                ],
            },
        };
        const flat = flattenLayout(layout);
        expect(flat.map((n: FlatElement) => n.path)).toEqual([
            '/layout/0',
            '/layout/0/children/0',
            '/layout/0/children/0/children/0',
        ]);
        expect(flat[2]?.element_type).toBe('text');
        expect(flat[2]?.depth).toBe(2);
        expect(flat[2]?.parent_path).toBe('/layout/0/children/0');
        // props passthrough
        expect(flat[2]?.props).toEqual({ content: 'Hello' });
    });

    it('returns [] for empty layout', () => {
        expect(flattenLayout({})).toEqual([]);
    });

    it('treats missing children property as leaf', () => {
        const layout = { '0': { type: 'leaf', props: { a: 1 } } };
        const flat = flattenLayout(layout);
        expect(flat).toHaveLength(1);
        expect(flat[0]?.path).toBe('/layout/0');
        expect(flat[0]?.children).toBeUndefined();
        expect(flat[0]?.element_type).toBe('leaf');
    });

    it('preserves original child order (no sort)', () => {
        const layout = {
            '0': {
                type: 'section',
                children: [
                    { type: 'b' },
                    { type: 'a' },
                    { type: 'c' },
                ],
            },
        };
        const flat = flattenLayout(layout);
        // section + 3 children → 4 entries
        expect(flat.map((n) => n.element_type)).toEqual(['section', 'b', 'a', 'c']);
    });

    it('handles top-level layout with multiple roots (keys "0","1",...)', () => {
        const layout = {
            '0': { type: 'section', children: [{ type: 'text' }] },
            '1': { type: 'footer' },
        };
        const flat = flattenLayout(layout);
        expect(flat.map((n) => n.path)).toEqual([
            '/layout/0',
            '/layout/0/children/0',
            '/layout/1',
        ]);
        expect(flat[2]?.parent_path).toBe(null);
        expect(flat[2]?.depth).toBe(0);
    });

    it('accepts arrays at the top level as well (depth-first over indices)', () => {
        // Some YT installs serialise layout as an array directly. Tolerate it.
        const layout = [
            { type: 'section', children: [{ type: 'text' }] },
        ];
        const flat = flattenLayout(layout);
        expect(flat[0]?.path).toBe('/layout/0');
        expect(flat[1]?.path).toBe('/layout/0/children/0');
    });

    it('falls back gracefully when `type` field is missing', () => {
        const layout = { '0': { props: { x: 1 } } };
        const flat = flattenLayout(layout);
        expect(flat).toHaveLength(1);
        expect(flat[0]?.element_type).toBe('');
    });

    it('preserves non-object children gracefully (skips them)', () => {
        const layout = {
            '0': {
                type: 'section',
                children: [null, 'invalid', { type: 'text' }],
            },
        };
        const flat = flattenLayout(layout);
        // The valid child gets emitted at index 2 (its original position)
        expect(flat[0]?.element_type).toBe('section');
        expect(flat.map((n) => n.path)).toEqual([
            '/layout/0',
            '/layout/0/children/2',
        ]);
    });

    // ─── N-01 (Audit v4) — projection-relevant fields ────────────────
    // page_get_layout(flat:true) with fields:["rel_path",...] must
    // project rel_path / has_binding. Those fields have to be EMITTED
    // by flattenLayout for the projection to find them — same shape
    // element_list exposes.

    it('emits rel_path mirroring the path (no /layout prefix)', () => {
        const layout = {
            '0': { type: 'section', children: [{ type: 'text' }] },
        };
        const flat = flattenLayout(layout);
        expect(flat[0]?.rel_path).toBe('/0');
        expect(flat[1]?.rel_path).toBe('/0/children/0');
    });

    it('emits has_binding=false for an unbound node', () => {
        const layout = { '0': { type: 'text', props: { content: 'Hi' } } };
        const flat = flattenLayout(layout);
        expect(flat[0]?.has_binding).toBe(false);
    });

    it('emits has_binding=true when props.source carries a query name', () => {
        const layout = {
            '0': {
                type: 'grid',
                props: { source: { query: { name: 'posts.posts' } } },
            },
        };
        const flat = flattenLayout(layout);
        expect(flat[0]?.has_binding).toBe(true);
    });

    it('honours an explicit has_binding boolean from the REST plugin', () => {
        const layout = { '0': { type: 'grid', has_binding: true } };
        const flat = flattenLayout(layout);
        expect(flat[0]?.has_binding).toBe(true);
    });
});
