/**
 * `tool-builder/define` — the `defineTool` identity helper plus the
 * progress-reporter re-export.
 *
 * `defineTool` is intentionally trivial at runtime (returns its
 * argument verbatim) — its only job is to preserve the inferred Zod
 * schema shape so each tool's handler is fully type-checked against
 * its inputSchema. The wrapping/sanitising work happens in
 * `results.ts`.
 *
 * Split from `tool-builder.ts` in Round-1.5 — see `index.ts` for
 * the cohesion rationale.
 *
 * @license MIT
 */

import { createProgressReporter as toolkitCreateProgressReporter } from '@getimo/mcp-toolkit';
import type { ZodRawShape } from 'zod';
import type { ToolDefinition } from './types.js';

/**
 * Re-export the toolkit's `createProgressReporter` under a stable
 * local symbol so every write-handler in this package depends on a
 * single import path. Returns `null` when the caller sent no
 * `progressToken` — handlers then no-op via `progress?.report(...)`.
 */
export const createProgressReporter = toolkitCreateProgressReporter;

/**
 * `defineTool` — preserves the inferred Zod-schema shape so each
 * tool's handler is fully type-checked against its inputSchema.
 */
export function defineTool<TSchema extends ZodRawShape>(
    def: ToolDefinition<TSchema>,
): ToolDefinition<TSchema> {
    return def;
}
