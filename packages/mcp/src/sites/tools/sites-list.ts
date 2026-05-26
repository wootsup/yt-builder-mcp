/**
 * W7 — `yootheme_builder_sites_list` L1 tool.
 *
 * Platform-agnostic registry inspection: lists the in-process
 * {@link SiteRegistry} as a tabular result so an agent (or a Maria-class
 * Claude-Desktop user) can discover which site_ids are wired into this
 * MCP server WITHOUT a network round-trip.
 *
 * Why it does NOT call `pool.resolve()`:
 *  - The tool reads the registry verbatim and never touches bearers, the
 *    secret-resolver, or any HTTP endpoint. Resolving through the pool
 *    would force a default-site requirement that is exactly the
 *    bootstrap failure-mode the tool is meant to diagnose ("I added
 *    sites but forgot to mark a default"). For the same reason the
 *    handler intentionally skips `withSiteMeta` — there is no resolved
 *    site to stamp; instead we emit a `structuredContent._meta.tool_kind
 *    = 'registry'` marker so MCP hosts can route the result through a
 *    registry-aware UI if/when they grow one.
 *
 * Why `site_id` is exposed but unused:
 *  - The W5 SITE_ID_SCHEMA pin requires EVERY tool to expose the
 *    optional `site_id` field so legacy/scripted callers never trip a
 *    Zod parse on an extra-property. The W12-R1.3 DESCRIPTION_SUFFIX_EXEMPT
 *    list opts this tool out of the canonical description suffix
 *    because "operates on the default site" would imply per-row
 *    filtering that the handler does not perform.
 *
 * W12-R1.3 (A2-L4): `bearer_source` was previously projected into the
 *  output row + table column. Even though it carried only the literal
 *  `'plain'` / `'op'`, the `sites://current` resource already omits
 *  this field (see ADR rationale in server.ts) and the tool surface
 *  should match for cross-surface consistency. The field is dropped
 *  from both the structured row AND the table column.
 *
 * @license MIT
 */

import { tableResult, type TableColumn } from '@getimo/mcp-toolkit';
import { z } from 'zod';

import type { SiteRegistry } from '../registry.js';
import { SITE_ID_SCHEMA } from '../../tools/shared-schemas.js';
import {
    defineTool,
    readOnly,
    structuredResult,
    type AnyToolDefinition,
} from '../../tools/tool-builder.js';

const SITES_LIST_OUTPUT_SCHEMA = z.object({
    items: z.array(
        z.object({
            site_id: z.string(),
            url: z.string(),
            platform_hint: z.enum(['wordpress', 'joomla', 'auto']),
            platform_resolved: z.enum(['wordpress', 'joomla']).optional(),
            is_default: z.boolean(),
            label: z.string().optional(),
        }),
    ),
    total: z.number(),
    default_site_id: z.string().nullable(),
    _meta: z
        .object({
            tool_kind: z.string(),
        })
        .passthrough()
        .optional(),
});

const TABLE_COLUMNS: TableColumn[] = [
    { key: 'site_id', label: 'SITE_ID', width: 20 },
    { key: 'url', label: 'URL', width: 36 },
    { key: 'platform', label: 'PLATFORM', width: 11 },
    { key: 'is_default', label: 'DEFAULT', width: 9 },
    { key: 'label', label: 'LABEL', width: 24 },
];

/**
 * Build the W7 `yootheme_builder_sites_list` tool.
 *
 * Signature accepts the {@link SiteRegistry} directly — unlike other
 * tool-builders, it does NOT need the {@link ClientPool} because it
 * never resolves a site. Callers wire `buildSitesListTool(pool.registry)`
 * from the W7 aggregator (`src/sites/tools/index.ts`).
 */
export function buildSitesListTool(registry: SiteRegistry): AnyToolDefinition {
    return defineTool({
        name: 'yootheme_builder_sites_list',
        description:
            'List all sites configured in this multi-site MCP installation. ' +
            'Returns site_id + URL + platform (wordpress|joomla) + default flag per row. ' +
            'CALL THIS FIRST when working with a fresh MCP connection to discover available ' +
            'site_ids before targeting one with any other tool. Read-only, no REST calls. ' +
            'Keywords: list sites, list connections, list installations, discover site_id, ' +
            'available sites, configured sites, what sites exist, multi-site index. ' +
            '(site_id is accepted for schema-uniformity but ignored by this tool.)',
        inputSchema: {
            site_id: SITE_ID_SCHEMA,
        },
        outputSchema: SITES_LIST_OUTPUT_SCHEMA,
        annotations: readOnly('Sites — List configured'),
        handler: async () => {
            const rows = registry.listForDisplay();
            const defaultSiteId = registry.defaultSiteId();

            // Map the registry row to a structured-content item with
            // safe-to-render primitives ONLY (no bearer leakage). The
            // `platform` projection collapses hint + resolved into a
            // single display string so the table stays readable.
            const items = rows.map((row) => ({
                site_id: row.site_id,
                url: row.url,
                platform_hint: row.platform_hint,
                ...(row.platform_resolved !== undefined
                    ? { platform_resolved: row.platform_resolved }
                    : {}),
                is_default: row.is_default,
                ...(row.label !== undefined ? { label: row.label } : {}),
            }));

            // Table-side projection: humans see one `platform` column
            // (resolved if known, hint otherwise) and a yes/no on
            // is_default for legibility.
            const tableRows: Record<string, unknown>[] = rows.map((row) => ({
                site_id: row.site_id,
                url: row.url,
                platform: row.platform_resolved ?? row.platform_hint,
                is_default: row.is_default ? 'yes' : 'no',
                label: row.label ?? '',
            }));

            const toolkitResult = tableResult(tableRows, {
                columns: TABLE_COLUMNS,
                header: (count) =>
                    `${String(count)} configured site${count === 1 ? '' : 's'}` +
                    (defaultSiteId !== null ? ` (default: ${defaultSiteId})` : ' (no default configured)'),
                footer:
                    'Use yootheme_builder_sites_test <site_id> to verify connectivity. ' +
                    'Pass `site_id: "<id>"` on any tool call to target a specific site.',
            });

            // The W6 `withSiteMeta` wrapper is intentionally NOT used —
            // see the file header for rationale. We emit a stable
            // `_meta.tool_kind: "registry"` marker so MCP hosts can
            // distinguish registry tools from site-bound tools without
            // string-matching the tool name.
            return structuredResult(toolkitResult, {
                items,
                total: items.length,
                default_site_id: defaultSiteId,
                _meta: { tool_kind: 'registry' },
            });
        },
    });
}
