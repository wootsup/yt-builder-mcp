/**
 * Tests for `classify(status)` + `hintFor(code)` — Wave G.6.1.
 *
 * The agent receives one of nine canonical codes; each code yields a
 * focused, recovery-oriented hint string the LLM can act on.
 *
 * @license MIT
 */

import { describe, expect, test } from 'vitest';

import { classify, hintFor, type YtbErrorCode } from '../../src/errors/hints.js';

describe('classify(status)', () => {
    test('401 → auth_invalid', () => {
        expect(classify(401)).toBe('auth_invalid');
    });

    test('403 → forbidden', () => {
        expect(classify(403)).toBe('forbidden');
    });

    test('404 → not_found', () => {
        expect(classify(404)).toBe('not_found');
    });

    test('409 → conflict_etag', () => {
        expect(classify(409)).toBe('conflict_etag');
    });

    test('412 → conflict_etag (precondition failed)', () => {
        expect(classify(412)).toBe('conflict_etag');
    });

    test('422 → validation_error', () => {
        expect(classify(422)).toBe('validation_error');
    });

    test('429 → rate_limit', () => {
        expect(classify(429)).toBe('rate_limit');
    });

    test('500 → server_error', () => {
        expect(classify(500)).toBe('server_error');
    });

    test('503 → server_error', () => {
        expect(classify(503)).toBe('server_error');
    });

    test('0 (no response) → network', () => {
        expect(classify(0)).toBe('network');
    });

    test('200/201 fall back → server_error (caller shouldn\'t pass these)', () => {
        // Defensive: classify is documented for non-2xx but should still
        // return a sensible default rather than throw.
        expect(classify(200)).toBe('server_error');
    });

    test('missing-auth-header (synthetic) → auth_missing via dedicated path', () => {
        // 401 without WWW-Authenticate header is "auth_missing" — exposed via
        // an explicit overload; we model it as a separate code so the hint
        // can suggest setting YTB_MCP_BEARER_TOKEN.
        expect(classify(401, { hasWwwAuthenticate: false })).toBe('auth_missing');
        expect(classify(401, { hasWwwAuthenticate: true })).toBe('auth_invalid');
    });
});

describe('hintFor(code)', () => {
    const allCodes: YtbErrorCode[] = [
        'auth_invalid',
        'auth_missing',
        'forbidden',
        'not_found',
        'conflict_etag',
        'validation_error',
        'rate_limit',
        'server_error',
        'network',
    ];

    test.each(allCodes)('returns a non-empty hint for %s', (code) => {
        const hint = hintFor(code);
        expect(typeof hint).toBe('string');
        expect(hint.length).toBeGreaterThan(20);
    });

    test('rate_limit hint mentions WAF/Cloudflare', () => {
        expect(hintFor('rate_limit').toLowerCase()).toMatch(/waf|cloudflare/);
    });

    test('conflict_etag hint mentions get_etag', () => {
        expect(hintFor('conflict_etag')).toMatch(/get_etag|etag/i);
    });

    test('auth_invalid hint mentions Settings page', () => {
        expect(hintFor('auth_invalid').toLowerCase()).toMatch(/settings|regenerate|bearer/);
    });

    test('auth_missing hint mentions YTB_MCP_BEARER_TOKEN env var', () => {
        expect(hintFor('auth_missing')).toMatch(/YTB_MCP_BEARER_TOKEN/);
    });

    test('not_found hint references list/discovery tool', () => {
        expect(hintFor('not_found').toLowerCase()).toMatch(/list|discover/);
    });

    test('validation_error hint references inputSchema/schema', () => {
        expect(hintFor('validation_error').toLowerCase()).toMatch(/schema|input|validation/);
    });

    test('network hint references YTB_MCP_WP_URL or reachability', () => {
        expect(hintFor('network')).toMatch(/YTB_MCP_WP_URL|reach/);
    });

    test('server_error hint suggests retry + log check', () => {
        expect(hintFor('server_error').toLowerCase()).toMatch(/retry|log/);
    });

    test('forbidden hint mentions scope', () => {
        expect(hintFor('forbidden').toLowerCase()).toMatch(/scope|permission/);
    });
});
