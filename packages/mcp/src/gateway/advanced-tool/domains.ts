/**
 * Gateway domain taxonomy — single source of truth for grouping
 * advanced tools by domain in description, errors, and discovery
 * payloads.
 *
 * Round-2 (R2-A2-CRIT2) extracted from `gateway/advanced-tool.ts`
 * to keep that file ≤ 200 LoC per Architecture §11.
 *
 * The 4-entry `DOMAIN_ORDER` contract is pinned by
 * `tests/gateway/domain-order.test.ts` — any change here MUST update
 * that pin-test (Round-2 audit follow-up R2-A1-N1).
 *
 * @license MIT
 */

import type { AdvancedToolEntry } from '../capturing-server.js';

/**
 * Domains used to group advanced tool names. Order = display order in
 * gateway description, errors, and discovery responses. Aligned with
 * YOOtheme builder concepts (Design-Doc §3.1.1). Round-1 I4 trimmed
 * from 8→4 (singular forms now route via DOMAIN_PREFIX_MAP, not
 * duplicate entries). Pinned by `tests/gateway/domain-order.test.ts`.
 */
export const DOMAIN_ORDER = ['pages', 'elements', 'sources', 'multi-items', 'inspection'] as const;

/**
 * Prefix→domain routing table. Each domain accepts plural
 * (`pages_list`) + singular (`page_save`, `element_add`, `source_…`)
 * stems via `stem.startsWith(prefix + '_')` or full-equality. Anything
 * outside the taxonomy falls through to 'misc'.
 *
 * The `multi-items` domain captures the YT-Pro Multi-Items binding
 * pattern tools (inspect / clean-implode). Stems used:
 *  - `inspect_multi_items_binding` → starts with `inspect_multi_items`
 *  - `clean_implode_directives`    → starts with `clean_implode`
 */
export const DOMAIN_PREFIX_MAP: Record<(typeof DOMAIN_ORDER)[number], readonly string[]> = {
    pages: ['pages', 'page'],
    elements: ['elements', 'element'],
    sources: ['sources', 'source'],
    'multi-items': ['inspect_multi_items', 'clean_implode'],
    inspection: ['inspection'],
};

export const TOOL_PREFIX = 'yootheme_builder_';

/** Maps a tool name to its display domain (the first underscore-segment after the prefix). */
export function domainOf(toolName: string): string {
    const stem = toolName.startsWith(TOOL_PREFIX) ? toolName.slice(TOOL_PREFIX.length) : toolName;
    for (const domain of DOMAIN_ORDER) {
        for (const prefix of DOMAIN_PREFIX_MAP[domain]) {
            if (stem === prefix || stem.startsWith(`${prefix}_`)) return domain;
        }
    }
    return 'misc';
}

/** Groups tool names by domain, in DOMAIN_ORDER, for stable output. */
export function groupByDomain(toolNames: string[]): Map<string, string[]> {
    const groups = new Map<string, string[]>();
    for (const domain of DOMAIN_ORDER) groups.set(domain, []);
    for (const name of [...toolNames].sort()) {
        const domain = domainOf(name);
        const bucket = groups.get(domain);
        if (bucket) bucket.push(name);
    }
    for (const [domain, names] of groups) {
        if (names.length === 0) groups.delete(domain);
    }
    return groups;
}

/** Renders the grouped tool list as readable text (used in description + errors). */
export function renderGroupedList(toolNames: string[]): string {
    const groups = groupByDomain(toolNames);
    const lines: string[] = [];
    for (const [domain, names] of groups) {
        lines.push(`  ${domain}: ${names.join(', ')}`);
    }
    return lines.join('\n');
}

/** Builds the gateway tool's description, grouping every advanced tool by domain. */
export function buildDescription(toolNames: string[]): string {
    const toolCount = toolNames.length;
    const domains = [...groupByDomain(toolNames).keys()];
    const domainCount = domains.length;
    const domainList = domains.join(', ');
    return (
        `Routes to ${String(toolCount)} advanced YOOtheme Builder tools across ${String(domainCount)} ` +
        `domain${domainCount === 1 ? '' : 's'}${domainCount > 0 ? ` (${domainList})` : ''}. ` +
        'Call with { tool } for a target\'s schema + annotations, or ' +
        '{ tool, arguments } to run it. The target tool\'s own confirmation ' +
        'guards and behavior stay in effect.\n\n' +
        'Available advanced tools by domain:\n' +
        `${renderGroupedList(toolNames)}\n\n` +
        'For connectivity diagnostics use yootheme_builder_health or yootheme_builder_diagnose (top-level).'
    );
}

/** Re-export the AdvancedToolEntry type so consumers don't need a second import path. */
export type { AdvancedToolEntry };
