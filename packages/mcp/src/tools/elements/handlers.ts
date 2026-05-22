/**
 * Element-tool read handlers + shared types/schemas.
 *
 * Split out of the original `src/tools/elements.ts` (Wave G.4.0). Write
 * handlers (add/update/move/clone/delete) live in `./handlers-write.ts`
 * to keep each file under the 200-LoC budget.
 *
 * Handler responsibilities:
 *   - call the REST endpoint via `deps.client`,
 *   - map REST data to the toolkit output builders (`tableResult`,
 *     `detailResult`),
 *   - wrap in `structuredResult` to carry the typed
 *     `structuredContent` payload,
 *   - on error, return `errorResult` with `context` + `hint`.
 *
 * @license MIT
 */

import { detailResult, tableResult } from '@getimo/mcp-toolkit';
import { z } from 'zod';

import { encodeElementPath, type RestClient } from '../../client.js';
import type { McpServerWithElicitation } from '../elicitation.js';
import {
    ELEMENTS_COMPACT_COLUMNS,
    ELEMENTS_TABLE_COLUMNS,
    buildElementDetail,
    mapElementRow,
} from '../format/elements-format.js';
import {
    DEFAULT_FIELDS_ELEMENT_LIST,
    projectFields,
    projectedFieldsEcho,
} from '../sparse-fields.js';
import {
    errorResult,
    structuredResult,
    type ToolResult,
} from '../tool-builder.js';

// ─── outputSchemas (Wave G.2 §4) ─────────────────────────────────────

export const ELEMENT_LIST_OUTPUT_SCHEMA = z.object({
    items: z.array(z.record(z.string(), z.unknown())),
    total: z.number(),
    template_id: z.string(),
    projected_fields: z.array(z.string()).optional(),
});

export const ELEMENT_GET_OUTPUT_SCHEMA = z.object({
    template_id: z.string(),
    element_path: z.string(),
    element_type: z.string(),
    label: z.string().optional(),
    props: z.record(z.string(), z.unknown()).optional(),
    children_count: z.number(),
});

// ─── shared deps ─────────────────────────────────────────────────────

export interface ElementsHandlerDeps {
    readonly client: RestClient;
    /**
     * Optional MCP elicitation capability. When present, destructive
     * tools (`element_delete`) prompt the user for confirmation via the
     * elicitation channel instead of demanding an explicit
     * `confirm: true` round-trip. When absent, tools fall back to the
     * preview-then-retry flow.
     */
    readonly elicitation?: McpServerWithElicitation;
}

// ─── element_list ────────────────────────────────────────────────────

export async function handleElementList(
    { client }: ElementsHandlerDeps,
    { template_id, fields }: { template_id: string; fields?: readonly string[] },
): Promise<ToolResult> {
    try {
        const data = await client.get<{ elements?: unknown }>(
            `/pages/${encodeURIComponent(template_id)}/elements`,
        );
        const raw = Array.isArray(data.elements) ? data.elements : [];
        const mapped = raw
            .filter((x): x is Record<string, unknown> => x !== null && typeof x === 'object')
            .map(mapElementRow);
        const items = projectFields(mapped, fields, DEFAULT_FIELDS_ELEMENT_LIST);
        const echo = projectedFieldsEcho(fields, DEFAULT_FIELDS_ELEMENT_LIST);
        const toolkitResult = tableResult(mapped, {
            columns: [...ELEMENTS_TABLE_COLUMNS],
            compactColumns: [...ELEMENTS_COMPACT_COLUMNS],
            header: (count) => `${String(count)} elements in template "${template_id}"`,
            footer: 'Use yootheme_builder_element_get <path> for full data.',
        });
        return structuredResult(toolkitResult, {
            items,
            total: items.length,
            template_id,
            ...(echo !== undefined ? { projected_fields: [...echo] } : {}),
        });
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id },
            hint: 'Verify the template_id via yootheme_builder_pages_list.',
        });
    }
}

// ─── element_get ─────────────────────────────────────────────────────

export async function handleElementGet(
    { client }: ElementsHandlerDeps,
    { template_id, element_path }: { template_id: string; element_path: string },
): Promise<ToolResult> {
    const encoded = encodeElementPath(element_path);
    try {
        const data = await client.get<Record<string, unknown>>(
            `/pages/${encodeURIComponent(template_id)}/elements/${encoded}`,
        );
        const elementType =
            typeof data.element_type === 'string'
                ? data.element_type
                : typeof data.type === 'string'
                    ? data.type
                    : '';
        const label = typeof data.label === 'string' ? data.label : undefined;
        const propsRaw = data.props;
        const props =
            propsRaw !== null && typeof propsRaw === 'object'
                ? (propsRaw as Record<string, unknown>)
                : undefined;
        const children = Array.isArray(data.children) ? data.children : [];

        const toolkitResult = detailResult(
            buildElementDetail({
                path: element_path,
                element_type: elementType,
                label,
                props,
                children: children as Array<{ type?: unknown }>,
            }),
        );
        return structuredResult(toolkitResult, {
            template_id,
            element_path,
            element_type: elementType,
            ...(label !== undefined ? { label } : {}),
            ...(props !== undefined ? { props } : {}),
            children_count: children.length,
        });
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id, element_path },
            hint:
                'Paths are JSON-Pointer style ("/0/children/1"). Use ' +
                'yootheme_builder_element_list to enumerate valid paths.',
        });
    }
}

// Re-export write handlers so callers can import from this barrel.
export {
    handleElementAdd,
    handleElementClone,
    handleElementDelete,
    handleElementMove,
    handleElementUpdateSettings,
} from './handlers-write.js';
