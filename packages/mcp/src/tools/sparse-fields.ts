/**
 * Sparse-fields helper — projects per-item field whitelists onto tool
 * responses and exposes per-tool default-sets that the AI client can rely
 * on when calling read tools without a `fields` parameter.
 *
 * Design references:
 *
 *   - §3.5 Sparse-Fields Adaption: 4 read tools opt-in to `fields[]`;
 *     the toolkit's `pickFields` is used directly; `projected_fields` is
 *     echoed in the result envelope so the LLM can confirm exactly which
 *     keys were kept.
 *   - §4.4.1 — when used together with `flat: true` projection runs
 *     AFTER the depth-first walk so paths are stable before narrowing.
 *
 * Reference implementation pattern: `connections.ts:721, 779-792, 810`
 * in `@wootsup/apimapper-mcp`.
 *
 * The defaults are pure constants so they can be imported, exported, and
 * asserted against in tests without any side-effects.
 *
 * @license MIT
 */

import { pickFields } from '@getimo/mcp-toolkit';
import { z } from 'zod';

// ─── Schema ──────────────────────────────────────────────────────────

/**
 * Optional whitelist of fields. Per Design §3.5 the cap is 40 to stop
 * runaway prompts from blowing up the request size; nested paths
 * (`props.title`) are supported by the toolkit's `pickFields`.
 */
export const FIELDS = z
    .array(z.string().min(1))
    .max(40)
    .optional()
    .describe(
        'Optional whitelist of fields to keep per item (supports nested paths like ' +
            '"props.title"). Default: tool-specific compact set (echoed in projected_fields). ' +
            'Cuts response size — e.g. fields:["path","element_type","label"] for a minimal ' +
            'navigation view.',
    );

/**
 * Flag enabling client-side flattening on `page_get_layout`. When true
 * the response shape switches from `{layout, etag}` to
 * `{elements: [...], etag}` and `fields[]` projection becomes meaningful.
 */
export const FLAT = z
    .boolean()
    .default(false)
    .describe(
        'When true, response is a flat [{path, element_type, ...}] array. Enables ' +
            'fields[] projection. Default: false (nested layout, no projection).',
    );

// ─── Default field-sets per tool ─────────────────────────────────────

/**
 * Default fields echoed when the caller omits `fields`. Following the
 * apimapper-mcp convention these are **echo-only** — projection itself
 * is skipped (default-all preserved) but `projected_fields` still
 * surfaces the implicit compact set so the AI can plan future requests.
 */
export const DEFAULT_FIELDS_PAGES_LIST: readonly string[] = [
    'id',
    'label',
    'type',
    'elements_count',
    'modified_at',
];

export const DEFAULT_FIELDS_ELEMENT_LIST: readonly string[] = [
    'path',
    'element_type',
    'label',
];

export const DEFAULT_FIELDS_SCHEMA: readonly string[] = [
    'path',
    'element_type',
    'label',
];

export const DEFAULT_FIELDS_SOURCES_LIST: readonly string[] = [
    'name',
    'label',
    'origin',
    'kind',
];

export const DEFAULT_FIELDS_TYPES_LIST: readonly string[] = [
    'name',
    'label',
    'origin',
    'has_children_support',
];

/**
 * element_get defaults to "all" because the AI often needs the full
 * props payload for follow-up edits; sparse projection here is opt-in
 * with nested-path support.
 */
export const DEFAULT_FIELDS_ELEMENT_GET: readonly string[] | undefined = undefined;

// ─── Pure projection helpers ─────────────────────────────────────────

/**
 * Project an array of items to the requested fields. Returns items
 * unchanged when `fields` is undefined (default-all). Skips non-object
 * entries without crashing (they pass through as-is).
 *
 * @param items - The items to project.
 * @param fields - The whitelist; `undefined` skips projection.
 * @param _defaults - Per-tool default set, accepted for interface symmetry
 *   with `projectedFieldsEcho` (unused for actual projection — default-all
 *   semantics matches the apimapper-mcp reference, see `connections.ts:789`).
 */
export function projectFields<T extends Record<string, unknown>>(
    items: readonly T[],
    fields: readonly string[] | undefined,
    _defaults: readonly string[],
): T[] {
    if (fields === undefined) {
        return [...items];
    }
    const mutable = [...fields];
    return items.map((item) => {
        if (item === null || typeof item !== 'object' || Array.isArray(item)) {
            return item;
        }
        return pickFields(item, mutable) as T;
    });
}

/**
 * Project a single object via the same `pickFields` rules. Returns the
 * object unchanged when `fields` is undefined.
 */
export function projectFieldsSingle<T extends Record<string, unknown>>(
    obj: T,
    fields: readonly string[] | undefined,
    _defaults: readonly string[] | undefined,
): T {
    if (fields === undefined) {
        return obj;
    }
    if (obj === null || typeof obj !== 'object' || Array.isArray(obj)) {
        return obj;
    }
    return pickFields(obj, [...fields]) as T;
}

/**
 * Compute the `projected_fields` echo. Returns the caller-requested
 * fields when provided; otherwise returns the per-tool default set so
 * the AI client knows exactly which compact view it implicitly opted into.
 *
 * @returns `undefined` only when neither `requested` nor `defaults` was set
 *   (e.g. `element_get` with no opt-in) — callers should omit
 *   `projected_fields` from the structuredContent in that case.
 */
export function projectedFieldsEcho(
    requested: readonly string[] | undefined,
    defaults: readonly string[] | undefined,
): readonly string[] | undefined {
    if (requested !== undefined) return requested;
    if (defaults !== undefined) return defaults;
    return undefined;
}

/**
 * Per-item projection feedback for the LLM/agent.
 *
 * F-004/F-005 fix (2026-05-25 exhaustive audit): when the caller passes
 * `fields: ["template_id", "name"]` against `pages_list` (whose actual
 * item shape uses `id` + `label`), the previous projection returned
 * `items: [{}, {}, …]` with zero indication that NOTHING matched. The
 * silence cost an entire follow-up debug round in cold-agent flows. We
 * now surface the field vocabulary every call so the agent can self-
 * correct on the next request:
 *
 *  - `available_fields`: union of top-level keys observed across items
 *    (best-effort vocabulary discovery without needing per-tool enums).
 *  - `unknown_fields`: caller-requested fields that did NOT appear in
 *    any item — explicit "you asked for these but they aren't a thing".
 *
 * Both are only emitted when the caller supplied `fields[]`; default-
 * compact callers don't need the audit overhead.
 */
export interface ProjectionFeedback {
    /** Union of top-level keys observed across items. */
    readonly available_fields: readonly string[];
    /** Caller-requested fields that did NOT appear in any item. */
    readonly unknown_fields: readonly string[];
}

export function projectionFeedback(
    items: readonly Record<string, unknown>[],
    requested: readonly string[] | undefined,
): ProjectionFeedback | undefined {
    if (requested === undefined || requested.length === 0) return undefined;
    const seen = new Set<string>();
    for (const item of items) {
        if (item && typeof item === 'object' && !Array.isArray(item)) {
            for (const k of Object.keys(item)) seen.add(k);
        }
    }
    const available = [...seen].sort();
    // A leaf-path counts as "known" if its TOP level matches a key on any
    // item (the toolkit `pickFields` walks dotted paths — `props.title`
    // is reachable as long as `props` exists; per-item depth would be
    // overkill here).
    const unknown = requested.filter((f) => {
        const top = f.split('.')[0];
        return top !== undefined && !seen.has(top);
    });
    return { available_fields: available, unknown_fields: unknown };
}
