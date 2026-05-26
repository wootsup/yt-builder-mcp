/**
 * `tool-builder/types` — pure type declarations for the tool-definition
 * system. Split from `tool-builder.ts` in Round-1.5 (replaces the
 * Round-1 LoC-exception spec-amendment with a structural code-fix).
 *
 * @license MIT
 */

import type { ServerNotification } from '@modelcontextprotocol/sdk/types.js';
import type { ZodRawShape, z } from 'zod';

/**
 * A content item the SDK accepts. Mirrors the SDK
 * `CallToolResult.content[number]` union (text/image/resource). Tools
 * in this server only emit text, but the toolkit's structured builders
 * return the wider union, so the envelope-merger must accept it.
 */
export type ToolContent = {
    type: string;
    [key: string]: unknown;
};

/**
 * MCP-compatible content envelope. Fields:
 *  - `content`            — text blocks shown to the LLM.
 *  - `isError`            — flag honoured by the SDK for error results.
 *  - `_meta.ui`           — Rich-Card hints (toolkit structured-display
 *                            protocol: data-table, detail, stats, error).
 *  - `structuredContent`  — domain-typed payload validated against the
 *                            tool's optional `outputSchema`.
 */
export interface ToolResult {
    content: ToolContent[];
    isError?: boolean;
    _meta?: { ui?: unknown } & Record<string, unknown>;
    structuredContent?: Record<string, unknown>;
}

export interface ToolAnnotations {
    readonly title?: string;
    /** Tool does not mutate state. */
    readonly readOnlyHint?: boolean;
    /** Tool may delete or otherwise destroy state. */
    readonly destructiveHint?: boolean;
    /** Tool talks to a system outside the host process. */
    readonly openWorldHint?: boolean;
    /** Identical invocations have identical effects (PUT-style). */
    readonly idempotentHint?: boolean;
}

/**
 * Minimal MCP-handler `extra` surface used by Wave G.5 progress reports.
 * Only `_meta.progressToken` and `sendNotification` are consumed; the
 * toolkit's `createProgressReporter` is defined against this same
 * minimal shape so we forward verbatim with no cast at the call site.
 */
export interface HandlerExtra {
    readonly _meta?: { progressToken?: string | number };
    readonly sendNotification: (notification: ServerNotification) => Promise<void>;
}

export type ToolHandler<TSchema extends ZodRawShape> = (
    args: z.infer<z.ZodObject<TSchema>>,
    extra?: HandlerExtra,
) => Promise<ToolResult>;

export interface ToolDefinition<TSchema extends ZodRawShape = ZodRawShape> {
    readonly name: string;
    readonly description: string;
    readonly inputSchema: TSchema;
    /**
     * Optional Zod schema describing the `structuredContent` payload.
     * Hosts that support MCP structured output validate responses
     * against this schema. Set on migrated tools using
     * `structuredResult` / `tableResult` / `detailResult` /
     * `statsResult`; tools on `jsonResult` may omit it.
     */
    readonly outputSchema?: z.ZodTypeAny;
    readonly annotations: ToolAnnotations;
    readonly handler: ToolHandler<TSchema>;
    /**
     * F-203 follow-up (Audit 2026-05-26 reviewer Gap 2): optional refined
     * `ZodObject` representation of the input shape. When present,
     * `registerToolOn` forwards THIS to the SDK in place of the raw
     * `inputSchema` shape — so a top-level `.refine()` / `.superRefine()`
     * is honoured by the SDK's pre-handler validation and a violation
     * surfaces as a JSON-RPC `-32602 InvalidParams` envelope BEFORE the
     * handler runs.
     *
     * The handler signature is still derived from `inputSchema: TSchema`
     * (the raw shape) so the per-handler arg type stays inferred and
     * stable. The refined object MUST be built from the SAME shape — any
     * drift will compile-fail on the handler's argument typing.
     */
    readonly inputObjectSchema?: z.ZodTypeAny;
}

/**
 * Type-erased tool definition used by the aggregator and server. Tools
 * carry their own runtime-typed handler internally — at the registry
 * boundary we only need the metadata + an opaque handler.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any -- registry boundary; each tool validates its own input via zod
export type AnyToolDefinition = ToolDefinition<any>;
