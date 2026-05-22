/**
 * Snapshot-style pin tests for every tool's annotations across all 3 lanes.
 *
 * Annotations drive LLM-host UI behaviour (read-only vs. mutating vs.
 * destructive). Re-shaping a tool's annotations silently changes how the
 * host treats it; the pins surface that change as a diff in PR review.
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import { RestClient } from '../../src/client.js';
import { createServer } from '../../src/server.js';
import { collectAllRegisteredTools } from '../../src/gateway/test-support.js';

function makeClient(): RestClient {
    return new RestClient({ baseUrl: 'https://example.com', bearerToken: 't' });
}

describe('annotation pin tests (all lanes)', () => {
    it('every registered tool has annotations (no missing flag set)', () => {
        const { mcp, capturing } = createServer({ client: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        for (const [name, tool] of Object.entries(all)) {
            expect(tool.annotations, `${name} missing annotations object`).toBeDefined();
        }
    });

    it('every destructive tool has destructiveHint=true AND a confirm parameter', () => {
        const { mcp, capturing } = createServer({ client: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        for (const [name, tool] of Object.entries(all)) {
            const ann = tool.annotations ?? {};
            // Stream D3 T3: the gateway router carries `destructiveHint:
            // true` because it can dispatch into delete/unbind handlers,
            // but it is not itself the mutation site — the target tool's
            // own confirm-guard enforces the prompt. Exempt from the
            // per-tool confirm rule.
            if (name === 'yootheme_builder_advanced') continue;
            if (ann.destructiveHint === true) {
                // F-16 (2026-05-22): destructive tools now span lanes. L2
                // advanced tools carry the raw `defineTool`-shape map; L1
                // tools registered via `mcp.registerTool` may surface a
                // zod ZodObject. Accept both representations.
                const schema = tool.inputSchema as unknown;
                let hasConfirm = false;
                if (schema && typeof schema === 'object') {
                    const s = schema as Record<string, unknown>;
                    if ('confirm' in s) {
                        hasConfirm = true;
                    } else if (
                        'shape' in s &&
                        s.shape &&
                        typeof s.shape === 'object' &&
                        'confirm' in (s.shape as Record<string, unknown>)
                    ) {
                        hasConfirm = true;
                    } else if (
                        '_def' in s &&
                        s._def &&
                        typeof s._def === 'object' &&
                        'shape' in (s._def as Record<string, unknown>) &&
                        typeof (s._def as { shape?: unknown }).shape === 'function'
                    ) {
                        const shapeFn = (s._def as { shape: () => Record<string, unknown> }).shape;
                        hasConfirm = 'confirm' in shapeFn();
                    }
                }
                expect(
                    hasConfirm,
                    `${name} marked destructive but has no \`confirm\` input field`,
                ).toBe(true);
            }
        }
    });

    it('every read-only tool has readOnlyHint=true (list/get convention)', () => {
        const { mcp, capturing } = createServer({ client: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        for (const [name, tool] of Object.entries(all)) {
            if (name === 'yootheme_builder_advanced') continue;
            if (name.endsWith('_list') || name.endsWith('_get') || name.endsWith('_get_layout') ||
                name.endsWith('_get_schema') || name.endsWith('_get_binding') || name === 'yootheme_builder_get_etag' ||
                name === 'yootheme_builder_health' || name === 'yootheme_builder_diagnose') {
                expect(
                    tool.annotations?.readOnlyHint,
                    `${name} (read-only convention) should set readOnlyHint=true`,
                ).toBe(true);
            }
        }
    });

    it('gateway tool annotations: non-readOnly, destructive, openWorld, non-idempotent', () => {
        const { mcp, capturing } = createServer({ client: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        const gw = all.yootheme_builder_advanced;
        expect(gw).toBeDefined();
        const ann = gw!.annotations ?? {};
        // Gateway is a router that can dispatch into delete/unbind
        // handlers — conservative spec-default treats it as destructive.
        // openWorld=true because the gateway's behaviour is dynamic
        // (target tool decided at call-time), making it effectively
        // open-world from the host's UI perspective.
        expect(ann.readOnlyHint).toBe(false);
        expect(ann.destructiveHint).toBe(true);
        expect(ann.idempotentHint).toBe(false);
        expect(ann.openWorldHint).toBe(true);
    });

    // T3 (Audit-v3): every registered tool MUST carry the full 4-hint
    // tuple (readOnly + destructive + idempotent + openWorld). Anthropic
    // MCP spec 2026-03-16 — conservative default treats `undefined` hints
    // as "potentially destructive". Setting all 4 explicitly removes that
    // ambiguity.
    it('every registered tool sets ALL four behavioural hints explicitly', () => {
        const { mcp, capturing } = createServer({ client: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        for (const [name, tool] of Object.entries(all)) {
            const ann = tool.annotations ?? {};
            expect(typeof ann.readOnlyHint, `${name}.readOnlyHint must be boolean`).toBe('boolean');
            expect(typeof ann.destructiveHint, `${name}.destructiveHint must be boolean`).toBe(
                'boolean',
            );
            expect(typeof ann.idempotentHint, `${name}.idempotentHint must be boolean`).toBe(
                'boolean',
            );
            expect(typeof ann.openWorldHint, `${name}.openWorldHint must be boolean`).toBe(
                'boolean',
            );
        }
    });

    // T3 per-tool matrix (Stream D3 spec — Audit-v3 Anthropic alignment).
    // Builder domain is closed-world (REST is a local WP option store);
    // gateway is the only open-world router.
    const EXPECTED_MATRIX: Record<
        string,
        {
            readOnlyHint: boolean;
            destructiveHint: boolean;
            idempotentHint: boolean;
            openWorldHint: boolean;
        }
    > = {
        // read-only L1 + L3
        yootheme_builder_health: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_diagnose: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_get_etag: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_pages_list: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_page_get_layout: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_page_get_schema: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_element_list: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_element_get: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_element_types_list: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_element_type_get_schema: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_sources_list: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_element_get_binding: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_inspect_multi_items_binding: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },

        // additive (creating) writes — not idempotent
        yootheme_builder_element_add: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
        yootheme_builder_element_clone: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },

        // idempotent mutating writes
        yootheme_builder_element_bind_source: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_element_update_settings: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_element_move: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_page_save: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_page_publish: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
        yootheme_builder_clean_implode_directives: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },

        // destructive writes
        yootheme_builder_element_delete: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
        yootheme_builder_element_unbind_source: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },

        // gateway — open-world router, dispatches into destructive handlers
        yootheme_builder_advanced: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true },
    };

    it('per-tool annotation matrix matches T3 spec (Audit-v3)', () => {
        const { mcp, capturing } = createServer({ client: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        for (const [name, expected] of Object.entries(EXPECTED_MATRIX)) {
            const tool = all[name];
            if (!tool) continue; // tool may not yet exist (D1 template_summary)
            const ann = tool.annotations ?? {};
            expect(ann.readOnlyHint, `${name}.readOnlyHint`).toBe(expected.readOnlyHint);
            expect(ann.destructiveHint, `${name}.destructiveHint`).toBe(expected.destructiveHint);
            expect(ann.idempotentHint, `${name}.idempotentHint`).toBe(expected.idempotentHint);
            expect(ann.openWorldHint, `${name}.openWorldHint`).toBe(expected.openWorldHint);
        }
    });

    it('pin snapshot: per-tool annotation shape (alphabetised)', () => {
        const { mcp, capturing } = createServer({ client: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        const snapshot: Record<string, Record<string, unknown>> = {};
        for (const name of Object.keys(all).sort()) {
            snapshot[name] = all[name]!.annotations ?? {};
        }
        // Tool count is 24 (post-F-16 promotion). D1 may add
        // `template_summary` — adjust expected count if/when it lands.
        expect(Object.keys(snapshot).length).toBeGreaterThanOrEqual(24);
        // Every entry advertises the full 4-tuple (T3 enforcement).
        for (const [name, ann] of Object.entries(snapshot)) {
            expect(typeof ann.readOnlyHint, `${name}.readOnlyHint must be boolean`).toBe('boolean');
            expect(typeof ann.destructiveHint, `${name}.destructiveHint must be boolean`).toBe(
                'boolean',
            );
            expect(typeof ann.idempotentHint, `${name}.idempotentHint must be boolean`).toBe(
                'boolean',
            );
            expect(typeof ann.openWorldHint, `${name}.openWorldHint must be boolean`).toBe(
                'boolean',
            );
        }
    });
});
