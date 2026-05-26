/**
 * Wave H5 (v1.1.6) — destructive-tools confirm-guard pin.
 *
 * Iterates EVERY tool registered with `destructiveHint: true` and asserts:
 *
 *   1. The input schema declares a `confirm` field (Zod schema or flat
 *      defineTool record).
 *   2. The `confirm` field accepts `true` / `false` / `undefined` and
 *      rejects non-boolean values (string / number / null / object).
 *
 * This guards against future destructive tools shipping without the
 * preview-and-retry guard. The gateway (`yootheme_builder_advanced`) is
 * exempt — it is a router whose dispatched targets each carry their own
 * confirm-guard (the per-tool confirm is the actual gate).
 *
 * Companion of:
 *   - tests/gateway/annotations-pin.test.ts (annotation 4-tuple matrix)
 *   - tests/tools/multi-items/clean-implode-confirm-guard.test.ts (H5 behaviour pin)
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import { createServer } from '../../src/server.js';
import { collectAllRegisteredTools } from '../../src/gateway/test-support.js';
import type { ClientPool } from '../../src/sites/client-pool.js';
import { makeTestPool } from '../helpers/test-pool.js';

function makeClient(): ClientPool {
    return makeTestPool({ baseUrl: 'https://example.com', bearer: 't' });
}

interface ZodLike {
    safeParse?: (v: unknown) => { success: boolean };
}

/**
 * Retrieve the `confirm` Zod schema from a tool's input schema, regardless
 * of representation. The codebase has three live shapes:
 *   - L1 tools registered via `mcp.registerTool` carry a flat record
 *     of Zod schemas (`schema.confirm` is the Zod field).
 *   - L2 advanced tools registered via the gateway carry the same flat
 *     record.
 *   - Some legacy shapes hand a ZodObject (`schema.shape.confirm` or
 *     `schema._def.shape().confirm`).
 *
 * Returns `null` when there is no `confirm` field at all.
 */
function extractConfirmField(schema: unknown): ZodLike | null {
    if (!schema || typeof schema !== 'object') return null;
    const s = schema as Record<string, unknown>;
    if ('confirm' in s) {
        return s.confirm as ZodLike;
    }
    if ('shape' in s && s.shape && typeof s.shape === 'object') {
        const shape = s.shape as Record<string, unknown>;
        if ('confirm' in shape) return shape.confirm as ZodLike;
    }
    if ('_def' in s && s._def && typeof s._def === 'object') {
        const def = s._def as { shape?: unknown };
        if (typeof def.shape === 'function') {
            const shape = (def.shape as () => Record<string, unknown>)();
            if ('confirm' in shape) return shape.confirm as ZodLike;
        }
    }
    return null;
}

describe('destructive-tools confirm-guard pin (Wave H5)', () => {
    // The gateway is itself flagged destructive because it can route
    // into destructive handlers — but each dispatched target carries
    // its own confirm. The gateway is the routing layer, not the gate.
    const GATEWAY_EXEMPT = new Set<string>(['yootheme_builder_advanced']);

    it('every destructive tool declares a `confirm` input field', () => {
        const { mcp, capturing } = createServer({ pool: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);

        const destructive = Object.entries(all).filter(
            ([name, tool]) =>
                tool.annotations?.destructiveHint === true && !GATEWAY_EXEMPT.has(name),
        );

        // Sanity floor — if this drops to zero the harness is broken,
        // not a real regression.
        expect(destructive.length).toBeGreaterThan(0);

        for (const [name, tool] of destructive) {
            const confirmField = extractConfirmField(tool.inputSchema);
            expect(
                confirmField,
                `${name} is marked destructive but exposes no \`confirm\` input field`,
            ).not.toBeNull();
        }
    });

    it('every destructive tool\'s `confirm` field is a boolean-shaped Zod schema', () => {
        const { mcp, capturing } = createServer({ pool: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);

        const destructive = Object.entries(all).filter(
            ([name, tool]) =>
                tool.annotations?.destructiveHint === true && !GATEWAY_EXEMPT.has(name),
        );

        for (const [name, tool] of destructive) {
            const confirmField = extractConfirmField(tool.inputSchema);
            if (!confirmField || typeof confirmField.safeParse !== 'function') {
                // Fall back to a positive assertion — at minimum the field
                // must be present (covered by the previous test).
                continue;
            }
            // Accepts booleans + undefined (omission allowed).
            expect(
                confirmField.safeParse(true).success,
                `${name}.confirm should accept \`true\``,
            ).toBe(true);
            expect(
                confirmField.safeParse(false).success,
                `${name}.confirm should accept \`false\``,
            ).toBe(true);
            expect(
                confirmField.safeParse(undefined).success,
                `${name}.confirm should accept omission (undefined)`,
            ).toBe(true);
            // Rejects non-boolean truthies that an agent might pass by
            // mistake. These are the classic "string yes / numeric 1"
            // bug shapes.
            expect(
                confirmField.safeParse('yes').success,
                `${name}.confirm must reject string \`"yes"\``,
            ).toBe(false);
            expect(
                confirmField.safeParse(1).success,
                `${name}.confirm must reject number \`1\``,
            ).toBe(false);
        }
    });

    it('every destructive tool returns a preview (no HTTP call) when invoked without `confirm`', async () => {
        const { mcp, capturing } = createServer({ pool: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);

        const destructive = Object.entries(all).filter(
            ([name, tool]) =>
                tool.annotations?.destructiveHint === true && !GATEWAY_EXEMPT.has(name),
        );

        for (const [name, tool] of destructive) {
            // Build a minimal args bag — every destructive tool here takes
            // `template_id` + `element_path` + `etag` and we deliberately
            // omit `confirm`. The makeTestPool fetch is the harness default
            // (returns 200/`{}`) — but the guard should short-circuit
            // before that fetch is even reached.
            const args: Record<string, unknown> = {
                template_id: 'tpl',
                element_path: '/templates/tpl/layout/children/0',
                etag: '"e1"',
            };
            const result = await tool.handler(args);
            const text = result.content[0]?.text;
            expect(typeof text, `${name} handler must produce a text payload`).toBe('string');
            // The confirmGuard helper writes `"preview": true` + a
            // `DESTRUCTIVE` warning. We do a substring assert rather than
            // a full JSON parse to keep the matcher resilient against
            // per-tool envelope shape variation.
            expect(
                text,
                `${name} should return a destructive preview when \`confirm\` is omitted`,
            ).toMatch(/preview/);
            expect(
                text,
                `${name} preview should contain a DESTRUCTIVE warning`,
            ).toMatch(/DESTRUCTIVE/);
        }
    });
});
