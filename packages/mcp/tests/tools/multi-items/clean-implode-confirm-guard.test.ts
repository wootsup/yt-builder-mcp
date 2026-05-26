/**
 * Wave H5 (v1.1.6) — `clean_implode_directives` destructive confirm-guard.
 *
 * The tool was previously registered with `mutating()` and executed the
 * REST call on first invocation. As of v1.1.6 it carries `destructive()`
 * and requires an explicit `confirm: true` before doing any work. This
 * test pins the guard behaviour at the handler boundary so future drift
 * surfaces as a clear diff.
 *
 * Companion of:
 *   - tests/gateway/annotations-pin.test.ts (per-tool annotation matrix)
 *   - tests/tools/destructive-tools-confirm-guard-pin.test.ts (cross-tool pin)
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import type { ClientPool } from '../../../src/sites/client-pool.js';
import { buildMultiItemsTools } from '../../../src/tools/multi-items/index.js';
import { makeTestPool, stripSitePrefix } from '../../helpers/test-pool.js';

function findTool(tools: ReturnType<typeof buildMultiItemsTools>, name: string) {
    const t = tools.find((x) => x.name === name);
    if (!t) throw new Error(`Tool ${name} not found`);
    return t;
}

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('clean_implode_directives confirm-guard (Wave H5)', () => {
    it('without `confirm: true`, returns a preview and makes NO HTTP call', async () => {
        const fetchSpy = vi.fn(async () =>
            jsonResponse({
                template_id: 'tpl',
                element_path: '/0',
                cleaned_count: 99,
                removed_directives: [],
                new_etag: '"should-never-see-this"',
            }),
        );
        const pool: ClientPool = makeTestPool({
            baseUrl: 'https://example.com',
            bearer: 't',
            fetch: fetchSpy as unknown as typeof fetch,
        });
        const tools = buildMultiItemsTools(pool);

        const result = await findTool(
            tools,
            'yootheme_builder_clean_implode_directives',
        ).handler({
            template_id: 'tpl',
            element_path: '/templates/tpl/layout/children/1',
            etag: '"e2"',
            // confirm omitted on purpose
        });

        // Zero HTTP calls happened — the guard short-circuited before the
        // RestClient was reached.
        expect(fetchSpy).not.toHaveBeenCalled();

        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as {
            preview: boolean;
            warning: string;
            operation: string;
            details: { template_id: string; element_path: string };
            instruction: string;
        };
        expect(parsed.preview).toBe(true);
        expect(parsed.warning).toContain('DESTRUCTIVE');
        expect(parsed.operation).toContain('implode');
        expect(parsed.details.template_id).toBe('tpl');
        expect(parsed.details.element_path).toBe('/templates/tpl/layout/children/1');
        expect(parsed.instruction).toContain('confirm: true');
    });

    it('with explicit `confirm: false`, also returns a preview and makes NO HTTP call', async () => {
        const fetchSpy = vi.fn(async () =>
            jsonResponse({
                template_id: 'tpl',
                element_path: '/0',
                cleaned_count: 0,
                removed_directives: [],
                new_etag: '"e3"',
            }),
        );
        const pool: ClientPool = makeTestPool({
            baseUrl: 'https://example.com',
            bearer: 't',
            fetch: fetchSpy as unknown as typeof fetch,
        });
        const tools = buildMultiItemsTools(pool);

        const result = await findTool(
            tools,
            'yootheme_builder_clean_implode_directives',
        ).handler({
            template_id: 'tpl',
            element_path: '/0',
            etag: '"e2"',
            confirm: false,
        });

        expect(fetchSpy).not.toHaveBeenCalled();
        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as {
            preview: boolean;
        };
        expect(parsed.preview).toBe(true);
    });

    it('with `confirm: true`, executes the POST and returns the audit log', async () => {
        const fetchSpy = vi.fn(async () =>
            jsonResponse({
                template_id: 'tpl',
                element_path: '/0',
                cleaned_count: 1,
                removed_directives: [{ prop_name: 'title', directive: { join: ',' } }],
                new_etag: '"e3"',
            }),
        );
        const pool: ClientPool = makeTestPool({
            baseUrl: 'https://example.com',
            bearer: 't',
            fetch: fetchSpy as unknown as typeof fetch,
        });
        const tools = buildMultiItemsTools(pool);

        const result = await findTool(
            tools,
            'yootheme_builder_clean_implode_directives',
        ).handler({
            template_id: 'tpl',
            element_path: '/0',
            etag: '"e2"',
            confirm: true,
        });

        expect(fetchSpy).toHaveBeenCalledTimes(1);
        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as {
            cleaned_count: number;
            removed_directives: Array<{ prop_name: string }>;
        };
        expect(parsed.cleaned_count).toBe(1);
        expect(parsed.removed_directives[0]!.prop_name).toBe('title');
    });
});

describe('clean_implode_directives input schema (Wave H5)', () => {
    it('declares `confirm` as an optional boolean field', () => {
        const pool: ClientPool = makeTestPool({
            baseUrl: 'https://example.com',
            bearer: 't',
        });
        const tools = buildMultiItemsTools(pool);
        const tool = findTool(tools, 'yootheme_builder_clean_implode_directives');

        // The input schema is a flat record of Zod schemas (defineTool
        // shape). Walk the `confirm` field directly.
        const schema = tool.inputSchema as Record<string, unknown>;
        expect('confirm' in schema).toBe(true);

        // Verify default-parse semantics: omitting `confirm` is OK
        // (optional); passing a non-boolean fails.
        type ZodLike = {
            safeParse?: (v: unknown) => { success: boolean };
        };
        const confirmField = schema.confirm as ZodLike;
        if (confirmField.safeParse) {
            expect(confirmField.safeParse(undefined).success).toBe(true);
            expect(confirmField.safeParse(true).success).toBe(true);
            expect(confirmField.safeParse(false).success).toBe(true);
            expect(confirmField.safeParse('yes').success).toBe(false);
            expect(confirmField.safeParse(1).success).toBe(false);
        }
    });

    it('is registered with the destructive annotation', () => {
        const pool: ClientPool = makeTestPool({
            baseUrl: 'https://example.com',
            bearer: 't',
        });
        const tools = buildMultiItemsTools(pool);
        const tool = findTool(tools, 'yootheme_builder_clean_implode_directives');
        expect(tool.annotations?.destructiveHint).toBe(true);
        expect(tool.annotations?.idempotentHint).toBe(false);
        expect(tool.annotations?.readOnlyHint).toBe(false);
    });
});
