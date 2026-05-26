/**
 * Health & diagnostics tools.
 *
 *   yootheme_builder_health
 *      → GET /health (unauthenticated). Verifies the plugin is
 *        installed and reachable. Returns plugin/YT/WP/PHP versions
 *        + available endpoints.
 *
 *   yootheme_builder_diagnose
 *      → Aggregates health + a Bearer-auth probe so the agent can
 *        distinguish "plugin missing" from "wrong key".
 *
 * Both tools emit `detailResult` (Wave G.2) so hosts that support
 * structured content render a Detail-Card; the LLM still reads the text
 * leg from `content[0].text`.
 *
 * @license MIT
 */

import { detailResult, statsResult } from '@getimo/mcp-toolkit';
import { z } from 'zod';
import { RestError } from '../errors.js';
import type { ClientPool } from '../sites/client-pool.js';
import {
    buildDiagnoseDetail,
    buildHealthStats,
    type DiagnoseChecks,
    type HealthPayload,
} from './format/health-format.js';
import { SITE_ID_SCHEMA } from './shared-schemas.js';
import { resolveSiteOrError } from './pool-resolve-helper.js';
import {
    defineTool,
    errorResult,
    jsonResult,
    readOnly,
    structuredResult,
    withSiteMeta,
    type AnyToolDefinition,
} from './tool-builder.js';

// ─── outputSchemas (Wave G.2 §4) ─────────────────────────────────────

const HEALTH_OUTPUT_SCHEMA = z.object({
    plugin_version: z.string(),
    // Cross-platform note (Wave 7): the anonymous health payload only carries
    // {plugin_version, status, yootheme_loaded}; the rest is disclosed ONLY
    // with a valid Bearer (server-side fingerprint-reduction). On Joomla the
    // augmented payload uses `cms`/`cms_version` and emits NEITHER `wp_version`
    // NOR `yootheme_version`. All platform-/tier-variable fields are therefore
    // optional so the same MCP tool validates against WP and Joomla, anon and
    // authenticated.
    yootheme_version: z.string().nullable().optional(),
    wp_version: z.string().nullable().optional(),
    cms: z.string().optional(),
    cms_version: z.string().optional(),
    php_version: z.string().optional(),
    storage_type: z.string().optional(),
    storage_target: z.string().optional(),
    yootheme_loaded: z.boolean(),
    available_endpoints: z.array(z.string()).optional(),
    // 1.0.1 — surface canonical WP URLs so an agent can deep-link the
    // customer to the live site without a separate REST round-trip.
    // Both fields are optional in the schema for back-compat with older
    // 1.0.0 PHP servers that don't yet emit them.
    site_url: z
        .string()
        .nullable()
        .optional()
        .describe(
            'Canonical WordPress install URL including any subpath ' +
                '(e.g. `https://example.com/wordpress`). Authenticated payload only.',
        ),
    home_url: z
        .string()
        .nullable()
        .optional()
        .describe(
            'Front-end home URL — use this to deep-link the user to the live ' +
                'site they edited.',
        ),
});

const DIAGNOSE_OUTPUT_SCHEMA = z.object({
    plugin_reachable: z.boolean(),
    plugin_version: z.string().optional(),
    yootheme_loaded: z.boolean().optional(),
    yootheme_version: z.string().nullable().optional(),
    endpoint_count: z.number().optional(),
    plugin_error: z.string().optional(),
    bearer_valid: z.boolean().optional(),
    bearer_error: z.string().optional(),
    summary: z.string().optional(),
    // 1.0.1 — mirror the health fields for parity. Diagnose calls /health
    // internally so it has the values; surfacing them avoids forcing the
    // agent to call both tools to get URL info + bearer status.
    site_url: z
        .string()
        .nullable()
        .optional()
        .describe(
            'Canonical WordPress install URL including any subpath ' +
                '(e.g. `https://example.com/wordpress`). Mirrored from /health so an ' +
                'agent gets URL info + bearer status in one call.',
        ),
    home_url: z
        .string()
        .nullable()
        .optional()
        .describe(
            'Front-end home URL — use this to deep-link the user to the live ' +
                'site they edited.',
        ),
});

export function buildHealthTools(pool: ClientPool): readonly AnyToolDefinition[] {
    return [
        defineTool({
            name: 'yootheme_builder_health',
            description:
                'Check plugin installed/reachable. Returns plugin version, YT Pro version, REST ' +
                'endpoints. Authenticated payload adds site_url + home_url for deep-linking. ' +
                'See yootheme_builder_diagnose for Bearer-validity + connectivity summary. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
            },
            outputSchema: HEALTH_OUTPUT_SCHEMA,
            annotations: readOnly('Health Check'),
            handler: async ({ site_id }) => {
                const r = await resolveSiteOrError(pool, site_id);
                if (!r.ok) return r.error;
                const { client: siteClient, site } = r;
                try {
                    const data = await siteClient.get<HealthPayload>('/health');
                    // Round-1 audit I1 fix: spec §3.2 row 1 calls for
                    // `statsResult` (flat metrics). Earlier shipped
                    // `detailResult` — that's a Detail-Card variant, not
                    // the stats-display the spec asked for. The flat
                    // shape is built by `buildHealthStats`.
                    const toolkitResult = statsResult(buildHealthStats(data));
                    // Only surface fields the server actually returned — the
                    // anonymous tier and Joomla omit different subsets, and the
                    // output schema now marks all platform-/tier-variable fields
                    // optional (Wave 7 cross-platform parity).
                    return withSiteMeta(structuredResult(toolkitResult, {
                        plugin_version: data.plugin_version,
                        yootheme_loaded: data.yootheme_loaded,
                        ...(data.yootheme_version !== undefined ? { yootheme_version: data.yootheme_version } : {}),
                        ...(data.wp_version !== undefined ? { wp_version: data.wp_version } : {}),
                        ...(data.cms !== undefined ? { cms: data.cms } : {}),
                        ...(data.cms_version !== undefined ? { cms_version: data.cms_version } : {}),
                        ...(data.php_version !== undefined ? { php_version: data.php_version } : {}),
                        ...(data.storage_type !== undefined ? { storage_type: data.storage_type } : {}),
                        ...(data.storage_target !== undefined ? { storage_target: data.storage_target } : {}),
                        ...(data.available_endpoints !== undefined ? { available_endpoints: data.available_endpoints } : {}),
                        ...(data.site_url !== undefined ? { site_url: data.site_url } : {}),
                        ...(data.home_url !== undefined ? { home_url: data.home_url } : {}),
                    }), site);
                } catch (e) {
                    return withSiteMeta(errorResult({
                        error: e,
                        context: { site_id: site.id },
                        hint:
                            'Verify YTB_MCP_SITE_URL points at a WordPress or Joomla site with the ' +
                            'yt-builder-mcp plugin active. Try opening ' +
                            '<site_url>/wp-json/yt-builder-mcp/v1/health (WP) or ' +
                            '<site_url>/api/index.php/v1/yt-builder-mcp/health (Joomla) in a browser.',
                    }), site);
                }
            },
        }),

        defineTool({
            name: 'yootheme_builder_diagnose',
            description:
                'Full diagnostic: /health + authenticated /etag probe. Returns site_url, ' +
                'home_url, plugin reachability, Bearer validity in one call. First call when ' +
                'you need to know where the site lives. For per-template URLs see pages_list. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
            },
            outputSchema: DIAGNOSE_OUTPUT_SCHEMA,
            annotations: readOnly('Diagnose Connection'),
            handler: async ({ site_id }) => {
                const r = await resolveSiteOrError(pool, site_id);
                if (!r.ok) return r.error;
                const { client: siteClient, site } = r;
                const checks: DiagnoseChecks = { plugin_reachable: false };

                try {
                    const health = await siteClient.get<HealthPayload>('/health');
                    checks.plugin_reachable = true;
                    checks.plugin_version = health.plugin_version;
                    checks.yootheme_loaded = health.yootheme_loaded;
                    checks.yootheme_version = health.yootheme_version;
                    checks.endpoint_count = (health.available_endpoints ?? []).length;
                    // 1.0.1 — mirror health URLs into diagnose output so an
                    // agent gets URL info AND bearer-status in one call.
                    if (health.site_url !== undefined) {
                        checks.site_url = health.site_url;
                    }
                    if (health.home_url !== undefined) {
                        checks.home_url = health.home_url;
                    }
                } catch (e) {
                    checks.plugin_reachable = false;
                    checks.plugin_error = e instanceof Error ? e.message : String(e);
                    // Plugin-unreachable path stays on jsonResult so the LLM
                    // sees the structured error hint inline (detailResult would
                    // hide the actionable "verify YTB_MCP_SITE_URL" hint inside
                    // a sub-group). isError=true preserves SDK semantics.
                    return withSiteMeta(jsonResult({
                        ...checks,
                        hint:
                            'Plugin not reachable. Verify YTB_MCP_SITE_URL and that the ' +
                            'yt-builder-mcp plugin is active on this site.',
                    }, { isError: true }), site);
                }

                try {
                    await siteClient.get('/etag');
                    checks.bearer_valid = true;
                } catch (e) {
                    checks.bearer_valid = false;
                    if (e instanceof RestError) {
                        checks.bearer_error = `HTTP ${String(e.status)}: ${e.message}`;
                    } else {
                        checks.bearer_error = e instanceof Error ? e.message : String(e);
                    }
                    return withSiteMeta(jsonResult({
                        ...checks,
                        hint:
                            'Bearer key rejected. Regenerate the key in wp-admin → Tools → ' +
                            '"YT Builder MCP" → Bearer Keys (or Joomla Components → YT Builder MCP) ' +
                            'and re-run `yt-builder-mcp setup`.',
                    }, { isError: true }), site);
                }

                const toolkitResult = detailResult(buildDiagnoseDetail(checks));
                return withSiteMeta(structuredResult(toolkitResult, {
                    ...checks,
                    summary: 'OK — plugin reachable and Bearer key accepted.',
                }), site);
            },
        }),
    ];
}
