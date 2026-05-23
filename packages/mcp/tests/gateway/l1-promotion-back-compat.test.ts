/**
 * Pin-test for the L1 promotion contract — Wave 1.5 B8 (audit).
 *
 * Background:
 *   Wave-1 (commit 0fd83ddb8) promoted `yootheme_builder_element_type_
 *   get_schema` from L2 (advanced gateway) to L1 (first-class
 *   `tools/list` entry). Five earlier promotions (F-16) moved similar
 *   hot-path tools.
 *
 * Contract pinned here:
 *   The CapturingServer guarantees a **disjoint lane invariant** —
 *   `tools-list-size.test.ts` pins that L1 essentials NEVER appear in
 *   the advanced registry. Consequently, calling the gateway with an
 *   L1-promoted tool name (`yootheme_builder_advanced` with
 *   `{tool: 'yootheme_builder_element_type_get_schema'}`) returns the
 *   structured `unknown_tool` error rather than dispatching.
 *
 * Why pin this:
 *   The audit (Wave-1.5 B8) asked: either route L1 through the gateway
 *   too, OR pin the breaking-change semantic of L1 promotion. The
 *   lane-disjoint invariant is load-bearing for the CursorCap-safe
 *   `tools/list` size (15 L1 + 2 L3 + 1 gateway = 18) — dual-registering
 *   would surface every L1 tool in the gateway's description, blowing
 *   the description-length budget. So callers MUST call L1 tools
 *   directly. This pin makes that contract explicit.
 *
 * Migration guidance for any caller relying on the legacy gateway
 * call-form for an L1-promoted tool: call the tool name directly as a
 * `tools/list` entry. Both Claude Desktop and Cursor handle the
 * promotion transparently; only callers that hard-coded the gateway
 * dispatch path need to update.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { RestClient } from '../../src/client.js';
import { createServer } from '../../src/server.js';
import { ESSENTIAL_TOOLS } from '../../src/gateway/essentials.js';
import { collectAllRegisteredTools } from '../../src/gateway/test-support.js';

function makeClient(): RestClient {
    return new RestClient({
        baseUrl: 'https://example.com',
        bearerToken: 't',
        fetch: vi.fn(async () => new Response('{}', { status: 200 })) as unknown as typeof fetch,
    });
}

describe('Gateway L1-promotion contract', () => {
    it('every L1 essential is reachable as a direct tools/list entry', () => {
        const { mcp, capturing } = createServer({ client: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        for (const name of ESSENTIAL_TOOLS) {
            expect(all[name], `${name} missing from unified surface`).toBeDefined();
        }
    });

    it('gateway returns unknown_tool when called with an L1-promoted name', async () => {
        const { mcp, capturing } = createServer({ client: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        const gateway = all.yootheme_builder_advanced!;

        // Pick a representative L1-promoted tool. element_type_get_schema
        // is the canonical case (Wave-1 0fd83ddb8 L1 promotion).
        const result = (await gateway.handler({
            tool: 'yootheme_builder_element_type_get_schema',
        })) as { isError?: boolean; structuredContent?: { code?: string } };

        expect(result.isError).toBe(true);
        const sc = result.structuredContent;
        expect(sc?.code).toBe('unknown_tool');
    });

    it('the unknown_tool error suggestion still lists the advanced surface for orientation', async () => {
        const { mcp, capturing } = createServer({ client: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        const gateway = all.yootheme_builder_advanced!;

        // Call with an L1 name — the rejection message should still help
        // the caller see what IS reachable through the gateway. We assert
        // on the structured `details.valid_tools_by_domain` echo because
        // the textual `suggestion` is dynamically composed.
        const result = (await gateway.handler({
            tool: 'yootheme_builder_element_type_get_schema',
        })) as {
            structuredContent?: { details?: { valid_tools_by_domain?: string } };
        };
        const grouped = result.structuredContent?.details?.valid_tools_by_domain;
        expect(grouped).toBeDefined();
        // The L2-captured `element_unbind_source` is reachable; it should
        // appear in the orientation block.
        expect(grouped).toContain('element_unbind_source');
    });
});
