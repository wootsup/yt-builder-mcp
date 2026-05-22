/**
 * `yootheme_builder_advanced` gateway tool — Wave G.1 / Design-Doc §3.1.1.
 *
 * Single entry-point to every L2 (non-essential, non-direct) tool. Cursor
 * caps MCP servers at ~40 tools; routing 12 advanced tools through one
 * gateway keeps `tools/list` at 10 (7 L1 + 2 L3 + 1 gateway).
 *
 * Two modes:
 *   { tool }              → discovery: returns the target's description,
 *                           a JSON-schema derived from its Zod inputSchema,
 *                           and its annotation flags. The target handler
 *                           is NOT invoked.
 *   { tool, arguments }   → execution: `arguments` is validated against
 *                           the target's Zod inputSchema in `.strict()`
 *                           mode (unknown keys surface as Zod issues), then
 *                           the target handler is invoked with the parsed
 *                           args and the same `extra` object — so the
 *                           target tool's progress, elicitation and
 *                           AbortSignal keep working.
 *
 * Errors are structured ({ error, code, suggestion, details }). The gateway
 * cannot route to itself: `yootheme_builder_advanced` is never in the
 * advanced registry (the CapturingServer never sees its name — it's
 * registered after the CapturingServer hands off).
 *
 * Round-2 (R2-A2-CRIT2): split into 5 files under `advanced-tool/`
 * (this barrel + `domains.ts` + `discovery.ts` + `execute.ts` +
 * `register.ts`) to keep each file ≤ 100 LoC per Architecture §11.
 *
 * @license MIT
 */

// Re-exports — keep the public surface that `advanced-tool.ts` exposed
// before the split, so downstream importers keep working through the
// `./advanced-tool.js` backward-compat shim.
export {
    DOMAIN_ORDER,
    DOMAIN_PREFIX_MAP,
    TOOL_PREFIX,
    buildDescription,
    domainOf,
    groupByDomain,
    renderGroupedList,
} from './domains.js';
export { discoveryResult, inputSchemaJson, shapeOf } from './discovery.js';
export { errorResult, executeAdvancedTool } from './execute.js';
export { registerAdvancedTool } from './register.js';
