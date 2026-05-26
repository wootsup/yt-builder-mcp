/**
 * Tool aggregator — collects all domain tools into a single list.
 * The MCP server iterates this list at startup to register handlers
 * with `@modelcontextprotocol/sdk`.
 *
 * @license MIT
 */

import type { ClientPool } from '../sites/client-pool.js';
import { buildSitesTools } from '../sites/tools/index.js';
import { buildElementsTools } from './elements/index.js';
import type { McpServerWithElicitation } from './elicitation.js';
import { buildHealthTools } from './health.js';
import { buildInspectionTools } from './inspection.js';
import { buildMultiItemsTools } from './multi-items/index.js';
import { buildPagesTools } from './pages.js';
import { buildSourcesTools } from './sources/index.js';
import type { AnyToolDefinition } from './tool-builder.js';

export interface BuildAllToolsOptions {
    /**
     * Optional MCP elicitation capability — see
     * `src/tools/elicitation.ts` for the adapter. When supplied,
     * destructive + ambiguity-resolution tools prompt the user via the
     * elicitation channel; when omitted, they fall back to the
     * preview-then-retry / `ambiguityFallbackError` flow.
     */
    readonly elicitation?: McpServerWithElicitation;
}

/**
 * W6 — pool-based registration entrypoint. Pre-W6 this took a bare
 * `RestClient`; W6 introduces the {@link ClientPool} as the single
 * source of REST clients (multi-site). Each domain builder now
 * resolves the pool per-handler via `resolveSiteOrError` and wraps
 * every result in `withSiteMeta` (Commit 3 of the W6 trio).
 */
export function buildAllTools(
    pool: ClientPool,
    options: BuildAllToolsOptions = {},
): readonly AnyToolDefinition[] {
    const deps = options.elicitation !== undefined ? { elicitation: options.elicitation } : {};
    return [
        ...buildHealthTools(pool),
        ...buildPagesTools(pool),
        ...buildElementsTools(pool, deps),
        ...buildSourcesTools(pool, deps),
        ...buildMultiItemsTools(pool),
        ...buildInspectionTools(pool),
        // W7 — Multi-Site registry tools (L1). The aggregator pulls
        // `pool.registry` internally (W12-R1.3); the sites_list handler
        // reads the registry without ever resolving a bearer or hitting
        // the network, while sites_test threads the pool through to
        // probe one specific site.
        ...buildSitesTools(pool),
    ];
}

export type { AnyToolDefinition, ToolDefinition } from './tool-builder.js';
