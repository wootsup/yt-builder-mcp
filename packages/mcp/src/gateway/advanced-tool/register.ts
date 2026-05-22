/**
 * Gateway tool registration — wires `yootheme_builder_advanced` onto the
 * real McpServer, with discovery-mode + execute-mode dispatch.
 *
 * Round-2 (R2-A2-CRIT2) extracted from `gateway/advanced-tool.ts`.
 *
 * @license MIT
 */

import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';

import type { AdvancedToolEntry } from '../capturing-server.js';
import { buildDescription, renderGroupedList } from './domains.js';
import { discoveryResult } from './discovery.js';
import { errorResult, executeAdvancedTool } from './execute.js';

/** Builds the gateway tool's Zod inputSchema for a given non-empty enum of advanced names. */
function gatewayInputSchema(enumValues: [string, ...string[]]) {
    return {
        tool: z.enum(enumValues).describe(
            'Advanced tool to route to (e.g. "yootheme_builder_page_save"). ' +
                'Omit `arguments` to discover its schema first.',
        ),
        arguments: z.record(z.string(), z.unknown()).optional().describe(
            'Arguments for the target tool. Omit for discovery mode ' +
                "(returns the tool's schema + annotations).",
        ),
    };
}

/**
 * Registers `yootheme_builder_advanced` on the real McpServer.
 *
 * @param realServer       the McpServer that exposes the gateway tool
 * @param advancedRegistry the captured non-essential tools (from CapturingServer)
 */
export function registerAdvancedTool(
    realServer: McpServer,
    advancedRegistry: Map<string, AdvancedToolEntry>,
): void {
    const advancedNames = [...advancedRegistry.keys()].sort();

    // z.enum needs a non-empty tuple. The empty-registry arm is exercised
    // only by defensive test wiring; production always populates the
    // registry. Keeping the guard means a zero-tool wiring still yields a
    // valid (if inert) gateway schema rather than a z.enum crash.
    const enumValues: [string, ...string[]] =
        advancedNames.length > 0 ? (advancedNames as [string, ...string[]]) : ['__none__'];

    realServer.registerTool(
        'yootheme_builder_advanced',
        {
            title: 'Advanced Tool Gateway',
            description: buildDescription(advancedNames),
            inputSchema: gatewayInputSchema(enumValues),
            annotations: {
                title: 'Advanced Tool Gateway',
                readOnlyHint: false,
                openWorldHint: true,
                idempotentHint: false,
            },
        },
        async (args, extra): Promise<CallToolResult> => {
            const entry = advancedRegistry.get(args.tool);
            if (!entry) {
                return errorResult({
                    message: `Unknown advanced tool: "${args.tool}".`,
                    code: 'unknown_tool',
                    suggestion:
                        'Pick one of the valid advanced tool names below, or call ' +
                        'yootheme_builder_health to verify connectivity.\n\n' +
                        `Valid advanced tools by domain:\n${renderGroupedList(advancedNames)}`,
                    details: { valid_tools_by_domain: renderGroupedList(advancedNames) },
                });
            }
            if (args.arguments === undefined) return discoveryResult(args.tool, entry);
            return executeAdvancedTool(args.tool, entry, args.arguments, extra);
        },
    );
}
