/**
 * Secret-masking helpers — Wave G.6.2.
 *
 * `maskBearerToken` rewrites any `Bearer <token>` substring to
 * `Bearer ***masked***`. `sanitizeForLogs` additionally truncates very
 * long strings so a paste of a multi-MB stack trace can't blow the
 * context budget.
 *
 * Defense-in-depth: this is the single choke-point for any string
 * (error message, response body excerpt, log line) that crosses the
 * MCP boundary into the LLM's view.
 *
 * @license MIT
 */

/** Maximum length for a sanitised log string. Anything longer is truncated. */
export const LOG_TRUNCATION_LIMIT = 2000;

/**
 * Replace every `Bearer <opaque-token>` substring with `Bearer ***masked***`.
 *
 * Token-shape is intentionally permissive — we'd rather over-mask than
 * leak. Any non-whitespace run following the literal `Bearer ` is treated
 * as the token and replaced.
 */
export function maskBearerToken(input: string): string {
    if (input === '') return '';
    // The `g` flag handles repeated occurrences in one string.
    return input.replace(/Bearer\s+\S+/g, 'Bearer ***masked***');
}

/**
 * Sanitise a string before it joins the LLM-visible output:
 *   1. mask any Bearer tokens
 *   2. truncate to `LOG_TRUNCATION_LIMIT` chars with a clear suffix
 *
 * Non-string inputs (null / undefined) collapse to an empty string —
 * caller code typically passes `error?.message` and `?? ''` is a common
 * upstream pattern.
 */
export function sanitizeForLogs(input: string): string {
    if (input === null || input === undefined) return '';
    if (input === '') return '';
    const masked = maskBearerToken(input);
    if (masked.length <= LOG_TRUNCATION_LIMIT) return masked;
    return masked.slice(0, LOG_TRUNCATION_LIMIT) + '... [truncated]';
}
