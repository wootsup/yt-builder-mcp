/**
 * Gateway-Hub — single source of truth for the YT Builder MCP
 * 3-lane registration surface.
 *
 * Lanes (Wave G.1 / Design-Doc §3.1.1):
 *   L1 — ESSENTIAL_TOOLS         forwarded by the CapturingServer as
 *                                 first-class MCP tools in `tools/list`.
 *   L2 — (advanced)              every NON-essential, NON-direct tool is
 *                                 captured by the CapturingServer and
 *                                 reachable through the single
 *                                 `yootheme_builder_advanced` gateway tool.
 *   L3 — DIRECT_TOP_LEVEL_TOOLS  registered directly on the real McpServer
 *                                 BEFORE the CapturingServer wraps it.
 *                                 The CapturingServer skips these names
 *                                 entirely (no forward, no capture) so the
 *                                 SDK never sees a duplicate registration.
 *
 * Why this split: Cursor caps MCP servers at ~40 tools. This adapter
 * declares 27 callable tools (19 domain L1/L3 + 7 advanced L2 + 1 gateway).
 * The 3-lane shape keeps `tools/list` at 17 (L1) + 2 (L3) + 1 (gateway) =
 * 20 entries, well under the cap, while every tool stays fully reachable
 * (advanced tools through `yootheme_builder_advanced`).
 *
 * Health + diagnose live in L3 because they MUST be the first thing an
 * LLM-host calls when something is broken; routing them through a gateway
 * would create a chicken-and-egg loop (the LLM would have to discover
 * the gateway before it can diagnose why the gateway is unreachable).
 *
 * @license MIT
 */

/**
 * L1 — Tools kept as first-class entries in `tools/list`.
 * Forwarded by the CapturingServer to the real McpServer.
 */
export const ESSENTIAL_TOOLS = [
    'yootheme_builder_pages_list',
    'yootheme_builder_get_etag',
    'yootheme_builder_element_list',
    'yootheme_builder_element_add',
    'yootheme_builder_element_update_settings',
    'yootheme_builder_sources_list',
    'yootheme_builder_element_types_list',
    // Multi-Items inspector — L1 discovery tool. Surfaces when a
    // binding lives on the wrong (container) level and which `*_item`
    // child should carry it. Frequent first-call when diagnosing
    // Grid/Slider/Switcher rendering issues.
    'yootheme_builder_inspect_multi_items_binding',
    // F-16 hot-path promotions (Maria-Audit v2 re-confirmed) — the 5
    // most-used read/mutate operations skip the advanced-router
    // discovery roundtrip. Cursor cap is ~40; with the W7 sites_list +
    // sites_test additions we sit at 17 L1 + 2 L3 + 1 gateway = 20,
    // comfortable below the limit.
    'yootheme_builder_element_get',
    'yootheme_builder_element_delete',
    'yootheme_builder_element_clone',
    'yootheme_builder_element_move',
    'yootheme_builder_page_get_layout',
    // T9 (Audit-v3 B.5) — token-efficient template overview. L1 because
    // it's the recommended first call when orienting in a large template.
    'yootheme_builder_template_summary',
    // 1.0.1 — element_type_get_schema promoted L2 → L1. Live-testing
    // 1.0.0 in Claude Desktop showed tool_search couldn't surface it
    // through the advanced gateway, yet it is the canonical prop-key
    // discovery tool an agent needs BEFORE every element_add or
    // element_update_settings. Hot-path for any write workflow.
    'yootheme_builder_element_type_get_schema',
    // W7 (Multi-Site) — sites_list + sites_test must be L1 so an agent
    // that just connected to a fresh MCP server can answer "which sites
    // am I configured against?" without first discovering the gateway.
    // Both are platform-agnostic (sites_list reads the registry only,
    // sites_test probes /health + /etag for ONE site). L1 surface 15 → 17.
    'yootheme_builder_sites_list',
    'yootheme_builder_sites_test',
] as const;

/**
 * L3 — Tools registered DIRECTLY on the real McpServer, BEFORE the
 * CapturingServer wraps it. The CapturingServer treats these names as a
 * no-op (no forward, no capture) so the SDK never sees a duplicate
 * registration. Use this lane only for tools that must be reachable
 * even if the gateway itself fails — currently health + diagnose.
 */
export const DIRECT_TOP_LEVEL_TOOLS = [
    'yootheme_builder_health',
    'yootheme_builder_diagnose',
] as const;

export type EssentialTool = (typeof ESSENTIAL_TOOLS)[number];
export type DirectTopLevelTool = (typeof DIRECT_TOP_LEVEL_TOOLS)[number];

/** True if `name` is in the L1 forwarded essentials surface. */
export function isEssential(name: string): boolean {
    return (ESSENTIAL_TOOLS as readonly string[]).includes(name);
}

/** True if `name` is in the L3 direct top-level surface. */
export function isDirectTopLevel(name: string): boolean {
    return (DIRECT_TOP_LEVEL_TOOLS as readonly string[]).includes(name);
}
