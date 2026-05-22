/**
 * Source-binding read handlers + shared types/schemas.
 *
 * Split out of `src/tools/sources.ts` (Wave G.4.0b). Write handlers
 * (`element_bind_source` with G.4.3 ambiguity resolution,
 * `element_unbind_source` with G.4.2 elicit-confirm) live in
 * `./handlers-bind.ts`.
 *
 * @license MIT
 */

import { detailResult, tableResult } from '@getimo/mcp-toolkit';
import { z } from 'zod';

import { encodeElementPath, type RestClient } from '../../client.js';
import {
    SOURCES_TABLE_COLUMNS,
    buildBindingDetail,
    flattenSourcesPayload,
    mapSourceRow,
} from '../format/sources-format.js';
import type { McpServerWithElicitation } from '../elicitation.js';
import {
    DEFAULT_FIELDS_SOURCES_LIST,
    projectFields,
    projectedFieldsEcho,
} from '../sparse-fields.js';
import {
    errorResult,
    structuredResult,
    type ToolResult,
} from '../tool-builder.js';

// ─── outputSchemas (Wave G.2 §4) ─────────────────────────────────────

export const SOURCES_LIST_OUTPUT_SCHEMA = z.object({
    items: z.array(z.record(z.string(), z.unknown())),
    total: z.number(),
    projected_fields: z.array(z.string()).optional(),
});

export const BINDING_OUTPUT_SCHEMA = z.object({
    template_id: z.string(),
    element_path: z.string(),
    binding: z.record(z.string(), z.unknown()),
    has_binding: z.boolean(),
});

export interface SourcesHandlerDeps {
    readonly client: RestClient;
    /**
     * Optional MCP elicitation capability. When present,
     * `element_unbind_source` prompts for destructive confirmation and
     * `element_bind_source` prompts for cross-plugin disambiguation
     * via the elicitation channel. When absent, both fall back to
     * structured-error / preview flows.
     */
    readonly elicitation?: McpServerWithElicitation;
}

// ─── sources_list ────────────────────────────────────────────────────

export async function handleSourcesList(
    { client }: SourcesHandlerDeps,
    { fields }: { fields?: readonly string[] },
): Promise<ToolResult> {
    try {
        const data = await client.get<{ sources?: unknown }>('/sources');
        const payload = data.sources ?? data;
        const flat = flattenSourcesPayload(payload);
        const mapped = flat.map(mapSourceRow);
        const mappedRecords = mapped as unknown as Record<string, unknown>[];
        const items = projectFields(mappedRecords, fields, DEFAULT_FIELDS_SOURCES_LIST);
        const echo = projectedFieldsEcho(fields, DEFAULT_FIELDS_SOURCES_LIST);
        const toolkitResult = tableResult(mappedRecords, {
            columns: [...SOURCES_TABLE_COLUMNS],
            header: (count) => `${String(count)} sources`,
            footer: 'Use yootheme_builder_element_bind_source to bind one.',
        });
        return structuredResult(toolkitResult, {
            items,
            total: items.length,
            ...(echo !== undefined ? { projected_fields: [...echo] } : {}),
        });
    } catch (e) {
        return errorResult({
            error: e,
            context: {},
            hint: 'Run yootheme_builder_diagnose to verify auth.',
        });
    }
}

// ─── element_get_binding ─────────────────────────────────────────────

export async function handleElementGetBinding(
    { client }: SourcesHandlerDeps,
    { template_id, element_path }: { template_id: string; element_path: string },
): Promise<ToolResult> {
    const encoded = encodeElementPath(element_path);
    try {
        const data = await client.get<Record<string, unknown>>(
            `/pages/${encodeURIComponent(template_id)}/elements/${encoded}/binding`,
        );
        const binding = data !== null && typeof data === 'object' ? data : {};
        // F-01-Mapping (Audit v4): trust the BE-computed `has_binding`
        // flag (BindingSerializer SSoT) — it also flips true for an
        // item-level binding that has field_mappings but no source_name.
        // Fall back to the source_name heuristic only when absent.
        const hasBinding =
            typeof binding.has_binding === 'boolean'
                ? binding.has_binding
                : typeof binding.source_name === 'string' &&
                  binding.source_name.length > 0;
        const toolkitResult = detailResult(
            buildBindingDetail({ template_id, element_path, binding }),
        );
        return structuredResult(toolkitResult, {
            template_id,
            element_path,
            binding,
            has_binding: hasBinding,
        });
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id, element_path },
            hint: 'Verify element_path via yootheme_builder_element_list.',
        });
    }
}

// Re-export write handlers so builders can import everything from this
// barrel and we keep a single `SourcesHandlerDeps` definition.
export {
    handleElementBindSource,
    handleElementUnbindSource,
} from './handlers-bind.js';
