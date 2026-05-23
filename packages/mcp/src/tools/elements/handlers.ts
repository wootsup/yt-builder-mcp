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

import { detailResult, tableResult, type TableColumn } from '@getimo/mcp-toolkit';
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
    // 1.0.1 Wave-1.5 — Cold agents need to see the row shape so they know
    // they can copy `rel_path`/`path`/`element_type`/`parent_path` straight
    // back into the next call. All fields are optional + the row is
    // `passthrough()` so projected/narrow `fields[]` calls still validate.
    items: z.array(
        z
            .object({
                path: z
                    .string()
                    .optional()
                    .describe(
                        'Fully-qualified JSON-Pointer ' +
                            '(`/templates/<id>/layout/children/...`).',
                    ),
                rel_path: z
                    .string()
                    .optional()
                    .describe(
                        'Path relative to layout root (`/children/0/...`). ' +
                            'Pass either form back as `element_path`.',
                    ),
                element_type: z
                    .string()
                    .optional()
                    .describe(
                        "Element type (e.g. `headline`, `section`, `grid`). " +
                            "Feeds `element_add.element_type` directly.",
                    ),
                parent_path: z
                    .string()
                    .optional()
                    .describe(
                        "Path of this element's parent. Feeds `element_add.parent_path` / " +
                            "`element_move.to_parent_path` directly (rel_path form preferred).",
                    ),
                label: z
                    .string()
                    .optional()
                    .describe('Human-readable name of the element (alias of `name`).'),
                has_binding: z
                    .boolean()
                    .optional()
                    .describe(
                        'True when this element is dynamically bound to a data ' +
                            'source. Use `element_get_binding` for the full mapping.',
                    ),
            })
            .passthrough(),
    ),
    total: z.number(),
    template_id: z.string(),
    // N-01: present only when the call was paginated and more rows remain.
    next_cursor: z.string().optional(),
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

/**
 * N-01 (Audit v4): build slim text-table columns from a caller-supplied
 * `fields[]` projection. The default compact text table truncates at
 * 2000 chars (~26 of 96 nodes); projecting to a couple of narrow
 * columns lets the FULL node list fit in the 8000-char `full` budget.
 *
 * Width heuristic: paths get a wide column, everything else a modest
 * one. Labels are uppercased for the header to match the toolkit style.
 */
function columnsFromFields(fields: readonly string[]): TableColumn[] {
    return fields.map((key): TableColumn => {
        const isPath = key === 'path' || key === 'rel_path' || key === 'parent_path';
        return {
            key,
            label: key.replace(/_/g, ' ').toUpperCase(),
            width: isPath ? 48 : 16,
        };
    });
}


export async function handleElementList(
    { client }: ElementsHandlerDeps,
    {
        template_id,
        fields,
        root_path,
        depth,
        limit,
        cursor,
    }: {
        template_id: string;
        fields?: readonly string[];
        root_path?: string;
        depth?: number;
        limit?: number;
        cursor?: string;
    },
): Promise<ToolResult> {
    try {
        // N-01 (Audit-v3): forward the transport-safe scoping params as
        // query string. The REST layer returns the pagination envelope
        // `{items, next_cursor, total}` when `limit` is set, else the flat
        // `{elements, total}` shape — handle both.
        const qs = new URLSearchParams();
        if (typeof root_path === 'string' && root_path !== '') {
            qs.set('root_path', root_path);
        }
        if (typeof depth === 'number') qs.set('depth', String(depth));
        if (typeof limit === 'number') qs.set('limit', String(limit));
        if (typeof cursor === 'string' && cursor !== '') qs.set('cursor', cursor);
        const query = qs.toString();
        const data = await client.get<{
            elements?: unknown;
            items?: unknown;
            next_cursor?: string | null;
            total?: number;
        }>(
            `/pages/${encodeURIComponent(template_id)}/elements${query !== '' ? `?${query}` : ''}`,
        );
        // Paginated envelope uses `items`; flat shape uses `elements`.
        const rawSource = Array.isArray(data.items)
            ? data.items
            : Array.isArray(data.elements)
                ? data.elements
                : [];
        const mapped = rawSource
            .filter((x): x is Record<string, unknown> => x !== null && typeof x === 'object')
            .map(mapElementRow);
        const items = projectFields(mapped, fields, DEFAULT_FIELDS_ELEMENT_LIST);
        const echo = projectedFieldsEcho(fields, DEFAULT_FIELDS_ELEMENT_LIST);

        // N-01 (Audit v4): when the caller passes an explicit narrow
        // `fields[]`, render the text table from the projected (slim)
        // rows with `full` detail (8000 chars) so the WHOLE node list
        // survives — the default compact table caps at 2000 chars and
        // hides ~70 of a 96-node template. Without `fields[]` keep the
        // auto-scaling behaviour (full columns, count-driven level).
        const hasNarrowProjection =
            Array.isArray(fields) && fields.length > 0;
        const toolkitResult = hasNarrowProjection
            ? tableResult(
                  items as Record<string, unknown>[],
                  {
                      columns: columnsFromFields(fields),
                      header: (count) =>
                          `${String(count)} elements in template "${template_id}"`,
                      footer: 'Use yootheme_builder_element_get <path> for full data.',
                  },
                  'full',
              )
            : tableResult(mapped, {
                  columns: [...ELEMENTS_TABLE_COLUMNS],
                  compactColumns: [...ELEMENTS_COMPACT_COLUMNS],
                  header: (count) =>
                      `${String(count)} elements in template "${template_id}"`,
                  footer: 'Use yootheme_builder_element_get <path> for full data.',
              });
        return structuredResult(toolkitResult, {
            items,
            total: typeof data.total === 'number' ? data.total : items.length,
            template_id,
            ...(typeof data.next_cursor === 'string' && data.next_cursor !== ''
                ? { next_cursor: data.next_cursor }
                : {}),
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
