/**
 * CapturingServer unit tests.
 *
 *  - Essential names get FORWARDED to the real McpServer.
 *  - Non-essential names get CAPTURED into the advanced registry.
 *  - DIRECT_TOP_LEVEL_TOOLS names are SKIPPED entirely (no forward, no
 *    capture) — they must already be on the real server.
 *  - registerResource is always forwarded.
 *
 * @license MIT
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { describe, expect, it } from 'vitest';
import { z } from 'zod';

import { CapturingServer } from '../../src/gateway/capturing-server.js';

function makeServer(): McpServer {
    return new McpServer({ name: 'test', version: '0.0.0' });
}

interface RegisteredToolsRecord {
    _registeredTools: Record<string, unknown>;
}

function realToolNames(server: McpServer): string[] {
    const real = server as unknown as RegisteredToolsRecord;
    return Object.keys(real._registeredTools);
}

describe('CapturingServer', () => {
    it('forwards an L1 essential tool to the real McpServer', () => {
        const real = makeServer();
        const capturing = new CapturingServer(real);

        capturing.registerTool(
            'yootheme_builder_pages_list',
            {
                title: 'Pages List',
                description: 'List pages',
                inputSchema: {},
            },
            async () => ({ content: [{ type: 'text', text: 'ok' }] }),
        );

        expect(realToolNames(real)).toContain('yootheme_builder_pages_list');
        expect(capturing.getAdvancedRegistry().size).toBe(0);
    });

    it('captures a non-essential tool into the advanced registry (NOT on real server)', () => {
        const real = makeServer();
        const capturing = new CapturingServer(real);

        capturing.registerTool(
            'yootheme_builder_page_save',
            {
                title: 'Page Save',
                description: 'Save a page',
                inputSchema: { id: z.string() },
            },
            async () => ({ content: [{ type: 'text', text: 'saved' }] }),
        );

        expect(realToolNames(real)).not.toContain('yootheme_builder_page_save');
        expect(capturing.getAdvancedRegistry().has('yootheme_builder_page_save')).toBe(true);
        expect(capturing.getAdvancedRegistry().size).toBe(1);
    });

    it('SKIPS L3 direct top-level names (no forward, no capture)', () => {
        const real = makeServer();
        const capturing = new CapturingServer(real);

        capturing.registerTool(
            'yootheme_builder_health',
            {
                title: 'Health',
                description: 'Health check',
                inputSchema: {},
            },
            async () => ({ content: [{ type: 'text', text: 'health' }] }),
        );
        capturing.registerTool(
            'yootheme_builder_diagnose',
            {
                title: 'Diagnose',
                description: 'Diagnose',
                inputSchema: {},
            },
            async () => ({ content: [{ type: 'text', text: 'diag' }] }),
        );

        // Neither went to the real server (the real server must already
        // have its own direct registration), nor was either captured.
        expect(realToolNames(real)).not.toContain('yootheme_builder_health');
        expect(realToolNames(real)).not.toContain('yootheme_builder_diagnose');
        expect(capturing.getAdvancedRegistry().size).toBe(0);
    });

    it('stores the handler so the advanced gateway can invoke it later', async () => {
        const real = makeServer();
        const capturing = new CapturingServer(real);

        capturing.registerTool(
            'yootheme_builder_page_save',
            { description: 'save', inputSchema: { id: z.string() } },
            async ({ id }) => ({
                content: [{ type: 'text', text: `saved-${id}` }],
            }),
        );

        const entry = capturing.getAdvancedRegistry().get('yootheme_builder_page_save');
        expect(entry).toBeDefined();
        const result = await entry!.handler({ id: 'home' }, {});
        expect(result.content[0]).toMatchObject({ type: 'text', text: 'saved-home' });
    });

    it('routes a mix of all 3 lanes correctly in one pass', () => {
        const real = makeServer();
        const capturing = new CapturingServer(real);

        // L3 direct (skip)
        capturing.registerTool(
            'yootheme_builder_health',
            { description: 'h', inputSchema: {} },
            async () => ({ content: [] }),
        );
        // L1 essentials (forward)
        capturing.registerTool(
            'yootheme_builder_pages_list',
            { description: 'p', inputSchema: {} },
            async () => ({ content: [] }),
        );
        capturing.registerTool(
            'yootheme_builder_sources_list',
            { description: 's', inputSchema: {} },
            async () => ({ content: [] }),
        );
        // L2 advanced (capture)
        capturing.registerTool(
            'yootheme_builder_page_save',
            { description: 'ps', inputSchema: { id: z.string() } },
            async () => ({ content: [] }),
        );
        capturing.registerTool(
            'yootheme_builder_element_unbind_source',
            {
                description: 'ed',
                inputSchema: { id: z.string(), confirm: z.boolean() },
            },
            async () => ({ content: [] }),
        );

        const forwarded = realToolNames(real);
        expect(forwarded).toContain('yootheme_builder_pages_list');
        expect(forwarded).toContain('yootheme_builder_sources_list');
        expect(forwarded).not.toContain('yootheme_builder_health');
        expect(forwarded).not.toContain('yootheme_builder_page_save');

        const captured = [...capturing.getAdvancedRegistry().keys()].sort();
        expect(captured).toEqual([
            'yootheme_builder_element_unbind_source',
            'yootheme_builder_page_save',
        ]);
    });
});
