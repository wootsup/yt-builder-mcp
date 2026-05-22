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

export function buildBindingDetail(payload: {
    template_id: string;
    element_path: string;
    binding: Record<string, unknown>;
}): { groups: DetailGroup[]; title?: string } {
    const b = payload.binding;
    const sourceName = typeof b.source_name === 'string' ? b.source_name : null;
    const configCount =
        b.source_config !== null && typeof b.source_config === 'object'
            ? Object.keys(b.source_config as Record<string, unknown>).length
            : 0;
    const argsCount =
        b.source_args !== null && typeof b.source_args === 'object'
            ? Object.keys(b.source_args as Record<string, unknown>).length
            : 0;

    return {
        title: `Binding for ${payload.element_path}`,
        groups: [
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
                entries: [
                    {
                        key: 'source_name',
                        label: 'Source',
                        value: sourceName,
                        format: 'badge',
                    },
                    {
                        key: 'config_keys',
                        label: 'Config keys',
                        value: configCount,
                        format: 'text',
                    },
                    {
                        key: 'args_keys',
                        label: 'Args keys',
                        value: argsCount,
                        format: 'text',
                    },
                ],
            },
        ],
    };
}
