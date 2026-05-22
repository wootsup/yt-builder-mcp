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
import type { RestClient } from '../client.js';
import { RestError } from '../errors.js';
import {
    buildDiagnoseDetail,
    buildHealthStats,
    type DiagnoseChecks,
    type HealthPayload,
} from './format/health-format.js';
import {
    defineTool,
    errorResult,
    jsonResult,
    readOnly,
    structuredResult,
    type AnyToolDefinition,
} from './tool-builder.js';

// ─── outputSchemas (Wave G.2 §4) ─────────────────────────────────────

const HEALTH_OUTPUT_SCHEMA = z.object({
    plugin_version: z.string(),
    yootheme_version: z.string().nullable(),
    wp_version: z.string().nullable(),
    php_version: z.string(),
    storage_type: z.string(),
    storage_target: z.string(),
    yootheme_loaded: z.boolean(),
    available_endpoints: z.array(z.string()),
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
});

export function buildHealthTools(client: RestClient): readonly AnyToolDefinition[] {
    return [
        defineTool({
            name: 'yootheme_builder_health',
            description:
                'Check that the YOOtheme Builder MCP plugin is installed and reachable. ' +
                'Returns plugin version, YOOtheme Pro version (if loaded), and the list of ' +
                'available REST endpoints. Unauthenticated probe — call this first when ' +
                'troubleshooting connectivity.',
            inputSchema: {},
            outputSchema: HEALTH_OUTPUT_SCHEMA,
            annotations: readOnly('Health Check'),
            handler: async () => {
                try {
                    const data = await client.get<HealthPayload>('/health');
                    // Round-1 audit I1 fix: spec §3.2 row 1 calls for
                    // `statsResult` (flat metrics). Earlier shipped
                    // `detailResult` — that's a Detail-Card variant, not
                    // the stats-display the spec asked for. The flat
                    // shape is built by `buildHealthStats`.
                    const toolkitResult = statsResult(buildHealthStats(data));
                    return structuredResult(toolkitResult, {
                        plugin_version: data.plugin_version,
                        yootheme_version: data.yootheme_version,
                        wp_version: data.wp_version,
                        php_version: data.php_version,
                        storage_type: data.storage_type,
                        storage_target: data.storage_target,
                        yootheme_loaded: data.yootheme_loaded,
                        available_endpoints: data.available_endpoints,
                    });
                } catch (e) {
                    return errorResult({
                        error: e,
                        context: {},
                        hint:
                            'Verify YTB_MCP_WP_URL points at a WordPress site with the ' +
                            'yt-builder-mcp plugin active. Try opening ' +
                            '<wp_url>/wp-json/yt-builder-mcp/v1/health in a browser.',
                    });
                }
            },
        }),

        defineTool({
            name: 'yootheme_builder_diagnose',
            description:
                'Run a full diagnostic: hit /health (no auth), then attempt an authenticated ' +
                'call (/etag) to confirm the Bearer key is valid. Use when health passes but ' +
                'tools return 401/403.',
            inputSchema: {},
            outputSchema: DIAGNOSE_OUTPUT_SCHEMA,
            annotations: readOnly('Diagnose Connection'),
            handler: async () => {
                const checks: DiagnoseChecks = { plugin_reachable: false };

                try {
                    const health = await client.get<HealthPayload>('/health');
                    checks.plugin_reachable = true;
                    checks.plugin_version = health.plugin_version;
                    checks.yootheme_loaded = health.yootheme_loaded;
                    checks.yootheme_version = health.yootheme_version;
                    checks.endpoint_count = health.available_endpoints.length;
                } catch (e) {
                    checks.plugin_reachable = false;
                    checks.plugin_error = e instanceof Error ? e.message : String(e);
                    // Plugin-unreachable path stays on jsonResult so the LLM
                    // sees the structured error hint inline (detailResult would
                    // hide the actionable "verify YTB_MCP_WP_URL" hint inside
                    // a sub-group). isError=true preserves SDK semantics.
                    return jsonResult({
                        ...checks,
                        hint:
                            'Plugin not reachable. Verify YTB_MCP_WP_URL and that the ' +
                            'yt-builder-mcp WordPress plugin is active.',
                    }, { isError: true });
                }

                try {
                    await client.get('/etag');
                    checks.bearer_valid = true;
                } catch (e) {
                    checks.bearer_valid = false;
                    if (e instanceof RestError) {
                        checks.bearer_error = `HTTP ${String(e.status)}: ${e.message}`;
                    } else {
                        checks.bearer_error = e instanceof Error ? e.message : String(e);
                    }
                    return jsonResult({
                        ...checks,
                        hint:
                            'Bearer key rejected. Regenerate the key in wp-admin → ' +
                            '"YOOtheme Builder MCP" → Settings and re-run ' +
                            '`yt-builder-mcp setup`.',
                    }, { isError: true });
                }

                const toolkitResult = detailResult(buildDiagnoseDetail(checks));
                return structuredResult(toolkitResult, {
                    ...checks,
                    summary: 'OK — plugin reachable and Bearer key accepted.',
                });
            },
        }),
    ];
}
