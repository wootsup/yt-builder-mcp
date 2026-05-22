/**
 * Gateway execute-mode dispatch — handles `{ tool, arguments }`
 * invocations by validating `arguments` against the target tool's Zod
 * schema in `.strict()` mode, then invoking the captured handler with
 * the parsed args and the original SDK `extra` (so progress/elicitation/
 * AbortSignal of the target tool keep working).
 *
 * Round-2 (R2-A2-CRIT2) extracted from `gateway/advanced-tool.ts`.
 *
 * @license MIT
 */

import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';

import type { AdvancedToolEntry } from '../capturing-server.js';
import { inputSchemaJson, shapeOf } from './discovery.js';

/** Builds an error CallToolResult with structured payload (echoed into text + structuredContent). */
export function errorResult(payload: {
    message: string;
    code: string;
    suggestion: string;
    details?: Record<string, unknown>;
}): CallToolResult {
    const body = {
        error: payload.message,
        code: payload.code,
        suggestion: payload.suggestion,
        ...(payload.details !== undefined ? { details: payload.details } : {}),
    };
    return {
        content: [{ type: 'text', text: JSON.stringify(body, null, 2) }],
        structuredContent: body,
        isError: true,
    };
}

/**
 * Executes a captured advanced tool with strict-validated arguments.
 *
 * Returns a structured `invalid_arguments` error on Zod-validation
 * failure; otherwise invokes `entry.handler` with `(parsedArgs, extra)`
 * and returns its CallToolResult verbatim.
 */
export async function executeAdvancedTool(
    toolName: string,
    entry: AdvancedToolEntry,
    rawArgs: Record<string, unknown>,
    extra: unknown,
): Promise<CallToolResult> {
    // strict() surfaces unknown keys as Zod issues rather than silently
    // dropping them, so callers can self-correct when they pass schema-
    // extra keys.
    const parsed = z.object(shapeOf(entry)).strict().safeParse(rawArgs);
    if (!parsed.success) {
        return errorResult({
            message: `Invalid arguments for "${toolName}".`,
            code: 'invalid_arguments',
            suggestion:
                `Call yootheme_builder_advanced with { tool: "${toolName}" } to see ` +
                'the expected schema, then retry with corrected arguments.',
            details: {
                issues: parsed.error.issues,
                expected_schema: inputSchemaJson(entry),
            },
        });
    }

    return entry.handler(parsed.data, extra);
}
