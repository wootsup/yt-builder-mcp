/**
 * Gateway test helpers — Wave G.1.
 *
 * After the gateway lands, tests that previously looked tools up from
 * `McpServer._registeredTools` no longer find advanced tools (they're
 * captured in the advanced registry, not on the real server). These
 * helpers expose a unified view across all three lanes:
 *
 *   - L1 essentials   → real server's `_registeredTools`
 *   - L2 advanced     → CapturingServer's `getAdvancedRegistry()`
 *   - L3 + gateway    → real server's `_registeredTools`
 *
 * Test-only: never imported by any runtime path.
 *
 * @license MIT
 */

import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';

import type { CapturingServer } from './capturing-server.js';

/** A tool entry as seen by tests: handler + the registered config. */
export interface CollectedTool {
    handler: (
        args: Record<string, unknown>,
        extra?: unknown,
    ) => CallToolResult | Promise<CallToolResult>;
    title?: string;
    description?: string;
    inputSchema?: unknown;
    annotations?: Record<string, unknown>;
}

interface RealRegisteredTool {
    callback: CollectedTool['handler'];
    title?: string;
    description?: string;
    inputSchema?: unknown;
    annotations?: Record<string, unknown>;
}

interface RealRegisteredToolLegacy {
    handler: CollectedTool['handler'];
    title?: string;
    description?: string;
    inputSchema?: unknown;
    annotations?: Record<string, unknown>;
}

/**
 * Returns every tool — essentials + gateway on the real McpServer plus
 * the advanced tools captured by the CapturingServer — keyed by tool
 * name. The CapturingServer must have been used to register the L1/L2
 * tools, and `registerAdvancedTool` must have run, before this is called.
 *
 * Production size = 22:
 *   7 L1 essentials (real server) + 12 L2 advanced (registry)
 *   + 2 L3 direct (real server) + 1 gateway (real server) = 22.
 */
export function collectAllRegisteredTools(
    server: McpServer,
    capturing: CapturingServer,
): Record<string, CollectedTool> {
    const result: Record<string, CollectedTool> = {};

    // The SDK changed the internal field name from `handler` to `callback`
    // over its 1.x lifetime; accept either to keep the helper resilient.
    const real = server as unknown as {
        _registeredTools: Record<string, RealRegisteredTool | RealRegisteredToolLegacy>;
    };
    for (const [name, tool] of Object.entries(real._registeredTools)) {
        const callback =
            'callback' in tool && typeof tool.callback === 'function'
                ? tool.callback
                : (tool as RealRegisteredToolLegacy).handler;
        result[name] = {
            handler: callback,
            title: tool.title,
            description: tool.description,
            inputSchema: tool.inputSchema,
            annotations: tool.annotations,
        };
    }

    for (const [name, entry] of capturing.getAdvancedRegistry()) {
        const cfg = entry.config as {
            title?: string;
            description?: string;
            inputSchema?: unknown;
            annotations?: Record<string, unknown>;
        };
        result[name] = {
            handler: entry.handler,
            title: cfg.title,
            description: cfg.description,
            inputSchema: cfg.inputSchema,
            annotations: cfg.annotations,
        };
    }

    return result;
}

/** By-name lookup over a `collectAllRegisteredTools` map. */
export function findTool(
    tools: Record<string, CollectedTool>,
    name: string,
): CollectedTool | undefined {
    return tools[name];
}
