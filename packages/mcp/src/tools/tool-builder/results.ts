/**
 * `tool-builder/results` — envelope builders (`jsonResult`,
 * `errorResult`, `structuredResult`, `confirmGuard`) + the shared
 * `sanitizeContentItem` helper. Every payload accepted here runs
 * through the `sanitizeSecrets` / `sanitizeForLogs` boundary so an
 * upstream regression can never land in the LLM context. The mask
 * is concentrated here — `define.ts` and call-sites do not re-mask.
 *
 * Split from `tool-builder.ts` in Round-1.5.
 *
 * @license MIT
 */

import { NetworkError, RestError } from '../../errors.js';
import { sanitizeForLogs } from '../../errors/mask.js';
import { sanitizeSecrets } from '../../errors/sanitize.js';
import type { ResolvedSite } from '../../sites/registry.js';
import type { ToolContent, ToolResult } from './types.js';

function stringify(value: unknown): string {
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}

/**
 * Convert any JSON-serializable value into the MCP content envelope
 * (2-space JSON). Wave G.6.4b: every payload is deep-walked through
 * `sanitizeSecrets` before stringification so an upstream regression
 * (REST handler surfacing `oauth_refresh_token`, `auth_data`, etc.)
 * can never land in the LLM context.
 */
export function jsonResult(payload: unknown, options: { isError?: boolean } = {}): ToolResult {
    const safe = sanitizeSecrets(payload);
    return {
        content: [{ type: 'text', text: stringify(safe) }],
        ...(options.isError === true ? { isError: true } : {}),
    };
}

/** Extract `(message, status?, code?)` from any thrown value, honouring
 * `RestError` / `NetworkError` shape distinctions. */
function describeError(
    e: unknown,
): { message: string; status?: number; code?: string } {
    if (e instanceof RestError) return { message: e.message, status: e.status, code: e.code };
    if (e instanceof NetworkError) return { message: e.message };
    if (e instanceof Error) return { message: e.message };
    return { message: stringify(e) };
}

/**
 * Render a structured error result with `context` (echoed input) and a
 * recovery `hint`. Wave G.6.3 defense-in-depth: message is
 * mask-and-truncate'd; context is deep-walked so an input param that
 * accidentally echoed a secret-bearing value cannot leak.
 */
export function errorResult(opts: {
    error: unknown;
    context: Record<string, unknown>;
    hint: string;
}): ToolResult {
    const { message, status, code } = describeError(opts.error);
    return jsonResult(
        {
            error: sanitizeForLogs(message),
            ...(status !== undefined ? { status } : {}),
            ...(code !== undefined ? { code } : {}),
            context: sanitizeSecrets(opts.context),
            hint: opts.hint,
        },
        { isError: true },
    );
}

/**
 * Confirm-guard preview helper for destructive tools. Returns the
 * preview `ToolResult` when `confirm` is false; callers `return` it
 * before executing the destructive REST call.
 */
export function confirmGuard(opts: {
    operation: string;
    details: Record<string, unknown>;
}): ToolResult {
    return jsonResult({
        preview: true,
        warning: 'DESTRUCTIVE — Confirmation required',
        operation: opts.operation,
        details: opts.details,
        instruction: 'Ask the user to confirm, then call again with `confirm: true`.',
    });
}

/**
 * Sanitise a single content item from a toolkit builder. Text items
 * frequently embed a JSON-stringified payload — if it parses, deep-walk
 * and re-stringify; otherwise leave as-is (toolkit-rendered tables /
 * details are already domain-mapped at the call site).
 */
function sanitizeContentItem(item: ToolContent): ToolContent {
    if (item.type !== 'text' || typeof item.text !== 'string') return item;
    const text = item.text;
    try {
        const parsed: unknown = JSON.parse(text);
        const safe = sanitizeSecrets(parsed);
        return { ...item, text: stringify(safe) };
    } catch {
        // Not JSON — toolkit-rendered text. No structural redaction
        // possible without false-positives; the caller has already
        // mapped raw secrets out via field-projection helpers.
        return item;
    }
}

/**
 * Merge a toolkit `StructuredCallToolResult` (carries `content[0].text`
 * + `_meta.ui`) with a domain-typed `structuredContent` payload. The
 * result satisfies both Rich-Card hosts (read `_meta.ui`) AND
 * structured-output hosts (read `structuredContent`, may validate
 * against `outputSchema`). Single envelope-merger from every migrated
 * tool's handler — toolkit builders are wrapped, not modified.
 */
/**
 * W6.2 — site-awareness envelope wrapper.
 *
 * Stamps a resolved-site descriptor onto a tool result so the human +
 * agent both know which connected site the call ran against. Two
 * channels:
 *
 *   1. **text-prefix PRIMARY surface** — `[<label or site_id> @
 *      <hostname>] ` is injected at the start of `content[0].text`
 *      when that block is a text item. Per the W6 web-research
 *      addendum (HCFV §B, Claude-Desktop UI gap doc'd in plan
 *      Z.1329-1332), `_meta` is read by the agent but NOT rendered as
 *      a UI widget today — so the prefix is the only thing Maria the
 *      Claude-Desktop-user reliably SEES. LOAD-BEARING.
 *
 *   2. **result-level `_meta` SECONDARY surface** — `_meta:
 *      {site_id, site_url, platform}` is merged into the RESULT-level
 *      `_meta` (alongside the toolkit's `_meta.ui`). This is the
 *      MCP-spec metadata channel: free-form and NOT validated against
 *      the tool's `outputSchema`.
 *
 *      W12-R2 fix: site meta was previously merged into
 *      `structuredContent._meta`. That broke real hosts. Every tool's
 *      `outputSchema` is generated with `additionalProperties:false`,
 *      so an undeclared `_meta` key inside `structuredContent` fails
 *      client-side schema validation (`-32602`) and surfaces as a
 *      "Failed to call tool" toast in Claude Desktop EVEN ON A
 *      SUCCESSFUL CALL. `live-verify.mjs` never caught it because raw
 *      JSON-RPC calls skip the host's structuredContent↔outputSchema
 *      validation. `structuredContent` must stay schema-pure.
 *
 * Pure function: never mutates the input result. Non-text blocks
 * (resource / image / etc.) pass through verbatim. `structuredContent`
 * is passed through UNTOUCHED so it always validates against
 * `outputSchema`. Empty `content[]` is tolerated and produces a
 * prefix-free result (the meta still lands in result-level `_meta`).
 *
 * Label-fallback: `site.label` wins; falls back to `site.id`.
 *
 * Hostname extraction defaults to `new URL(site.url).host`. A
 * malformed URL falls back to the raw site.url string so the prefix
 * remains user-readable even when the registry was hand-edited.
 */
export function withSiteMeta<R extends ToolResult>(
    result: R,
    site: ResolvedSite,
): R {
    const displayLabel = site.label ?? site.id;
    let host: string;
    try {
        host = new URL(site.url).host;
    } catch {
        host = site.url;
    }
    const prefix = `[${displayLabel} @ ${host}] `;

    const meta = {
        site_id: site.id,
        site_url: site.url,
        platform: site.platform.kind,
    };

    // W12-R1.2 (A4-F2): site-awareness is the LOAD-BEARING surface for
    // Claude-Desktop users. When the wrapped result has no text content
    // (e.g. an image-only / resource-only / empty-list response), the
    // prefix would otherwise be lost and the agent would have no visible
    // signal of which site the call ran against. Prepend a synthetic
    // text block so the prefix is ALWAYS the first visible glyph.
    // Trim the trailing space — a standalone prefix block reads better
    // without it.
    const firstItem = result.content[0];
    const needsSynthetic =
        result.content.length === 0
        || firstItem === undefined
        || firstItem.type !== 'text';

    const newContent: ToolContent[] = needsSynthetic
        ? [
              { type: 'text', text: prefix.trimEnd() },
              ...result.content,
          ]
        : result.content.map((item, idx): ToolContent => {
              if (idx !== 0) return item;
              if (item.type !== 'text') return item;
              const existingText = typeof item.text === 'string' ? item.text : '';
              return { ...item, text: prefix + existingText };
          });

    // Site meta merges into the RESULT-level `_meta` (next to the
    // toolkit's `_meta.ui`), NEVER into `structuredContent`. The latter
    // is validated by the host against the tool's `outputSchema`
    // (additionalProperties:false); an undeclared key there triggers a
    // `-32602` "Failed to call tool" reject even on success. The result
    // envelope `_meta` is the spec's free-form metadata channel.
    const baseTopMeta = result._meta;
    const mergedTopMeta =
        baseTopMeta !== undefined && baseTopMeta !== null && typeof baseTopMeta === 'object'
            ? { ...baseTopMeta, ...meta }
            : meta;

    return {
        ...result,
        content: newContent,
        _meta: mergedTopMeta,
    };
}

export function structuredResult(
    toolkitResult: {
        content: ToolContent[];
        _meta?: { ui?: unknown } & Record<string, unknown>;
        isError?: boolean;
    },
    structuredContent: Record<string, unknown>,
): ToolResult {
    // Wave G.6.4b — deep-walk-sanitise all three legs (text content,
    // _meta.ui, structuredContent) so regressing sources never bypass
    // the LLM-boundary mask.
    const safeContent = toolkitResult.content.map(sanitizeContentItem);
    const safeStructured = sanitizeSecrets(structuredContent) as Record<string, unknown>;
    const safeMeta =
        toolkitResult._meta !== undefined
            ? (sanitizeSecrets(toolkitResult._meta) as { ui?: unknown } & Record<string, unknown>)
            : undefined;
    return {
        content: safeContent,
        ...(safeMeta !== undefined ? { _meta: safeMeta } : {}),
        ...(toolkitResult.isError === true ? { isError: true } : {}),
        structuredContent: safeStructured,
    };
}
