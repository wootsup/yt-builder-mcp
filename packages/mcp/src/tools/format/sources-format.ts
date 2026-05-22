/**
 * Sources format-sidecar — pure transforms for `sources_list` (table) and
 * `element_get_binding` (detail). Wave G.2.7 (Design §3.2 rows 16 / 17).
 *
 * @license MIT
 */

import type { DetailGroup, TableColumn } from '@getimo/mcp-toolkit';

// ─── sources_list columns (Design §3.2 row 16) ───────────────────────

export const SOURCES_TABLE_COLUMNS: readonly TableColumn[] = [
    { key: 'name', label: 'NAME', width: 36, llmOnly: true },
    { key: 'label', label: 'LABEL', width: 28 },
    { key: 'origin', label: 'ORIGIN', width: 12 },
    { key: 'kind', label: 'KIND', width: 10 },
];

// ─── Row mapper ──────────────────────────────────────────────────────

function asString(v: unknown): string {
    return typeof v === 'string' ? v : '';
}

export interface SourceRow {
    name: string;
    label: string;
    origin: string;
    kind: string;
}

export function mapSourceRow(input: Record<string, unknown>): SourceRow {
    return {
        name: asString(input.name),
        label: asString(input.label),
        origin: asString(input.origin),
        // F-04 Kleinkram (Audit v2): BE returns `type` (GraphQL type name
        // from the YT source-provider), not `kind`. Fall back so the
        // column populates without needing a BE rename.
        kind: asString(
            input.kind !== undefined && input.kind !== '' ? input.kind : input.type,
        ),
    };
}

/**
 * The REST plugin exposes sources EITHER as a grouped object
 * `{apimapper:[…], wordpress:[…], essentials:[…]}` OR (post-refactor)
 * as a flat array of `{name, label, origin, kind}`. This helper
 * normalises both into a flat array — flattening adds `origin` from the
 * group key when absent.
 */
const KNOWN_ORIGINS = ['apimapper', 'wordpress', 'essentials'] as const;

export function flattenSourcesPayload(
    payload: unknown,
): Record<string, unknown>[] {
    if (Array.isArray(payload)) {
        return payload.filter((x): x is Record<string, unknown> => x !== null && typeof x === 'object');
    }
    if (payload !== null && typeof payload === 'object') {
        const out: Record<string, unknown>[] = [];
        const obj = payload as Record<string, unknown>;
        for (const origin of KNOWN_ORIGINS) {
            const group = obj[origin];
            if (Array.isArray(group)) {
                for (const item of group) {
                    if (item !== null && typeof item === 'object') {
                        const rec = item as Record<string, unknown>;
                        out.push({ origin, ...rec });
                    }
                }
            }
        }
        return out;
    }
    return [];
}

// ─── Detail builder (Design §3.2 row 17) ─────────────────────────────

/** Render a primitive binding-value into a DetailEntry-safe scalar. */
function scalarValue(v: unknown): string | number | boolean | null {
    if (v === null || typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') {
        return v;
    }
    // Objects / arrays (nested filters, directive args) — JSON-flatten so
    // the LLM still sees the structure without an [object Object].
    try {
        return JSON.stringify(v);
    } catch {
        return String(v);
    }
}

/**
 * Normalise the field-mappings carried on the binding response into a
 * `prop → source_field` dict.
 *
 * F-01-Mapping (Audit v4): the REST plugin surfaces field-mappings in
 * TWO shapes — `field_mappings` (dict, back-compat) and
 * `field_mappings_structured` (list of `{element_prop, source_field}`,
 * the BindingSerializer SSoT output). The structured list is preferred
 * because it preserves insertion order and field-level `filters`.
 */
function extractFieldMappings(
    binding: Record<string, unknown>,
): { prop: string; field: string }[] {
    const structured = binding.field_mappings_structured;
    if (Array.isArray(structured)) {
        const out: { prop: string; field: string }[] = [];
        for (const entry of structured) {
            if (entry !== null && typeof entry === 'object') {
                const rec = entry as Record<string, unknown>;
                const prop = rec.element_prop;
                const field = rec.source_field;
                if (typeof prop === 'string' && prop !== '') {
                    out.push({
                        prop,
                        field: typeof field === 'string' ? field : String(field ?? ''),
                    });
                }
            }
        }
        return out;
    }
    const dict = binding.field_mappings;
    if (dict !== null && typeof dict === 'object') {
        return Object.entries(dict as Record<string, unknown>).map(([prop, field]) => ({
            prop,
            field: typeof field === 'string' ? field : String(field ?? ''),
        }));
    }
    return [];
}

/** Pull `query_arguments` off the binding as a flat record. */
function extractQueryArguments(
    binding: Record<string, unknown>,
): Record<string, unknown> {
    const args = binding.query_arguments;
    if (args !== null && typeof args === 'object' && !Array.isArray(args)) {
        return args as Record<string, unknown>;
    }
    return {};
}

export function buildBindingDetail(payload: {
    template_id: string;
    element_path: string;
    binding: Record<string, unknown>;
}): { groups: DetailGroup[]; title?: string } {
    const b = payload.binding;
    const sourceName = typeof b.source_name === 'string' ? b.source_name : null;
    const queryField = typeof b.query_field === 'string' ? b.query_field : null;

    const fieldMappings = extractFieldMappings(b);
    const queryArgs = extractQueryArguments(b);
    const argEntries = Object.entries(queryArgs);

    const bindingEntries: DetailGroup['entries'] = [
        {
            key: 'source_name',
            label: 'Source',
            value: sourceName,
            format: 'badge',
        },
    ];
    if (queryField !== null) {
        bindingEntries.push({
            key: 'query_field',
            label: 'Query field',
            value: queryField,
            format: 'code',
        });
    }
    bindingEntries.push(
        {
            key: 'mapping_count',
            label: 'Field mappings',
            value: fieldMappings.length,
            format: 'text',
        },
        {
            key: 'arg_count',
            label: 'Query arguments',
            value: argEntries.length,
            format: 'text',
        },
    );

    const groups: DetailGroup[] = [
        {
            label: 'Element',
            entries: [
                {
                    key: 'template_id',
                    label: 'Template',
                    value: payload.template_id,
                    format: 'badge',
                },
                {
                    key: 'element_path',
                    label: 'Path',
                    value: payload.element_path,
                    format: 'code',
                    copyable: true,
                },
            ],
        },
        {
            label: 'Binding',
            entries: bindingEntries,
        },
    ];

    // Field-mapping group — one entry per element-prop → source-field
    // pair so the LLM sees exactly WHICH source field feeds WHICH prop.
    if (fieldMappings.length > 0) {
        groups.push({
            label: 'Field mappings',
            entries: fieldMappings.map((m, i) => ({
                key: `mapping_${String(i)}`,
                label: m.prop,
                value: m.field,
                format: 'code',
            })),
        });
    }

    // Query-arguments group — the GraphQL `query.field.arguments`.
    if (argEntries.length > 0) {
        groups.push({
            label: 'Query arguments',
            entries: argEntries.map(([key, value], i) => ({
                key: `arg_${String(i)}`,
                label: key,
                value: scalarValue(value),
                format: 'text',
            })),
        });
    }

    return {
        title: `Binding for ${payload.element_path}`,
        groups,
    };
}
