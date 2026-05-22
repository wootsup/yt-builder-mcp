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
