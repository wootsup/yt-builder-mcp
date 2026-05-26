/**
 * `clean_implode_directives` handler — strip `props.source.props.*.implode`
 * directives from an element binding via REST round-trip.
 *
 * Wave H5 (2026-05-26): destructive confirm-guard. The tool is now
 * registered with `destructive()` annotation (was `mutating()`); per the
 * MCP Goldstandard, every destructive tool MUST also expose a `confirm`
 * input field + preview guard so an auto-acting agent cannot strip
 * implode directives from a binding without an explicit second gate.
 * Mirrors the `element_delete` pattern (handlers-write.ts).
 *
 * @license MIT
 */

import { z } from 'zod';

import { encodeElementPath, type RestClient } from '../../client.js';
import {
    confirmGuard,
    errorResult,
    jsonResult,
    structuredResult,
    type ToolResult,
} from '../tool-builder.js';

export const CLEAN_IMPLODE_OUTPUT_SCHEMA = z.object({
    template_id: z.string(),
    element_path: z.string(),
    cleaned_count: z.number(),
    removed_directives: z.array(
        z.object({
            prop_name: z.string(),
            directive: z.record(z.string(), z.unknown()),
        }),
    ),
    new_etag: z.string(),
});

export interface CleanImplodeHandlerDeps {
    readonly client: RestClient;
}

export async function handleCleanImplodeDirectives(
    { client }: CleanImplodeHandlerDeps,
    {
        template_id,
        element_path,
        etag,
        confirm,
    }: {
        template_id: string;
        element_path: string;
        etag: string;
        confirm?: boolean;
    },
): Promise<ToolResult> {
    // Wave H5 confirm-guard. Without `confirm: true` we return a preview
    // payload and make NO HTTP call. The preview surfaces enough context
    // (template + element_path + operation) for the host agent to ask the
    // user to confirm. Symmetric with `element_delete` / `element_unbind_source`.
    if (confirm !== true) {
        return confirmGuard({
            operation: 'strip implode directives from element binding',
            details: { template_id, element_path },
        });
    }
    const encoded = encodeElementPath(element_path);
    try {
        const data = await client.post<Record<string, unknown>>(
            `/pages/${encodeURIComponent(template_id)}/elements/${encoded}/multi-items/clean-implode`,
            { body: {}, etag },
        );
        // The REST endpoint returns the canonical CLEAN_IMPLODE_OUTPUT_SCHEMA
        // shape ({template_id, element_path, cleaned_count, removed_directives,
        // new_etag}) — pass it through as structuredContent so the declared
        // outputSchema is satisfied (a tool with an outputSchema MUST emit
        // structuredContent, else the MCP SDK rejects with -32602).
        return structuredResult(jsonResult(data), data);
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id, element_path },
            hint:
                'On 412 refresh via yootheme_builder_get_etag and retry. On 404 verify the ' +
                'element_path via yootheme_builder_element_list.',
        });
    }
}
