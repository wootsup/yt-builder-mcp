/**
 * Pin-tests for the gateway essentials manifest.
 *
 * These pins enforce Wave G.1 design intent (post-W7):
 *   - ESSENTIAL_TOOLS = the 17 L1 tools forwarded as first-class entries
 *     (15 pre-W7 + sites_list + sites_test added by Multi-Site W7).
 *   - DIRECT_TOP_LEVEL_TOOLS = the 2 L3 tools registered directly on the
 *     real McpServer (health + diagnose) — they bypass the CapturingServer.
 *   - The two sets are disjoint.
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    DIRECT_TOP_LEVEL_TOOLS,
    ESSENTIAL_TOOLS,
    isDirectTopLevel,
    isEssential,
} from '../../src/gateway/essentials.js';

describe('gateway essentials manifest', () => {
    it('ESSENTIAL_TOOLS has exactly 17 entries (L1 forwarded surface, post-W7)', () => {
        // Pre-W7: 15. W7 Multi-Site: +2 (sites_list + sites_test).
        expect(ESSENTIAL_TOOLS.length).toBe(17);
    });

    it('DIRECT_TOP_LEVEL_TOOLS has exactly 2 entries (L3 direct surface: health + diagnose)', () => {
        expect(DIRECT_TOP_LEVEL_TOOLS.length).toBe(2);
        expect(DIRECT_TOP_LEVEL_TOOLS).toContain('yootheme_builder_health');
        expect(DIRECT_TOP_LEVEL_TOOLS).toContain('yootheme_builder_diagnose');
    });

    it('ESSENTIAL_TOOLS contains the 17 expected canonical L1 names (post-W7)', () => {
        expect([...ESSENTIAL_TOOLS].sort()).toEqual(
            [
                'yootheme_builder_pages_list',
                'yootheme_builder_get_etag',
                'yootheme_builder_element_list',
                'yootheme_builder_element_add',
                'yootheme_builder_element_update_settings',
                'yootheme_builder_sources_list',
                'yootheme_builder_element_types_list',
                'yootheme_builder_inspect_multi_items_binding',
                // F-16 (Audit v2): 5 hot-path tools promoted to L1.
                'yootheme_builder_element_get',
                'yootheme_builder_element_delete',
                'yootheme_builder_element_clone',
                'yootheme_builder_element_move',
                'yootheme_builder_page_get_layout',
                // T9 (Audit v3): token-efficient template overview.
                'yootheme_builder_template_summary',
                // 1.0.1: prop-key discovery promoted L2 → L1.
                'yootheme_builder_element_type_get_schema',
                // W7 (Multi-Site): registry-discovery + connectivity-probe.
                'yootheme_builder_sites_list',
                'yootheme_builder_sites_test',
            ].sort(),
        );
    });

    it('the ESSENTIAL and DIRECT_TOP_LEVEL sets are disjoint', () => {
        for (const name of DIRECT_TOP_LEVEL_TOOLS) {
            expect(
                ESSENTIAL_TOOLS as readonly string[],
                `${name} must not appear in both L1 essentials and L3 direct surface`,
            ).not.toContain(name);
        }
    });

    it('isEssential returns true for every L1 entry and false otherwise', () => {
        for (const name of ESSENTIAL_TOOLS) {
            expect(isEssential(name)).toBe(true);
        }
        expect(isEssential('yootheme_builder_health')).toBe(false);
        expect(isEssential('yootheme_builder_page_save')).toBe(false);
        expect(isEssential('unknown_tool')).toBe(false);
    });

    // 1.0.1 pin — element_type_get_schema is the canonical prop-key
    // discovery tool agents need before every element_add / _update_settings;
    // it must surface in tools/list (L1), not be hidden behind the advanced
    // gateway.
    it('element_type_get_schema is exposed as a first-class L1 essential', () => {
        expect(isEssential('yootheme_builder_element_type_get_schema')).toBe(true);
    });

    it('isDirectTopLevel returns true for every L3 entry and false otherwise', () => {
        for (const name of DIRECT_TOP_LEVEL_TOOLS) {
            expect(isDirectTopLevel(name)).toBe(true);
        }
        for (const name of ESSENTIAL_TOOLS) {
            expect(isDirectTopLevel(name)).toBe(false);
        }
        expect(isDirectTopLevel('yootheme_builder_advanced')).toBe(false);
    });
});
