/**
 * Zod-rejection tests — Wave-6 Fix 17.
 *
 * The MCP server side parses arguments via Zod before invoking the
 * handler. These tests pin the input-schema's `safeParse` rejection
 * behaviour so a description tweak cannot silently drop validation.
 *
 * Five canonical rejection scenarios:
 *   1. element_add  — missing element_type
 *   2. element_add  — missing etag (required ETag)
 *   3. element_get  — empty element_path (.min(1))
 *   4. element_move — to_index < 0 or non-integer
 *   5. sources_bind_source — missing source_name
 *
 * @license MIT
 */

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import { describe, expect, it, vi } from 'vitest';
import { z } from 'zod';

import type { ClientPool } from '../../src/sites/client-pool.js';
import { buildElementsTools } from '../../src/tools/elements.js';
import { buildSourcesTools } from '../../src/tools/sources.js';
import { makeTestPool } from '../helpers/test-pool.js';

function noopClient(): ClientPool {
    return makeTestPool({
        baseUrl: 'https://example.com',
        bearer: 't',
        fetch: vi.fn() as unknown as typeof fetch,
    });
}

function inputSchemaOf<T extends { inputSchema: z.ZodRawShape }>(tool: T): z.ZodObject<T['inputSchema']> {
    return z.object(tool.inputSchema);
}

describe('zod input rejection — element tools', () => {
    const tools = buildElementsTools(noopClient());

    it('element_add rejects missing element_type', () => {
        const add = tools.find((t) => t.name === 'yootheme_builder_element_add');
        if (!add) throw new Error('tool not found');
        const schema = inputSchemaOf(add);
        const result = schema.safeParse({ template_id: 'tpl', etag: 'e' });
        expect(result.success).toBe(false);
    });

    it('element_add rejects missing etag', () => {
        const add = tools.find((t) => t.name === 'yootheme_builder_element_add');
        if (!add) throw new Error('tool not found');
        const schema = inputSchemaOf(add);
        const result = schema.safeParse({ template_id: 'tpl', element_type: 'headline' });
        expect(result.success).toBe(false);
    });

    it('element_get rejects empty element_path', () => {
        const get = tools.find((t) => t.name === 'yootheme_builder_element_get');
        if (!get) throw new Error('tool not found');
        const schema = inputSchemaOf(get);
        const result = schema.safeParse({ template_id: 'tpl', element_path: '' });
        expect(result.success).toBe(false);
    });

    it('element_move rejects negative to_index', () => {
        const move = tools.find((t) => t.name === 'yootheme_builder_element_move');
        if (!move) throw new Error('tool not found');
        const schema = inputSchemaOf(move);
        const result = schema.safeParse({
            template_id: 'tpl',
            element_path: '/0',
            to_parent_path: '',
            to_index: -1,
            etag: 'e',
        });
        expect(result.success).toBe(false);
    });

    it('element_move rejects non-integer to_index', () => {
        const move = tools.find((t) => t.name === 'yootheme_builder_element_move');
        if (!move) throw new Error('tool not found');
        const schema = inputSchemaOf(move);
        const result = schema.safeParse({
            template_id: 'tpl',
            element_path: '/0',
            to_parent_path: '',
            to_index: 1.5,
            etag: 'e',
        });
        expect(result.success).toBe(false);
    });

    it('element_update_settings rejects empty template_id', () => {
        const upd = tools.find((t) => t.name === 'yootheme_builder_element_update_settings');
        if (!upd) throw new Error('tool not found');
        const schema = inputSchemaOf(upd);
        const result = schema.safeParse({
            template_id: '',
            element_path: '/0',
            props: { x: 1 },
            etag: 'e',
        });
        expect(result.success).toBe(false);
    });
});

describe('zod input rejection — source tools', () => {
    const tools = buildSourcesTools(noopClient());

    it('element_bind_source rejects missing source_name', () => {
        const bind = tools.find((t) => t.name === 'yootheme_builder_element_bind_source');
        if (!bind) throw new Error('tool not found');
        const schema = inputSchemaOf(bind);
        const result = schema.safeParse({
            template_id: 'tpl',
            element_path: '/0',
            etag: 'e',
        });
        expect(result.success).toBe(false);
    });
});
