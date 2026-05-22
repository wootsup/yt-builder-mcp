/**
 * Elements format-sidecar — pure transforms for `element_list` (table) and
 * `element_get` (detail). Wave G.2.5 (Design §3.2 rows 9 / 10).
 *
 * @license MIT
 */

import type { DetailGroup, TableColumn } from '@getimo/mcp-toolkit';

// ─── Element-list columns (Design §3.2 row 9) ────────────────────────

export const ELEMENTS_TABLE_COLUMNS: readonly TableColumn[] = [
    { key: 'path', label: 'PATH', width: 32, llmOnly: true },
    { key: 'element_type', label: 'TYPE', width: 16 },
    { key: 'label', label: 'LABEL', width: 28 },
    { key: 'has_binding', label: 'BIND', width: 8 },
];

/** Compact columns kick in at 21+ rows — keep path + type only. */
export const ELEMENTS_COMPACT_COLUMNS: readonly TableColumn[] = [
    { key: 'path', label: 'PATH', width: 32, llmOnly: true },
    { key: 'element_type', label: 'TYPE', width: 16 },
];

// ─── Row mapper ──────────────────────────────────────────────────────

function asString(v: unknown): string {
    return typeof v === 'string' ? v : '';
}

function hasSourceBinding(props: unknown): boolean {
    if (props === null || typeof props !== 'object') return false;
    const obj = props as Record<string, unknown>;
    return typeof obj.source === 'string' && obj.source.length > 0;
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

    return {
        path: asString(input.path),
        element_type: asString(input.element_type),
        label,
        has_binding:
            explicitBinding ?? hasSourceBinding((input as Record<string, unknown>).props),
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
