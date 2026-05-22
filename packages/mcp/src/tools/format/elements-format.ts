/**
 * Elements format-sidecar — pure transforms for `element_list` (table) and
 * `element_get` (detail). Wave G.2.5 (Design §3.2 rows 9 / 10).
 *
 * @license MIT
 */

import type { DetailGroup, TableColumn } from '@getimo/mcp-toolkit';

// ─── Element-list columns (Design §3.2 row 9) ────────────────────────
// F-06: PATH column widened to 64 chars + 'rel_path' shows just the
// template-layout suffix so every row stays uniquely identifiable in
// the text table. Full path remains available in structuredContent.

export const ELEMENTS_TABLE_COLUMNS: readonly TableColumn[] = [
    { key: 'rel_path', label: 'PATH', width: 48, llmOnly: true },
    { key: 'element_type', label: 'TYPE', width: 16 },
    { key: 'label', label: 'LABEL', width: 28 },
    { key: 'has_binding', label: 'BIND', width: 8 },
];

/** Compact columns kick in at 21+ rows — keep path + type only. */
export const ELEMENTS_COMPACT_COLUMNS: readonly TableColumn[] = [
    { key: 'rel_path', label: 'PATH', width: 48, llmOnly: true },
    { key: 'element_type', label: 'TYPE', width: 16 },
];

// ─── Row mapper ──────────────────────────────────────────────────────

function asString(v: unknown): string {
    return typeof v === 'string' ? v : '';
}

/**
 * Defensive `has_binding` derivation used when the REST plugin does not
 * surface an explicit boolean. Mirrors `BindingSerializer::hasBinding()`
 * on the PHP side so all readers agree on the same node.
 *
 * D1 / T1 (F-01-Rest, 2026-05-22): the heuristic accepts the same four
 * carrier slots BindingSerializer accepts on the PHP side:
 *   1. props.source                — F-13 canonical
 *   2. top-level node.source       — pre-bind cached YT4 trees
 *   3. node.source_extended        — YT4 internal cached/expanded form
 *   4. legacy plain-string source  — pre-F-13 user data
 *
 * A node is "bound" if any carrier yields either a non-empty `query.name`
 * OR at least one `props.<el>.name` field-mapping (inherit-from-parent
 * pattern — the field-bindings reference the parent iteration source via
 * the `${builder.source}` token without a local query name).
 */
function isStructuredBinding(src: unknown): boolean {
    if (src === null || typeof src !== 'object') return false;
    const obj = src as Record<string, unknown>;
    const query = obj.query;
    if (query !== null && typeof query === 'object') {
        const name = (query as Record<string, unknown>).name;
        if (typeof name === 'string' && name.length > 0) return true;
    }
    // No query name — bound only if at least one props.<el>.name field-
    // mapping is present (inherit-from-parent pattern).
    const props = obj.props;
    if (props !== null && typeof props === 'object') {
        for (const k of Object.keys(props as Record<string, unknown>)) {
            const v = (props as Record<string, unknown>)[k];
            if (v !== null && typeof v === 'object') {
                const name = (v as Record<string, unknown>).name;
                if (typeof name === 'string' && name.length > 0) return true;
            }
        }
    }
    return false;
}

function hasSourceBinding(node: unknown): boolean {
    if (node === null || typeof node !== 'object') return false;
    const obj = node as Record<string, unknown>;

    // (1) props.source — F-13 canonical.
    const props = obj.props;
    if (props !== null && typeof props === 'object' && 'source' in (props as Record<string, unknown>)) {
        const src = (props as Record<string, unknown>).source;
        if (typeof src === 'string') {
            if (src.length > 0) return true;
        } else if (isStructuredBinding(src)) {
            return true;
        }
    }

    // (2) top-level node.source.
    if ('source' in obj) {
        const src = obj.source;
        if (typeof src === 'string') {
            if (src.length > 0) return true;
        } else if (isStructuredBinding(src)) {
            return true;
        }
    }

    // (3) node.source_extended — YT4 cached/expanded.
    if ('source_extended' in obj && isStructuredBinding(obj.source_extended)) {
        return true;
    }

    return false;
}

/**
 * Maps an `/elements` row to the table shape.
 *
 * - `label` falls back to `title` (REST surface varies — newer builds emit
 *   `label`, older emit `title` as the human-readable name).
 * - `has_binding` honours an explicit boolean from the REST plugin, else
 *   derives from `props.source` presence.
 */
export function mapElementRow(input: Record<string, unknown>): Record<string, unknown> {
    const label =
        asString(input.label) !== ''
            ? asString(input.label)
            : asString((input as Record<string, unknown>).title);

    const explicitBinding =
        typeof input.has_binding === 'boolean' ? input.has_binding : undefined;

    const path = asString(input.path);
    // F-06: derive relative path by stripping `/templates/<id>/layout` prefix
    // so the LLM table column shows uniquely-identifying suffix.
    const relPath = path.replace(/^\/templates\/[^/]+\/layout/, '') || '/';

    return {
        path,
        rel_path: relPath,
        element_type: asString(input.element_type),
        label,
        has_binding: explicitBinding ?? hasSourceBinding(input),
    };
}

// ─── Detail builder (Design §3.2 row 10) ─────────────────────────────

interface ElementChildLike {
    type?: unknown;
}

function summarizeProps(props: unknown): string {
    if (props === null || typeof props !== 'object') return '—';
    const keys = Object.keys(props as Record<string, unknown>);
    if (keys.length === 0) return '—';
    if (keys.length <= 6) return keys.join(', ');
    return `${keys.slice(0, 6).join(', ')}, …(+${String(keys.length - 6)} more)`;
}

function listChildTypes(children: ElementChildLike[]): string {
    if (children.length === 0) return '—';
    const seen = new Map<string, number>();
    for (const c of children) {
        const t = typeof c.type === 'string' ? c.type : '?';
        seen.set(t, (seen.get(t) ?? 0) + 1);
    }
    return [...seen.entries()].map(([t, n]) => (n > 1 ? `${t}×${String(n)}` : t)).join(', ');
}

export function buildElementDetail(payload: {
    path: string;
    element_type: string;
    props?: unknown;
    children?: ElementChildLike[];
    label?: string;
}): { groups: DetailGroup[]; title?: string } {
    const children = Array.isArray(payload.children) ? payload.children : [];

    return {
        title: `Element ${payload.path}`,
        groups: [
            {
                label: 'Identity',
                entries: [
                    { key: 'path', label: 'Path', value: payload.path, format: 'code', copyable: true },
                    { key: 'element_type', label: 'Type', value: payload.element_type, format: 'badge' },
                    ...(typeof payload.label === 'string' && payload.label.length > 0
                        ? [{ key: 'label', label: 'Label', value: payload.label } as const]
                        : []),
                ],
            },
            {
                label: 'Props (summary)',
                entries: [
                    { key: 'keys', label: 'Keys', value: summarizeProps(payload.props) },
                ],
            },
            {
                label: 'Children',
                entries: [
                    { key: 'count', label: 'Count', value: children.length, format: 'text' },
                    { key: 'types', label: 'Types', value: listChildTypes(children) },
                ],
            },
            {
                label: 'Next steps',
                entries: [
                    {
                        key: 'next',
                        label: 'Suggestions',
                        value:
                            'Use yootheme_builder_element_update_settings to change props, ' +
                            'yootheme_builder_element_clone to duplicate, or ' +
                            'yootheme_builder_element_delete to remove (requires confirm).',
                    },
                ],
            },
        ],
    };
}
