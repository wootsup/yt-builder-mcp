/**
 * `tool-builder/index` — barrel re-export for the split
 * tool-definition system.
 *
 * Round-1.5: structural split of the former monolithic
 * `tool-builder.ts` (320 LoC). Cohesion is preserved by topic:
 *
 *   - `types.ts`        — pure type declarations (no runtime imports
 *                         beyond type-only `zod` + SDK notifications)
 *   - `annotations.ts`  — `readOnly` / `mutating` / `creating` /
 *                         `destructive` annotation builders
 *   - `results.ts`      — `jsonResult` / `errorResult` /
 *                         `structuredResult` / `confirmGuard` envelope
 *                         builders + the shared `sanitizeContentItem`
 *                         helper (single LLM-boundary mask)
 *   - `define.ts`       — `defineTool` identity helper + progress-
 *                         reporter re-export
 *
 * The parent file `../tool-builder.ts` re-exports this barrel so
 * existing `./tool-builder.js` imports keep working without churn.
 *
 * @license MIT
 */

export type {
    AnyToolDefinition,
    HandlerExtra,
    ToolAnnotations,
    ToolContent,
    ToolDefinition,
    ToolHandler,
    ToolResult,
} from './types.js';
export {
    creating,
    destructive,
    mutating,
    readOnly,
} from './annotations.js';
export {
    confirmGuard,
    errorResult,
    jsonResult,
    structuredResult,
    withSiteMeta,
} from './results.js';
export { createProgressReporter, defineTool } from './define.js';
