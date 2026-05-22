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
 * Audit-v3 / Stream D3 T3 (2026-05-22) — per Anthropic MCP spec
 * 2026-03-16 "Tool Annotations", every registered tool MUST advertise
 * the full 4-tuple `{readOnlyHint, destructiveHint, idempotentHint,
 * openWorldHint}`. Hosts treat `undefined` hints as "potentially
 * destructive" — defaulting `destructiveHint:false` and
 * `idempotentHint:true/false` explicitly removes that ambiguity for
 * read-only and additive tools.
 *
 * `openWorldHint:false` reflects the closed Builder domain: the REST
 * surface writes to a single local `wp_option('yootheme')` blob —
 * there is no external network side-effect or world-modifying action.
 * The gateway (`yootheme_builder_advanced`) is the exception (its
 * effective behaviour is dynamic, decided at call-time).
 *
 * @license MIT
 */

import type { ToolAnnotations } from './types.js';

/**
 * Build a `readOnly` annotation.
 *
 * Reads are idempotent by definition — repeating a read returns the
 * same data (modulo concurrent writes). Setting `idempotentHint:true`
 * is informative for hosts that surface a "safe-to-retry" badge.
 */
export function readOnly(title?: string): ToolAnnotations {
    return {
        title,
        readOnlyHint: true,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
    };
}

/**
 * Build a `mutating` annotation for idempotent updates (PUT-style
 * writes — full replacement semantics where repeating the call with
 * the same inputs yields the same end-state).
 */
export function mutating(title?: string): ToolAnnotations {
    return {
        title,
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: true,
        openWorldHint: false,
    };
}

/**
 * Build a `creating` annotation for non-idempotent creates (POST-style
 * writes — repeating the call produces additional elements / new IDs).
 */
export function creating(title?: string): ToolAnnotations {
    return {
        title,
        readOnlyHint: false,
        destructiveHint: false,
        idempotentHint: false,
        openWorldHint: false,
    };
}

/**
 * Build a `destructive` annotation. Callers MUST also expose a
 * `confirm` param + preview guard (see annotations-pin.test.ts).
 *
 * Destructive ops are inherently non-idempotent (the first call
 * removes data; the second call hits a 404 / already-gone state).
 */
export function destructive(title?: string): ToolAnnotations {
    return {
        title,
        readOnlyHint: false,
        destructiveHint: true,
        idempotentHint: false,
        openWorldHint: false,
    };
}
