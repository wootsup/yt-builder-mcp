/**
 * Pure helpers for the setup wizard — token decode + URL extraction.
 *
 * Split out of `setup-prompts.ts` so it can be unit-tested without a TTY
 * and so the `runWizard` body can decode a token without dragging in the
 * @clack/prompts UI surface.
 *
 * The decode is intentionally signature-UNVERIFIED. The wizard cannot
 * verify the HMAC signature (that requires the plugin's signing-secret
 * which only the server holds). We use the decoded payload purely to
 * pre-fill the URL prompt as a convenience — the canonical auth check
 * happens later when `runWizard` hits `/v1/health` with the token; if
 * the payload has been tampered with, that probe rejects it.
 *
 * @license MIT
 */

import type { DecodedTokenPayload } from './setup-wizard-types.js';

/** Plugin token format: ytb_(live|test)_<base64url-payload>.<base64url-sig> */
const TOKEN_FORMAT = /^ytb_(?:live|test)_([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/;

/**
 * Decode the base64url payload of a Bearer key.
 *
 * Returns `null` when the token shape is invalid OR the payload JSON is
 * malformed OR the required fields (kid + scope + iss) are missing or
 * the wrong type. Callers should treat `null` as "this token is unusable
 * for URL pre-fill; ask the user to type the URL by hand."
 */
export function decodeToken(token: string): DecodedTokenPayload | null {
    const trimmed = token.trim();
    const match = TOKEN_FORMAT.exec(trimmed);
    if (match === null) return null;

    const payloadB64 = match[1];
    if (typeof payloadB64 !== 'string' || payloadB64.length === 0) return null;

    let json: string;
    try {
        json = base64UrlDecode(payloadB64);
    } catch {
        return null;
    }

    let parsed: unknown;
    try {
        parsed = JSON.parse(json) as unknown;
    } catch {
        return null;
    }

    if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
        return null;
    }

    const obj = parsed as Record<string, unknown>;
    const kid = obj['kid'];
    const scope = obj['scope'];
    const iss = obj['iss'];

    if (typeof kid !== 'string' || kid === '') return null;
    if (typeof iss !== 'string' || iss === '') return null;

    let scopeList: readonly string[];
    if (Array.isArray(scope)) {
        scopeList = scope.filter((s): s is string => typeof s === 'string');
    } else if (typeof scope === 'string') {
        scopeList = [scope];
    } else {
        return null;
    }

    const exp = obj['exp'];
    const payload: DecodedTokenPayload = {
        kid,
        scope: scopeList,
        iss: iss.replace(/\/+$/, ''),
        ...(typeof exp === 'number' && Number.isFinite(exp) ? { exp } : {}),
    };
    return payload;
}

/**
 * Normalise a URL string the way the wizard wants it:
 *  - trim whitespace
 *  - strip trailing slashes
 *  - return empty string on null/undefined input
 *
 * Exposed so the prompt validator and the identity probe share one
 * canonicalisation rule (otherwise drift causes "looks the same to the
 * human, mismatches in code" bugs).
 */
export function normaliseUrl(input: string | null | undefined): string {
    if (typeof input !== 'string') return '';
    return input.trim().replace(/\/+$/, '');
}

/**
 * Standard base64url → utf-8 decoder. Pads + decodes via Buffer; throws
 * a generic Error when the input isn't valid base64url so callers can
 * `try/catch` and return null.
 */
function base64UrlDecode(input: string): string {
    const padded = input.replace(/-/g, '+').replace(/_/g, '/');
    const padding = padded.length % 4;
    const fullPadded = padding === 0 ? padded : padded + '='.repeat(4 - padding);
    const buf = Buffer.from(fullPadded, 'base64');
    return buf.toString('utf8');
}
