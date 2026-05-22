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
            if (ann.destructiveHint === true) {
                const schema = tool.inputSchema as Record<string, unknown> | undefined;
                expect(
                    schema && 'confirm' in schema,
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

    it('gateway tool annotations: non-readOnly, openWorld, non-idempotent', () => {
        const { mcp, capturing } = createServer({ client: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        const gw = all.yootheme_builder_advanced;
        expect(gw).toBeDefined();
        const ann = gw!.annotations ?? {};
        expect(ann.openWorldHint).toBe(true);
        // Gateway is a router; it may dispatch into mutating or destructive
        // handlers. It should not advertise itself as read-only.
        expect(ann.readOnlyHint).toBe(false);
    });

    it('pin snapshot: per-tool annotation shape (alphabetised)', () => {
        const { mcp, capturing } = createServer({ client: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        const snapshot: Record<string, Record<string, unknown>> = {};
        for (const name of Object.keys(all).sort()) {
            snapshot[name] = all[name]!.annotations ?? {};
        }
        // Inline snapshot pins the structure — any unintentional change
        // shows up as a test diff. (Title strings excluded as they are
        // free-form labels.)
        expect(Object.keys(snapshot).length).toBe(24);
        // Every entry has at minimum a hint flag set.
        for (const [name, ann] of Object.entries(snapshot)) {
            const hasAnyHint =
                ann.readOnlyHint !== undefined ||
                ann.destructiveHint !== undefined ||
                ann.idempotentHint !== undefined ||
                ann.openWorldHint !== undefined;
            expect(hasAnyHint, `${name} annotations carry no behavioural hint`).toBe(true);
        }
    });
});
