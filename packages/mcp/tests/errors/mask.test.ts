/**
 * Tests for `maskBearerToken` + `sanitizeForLogs` — Wave G.6.2.
 *
 * Defense-in-depth: every error string crossing the MCP boundary is
 * routed through `sanitizeForLogs` so a leaked Bearer / long stack-trace
 * never reaches the LLM context.
 *
 * @license MIT
 */

import { describe, expect, test } from 'vitest';

import { maskBearerToken, sanitizeForLogs } from '../../src/errors/mask.js';

describe('maskBearerToken', () => {
    test('masks a standard Authorization header', () => {
        const input = 'Authorization: Bearer ytb_live_abc123xyz';
        expect(maskBearerToken(input)).toBe('Authorization: Bearer ***masked***');
    });

    test('masks multiple occurrences in one string', () => {
        const input =
            'tried Bearer aaaaaaaaaa then Bearer bbbbbbbbbb — both 401';
        const out = maskBearerToken(input);
        expect(out).not.toMatch(/aaaaaaaaaa/);
        expect(out).not.toMatch(/bbbbbbbbbb/);
        expect(out.match(/\*\*\*masked\*\*\*/g)?.length).toBe(2);
    });

    test('leaves unrelated text unchanged', () => {
        expect(maskBearerToken('hello world')).toBe('hello world');
    });

    test('handles empty string', () => {
        expect(maskBearerToken('')).toBe('');
    });

    test('masks short tokens too (defensive — even 8 chars)', () => {
        const input = 'sent Bearer ytb_test';
        const out = maskBearerToken(input);
        expect(out).not.toMatch(/ytb_test/);
        expect(out).toMatch(/\*\*\*masked\*\*\*/);
    });

    test('over-masks "Bearer <word>" pairs anywhere in the string (defensive)', () => {
        // We accept the rare false-positive ("Bearer Bonds are great") rather
        // than risk leaking a real token by being too clever with the regex.
        // The word after `Bearer ` is always replaced with `***masked***`.
        expect(maskBearerToken('Bearer Bonds are great')).toBe(
            'Bearer ***masked*** are great',
        );
    });
});

describe('sanitizeForLogs', () => {
    test('applies maskBearerToken', () => {
        const out = sanitizeForLogs('failed: Bearer secret_xyz_long');
        expect(out).not.toMatch(/secret_xyz_long/);
        expect(out).toMatch(/\*\*\*masked\*\*\*/);
    });

    test('truncates strings beyond the default cap', () => {
        const big = 'x'.repeat(3000);
        const out = sanitizeForLogs(big);
        expect(out.length).toBeLessThan(3000);
        expect(out).toMatch(/\.\.\. \[truncated\]$/);
    });

    test('does NOT truncate strings under the cap', () => {
        const small = 'short message';
        expect(sanitizeForLogs(small)).toBe('short message');
    });

    test('handles empty input', () => {
        expect(sanitizeForLogs('')).toBe('');
    });

    test('chained Bearer + truncation works', () => {
        const input = 'Bearer leaked_token_value ' + 'x'.repeat(3000);
        const out = sanitizeForLogs(input);
        expect(out).not.toMatch(/leaked_token_value/);
        expect(out).toMatch(/\.\.\. \[truncated\]$/);
    });

    test('preserves non-string inputs as their String() repr', () => {
        // Defensive: handler-paths sometimes pass numbers / undefined.
        expect(sanitizeForLogs(undefined as unknown as string)).toBe('');
        expect(sanitizeForLogs(null as unknown as string)).toBe('');
    });
});
