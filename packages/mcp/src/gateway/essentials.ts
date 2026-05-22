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
 * declares 22 tools (21 domain + 1 gateway). The 3-lane shape keeps
 * `tools/list` at 7 (L1) + 2 (L3) + 1 (gateway) = 10 entries, well under
 * the cap, while every tool stays fully reachable.
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
