/**
 * Pin-tests for the Cursor-cap-safe `tools/list` surface.
 *
 * After Wave G.1 (with the 1.0.1 element_type_get_schema promotion), an
 * LLM-host enumerating `tools/list` MUST see exactly 18 entries
 * (15 L1 essentials + 2 L3 direct + 1 gateway). Every other tool
 * reachable through `yootheme_builder_advanced`. The total tool count
 * across BOTH surfaces is exactly 25.
 *
 * These counts are PINNED. A failing pin = a wave-spec drift; either
 * (a) the spec genuinely needs to change and the pin updates, or
 * (b) a builder accidentally added/removed a tool.
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import { RestClient } from '../../src/client.js';
import { createServer } from '../../src/server.js';
import {
    DIRECT_TOP_LEVEL_TOOLS,
    ESSENTIAL_TOOLS,
} from '../../src/gateway/essentials.js';
import { collectAllRegisteredTools } from '../../src/gateway/test-support.js';

function makeClient(): RestClient {
    return new RestClient({ baseUrl: 'https://example.com', bearerToken: 't' });
}

interface RegisteredToolsRecord {
    _registeredTools: Record<string, unknown>;
}

function realToolNames(server: ReturnType<typeof createServer>['mcp']): string[] {
    return Object.keys((server as unknown as RegisteredToolsRecord)._registeredTools);
}

describe('tools/list surface (Cursor-cap-safe)', () => {
    it('the real server exposes exactly 18 tools: 15 L1 + 2 L3 + 1 gateway', () => {
        const { mcp } = createServer({ client: makeClient() });
        const names = realToolNames(mcp).sort();
        expect(names.length).toBe(18);
    });

    it('the 15 L1 essentials are all on the real server', () => {
        const { mcp } = createServer({ client: makeClient() });
        const names = realToolNames(mcp);
        for (const name of ESSENTIAL_TOOLS) {
            expect(names, `${name} (L1) missing from tools/list`).toContain(name);
        }
    });

    it('the 2 L3 direct tools are on the real server', () => {
        const { mcp } = createServer({ client: makeClient() });
        const names = realToolNames(mcp);
        for (const name of DIRECT_TOP_LEVEL_TOOLS) {
            expect(names, `${name} (L3) missing from tools/list`).toContain(name);
        }
    });

    it('the gateway tool `yootheme_builder_advanced` is on the real server', () => {
        const { mcp } = createServer({ client: makeClient() });
        expect(realToolNames(mcp)).toContain('yootheme_builder_advanced');
    });

    it('collectAllRegisteredTools returns exactly 25 tools (full surface)', () => {
        const { mcp, capturing } = createServer({ client: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        expect(Object.keys(all).length).toBe(25);
    });

    it('the L2 advanced surface holds exactly 7 captured tools', () => {
        // L2 = all tools - L1 (15 essentials) - L3 (2 direct) - gateway (1) = 7
        // F-16 (Audit v2): 5 hot-path tools promoted L2 → L1.
        // 1.0.1: element_type_get_schema promoted L2 → L1, shrinking L2 further.
        const { capturing } = createServer({ client: makeClient() });
        expect(capturing.getAdvancedRegistry().size).toBe(7);
    });

    it('no L1 essential leaks into the advanced registry (lane disjoint)', () => {
        const { capturing } = createServer({ client: makeClient() });
        for (const name of ESSENTIAL_TOOLS) {
            expect(
                capturing.getAdvancedRegistry().has(name),
                `${name} (L1) must not be captured by the gateway`,
            ).toBe(false);
        }
    });

    it('no L3 direct tool leaks into the advanced registry', () => {
        const { capturing } = createServer({ client: makeClient() });
        for (const name of DIRECT_TOP_LEVEL_TOOLS) {
            expect(
                capturing.getAdvancedRegistry().has(name),
                `${name} (L3) must not be captured by the gateway`,
            ).toBe(false);
        }
    });
});
