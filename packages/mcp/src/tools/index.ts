/**
 * Tool aggregator — collects all domain tools into a single list.
 * The MCP server iterates this list at startup to register handlers
 * with `@modelcontextprotocol/sdk`.
 *
 * @license MIT
 */

import type { RestClient } from '../client.js';
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

export function buildAllTools(
    client: RestClient,
    options: BuildAllToolsOptions = {},
): readonly AnyToolDefinition[] {
    const deps = options.elicitation !== undefined ? { elicitation: options.elicitation } : {};
    return [
        ...buildHealthTools(client),
        ...buildPagesTools(client),
        ...buildElementsTools(client, deps),
        ...buildSourcesTools(client, deps),
        ...buildMultiItemsTools(client),
        ...buildInspectionTools(client),
    ];
}

export type { AnyToolDefinition, ToolDefinition } from './tool-builder.js';
