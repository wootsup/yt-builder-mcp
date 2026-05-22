/**
 * DOMAIN_ORDER pin-test (Round-2 audit follow-up R2-A1-N1).
 *
 * Pins the 4-entry contract for the gateway's domain taxonomy, plus the
 * `domainOf()` table for every advanced + essential tool name that
 * currently flows through the gateway. A future refactor that silently
 * mutates DOMAIN_ORDER (adds/removes/renames an entry) or breaks the
 * prefix→domain routing surfaces here as a test failure rather than at
 * audit time.
 *
 * Pinned tools mirror the 21 tools registered by `src/server.ts`
 * (L1 essential + L2 advanced + 2 L3 direct). Stems unknown to the
 * taxonomy must fall through to 'misc'.
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    DOMAIN_ORDER,
    DOMAIN_PREFIX_MAP,
    TOOL_PREFIX,
    domainOf,
} from '../../src/gateway/advanced-tool.js';

// ─── 4-entry shape pin ───────────────────────────────────────────────

describe('DOMAIN_ORDER pin (R2-A1-N1)', () => {
    it('matches the strict 4-entry shape', () => {
        expect(DOMAIN_ORDER).toEqual(['pages', 'elements', 'sources', 'inspection']);
    });

    it('has exactly 4 entries (count pin)', () => {
        expect(DOMAIN_ORDER.length).toBe(4);
    });

    it('exposes a prefix map keyed by every domain entry', () => {
        for (const domain of DOMAIN_ORDER) {
            expect(DOMAIN_PREFIX_MAP[domain]).toBeDefined();
            expect(Array.isArray(DOMAIN_PREFIX_MAP[domain])).toBe(true);
            expect(DOMAIN_PREFIX_MAP[domain]!.length).toBeGreaterThan(0);
        }
    });

    it('exposes the canonical TOOL_PREFIX constant', () => {
        expect(TOOL_PREFIX).toBe('yootheme_builder_');
    });
});

// ─── domainOf() table-test for every registered tool name ───────────

describe('domainOf() routing table (R2-A1-N1)', () => {
    const cases: ReadonlyArray<readonly [name: string, expected: string]> = [
        // ── pages domain (L1 essentials + L2 advanced) ───────────────
        ['yootheme_builder_pages_list', 'pages'],
        ['yootheme_builder_page_get_layout', 'pages'],
        ['yootheme_builder_page_get_schema', 'pages'],
        ['yootheme_builder_page_save', 'pages'],
        ['yootheme_builder_page_publish', 'pages'],

        // ── elements domain (L1 essentials + L2 advanced) ────────────
        ['yootheme_builder_element_list', 'elements'],
        ['yootheme_builder_element_get', 'elements'],
        ['yootheme_builder_element_add', 'elements'],
        ['yootheme_builder_element_update_settings', 'elements'],
        ['yootheme_builder_element_move', 'elements'],
        ['yootheme_builder_element_clone', 'elements'],
        ['yootheme_builder_element_delete', 'elements'],

        // ── source-binding tools (`element_*` stem → elements domain;
        //    `sources_list` → sources domain) ─────────────────────────
        ['yootheme_builder_element_get_binding', 'elements'],
        ['yootheme_builder_element_bind_source', 'elements'],
        ['yootheme_builder_element_unbind_source', 'elements'],
        ['yootheme_builder_sources_list', 'sources'],

        // ── inspection / element-types (stem `element_types_…` /
        //    `element_type_…` → elements domain by prefix rule) ───────
        ['yootheme_builder_element_types_list', 'elements'],
        ['yootheme_builder_element_type_get_schema', 'elements'],

        // ── unmapped stems fall through to 'misc' ────────────────────
        ['yootheme_builder_get_etag', 'misc'],
        ['yootheme_builder_health', 'misc'],
        ['yootheme_builder_diagnose', 'misc'],
    ];

    it.each(cases)('domainOf(%s) === %s', (name, expected) => {
        expect(domainOf(name)).toBe(expected);
    });

    it('returns "misc" for an unknown prefix', () => {
        expect(domainOf('yootheme_builder_completely_unknown_tool')).toBe('misc');
    });

    it('returns "misc" for a name without the canonical TOOL_PREFIX', () => {
        // No prefix means the stem === name; if that stem doesn't match
        // any domain prefix, the function falls through to 'misc'.
        expect(domainOf('foo_bar')).toBe('misc');
    });

    it('handles full-equality match on a bare domain stem (no underscore suffix)', () => {
        // `stem === prefix` arm is exercised by these (rare in production
        // tool names but guarded by the prefix-map routing for safety).
        expect(domainOf('yootheme_builder_pages')).toBe('pages');
        expect(domainOf('yootheme_builder_page')).toBe('pages');
        expect(domainOf('yootheme_builder_elements')).toBe('elements');
        expect(domainOf('yootheme_builder_element')).toBe('elements');
        expect(domainOf('yootheme_builder_sources')).toBe('sources');
        expect(domainOf('yootheme_builder_source')).toBe('sources');
        expect(domainOf('yootheme_builder_inspection')).toBe('inspection');
    });
});
