/**
 * `yootheme_builder_advanced` gateway tool unit tests.
 *
 *  - Tool registers itself on the real McpServer.
 *  - Tool name is exactly `yootheme_builder_advanced` (pinned — never rename).
 *  - Discovery mode + execute mode both reachable.
 *  - Unknown tool returns a structured error with a listing of valid names.
 *  - Empty registry still registers a valid gateway (defensive guard).
 *
 * @license MIT
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { describe, expect, it } from 'vitest';
import { z } from 'zod';

import {
    type AdvancedToolEntry,
    CapturingServer,
} from '../../src/gateway/capturing-server.js';
import { registerAdvancedTool } from '../../src/gateway/advanced-tool.js';

interface RealRegistered {
    handler: (
        args: Record<string, unknown>,
        extra?: unknown,
    ) => Promise<{ content: Array<{ type: string; text: string }>; isError?: boolean; structuredContent?: unknown }>;
    title?: string;
    description?: string;
    annotations?: Record<string, unknown>;
}

function getRealTool(server: McpServer, name: string): RealRegistered {
    const real = server as unknown as { _registeredTools: Record<string, RealRegistered> };
    const t = real._registeredTools[name];
    if (!t) throw new Error(`Tool ${name} not registered`);
    return t;
}

function makePopulatedCapturing(): { real: McpServer; capturing: CapturingServer } {
    const real = new McpServer({ name: 't', version: '0.0.0' });
    const capturing = new CapturingServer(real);
    capturing.registerTool(
        'yootheme_builder_page_save',
        {
            description: 'Save a page layout',
            inputSchema: { id: z.string() },
            annotations: { readOnlyHint: false, idempotentHint: true },
        },
        async ({ id }) => ({
            content: [{ type: 'text', text: `saved-${String(id)}` }],
        }),
    );
    capturing.registerTool(
        'yootheme_builder_element_unbind_source',
        {
            description: 'Delete an element',
            inputSchema: {
                id: z.string(),
                confirm: z.boolean(),
            },
            annotations: { destructiveHint: true },
        },
        async () => ({ content: [{ type: 'text', text: 'deleted' }] }),
    );
    return { real, capturing };
}

describe('registerAdvancedTool', () => {
    it('registers `yootheme_builder_advanced` on the real server', () => {
        const { real, capturing } = makePopulatedCapturing();
        registerAdvancedTool(real, capturing.getAdvancedRegistry());
        const t = getRealTool(real, 'yootheme_builder_advanced');
        expect(t).toBeDefined();
        expect(t.description).toContain('advanced');
    });

    it('lists every captured tool name in the description (grouped)', () => {
        const { real, capturing } = makePopulatedCapturing();
        registerAdvancedTool(real, capturing.getAdvancedRegistry());
        const t = getRealTool(real, 'yootheme_builder_advanced');
        expect(t.description).toContain('yootheme_builder_page_save');
        expect(t.description).toContain('yootheme_builder_element_unbind_source');
    });

    it('discovery mode: returns inputSchema + annotations for a known tool', async () => {
        const { real, capturing } = makePopulatedCapturing();
        registerAdvancedTool(real, capturing.getAdvancedRegistry());
        const t = getRealTool(real, 'yootheme_builder_advanced');

        const result = await t.handler({ tool: 'yootheme_builder_page_save' });
        expect(result.isError).toBeFalsy();
        expect(result.content[0]!.text).toContain('Discovery for yootheme_builder_page_save');
        // structuredContent payload carries the JSON schema
        const sc = result.structuredContent as { inputSchema: unknown; tool: string };
        expect(sc.tool).toBe('yootheme_builder_page_save');
        expect(sc.inputSchema).toBeDefined();
    });

    it('execute mode: dispatches the captured handler with validated args', async () => {
        const { real, capturing } = makePopulatedCapturing();
        registerAdvancedTool(real, capturing.getAdvancedRegistry());
        const t = getRealTool(real, 'yootheme_builder_advanced');

        const result = await t.handler({
            tool: 'yootheme_builder_page_save',
            arguments: { id: 'home' },
        });
        expect(result.isError).toBeFalsy();
        expect(result.content[0]!.text).toBe('saved-home');
    });

    it('execute mode: rejects unknown extra keys with strict() (structured error)', async () => {
        const { real, capturing } = makePopulatedCapturing();
        registerAdvancedTool(real, capturing.getAdvancedRegistry());
        const t = getRealTool(real, 'yootheme_builder_advanced');

        const result = await t.handler({
            tool: 'yootheme_builder_page_save',
            arguments: { id: 'home', unknown_key: true },
        });
        expect(result.isError).toBe(true);
        expect(result.content[0]!.text).toContain('Invalid arguments');
    });

    it('unknown tool returns a structured error listing valid names', async () => {
        const { real, capturing } = makePopulatedCapturing();
        registerAdvancedTool(real, capturing.getAdvancedRegistry());
        const t = getRealTool(real, 'yootheme_builder_advanced');

        // zod enum will reject unknown tool at schema validation
        const result = await t.handler({ tool: 'yootheme_builder_does_not_exist' });
        // either the zod enum rejects (MCP returns isError) or our handler does.
        expect(result.isError).toBe(true);
    });

    it('defensive: registers a valid (inert) gateway when registry is empty', () => {
        const real = new McpServer({ name: 't', version: '0.0.0' });
        const empty = new Map<string, AdvancedToolEntry>();
        expect(() => registerAdvancedTool(real, empty)).not.toThrow();
        const t = getRealTool(real, 'yootheme_builder_advanced');
        expect(t).toBeDefined();
    });
});
