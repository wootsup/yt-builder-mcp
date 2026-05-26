/**
 * Server-factory tests — verify all tools register and the surface matches
 * the design-doc spec.
 *
 * @license MIT
 */

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import { describe, expect, it } from 'vitest';

import type { ClientPool } from '../src/sites/client-pool.js';
import { createServer } from '../src/server.js';
import { buildAllTools } from '../src/tools/index.js';
import { makeTestPool } from './helpers/test-pool.js';

function makeClient(): ClientPool {
    return makeTestPool({
        baseUrl: 'https://example.com',
        bearer: 'test',
    });
}

describe('createServer', () => {
    it('returns an McpServer + the tool list', () => {
        const { mcp, tools } = createServer({ pool: makeClient() });
        expect(mcp).toBeDefined();
        expect(tools.length).toBeGreaterThan(0);
    });

    it('registers at least 20 tools (design target ~28; baseline 21 in Wave 4)', () => {
        const { tools } = createServer({ pool: makeClient() });
        expect(tools.length).toBeGreaterThanOrEqual(20);
    });

    it('every tool has a name + description + annotations', () => {
        const tools = buildAllTools(makeClient());
        for (const t of tools) {
            expect(t.name, `tool ${t.name} missing name`).toMatch(/^yootheme_builder_/);
            expect(t.description.length, `tool ${t.name} has empty description`).toBeGreaterThan(20);
            expect(t.annotations, `tool ${t.name} missing annotations`).toBeDefined();
        }
    });

    it('exposes the design-doc tool surface (key tool names)', () => {
        const tools = buildAllTools(makeClient());
        const names = new Set(tools.map((t) => t.name));
        const required = [
            // health
            'yootheme_builder_health',
            'yootheme_builder_diagnose',
            // pages
            'yootheme_builder_pages_list',
            'yootheme_builder_page_get_layout',
            'yootheme_builder_page_get_schema',
            'yootheme_builder_page_save',
            'yootheme_builder_page_publish',
            'yootheme_builder_get_etag',
            // elements
            'yootheme_builder_element_list',
            'yootheme_builder_element_get',
            'yootheme_builder_element_add',
            'yootheme_builder_element_update_settings',
            'yootheme_builder_element_delete',
            'yootheme_builder_element_move',
            'yootheme_builder_element_clone',
            // sources
            'yootheme_builder_sources_list',
            'yootheme_builder_element_get_binding',
            'yootheme_builder_element_bind_source',
            'yootheme_builder_element_unbind_source',
            // inspection
            'yootheme_builder_element_types_list',
            'yootheme_builder_element_type_get_schema',
        ];
        for (const name of required) {
            expect(names.has(name), `missing tool: ${name}`).toBe(true);
        }
    });

    it('destructive tools have destructiveHint + a confirm parameter', () => {
        const tools = buildAllTools(makeClient());
        const destructive = tools.filter((t) => t.annotations.destructiveHint === true);
        expect(destructive.length).toBeGreaterThanOrEqual(2);
        for (const t of destructive) {
            expect(t.inputSchema, `${t.name} should have confirm in schema`).toHaveProperty(
                'confirm',
            );
        }
    });

    it('read-only tools have readOnlyHint=true', () => {
        const tools = buildAllTools(makeClient());
        const reads = tools.filter((t) => t.name.endsWith('_list') || t.name.endsWith('_get'));
        for (const t of reads) {
            expect(t.annotations.readOnlyHint, `${t.name} should be read-only`).toBe(true);
        }
    });
});
