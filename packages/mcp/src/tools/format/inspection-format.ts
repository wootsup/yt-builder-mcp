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
 * - `has_children_support` honours an explicit boolean (the canonical
 *   column key on the MCP-TS side). The PHP REST envelope surfaces both
 *   `has_children` (its canonical key) AND `has_children_support` (this
 *   alias) for every row — accept either to ride out a deploy skew where
 *   the wp-plugin and npm-package versions are temporarily out of step.
 *   Maria-Audit Stream C2 F-03 v2 (2026-05-22).
 * - Falls back to presence of a `children` field on the type definition.
 * - Missing fields default to empty.
 */
export function mapTypeRow(input: Record<string, unknown>): TypeRow {
    let explicitChildren: boolean | undefined;
    if (typeof input.has_children_support === 'boolean') {
        explicitChildren = input.has_children_support;
    } else if (typeof input.has_children === 'boolean') {
        // F-03 v2: PHP wire-shape uses `has_children`; older wp-plugins
        // may surface only this key. MCP-TS keeps `has_children_support`
        // as the canonical column-key but accepts the PHP-side spelling.
        explicitChildren = input.has_children;
    }

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
 * The REST plugin exposes element types in one of three shapes:
 *
 *   1. F-03 v2 (current — Maria-Audit Stream C2 2026-05-22): wrapper object
 *      `{items: [{name, label, origin, has_children, has_children_support,
 *      …}], total, element_types: [<string>, …]}`. This is the richest
 *      shape and the only one carrying label/origin/has_children — prefer
 *      it always.
 *   2. Legacy F-03 v1: wrapper object `{element_types: [<string>, …]}`
 *      with name-only entries (older WP-plugin versions). Used as the
 *      back-compat fallback when `items` is absent.
 *   3. Flat array of `{name, …}` objects — never seen on the live
 *      yt-builder-mcp REST but kept for paranoia.
 */
export function flattenTypesPayload(payload: unknown): Record<string, unknown>[] {
    if (Array.isArray(payload)) {
        return payload.map(normalizeTypeEntry).filter((x): x is Record<string, unknown> => x !== null);
    }
    if (payload !== null && typeof payload === 'object') {
        const obj = payload as Record<string, unknown>;
        // F-03 v2 preferred shape: `items[]` carries the full row.
        // Reading from `element_types` (plain string list) drops every
        // label/origin/has_children value before mapTypeRow ever sees
        // them, which is the root-cause of the audit-finding.
        if (Array.isArray(obj.items)) {
            return obj.items.map(normalizeTypeEntry).filter((x): x is Record<string, unknown> => x !== null);
        }
        if (Array.isArray(obj.element_types)) {
            return obj.element_types.map(normalizeTypeEntry).filter((x): x is Record<string, unknown> => x !== null);
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
    // `fields` is a list of {name,…} descriptors (canonical REST shape);
    // a plain record is also tolerated for back-compat.
    const keys = Array.isArray(fields)
        ? fields.map((f) => {
              if (f !== null && typeof f === 'object') {
                  const n = (f as Record<string, unknown>).name;
                  if (typeof n === 'string') return n;
              }
              return '?';
          })
        : Object.keys(fields as Record<string, unknown>);
    if (keys.length === 0) return '—';
    if (keys.length <= 8) return keys.join(', ');
    return `${keys.slice(0, 8).join(', ')}, …(+${String(keys.length - 8)} more)`;
}

function countFields(fields: unknown): number {
    if (fields === null || typeof fields !== 'object') return 0;
    if (Array.isArray(fields)) return fields.length;
    return Object.keys(fields as Record<string, unknown>).length;
}

// F-201 (Audit 2026-05-26): cap so the rendered tabular field list cannot
// blow the toolkit `detail` 8000-char budget. The largest element type
// observed in the wild (`grid`) carries 148 field descriptors — at ~70
// chars per row that would be ~10 kB which truncates. 50 rows comfortably
// fits and the footer points the agent at `structuredContent.fields` for
// the full list.
const FIELD_TABLE_CAP = 50;

interface FieldDescriptor {
    readonly name: string;
    readonly type: string;
    readonly label: string;
    readonly required: boolean;
}

function asFieldDescriptor(raw: unknown): FieldDescriptor | null {
    if (raw === null || typeof raw !== 'object') return null;
    const obj = raw as Record<string, unknown>;
    const name = typeof obj.name === 'string' ? obj.name : '';
    if (name === '') return null;
    return {
        name,
        type: typeof obj.type === 'string' ? obj.type : '',
        label: typeof obj.label === 'string' ? obj.label : '',
        // F-201 follow-up (reviewer Gap 1): honour an explicit
        // `required: true` flag on the field descriptor.
        //
        // Upstream-status: the live YT REST endpoint backing
        // `element_type_get_schema` does NOT surface required-ness today
        // (`Inspector::projectField` emits only
        // `{name, type, label?, default?, enum?, group?}` — YT4 itself
        // treats required-ness as UI-validation, not schema-declared).
        // The renderer still honours the flag so the moment upstream
        // adds a required-marker the table updates automatically with
        // no code change here.
        required: obj.required === true,
    };
}

function normaliseFieldList(fields: unknown): FieldDescriptor[] {
    if (Array.isArray(fields)) {
        const out: FieldDescriptor[] = [];
        for (const raw of fields) {
            const d = asFieldDescriptor(raw);
            if (d !== null) out.push(d);
        }
        return out;
    }
    if (fields !== null && typeof fields === 'object') {
        // Back-compat: record-shaped (legacy back-end).
        const out: FieldDescriptor[] = [];
        for (const [name, def] of Object.entries(fields as Record<string, unknown>)) {
            const type =
                def !== null && typeof def === 'object'
                    ? (typeof (def as Record<string, unknown>).type === 'string'
                          ? (def as Record<string, unknown>).type as string
                          : '')
                    : '';
            const label =
                def !== null && typeof def === 'object'
                    && typeof (def as Record<string, unknown>).label === 'string'
                    ? (def as Record<string, unknown>).label as string
                    : '';
            const required =
                def !== null && typeof def === 'object'
                    && (def as Record<string, unknown>).required === true;
            out.push({ name, type, label, required });
        }
        return out;
    }
    return [];
}

function padRight(s: string, width: number): string {
    if (s.length >= width) return s.slice(0, width);
    return s + ' '.repeat(width - s.length);
}

/**
 * F-201 (Audit 2026-05-26): render the field descriptor list as a
 * markdown-light text table for the LLM. Layout:
 *
 *     NAME              | TYPE         | LABEL
 *     ----------------- | ------------ | ----------------
 *   * content           | editor       | Content     ← required
 *     link              | link         | Link
 *
 * The leading 2-char gutter carries `* ` for required fields and two
 * spaces for optional fields, keeping column alignment intact whether
 * or not any row is required. A legend at the foot of the table
 * explains the marker semantics so the LLM doesn't have to guess.
 *
 * Required-marker upstream-status: see `asFieldDescriptor` above — live
 * YT REST does not surface required-ness, so the marker is dormant
 * until upstream adds the flag. The test fixture proves the renderer
 * is ready.
 *
 * Capped at FIELD_TABLE_CAP rows; large element types (`grid` = 148
 * fields) get a footer pointing the agent at `structuredContent.fields`
 * for the full list.
 *
 * Returns `undefined` when the field list is empty so the caller can
 * skip the section entirely.
 */
function buildFieldTable(fields: FieldDescriptor[]): string | undefined {
    if (fields.length === 0) return undefined;
    // Column widths: name 24, type 14, label fills remainder (cap 36).
    const nameW = 24;
    const typeW = 14;
    const labelW = 36;
    const lines: string[] = ['[Fields]'];
    // Header row — the gutter is empty here (no row gets a `*`).
    lines.push(
        `  ${padRight('NAME', nameW)} | ${padRight('TYPE', typeW)} | ${padRight('LABEL', labelW)}`,
    );
    lines.push(
        `  ${'-'.repeat(nameW)} | ${'-'.repeat(typeW)} | ${'-'.repeat(labelW)}`,
    );
    const visible = fields.slice(0, FIELD_TABLE_CAP);
    let anyRequired = false;
    for (const f of visible) {
        const prefix = f.required ? '* ' : '  ';
        if (f.required) anyRequired = true;
        lines.push(
            `${prefix}${padRight(f.name, nameW)} | ${padRight(f.type, typeW)} | ${padRight(f.label, labelW)}`,
        );
    }
    // Legend: only emit when at least one visible row is required, so
    // the common (no-required) case stays uncluttered. The literal
    // string `* = required` is the assertion target in the test.
    if (anyRequired) {
        lines.push('');
        lines.push('* = required');
    }
    if (fields.length > FIELD_TABLE_CAP) {
        // Detect required-ness in the truncated tail too so the legend
        // still fires when the only required rows are beyond the cap.
        if (!anyRequired) {
            const tailRequired = fields.slice(FIELD_TABLE_CAP).some((f) => f.required);
            if (tailRequired) {
                lines.push('');
                lines.push('* = required');
            }
        }
        const remaining = fields.length - FIELD_TABLE_CAP;
        lines.push('');
        lines.push(
            `…and ${String(remaining)} more — see structuredContent.fields for the full list.`,
        );
    }
    return lines.join('\n');
}

export function buildTypeSchemaDetail(payload: {
    name: string;
    label?: string;
    origin?: string;
    fields?: unknown;
}): { groups: DetailGroup[]; title?: string; appendText?: string } {
    const normalisedFields = normaliseFieldList(payload.fields);
    const appendText = buildFieldTable(normalisedFields);
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
        ...(appendText !== undefined ? { appendText } : {}),
    };
}
