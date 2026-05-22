/**
 * Tests for `sanitizeSecrets` deep-walk (error-path) — Wave G.6.4.
 *
 * Mirrors the apimapper-mcp credential-sanitizer pattern: a recursive
 * walker that swaps any value at a known-secret key for `[REDACTED]`,
 * regardless of nesting depth.
 *
 * @license MIT
 */

import { describe, expect, test } from 'vitest';

import { sanitizeSecrets } from '../../src/errors/sanitize.js';

describe('sanitizeSecrets — error-path deep walk', () => {
    test('redacts top-level "token" key', () => {
        const input = { token: 'abc123', name: 'x' };
        expect(sanitizeSecrets(input)).toEqual({
            token: '[REDACTED]',
            name: 'x',
        });
    });

    test('redacts nested secret keys', () => {
        const input = {
            request: {
                headers: {
                    authorization: 'Bearer xyz',
                    auth_data: 'should-go-away',
                },
            },
            response: { status: 401 },
        };
        const out = sanitizeSecrets(input) as Record<string, unknown>;
        const req = out.request as Record<string, unknown>;
        const headers = req.headers as Record<string, unknown>;
        expect(headers.auth_data).toBe('[REDACTED]');
        // `authorization` is NOT in SECRET_KEYS — it's not a typical JSON-body
        // key. Header-string masking is handled by maskBearerToken.
        expect(headers.authorization).toBe('Bearer xyz');
        expect((out.response as Record<string, unknown>).status).toBe(401);
    });

    test('walks into arrays', () => {
        const input = [
            { id: 1, secret: 's1' },
            { id: 2, secret: 's2' },
        ];
        expect(sanitizeSecrets(input)).toEqual([
            { id: 1, secret: '[REDACTED]' },
            { id: 2, secret: '[REDACTED]' },
        ]);
    });

    test('handles mixed nested arrays + objects', () => {
        const input = {
            sources: [
                {
                    name: 'pexels',
                    credential: { oauth_refresh_token: 'rt_abc', expires_at: 123 },
                },
            ],
        };
        const out = sanitizeSecrets(input) as { sources: Array<Record<string, unknown>> };
        const cred = out.sources[0].credential as Record<string, unknown>;
        expect(cred.oauth_refresh_token).toBe('[REDACTED]');
        expect(cred.expires_at).toBe(123);
    });

    test('leaves primitives untouched', () => {
        expect(sanitizeSecrets(42)).toBe(42);
        expect(sanitizeSecrets('hello')).toBe('hello');
        expect(sanitizeSecrets(null)).toBe(null);
        expect(sanitizeSecrets(true)).toBe(true);
    });

    test('redacts every documented secret key shape', () => {
        const allKeys = {
            token: 'a',
            bearer: 'b',
            bearer_token: 'c',
            bearerToken: 'd',
            auth_data: 'e',
            authData: 'f',
            oauth_refresh_token: 'g',
            oauthRefreshToken: 'h',
            refresh_token: 'i',
            refreshToken: 'j',
            access_token: 'k',
            accessToken: 'l',
            client_secret: 'm',
            clientSecret: 'n',
            api_key: 'o',
            apiKey: 'p',
            password: 'q',
            secret: 'r',
        };
        const out = sanitizeSecrets(allKeys) as Record<string, string>;
        for (const k of Object.keys(allKeys)) {
            expect(out[k]).toBe('[REDACTED]');
        }
    });

    test('does not mutate the input object (returns fresh copy)', () => {
        const input = { token: 'abc', nested: { secret: 'xyz' } };
        const out = sanitizeSecrets(input) as Record<string, unknown>;
        // Original object must remain unchanged.
        expect(input.token).toBe('abc');
        expect(input.nested.secret).toBe('xyz');
        // Output is the redacted copy.
        expect(out.token).toBe('[REDACTED]');
        expect((out.nested as Record<string, unknown>).secret).toBe('[REDACTED]');
    });
});
