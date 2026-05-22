/**
 * Tests that `errorResult` (in tool-builder.ts) routes the surfaced
 * error.message + the wrapped body through the sanitisers — Wave G.6.3.
 *
 * @license MIT
 */

import { describe, expect, test } from 'vitest';

import { NetworkError, RestError } from '../../src/errors.js';
import { errorResult, jsonResult } from '../../src/tools/tool-builder.js';

function extractPayload(result: { content: Array<{ text?: string }> }): Record<string, unknown> {
    const text = result.content[0]?.text ?? '';
    return JSON.parse(text) as Record<string, unknown>;
}

describe('errorResult — wiring with sanitizers', () => {
    test('masks Bearer tokens that leak into the error message', () => {
        const err = new Error('Auth failed: Bearer ytb_live_secret123abc rejected');
        const out = errorResult({
            error: err,
            context: {},
            hint: 'check key',
        });
        const payload = extractPayload(out);
        expect(payload.error as string).not.toMatch(/ytb_live_secret123abc/);
        expect(payload.error as string).toMatch(/\*\*\*masked\*\*\*/);
    });

    test('masks Bearer tokens in RestError messages', () => {
        const err = new RestError({
            status: 401,
            message: 'rejected key Bearer abc_leaked_token',
            body: null,
        });
        const out = errorResult({
            error: err,
            context: { template_id: 't1' },
            hint: 'h',
        });
        const payload = extractPayload(out);
        expect(payload.error as string).not.toMatch(/abc_leaked_token/);
        expect(payload.error as string).toMatch(/\*\*\*masked\*\*\*/);
        expect(payload.status).toBe(401);
    });

    test('redacts secret keys nested in the response body wrapped in the error', () => {
        // When a RestError carries a `body` object (e.g. WP_Error JSON)
        // that contains secrets — those must be redacted before the body
        // is surfaced anywhere the LLM could see it.
        const err = new RestError({
            status: 500,
            message: 'server',
            body: {
                code: 'internal',
                data: { auth_data: 'should-be-gone', other: 'ok' },
            },
        });
        // We expect the RestError instance to expose a sanitised view of
        // its body OR the errorResult to sanitise any body that ends up
        // in the payload. (errorResult currently doesn't echo body — but
        // if anyone refactors to do so, the sanitiser must already be
        // applied at construction.)
        const body = err.body as { data: { auth_data: string } };
        // RestError now sanitises body at construction time.
        expect(body.data.auth_data).toBe('[REDACTED]');
        expect(body.data.other).toBe('ok');

        // Smoke: errorResult still works with the sanitised body.
        const out = errorResult({ error: err, context: {}, hint: 'h' });
        expect(extractPayload(out).status).toBe(500);
    });

    test('truncates extremely long error messages', () => {
        const giant = 'X'.repeat(5000);
        const err = new Error(giant);
        const out = errorResult({ error: err, context: {}, hint: 'h' });
        const payload = extractPayload(out);
        expect((payload.error as string).length).toBeLessThan(5000);
        expect(payload.error as string).toMatch(/\.\.\. \[truncated\]$/);
    });

    test('R1.5: NetworkError surfaces .message without status/code', () => {
        const err = new NetworkError({
            url: 'https://example.com/health',
            cause: new Error('ECONNREFUSED'),
        });
        const out = errorResult({ error: err, context: {}, hint: 'h' });
        const payload = extractPayload(out);
        expect(payload.error).toContain('ECONNREFUSED');
        expect(payload.status).toBeUndefined();
        expect(payload.code).toBeUndefined();
    });

    test('R1.5: non-Error throw value falls back to stringified message', () => {
        // a thrown primitive (string / number / object) — should serialize
        // via the JSON-stringify fallback in describeError → stringify().
        const out = errorResult({ error: { weird: 'thing', n: 42 }, context: {}, hint: 'h' });
        const payload = extractPayload(out);
        expect(payload.error as string).toContain('weird');
    });
});

describe('R1.5: stringify fallback for non-JSON-serializable values', () => {
    test('errorResult: thrown BigInt falls back to String(value) via stringify', () => {
        // BigInt cannot be JSON.stringify'd → triggers the catch in
        // stringify() which falls back to String(value). errorResult
        // routes non-Error values through stringify() in describeError().
        const out = errorResult({ error: 123n, context: {}, hint: 'h' });
        const payload = JSON.parse(out.content[0]!.text as string) as Record<string, unknown>;
        expect(payload.error).toBe('123');
    });

    test('jsonResult: well-formed envelope for a normal payload', () => {
        const out = jsonResult({ ok: true });
        expect(out.content[0]?.type).toBe('text');
        expect(typeof out.content[0]?.text).toBe('string');
    });
});
