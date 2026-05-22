/**
 * Page-tool READ handlers + shared output-schemas.
 *
 * Split out of `src/tools/pages.ts` (Round-2 R2-A2-CRIT1) to keep
 * each file ≤ 200 LoC per Architecture §11. Write handlers (page_save,
 * page_publish — 3-phase progress contract) live in `./handlers-write.ts`.
 *
 * @license MIT
 */

import { detailResult, tableResult } from '@getimo/mcp-toolkit';

import type { RestClient } from '../../client.js';
import { flattenLayout } from '../layout-flatten.js';
import {
    PAGES_COMPACT_COLUMNS,
    PAGES_TABLE_COLUMNS,
    SCHEMA_COMPACT_COLUMNS,
    SCHEMA_TABLE_COLUMNS,
    buildEtagDetail,
    buildSchemaRows,
    mapPageRow,
} from '../format/pages-format.js';
import {
    DEFAULT_FIELDS_PAGES_LIST,
    projectFields,
    projectedFieldsEcho,
} from '../sparse-fields.js';
import {
    errorResult,
    jsonResult,
    structuredResult,
    type ToolResult,
} from '../tool-builder.js';

// ─── pages_list ─────────────────────────────────────────────────────

export async function handlePagesList(
    client: RestClient,
    { fields }: { fields?: readonly string[] },
): Promise<ToolResult> {
    try {
        const data = await client.get<{ pages: unknown; etag: string }>('/pages');
        const rawPages = Array.isArray(data.pages) ? data.pages : [];
        const mapped = rawPages
            .filter((x): x is Record<string, unknown> => x !== null && typeof x === 'object')
            .map(mapPageRow);
        // Project AFTER mapping — keeps stable key names and lets the
        // toolkit's tableResult format the raw, full-shape rows for the
        // LLM text leg while the structuredContent leg carries the
        // projected per-item slice.
        const items = projectFields(mapped, fields, DEFAULT_FIELDS_PAGES_LIST);
        const echo = projectedFieldsEcho(fields, DEFAULT_FIELDS_PAGES_LIST);
        const toolkitResult = tableResult(mapped, {
            columns: [...PAGES_TABLE_COLUMNS],
            compactColumns: [...PAGES_COMPACT_COLUMNS],
            header: (count) => `${String(count)} pages`,
            footer: 'Use yootheme_builder_page_get_schema <id> to inspect.',
        });
        return structuredResult(toolkitResult, {
            items,
            total: items.length,
            ...(typeof data.etag === 'string' ? { etag: data.etag } : {}),
            ...(echo !== undefined ? { projected_fields: [...echo] } : {}),
        });
    } catch (e) {
        return errorResult({
            error: e,
            context: {},
            hint: 'Run yootheme_builder_diagnose to verify connectivity and auth.',
        });
    }
}

// ─── page_get_layout ────────────────────────────────────────────────

export async function handlePageGetLayout(
    client: RestClient,
    { template_id, flat, fields }: {
        template_id: string;
        flat?: boolean;
        fields?: readonly string[];
    },
): Promise<ToolResult> {
    try {
        const data = await client.get<Record<string, unknown>>(
            `/pages/${encodeURIComponent(template_id)}/layout`,
        );
        if (flat !== true) {
            // Default: nested passthrough — unchanged from pre-G.3.
            return jsonResult(data);
        }
        // flat: true → depth-first walk on the nested layout. Apply
        // pickFields per element when `fields[]` was passed; echo the
        // projection so the AI knows the per-item shape it received.
        const flatElements = flattenLayout(data.layout);
        const projected = projectFields(
            flatElements as unknown as Record<string, unknown>[],
            fields,
            // No default-set on layout.flat: keep "all" semantics
            // unless caller opts-in. The echo helper below mirrors that.
            [],
        );
        const echo = projectedFieldsEcho(fields, undefined);
        return jsonResult({
            elements: projected,
            ...(typeof data.etag === 'string' ? { etag: data.etag } : {}),
            ...(echo !== undefined ? { projected_fields: [...echo] } : {}),
        });
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id },
            hint:
                'Verify the template_id exists via yootheme_builder_pages_list. ' +
                'IDs are case-sensitive.',
        });
    }
}

// ─── page_get_schema ────────────────────────────────────────────────

export async function handlePageGetSchema(
    client: RestClient,
    { template_id }: { template_id: string },
): Promise<ToolResult> {
    try {
        const data = await client.get<{ nodes?: unknown }>(
            `/pages/${encodeURIComponent(template_id)}/schema`,
        );
        const rawNodes = Array.isArray(data.nodes) ? data.nodes : [];
        const items = buildSchemaRows(
            rawNodes.filter(
                (x): x is Record<string, unknown> => x !== null && typeof x === 'object',
            ),
        );
        // Templates routinely exceed 21 nodes (a typical home page has
        // 30–150 elements). Force compact level so the LLM never gets
        // truncated text — toolkit's resolveDetailLevel honours the
        // explicit override.
        //
        // TODO(toolkit-upstream): contribute a `compactWhen: (count) => boolean`
        // field to @getimo/mcp-toolkit's AutoFormatOptions so this
        // can be expressed declaratively on the config object instead
        // of via the third positional argument. Tracked as Round-1.5
        // follow-up (I2).
        const toolkitResult = tableResult(
            items,
            {
                columns: [...SCHEMA_TABLE_COLUMNS],
                compactColumns: [...SCHEMA_COMPACT_COLUMNS],
                header: (count) => `${String(count)} nodes in template "${template_id}"`,
                footer: 'Use yootheme_builder_element_get <path> for full element data.',
            },
            'compact',
        );
        return structuredResult(toolkitResult, {
            items,
            total: items.length,
            template_id,
        });
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id },
            hint:
                'Verify the template_id exists via yootheme_builder_pages_list.',
        });
    }
}

// ─── get_etag ───────────────────────────────────────────────────────

export async function handleGetEtag(client: RestClient): Promise<ToolResult> {
    try {
        const data = await client.get<{ etag: string; generated_at?: string }>('/etag');
        const toolkitResult = detailResult(buildEtagDetail({
            etag: data.etag,
            generated_at: data.generated_at,
        }));
        return structuredResult(toolkitResult, {
            etag: data.etag,
            ...(typeof data.generated_at === 'string'
                ? { generated_at: data.generated_at }
                : {}),
        });
    } catch (e) {
        return errorResult({
            error: e,
            context: {},
            hint: 'Run yootheme_builder_diagnose to verify auth.',
        });
    }
}

// ─── template_summary (T9 — Audit-v3 B.5 token-efficient overview) ────

/**
 * T9: a one-call structured overview of a template — element counts by
 * type, binding count, max nesting depth, and named landmarks. Lets the
 * agent grasp a 96-node template for ~1 kB instead of pulling the full
 * element_list dump (20 kB+).
 */
export async function handleTemplateSummary(
    client: RestClient,
    { template_id }: { template_id: string },
): Promise<ToolResult> {
    try {
        const data = await client.get<{
            template_id: string;
            counts_by_type: Record<string, number>;
            bound_count: number;
            max_depth: number;
            total: number;
            named_sections: { path: string; name: string }[];
            etag: string;
        }>(`/pages/${encodeURIComponent(template_id)}/summary`);
        return jsonResult(data);
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id },
            hint: 'Verify the template_id exists via yootheme_builder_pages_list.',
        });
    }
}
