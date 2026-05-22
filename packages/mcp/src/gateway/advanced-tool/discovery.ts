/**
 * Gateway discovery-mode dispatch — handles `{ tool }`-only invocations
 * by returning the target tool's description, JSON-schema, and
 * annotations. Never invokes the target handler.
 *
 * Round-2 (R2-A2-CRIT2) extracted from `gateway/advanced-tool.ts`.
 *
 * @license MIT
 */

import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import { z, type ZodRawShape } from 'zod';

import type { AdvancedToolEntry } from '../capturing-server.js';

/** Reads the captured config's inputSchema as a Zod raw-shape (or empty). */
export function shapeOf(entry: AdvancedToolEntry): ZodRawShape {
    const schema = (entry.config as { inputSchema?: unknown }).inputSchema;
    if (schema && typeof schema === 'object') return schema as ZodRawShape;
    return {};
}

/** Builds a JSON-schema for a captured tool's inputs, with a defensive fallback. */
export function inputSchemaJson(entry: AdvancedToolEntry): unknown {
    try {
        return z.toJSONSchema(z.object(shapeOf(entry)));
    } catch {
        // A schema that cannot be JSON-projected still yields a usable
        // discovery payload — description + annotations carry the rest.
        return { note: 'input schema could not be derived' };
    }
}

/** Builds the discovery-mode result for a target tool. */
export function discoveryResult(toolName: string, entry: AdvancedToolEntry): CallToolResult {
    const cfg = entry.config as {
        description?: string;
        annotations?: Record<string, unknown>;
    };
    const schema = inputSchemaJson(entry);
    const payload = {
        tool: toolName,
        description: cfg.description ?? '',
        inputSchema: schema,
        annotations: cfg.annotations ?? {},
    };
    return {
        content: [
            {
                type: 'text',
                text:
                    `Discovery for ${toolName}\n\n` +
                    `${cfg.description ?? ''}\n\n` +
                    `Annotations: ${JSON.stringify(cfg.annotations ?? {})}\n\n` +
                    `Input schema:\n${JSON.stringify(schema, null, 2)}\n\n` +
                    `Call yootheme_builder_advanced again with { tool: "${toolName}", arguments: {...} } to run it.`,
            },
        ],
        structuredContent: payload,
    };
}
