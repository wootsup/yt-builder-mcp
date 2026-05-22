/**
 * Pages format-sidecar — pure transforms for `pages_list`, `page_get_schema`
 * and `get_etag`. Wave G.2.1 (Design §3.2 rows 3 / 5 / 6).
 *
 * These functions are stateless and free of REST-client concerns so the
 * handlers stay tiny and so the shape is asserted in unit tests
 * (`tests/tools/format/pages-format.test.ts`) and shape-pin tests
 * (`tests/wave-shape-pin.test.ts`).
 *
 * @license MIT
 */

import type { DetailGroup, TableColumn } from '@getimo/mcp-toolkit';

// ─── Pages list columns (Design §3.2 row 3) ──────────────────────────

/** Full columns for `pages_list`. */
export const PAGES_TABLE_COLUMNS: readonly TableColumn[] = [
    { key: 'id', label: 'ID', width: 36, llmOnly: true },
    { key: 'label', label: 'NAME', width: 28 },
    { key: 'type', label: 'TYPE', width: 12 },
    { key: 'elements_count', label: '#EL', width: 8, align: 'right' },
    { key: 'modified_at', label: 'MODIFIED', width: 20 },
];

/** Compact columns kick in at 21+ rows; drops `modified_at` + `type`. */
export const PAGES_COMPACT_COLUMNS: readonly TableColumn[] = [
    { key: 'id', label: 'ID', width: 36, llmOnly: true },
    { key: 'label', label: 'NAME', width: 28 },
    { key: 'elements_count', label: '#EL', width: 8, align: 'right' },
];

// ─── Schema columns (Design §3.2 row 5 — always-compact override) ────

export const SCHEMA_TABLE_COLUMNS: readonly TableColumn[] = [
    { key: 'path', label: 'PATH', width: 32, llmOnly: true },
    { key: 'element_type', label: 'TYPE', width: 16 },
    { key: 'label', label: 'LABEL', width: 28 },
    { key: 'has_binding', label: 'BIND', width: 8 },
];

/**
 * Compact columns for `page_get_schema`. Real-world templates routinely
 * exceed 21 nodes (a typical home-page has 30–150 elements; landing pages
 * reach 300+), so the handler ALWAYS uses these compact columns via the
 * `detailOverride: 'compact'` argument to `tableResult` (toolkit's
 * `resolveDetailLevel` short-circuits when given a direct level).
 */
export const SCHEMA_COMPACT_COLUMNS: readonly TableColumn[] = [
    { key: 'path', label: 'PATH', width: 32, llmOnly: true },
    { key: 'element_type', label: 'TYPE', width: 16 },
    { key: 'label', label: 'LABEL', width: 28 },
];

// ─── Row mappers ─────────────────────────────────────────────────────

function asString(v: unknown): string {
    return typeof v === 'string' ? v : '';
}

function asInt(v: unknown): number {
    return typeof v === 'number' && Number.isFinite(v) ? Math.trunc(v) : 0;
}

function asBool(v: unknown): boolean {
    return v === true;
}

/** Maps an `/pages` element to the table row shape. Missing fields default to empty.
 *  REST returns `name` (YOOtheme's internal field name); we surface it as `label`
 *  for LLM-friendly schemas. The legacy `label` fallback is for forward-compat
 *  if the backend ever switches naming. */
export function mapPageRow(input: Record<string, unknown>): Record<string, unknown> {
    return {
        id: asString(input.id),
        label: asString(input.name ?? input.label),
        type: asString(input.type),
        elements_count: asInt(input.elements_count),
        modified_at: asString(input.modified_at),
    };
}

/** Maps a `/pages/{id}/schema` node to the table row shape. */
export function mapSchemaNodeRow(input: Record<string, unknown>): Record<string, unknown> {
    return {
        path: asString(input.path),
        element_type: asString(input.element_type),
        label: asString(input.label),
        has_binding: asBool(input.has_binding),
    };
}

/** Builds schema rows from the array returned by the REST `nodes` field. */
export function buildSchemaRows(
    nodes: ReadonlyArray<Record<string, unknown>>,
): Record<string, unknown>[] {
    return nodes.map(mapSchemaNodeRow);
}

// ─── Detail builders ─────────────────────────────────────────────────

/** Build the `detailResult` body for `get_etag`. Two groups: identity + freshness. */
export function buildEtagDetail(payload: {
    etag: string;
    generated_at?: string;
}): { groups: DetailGroup[] } {
    return {
        groups: [
            {
                label: 'Identity',
                entries: [
                    {
                        key: 'etag',
                        label: 'ETag',
                        value: payload.etag,
                        format: 'code',
                        copyable: true,
                    },
                ],
            },
            {
                label: 'Freshness',
                entries: [
                    {
                        key: 'generated_at',
                        label: 'Generated',
                        value: payload.generated_at ?? null,
                        format: 'date',
                    },
                ],
            },
        ],
    };
}
