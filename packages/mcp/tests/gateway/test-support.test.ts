/**
 * `collectAllRegisteredTools` test-helper unit tests.
 *
 * After Wave G.1, tests can no longer scan `McpServer._registeredTools`
 * alone — non-essential tools are captured in the advanced registry.
 * `collectAllRegisteredTools` returns the unified view of every tool
 * (essentials forwarded to the real server + advanced from the registry
 * + the gateway tool itself).
 *
 * @license MIT
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { describe, expect, it } from 'vitest';
import { z } from 'zod';

import { registerAdvancedTool } from '../../src/gateway/advanced-tool.js';
import { CapturingServer } from '../../src/gateway/capturing-server.js';
import {
    collectAllRegisteredTools,
    findTool,
} from '../../src/gateway/test-support.js';

describe('collectAllRegisteredTools', () => {
    it('returns essentials forwarded to the real server', () => {
        const real = new McpServer({ name: 't', version: '0.0.0' });
        const capturing = new CapturingServer(real);
        capturing.registerTool(
            'yootheme_builder_pages_list',
            { description: 'list', inputSchema: {} },
            async () => ({ content: [] }),
        );
        registerAdvancedTool(real, capturing.getAdvancedRegistry());

        const all = collectAllRegisteredTools(real, capturing);
        expect(Object.keys(all)).toContain('yootheme_builder_pages_list');
    });

    it('returns advanced tools captured in the registry', () => {
        const real = new McpServer({ name: 't', version: '0.0.0' });
        const capturing = new CapturingServer(real);
        capturing.registerTool(
            'yootheme_builder_page_save',
            { description: 'save', inputSchema: { id: z.string() } },
            async () => ({ content: [] }),
        );
        registerAdvancedTool(real, capturing.getAdvancedRegistry());

        const all = collectAllRegisteredTools(real, capturing);
        expect(Object.keys(all)).toContain('yootheme_builder_page_save');
    });

    it('includes the gateway tool itself in the unified view', () => {
        const real = new McpServer({ name: 't', version: '0.0.0' });
        const capturing = new CapturingServer(real);
        registerAdvancedTool(real, capturing.getAdvancedRegistry());

        const all = collectAllRegisteredTools(real, capturing);
        expect(Object.keys(all)).toContain('yootheme_builder_advanced');
    });

    it('findTool locates by name across both surfaces', () => {
        const real = new McpServer({ name: 't', version: '0.0.0' });
        const capturing = new CapturingServer(real);
        capturing.registerTool(
            'yootheme_builder_pages_list',
            { description: 'list', inputSchema: {} },
            async () => ({ content: [] }),
        );
        capturing.registerTool(
            'yootheme_builder_page_save',
            { description: 'save', inputSchema: { id: z.string() } },
            async () => ({ content: [] }),
        );
        registerAdvancedTool(real, capturing.getAdvancedRegistry());

        const all = collectAllRegisteredTools(real, capturing);
        const fwd = findTool(all, 'yootheme_builder_pages_list');
        const cap = findTool(all, 'yootheme_builder_page_save');
        expect(fwd).toBeDefined();
        expect(cap).toBeDefined();
        expect(findTool(all, 'does_not_exist')).toBeUndefined();
    });
});
