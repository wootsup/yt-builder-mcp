/**
 * Health format-sidecar — pure transforms for `yootheme_builder_health` and
 * `yootheme_builder_diagnose`. Wave G.2.11 (Design §3.2 rows 1 / 2).
 *
 * Stateless helpers; the handlers themselves are tested separately in
 * `tests/tools/health.test.ts` (probe orchestration + error paths).
 *
 * @license MIT
 */

import type { DetailGroup, StatsResultOptions } from '@getimo/mcp-toolkit';

// ─── Health detail (Design §3.2 row 1) ───────────────────────────────

export interface HealthPayload {
    plugin_version: string;
    yootheme_version: string | null;
    wp_version: string | null;
    php_version: string;
    storage_type: string;
    storage_target: string;
    yootheme_loaded: boolean;
    available_endpoints: string[];
}

/**
 * Build the detail body for `yootheme_builder_health`.
 *
 * Three groups:
 *   1. **Plugin** — plugin_version + yootheme_loaded + yootheme_version
 *   2. **Host**   — wp_version + php_version + storage info
 *   3. **Endpoints** — count + a comma-separated sample (no full enumeration)
 *
 * Endpoint count rather than a long list keeps the detail card compact
 * (real installs expose 16+ endpoints across pages/elements/sources/etc.).
 */
export function buildHealthDetail(payload: HealthPayload): {
    groups: DetailGroup[];
    title?: string;
} {
    const endpoints = payload.available_endpoints;
    const endpointPreview = summarizeEndpoints(endpoints);

    return {
        title: 'YT Builder MCP — Health',
        groups: [
            {
                label: 'Plugin',
                entries: [
                    {
                        key: 'plugin_version',
                        label: 'Plugin version',
                        value: payload.plugin_version,
                        format: 'badge',
                    },
                    {
                        key: 'yootheme_loaded',
                        label: 'YOOtheme loaded',
                        value: payload.yootheme_loaded,
                        format: 'boolean',
                    },
                    {
                        key: 'yootheme_version',
                        label: 'YOOtheme version',
                        value: payload.yootheme_version,
                    },
                ],
            },
            {
                label: 'Host',
                entries: [
                    { key: 'wp_version', label: 'WordPress version', value: payload.wp_version },
                    { key: 'php_version', label: 'PHP version', value: payload.php_version },
                    {
                        key: 'storage_type',
                        label: 'Storage type',
                        value: payload.storage_type,
                        format: 'badge',
                    },
                    {
                        key: 'storage_target',
                        label: 'Storage target',
                        value: payload.storage_target,
                        format: 'code',
                        copyable: true,
                    },
                ],
            },
            {
                label: 'Endpoints',
                entries: [
                    { key: 'count', label: 'Count', value: endpoints.length, format: 'text' },
                    { key: 'sample', label: 'Sample', value: endpointPreview },
                ],
            },
        ],
    };
}

function summarizeEndpoints(endpoints: readonly string[]): string {
    if (endpoints.length === 0) return '—';
    if (endpoints.length <= 6) return endpoints.join(', ');
    return `${endpoints.slice(0, 6).join(', ')}, …(+${String(endpoints.length - 6)} more)`;
}

/**
 * Build the flat stats payload for `yootheme_builder_health` per
 * Design-Doc §3.2 row 1 (Round-1 audit I1 fix). Returns the shape
 * required by `@getimo/mcp-toolkit`'s `statsResult({ stats: [...] })`:
 * a flat metric array (no nested groups) suitable for the stats-display
 * Rich-Card variant.
 *
 * Metrics shipped (per Design-Doc §3.2 row 1 spec):
 *   plugin_version, yootheme_version, wp_version, php_version,
 *   storage_type, yootheme_loaded, endpoint_count
 *
 * `available_endpoints` is condensed to `endpoint_count` here — the
 * full enumeration lives in the structuredContent payload for hosts
 * that want it.
 */
export function buildHealthStats(payload: HealthPayload): StatsResultOptions {
    return {
        title: 'YT Builder MCP — Health',
        stats: [
            {
                key: 'plugin_version',
                label: 'Plugin version',
                value: payload.plugin_version,
            },
            {
                key: 'yootheme_version',
                label: 'YOOtheme version',
                value: payload.yootheme_version ?? '—',
            },
            {
                key: 'wp_version',
                label: 'WordPress version',
                value: payload.wp_version ?? '—',
            },
            {
                key: 'php_version',
                label: 'PHP version',
                value: payload.php_version,
            },
            {
                key: 'storage_type',
                label: 'Storage type',
                value: payload.storage_type,
            },
            {
                key: 'yootheme_loaded',
                label: 'YOOtheme loaded',
                value: payload.yootheme_loaded,
            },
            {
                key: 'endpoint_count',
                label: 'Endpoint count',
                value: payload.available_endpoints.length,
                format: 'number',
            },
        ],
    };
}

// ─── Diagnose detail (Design §3.2 row 2) ─────────────────────────────

export interface DiagnoseChecks {
    plugin_reachable: boolean;
    plugin_version?: string;
    yootheme_loaded?: boolean;
    yootheme_version?: string | null;
    endpoint_count?: number;
    plugin_error?: string;
    bearer_valid?: boolean;
    bearer_error?: string;
}

/**
 * Build the detail body for `yootheme_builder_diagnose`.
 *
 * Always 2 groups: Plugin probe + Bearer probe. The Bearer group is still
 * present when the plugin is unreachable — its entries collapse to N/A so
 * the structure stays stable across success / partial-fail / full-fail.
 */
export function buildDiagnoseDetail(checks: DiagnoseChecks): {
    groups: DetailGroup[];
    title?: string;
} {
    const pluginEntries: DetailGroup['entries'] = [
        { key: 'plugin_reachable', label: 'Reachable', value: checks.plugin_reachable, format: 'boolean' },
    ];
    if (typeof checks.plugin_version === 'string')
        pluginEntries.push({ key: 'plugin_version', label: 'Plugin version', value: checks.plugin_version, format: 'badge' });
    if (typeof checks.yootheme_loaded === 'boolean')
        pluginEntries.push({ key: 'yootheme_loaded', label: 'YOOtheme loaded', value: checks.yootheme_loaded, format: 'boolean' });
    if (typeof checks.yootheme_version === 'string')
        pluginEntries.push({ key: 'yootheme_version', label: 'YOOtheme version', value: checks.yootheme_version });
    if (typeof checks.endpoint_count === 'number')
        pluginEntries.push({ key: 'endpoint_count', label: 'Endpoint count', value: checks.endpoint_count, format: 'text' });
    if (typeof checks.plugin_error === 'string')
        pluginEntries.push({ key: 'plugin_error', label: 'Plugin error', value: checks.plugin_error, format: 'code' });

    const bearerEntries: DetailGroup['entries'] =
        typeof checks.bearer_valid === 'boolean'
            ? [{ key: 'bearer_valid', label: 'Bearer valid', value: checks.bearer_valid, format: 'boolean' }]
            : [{ key: 'bearer_valid', label: 'Bearer valid', value: null }];
    if (typeof checks.bearer_error === 'string')
        bearerEntries.push({ key: 'bearer_error', label: 'Bearer error', value: checks.bearer_error, format: 'code' });

    return {
        title: 'YT Builder MCP — Diagnose',
        groups: [
            { label: 'Plugin probe', entries: pluginEntries },
            { label: 'Bearer probe', entries: bearerEntries },
        ],
    };
}
