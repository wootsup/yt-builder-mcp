/**
 * W7 — `yootheme_builder_sites_test` L1 tool.
 *
 * Targeted connectivity probe for ONE site_id: runs `/health` (no auth
 * required) + `/etag` (auth required) in parallel and returns a
 * structured `{plugin_reachable, bearer_valid, platform, ...}` block.
 *
 * Why `site_id` is REQUIRED here (not optional):
 *  - Every other tool in the registry treats `site_id` as an optional
 *    selector that falls back to the default. `sites_test` is the
 *    exception: its raison d'être is to verify ONE specific id, so the
 *    schema upgrades from `.optional()` to required. The W5
 *    SITE_ID_SCHEMA pin-test in `tests/tools/site-id-schema.test.ts`
 *    explicitly skips this tool from its "every tool's site_id is
 *    optional" loop — see the SITES_TEST_EXEMPT_FROM_OPTIONAL set there.
 *
 * Why the result wraps `withSiteMeta`:
 *  - Once `pool.resolve(site_id)` succeeds we DO have a resolved-site
 *    descriptor, so the text-prefix `[<label> @ <host>] ` is the same
 *    site-awareness signal a Maria-class user already sees on every
 *    other tool. The W6.2 wrapper handles both green AND red results
 *    uniformly (it just stamps; no behaviour difference).
 *
 * Failure modes mapped:
 *  - `UnknownSiteError` → structured error `code: 'unknown_site'` with
 *    `available[]` hint (via `resolveSiteOrError`).
 *  - `NoDefaultSiteError` → cannot fire here because site_id is required
 *    and validated by Zod before the handler runs; left in the helper
 *    for defense-in-depth.
 *  - `/health` rejects → `plugin_reachable: false` + error message.
 *  - `/etag` 401/403 → `plugin_reachable: true` + `bearer_valid: false`.
 *  - `/etag` other reject → `plugin_reachable: true` + `bearer_valid:
 *    false` + raw error.
 *
 * @license MIT
 */

import { detailResult, type DetailGroup } from '@getimo/mcp-toolkit';
import { z } from 'zod';

import {
    resolveSiteOrError,
} from '../../tools/pool-resolve-helper.js';
import {
    defineTool,
    readOnly,
    structuredResult,
    withSiteMeta,
    type AnyToolDefinition,
} from '../../tools/tool-builder.js';
import type { ClientPool } from '../client-pool.js';
import { probeSite } from '../probe.js';

const SITES_TEST_OUTPUT_SCHEMA = z.object({
    site_id: z.string(),
    site_url: z.string(),
    platform: z.enum(['wordpress', 'joomla']),
    plugin_reachable: z.boolean(),
    bearer_valid: z.boolean(),
    etag_received: z.boolean(),
    plugin_error: z.string().optional(),
    bearer_error: z.string().optional(),
    summary: z.string(),
});

interface TestChecks {
    site_id: string;
    site_url: string;
    platform: 'wordpress' | 'joomla';
    plugin_reachable: boolean;
    bearer_valid: boolean;
    etag_received: boolean;
    plugin_error?: string;
    bearer_error?: string;
    summary: string;
}

/**
 * Build the W7 `yootheme_builder_sites_test` tool.
 *
 * Signature accepts the {@link ClientPool} directly (no separate
 * registry param) — `pool.resolve(site_id)` is the single source of
 * truth and carries the registry inside.
 */
export function buildSitesTestTool(pool: ClientPool): AnyToolDefinition {
    return defineTool({
        name: 'yootheme_builder_sites_test',
        description:
            'Verify connectivity to ONE site: probes /health (no auth) + ' +
            '/etag (auth) in parallel; returns plugin_reachable + ' +
            'bearer_valid. `site_id` is REQUIRED. Use sites_list to find IDs.',
        inputSchema: {
            site_id: z
                .string()
                .min(1)
                .max(64)
                .regex(
                    /^[a-zA-Z0-9_-]+$/,
                    'site_id uses letters/digits/dash/underscore only',
                )
                .describe(
                    'Site to test (REQUIRED for this tool — overrides default). ' +
                        'Use yootheme_builder_sites_list to discover valid IDs.',
                ),
        },
        outputSchema: SITES_TEST_OUTPUT_SCHEMA,
        annotations: readOnly('Sites — Test connectivity'),
        handler: async ({ site_id }) => {
            const r = await resolveSiteOrError(pool, site_id);
            if (!r.ok) return r.error;
            const { client: siteClient, site } = r;

            // W12-R1.2: the parallel /health + /etag probe lives in
            // sites/probe.ts so MCP-tool + CLI surfaces stay byte-for-byte
            // in sync. The handler here only maps the structured probe
            // result into the toolkit detail-card envelope and stamps
            // site-awareness via withSiteMeta.
            const probe = await probeSite(siteClient);

            const checks: TestChecks = {
                site_id: site.id,
                site_url: site.url,
                platform: site.platform.kind,
                plugin_reachable: probe.plugin_reachable,
                bearer_valid: probe.bearer_valid,
                etag_received: probe.etag_received,
                summary: probe.summary,
                ...(probe.plugin_error !== undefined ? { plugin_error: probe.plugin_error } : {}),
                ...(probe.bearer_error !== undefined ? { bearer_error: probe.bearer_error } : {}),
            };

            const toolkitResult = detailResult(buildTestDetail(checks));
            const isError = !(checks.plugin_reachable && checks.bearer_valid);
            const base = structuredResult(toolkitResult, {
                ...checks,
            });
            const wrapped: typeof base = isError
                ? { ...base, isError: true }
                : base;
            return withSiteMeta(wrapped, site);
        },
    });
}

/**
 * Render a 2-group detail-card body from the structured `checks`. Group
 * 1 = identity (site_id/url/platform), Group 2 = probe results
 * (plugin_reachable + bearer_valid + etag_received + summary). Keeps the
 * shape stable across green / partial / red so MCP-host Detail-Card
 * renderers don't need to branch.
 */
function buildTestDetail(checks: TestChecks): {
    groups: DetailGroup[];
    title?: string;
} {
    const identityEntries: DetailGroup['entries'] = [
        { key: 'site_id', label: 'Site ID', value: checks.site_id, format: 'badge' },
        { key: 'site_url', label: 'Site URL', value: checks.site_url },
        { key: 'platform', label: 'Platform', value: checks.platform, format: 'badge' },
    ];

    const probeEntries: DetailGroup['entries'] = [
        { key: 'plugin_reachable', label: 'Plugin reachable', value: checks.plugin_reachable, format: 'boolean' },
        { key: 'bearer_valid', label: 'Bearer valid', value: checks.bearer_valid, format: 'boolean' },
        { key: 'etag_received', label: 'ETag received', value: checks.etag_received, format: 'boolean' },
        { key: 'summary', label: 'Summary', value: checks.summary },
    ];
    if (checks.plugin_error !== undefined) {
        probeEntries.push({
            key: 'plugin_error', label: 'Plugin error', value: checks.plugin_error, format: 'code',
        });
    }
    if (checks.bearer_error !== undefined) {
        probeEntries.push({
            key: 'bearer_error', label: 'Bearer error', value: checks.bearer_error, format: 'code',
        });
    }

    return {
        title: 'YT Builder MCP — Sites Test',
        groups: [
            { label: 'Site', entries: identityEntries },
            { label: 'Probe', entries: probeEntries },
        ],
    };
}
