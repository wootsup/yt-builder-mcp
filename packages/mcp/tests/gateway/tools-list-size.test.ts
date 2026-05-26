/**
 * Pin-tests for the Cursor-cap-safe `tools/list` surface.
 *
 * Post-W7 (Multi-Site): an LLM-host enumerating `tools/list` MUST see
 * exactly 20 entries (17 L1 essentials + 2 L3 direct + 1 gateway).
 * Every other tool reachable through `yootheme_builder_advanced`. The
 * total tool count across BOTH surfaces is exactly 27 (15 prior L1 + 7
 * L2 + 2 L3 + 1 gateway + 2 new W7 L1).
 *
 * History:
 *   - Wave G.1 + 1.0.1: 15 L1 / 18 tools/list / 25 total
 *   - W7 Multi-Site:    17 L1 / 20 tools/list / 27 total (sites_list +
 *                       sites_test added as L1 platform-agnostic).
 *
 * These counts are PINNED. A failing pin = a wave-spec drift; either
 * (a) the spec genuinely needs to change and the pin updates, or
 * (b) a builder accidentally added/removed a tool.
 *
 * @license MIT
 */

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import { describe, expect, it } from 'vitest';

import { createServer } from '../../src/server.js';
import {
    DIRECT_TOP_LEVEL_TOOLS,
    ESSENTIAL_TOOLS,
} from '../../src/gateway/essentials.js';
import { collectAllRegisteredTools } from '../../src/gateway/test-support.js';
import type { ClientPool } from '../../src/sites/client-pool.js';
import { makeTestPool } from '../helpers/test-pool.js';

function makeClient(): ClientPool {
    return makeTestPool({ baseUrl: 'https://example.com', bearer: 't' });
}

interface RegisteredToolsRecord {
    _registeredTools: Record<string, unknown>;
}

function realToolNames(server: ReturnType<typeof createServer>['mcp']): string[] {
    return Object.keys((server as unknown as RegisteredToolsRecord)._registeredTools);
}

describe('tools/list surface (Cursor-cap-safe)', () => {
    it('the real server exposes exactly 20 tools: 17 L1 + 2 L3 + 1 gateway', () => {
        const { mcp } = createServer({ pool: makeClient() });
        const names = realToolNames(mcp).sort();
        expect(names.length).toBe(20);
    });

    it('the 17 L1 essentials are all on the real server', () => {
        const { mcp } = createServer({ pool: makeClient() });
        const names = realToolNames(mcp);
        for (const name of ESSENTIAL_TOOLS) {
            expect(names, `${name} (L1) missing from tools/list`).toContain(name);
        }
    });

    it('the 2 L3 direct tools are on the real server', () => {
        const { mcp } = createServer({ pool: makeClient() });
        const names = realToolNames(mcp);
        for (const name of DIRECT_TOP_LEVEL_TOOLS) {
            expect(names, `${name} (L3) missing from tools/list`).toContain(name);
        }
    });

    it('the gateway tool `yootheme_builder_advanced` is on the real server', () => {
        const { mcp } = createServer({ pool: makeClient() });
        expect(realToolNames(mcp)).toContain('yootheme_builder_advanced');
    });

    it('collectAllRegisteredTools returns exactly 27 tools (full surface)', () => {
        // Pre-W7: 25 (15 L1 + 7 L2 + 2 L3 + 1 gateway).
        // Post-W7: +2 L1 (sites_list + sites_test) = 27 total.
        const { mcp, capturing } = createServer({ pool: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        expect(Object.keys(all).length).toBe(27);
    });

    it('the L2 advanced surface holds exactly 7 captured tools', () => {
        // L2 = all tools - L1 (17 essentials post-W7) - L3 (2 direct)
        //    - gateway (1) = 7 (unchanged — W7 added L1 only).
        // F-16 (Audit v2): 5 hot-path tools promoted L2 → L1.
        // 1.0.1: element_type_get_schema promoted L2 → L1.
        // W7: sites_list + sites_test added directly as L1.
        const { capturing } = createServer({ pool: makeClient() });
        expect(capturing.getAdvancedRegistry().size).toBe(7);
    });

    it('no L1 essential leaks into the advanced registry (lane disjoint)', () => {
        const { capturing } = createServer({ pool: makeClient() });
        for (const name of ESSENTIAL_TOOLS) {
            expect(
                capturing.getAdvancedRegistry().has(name),
                `${name} (L1) must not be captured by the gateway`,
            ).toBe(false);
        }
    });

    it('no L3 direct tool leaks into the advanced registry', () => {
        const { capturing } = createServer({ pool: makeClient() });
        for (const name of DIRECT_TOP_LEVEL_TOOLS) {
            expect(
                capturing.getAdvancedRegistry().has(name),
                `${name} (L3) must not be captured by the gateway`,
            ).toBe(false);
        }
    });
});
