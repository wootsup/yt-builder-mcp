/**
 * Inspection format-sidecar — pure transforms for `element_types_list` (table)
 * and `element_type_get_schema` (detail). Wave G.2.9 (Design §3.2 rows 11 / 12).
 *
 * These functions are stateless. The handlers themselves are tested separately
 * in `tests/tools/inspection.test.ts` (shape pins on the REST URL).
 *
 * @license MIT
 */

import type { DetailGroup, TableColumn } from '@getimo/mcp-toolkit';

// ─── element_types_list columns (Design §3.2 row 11) ─────────────────

/** Full columns for `yootheme_builder_element_types_list`. */
export const TYPES_TABLE_COLUMNS: readonly TableColumn[] = [
    { key: 'name', label: 'NAME', width: 24, llmOnly: true },
    { key: 'label', label: 'LABEL', width: 28 },
    { key: 'origin', label: 'ORIGIN', width: 14 },
    { key: 'has_children_support', label: 'CHILDREN', width: 10 },
];

/** Compact columns kick in at 21+ rows — drop the boolean. */
export const TYPES_COMPACT_COLUMNS: readonly TableColumn[] = [
    { key: 'name', label: 'NAME', width: 24, llmOnly: true },
    { key: 'label', label: 'LABEL', width: 28 },
    { key: 'origin', label: 'ORIGIN', width: 14 },
];

// ─── Row mapper ──────────────────────────────────────────────────────

function asString(v: unknown): string {
    return typeof v === 'string' ? v : '';
}

function asBool(v: unknown): boolean {
    return v === true;
}

export interface TypeRow {
    name: string;
    label: string;
    origin: string;
    has_children_support: boolean;
}

/**
 * Maps an `/element-types` row to the table shape.
 *
 * - `has_children_support` honours an explicit boolean, else falls back to
 *   presence of a `children` field on the type definition.
 * - Missing fields default to empty.
 */
export function mapTypeRow(input: Record<string, unknown>): TypeRow {
    const explicitChildren =
        typeof input.has_children_support === 'boolean'
            ? input.has_children_support
            : undefined;

    return {
        name: asString(input.name),
        label: asString(input.label),
        origin: asString(input.origin),
        has_children_support:
            explicitChildren ?? hasChildrenField(input.children),
    };
}

function hasChildrenField(v: unknown): boolean {
    if (Array.isArray(v)) return v.length > 0;
    if (v !== null && typeof v === 'object') {
        return Object.keys(v as Record<string, unknown>).length > 0;
    }
    return asBool(v);
}

/**
 * The REST plugin exposes element types EITHER as a flat array of
 * `{name, label, origin, …}` objects OR as a wrapper object with an
 * `element_types` array. Tolerate both. Also tolerate string-only
 * entries (older shape — just the name) by promoting to `{name}`.
 */
export function flattenTypesPayload(payload: unknown): Record<string, unknown>[] {
    if (Array.isArray(payload)) {
        return payload.map(normalizeTypeEntry).filter((x): x is Record<string, unknown> => x !== null);
    }
    if (payload !== null && typeof payload === 'object') {
        const obj = payload as Record<string, unknown>;
        const list = obj.element_types;
        if (Array.isArray(list)) {
            return list.map(normalizeTypeEntry).filter((x): x is Record<string, unknown> => x !== null);
        }
    }
    return [];
}

function normalizeTypeEntry(entry: unknown): Record<string, unknown> | null {
    if (typeof entry === 'string') return { name: entry };
    if (entry !== null && typeof entry === 'object') return entry as Record<string, unknown>;
    return null;
}

// ─── Detail builder (Design §3.2 row 12) ─────────────────────────────

function summarizeFieldKeys(fields: unknown): string {
    if (fields === null || typeof fields !== 'object') return '—';
    const keys = Object.keys(fields as Record<string, unknown>);
    if (keys.length === 0) return '—';
    if (keys.length <= 8) return keys.join(', ');
    return `${keys.slice(0, 8).join(', ')}, …(+${String(keys.length - 8)} more)`;
}

function countFields(fields: unknown): number {
    if (fields === null || typeof fields !== 'object') return 0;
    return Object.keys(fields as Record<string, unknown>).length;
}

export function buildTypeSchemaDetail(payload: {
    name: string;
    label?: string;
    origin?: string;
    fields?: unknown;
}): { groups: DetailGroup[]; title?: string } {
    return {
        title: `Element type: ${payload.name}`,
        groups: [
            {
                label: 'Identity',
                entries: [
                    { key: 'name', label: 'Name', value: payload.name, format: 'code', copyable: true },
                    ...(typeof payload.label === 'string' && payload.label.length > 0
                        ? [{ key: 'label', label: 'Label', value: payload.label } as const]
                        : []),
                    ...(typeof payload.origin === 'string' && payload.origin.length > 0
                        ? [{ key: 'origin', label: 'Origin', value: payload.origin, format: 'badge' } as const]
                        : []),
                ],
            },
            {
                label: 'Fields (summary)',
                entries: [
                    { key: 'count', label: 'Count', value: countFields(payload.fields), format: 'text' },
                    { key: 'keys', label: 'Keys', value: summarizeFieldKeys(payload.fields) },
                ],
            },
        ],
    };
}
