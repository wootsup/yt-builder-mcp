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
// F-006 (2026-05-26): PATH column shows `rel_path` (path minus the
// `/templates/<id>/layout` prefix) so paths copied out of page_get_schema
// match the form element_list emits. Fully-qualified `path` remains on
// the row for back-compat and is surfaced via structuredContent.

export const SCHEMA_TABLE_COLUMNS: readonly TableColumn[] = [
    { key: 'rel_path', label: 'PATH', width: 48, llmOnly: true },
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
    { key: 'rel_path', label: 'PATH', width: 48, llmOnly: true },
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

function asNullableString(v: unknown): string | null {
    if (typeof v === 'string') {
        return v;
    }
    if (v === null) {
        return null;
    }
    // Unknown / undefined → null (consistent with the server-side honest-null
    // convention — `frontend_url: null` is meaningful, `undefined` is not).
    return null;
}

/** Maps an `/pages` element to the table row shape. Missing fields default to empty.
 *  REST returns `name` (YOOtheme's internal field name); we surface it as `label`
 *  for LLM-friendly schemas. The legacy `label` fallback is for forward-compat
 *  if the backend ever switches naming.
 *
 *  F-Frontend-URL (2026-05-25): the three frontend-URL hints carry through
 *  the mapper unchanged. The table-renderer doesn't render them (would
 *  blow the column budget), but the structured-content leg surfaces them
 *  so an MCP-agent walking the response sees the per-template URL hint
 *  without a follow-up REST call. */
export function mapPageRow(input: Record<string, unknown>): Record<string, unknown> {
    return {
        id: asString(input.id),
        label: asString(input.name ?? input.label),
        type: asString(input.type),
        elements_count: asInt(input.elements_count),
        modified_at: asString(input.modified_at),
        // F-Frontend-URL: nullable-strings, NOT empty-string coercion —
        // a null frontend_url is semantically different from an empty
        // string ("not applicable" vs "we tried and got nothing"). The
        // server emits null deliberately; the mapper preserves it.
        frontend_url: asNullableString(input.frontend_url),
        frontend_url_template: asNullableString(input.frontend_url_template),
        frontend_url_description: asNullableString(input.frontend_url_description),
    };
}

/** Maps a `/pages/{id}/schema` node to the table row shape.
 *
 *  F-006 (2026-05-26): also surfaces `rel_path` (path minus the
 *  `/templates/<id>/layout` prefix) so paths copied from `page_get_schema`
 *  match the `rel_path` form emitted by `element_list` + `page_get_layout`
 *  — agents that copy paths between tools no longer hit "no such element"
 *  on the fully-qualified pointer. Mirrors `mapElementRow` in
 *  `elements-format.ts`. The full `path` stays on the row for back-compat;
 *  the table column header points at `rel_path` instead.
 */
export function mapSchemaNodeRow(input: Record<string, unknown>): Record<string, unknown> {
    const path = asString(input.path);
    // F-006: derive relative path by stripping `/templates/<id>/layout`
    // prefix so the LLM table column shows uniquely-identifying suffix,
    // and the `rel_path` value can be copy-pasted into `element_path` /
    // `root_path` arguments without further trimming.
    const relPath = path.replace(/^\/templates\/[^/]+\/layout/, '') || '/';
    return {
        path,
        rel_path: relPath,
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
