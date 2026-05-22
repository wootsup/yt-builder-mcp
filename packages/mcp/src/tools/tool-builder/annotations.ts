/**
 * `tool-builder/annotations` — annotation builders that produce the
 * MCP `ToolAnnotations` shape. Each helper encodes a verified
 * read/write/destructive policy so call-sites in domain tools (pages,
 * elements, sources, …) get a single one-liner instead of repeating
 * the four-flag pattern verbatim.
 *
 * Split from `tool-builder.ts` in Round-1.5 — see `index.ts` for
 * the cohesion rationale.
 *
 * @license MIT
 */

import type { ToolAnnotations } from './types.js';

/** Build a `readOnly` annotation. */
export function readOnly(title?: string): ToolAnnotations {
    return { title, readOnlyHint: true, openWorldHint: true };
}

/** Build a `mutating` annotation for idempotent updates (PUT). */
export function mutating(title?: string): ToolAnnotations {
    return { title, readOnlyHint: false, openWorldHint: true, idempotentHint: true };
}

/** Build a `creating` annotation for non-idempotent creates (POST). */
export function creating(title?: string): ToolAnnotations {
    return { title, readOnlyHint: false, openWorldHint: true, idempotentHint: false };
}

/** Build a `destructive` annotation. Callers MUST also expose a `confirm` param. */
export function destructive(title?: string): ToolAnnotations {
    return {
        title,
        readOnlyHint: false,
        destructiveHint: true,
        openWorldHint: true,
        idempotentHint: false,
    };
}
