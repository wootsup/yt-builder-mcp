/**
 * Tests for `setup-token.ts` (decodeToken + normaliseUrl).
 *
 * The decode is signature-unverified by design — these tests pin the
 * accept/reject decisions and the iss-normalisation behaviour.
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import { decodeToken, normaliseUrl } from '../../src/setup-token.js';

/**
 * Build a token of the form `ytb_live_<base64url-payload>.<base64url-sig>`.
 * Signature value is a placeholder — decodeToken does not verify it.
 */
function makeToken(payload: Record<string, unknown>): string {
    const json = JSON.stringify(payload);
    const b64 = Buffer.from(json, 'utf8').toString('base64');
    const b64url = b64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    return `ytb_live_${b64url}.placeholder-sig`;
}

describe('decodeToken — happy path', () => {
    it('returns kid + scope (array) + iss for a minimal payload', () => {
        const tok = makeToken({ kid: 'k1', scope: ['read'], iss: 'https://example.com' });
        const out = decodeToken(tok);
        expect(out).not.toBeNull();
        expect(out?.kid).toBe('k1');
        expect(out?.scope).toEqual(['read']);
        expect(out?.iss).toBe('https://example.com');
        expect(out?.exp).toBeUndefined();
    });

    it('accepts scope as a single string and coerces to one-element array', () => {
        const tok = makeToken({ kid: 'k1', scope: 'write', iss: 'https://example.com' });
        expect(decodeToken(tok)?.scope).toEqual(['write']);
    });

    it('strips trailing slashes from iss', () => {
        const tok = makeToken({ kid: 'k1', scope: ['read'], iss: 'https://example.com///' });
        expect(decodeToken(tok)?.iss).toBe('https://example.com');
    });

    it('includes exp when present and numeric', () => {
        const tok = makeToken({ kid: 'k1', scope: ['read'], iss: 'https://example.com', exp: 9999999999 });
        expect(decodeToken(tok)?.exp).toBe(9999999999);
    });

    it('accepts ytb_test_ prefix', () => {
        const payload = { kid: 'k1', scope: ['read'], iss: 'https://example.com' };
        const json = JSON.stringify(payload);
        const b64url = Buffer.from(json, 'utf8').toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        const tok = `ytb_test_${b64url}.sig`;
        expect(decodeToken(tok)?.iss).toBe('https://example.com');
    });

    it('trims surrounding whitespace before parsing', () => {
        const tok = makeToken({ kid: 'k1', scope: ['read'], iss: 'https://example.com' });
        expect(decodeToken(`  ${tok}\n`)?.kid).toBe('k1');
    });
});

describe('decodeToken — reject branches', () => {
    it('returns null for an empty string', () => {
        expect(decodeToken('')).toBeNull();
    });

    it('returns null for a wrong prefix', () => {
        expect(decodeToken('amk_live_eyJrIjoxfQ.sig')).toBeNull();
    });

    it('returns null when payload section is missing', () => {
        expect(decodeToken('ytb_live_.sig')).toBeNull();
    });

    it('returns null when sig section is missing', () => {
        expect(decodeToken('ytb_live_payload')).toBeNull();
    });

    it('returns null when payload is not base64url-decodable JSON', () => {
        expect(decodeToken('ytb_live_!!!.sig')).toBeNull();
    });

    it('returns null when payload JSON is an array (not an object)', () => {
        const b64 = Buffer.from('[1,2,3]', 'utf8').toString('base64').replace(/=+$/, '');
        expect(decodeToken(`ytb_live_${b64}.sig`)).toBeNull();
    });

    it('returns null when kid is missing', () => {
        const tok = makeToken({ scope: ['read'], iss: 'https://example.com' });
        expect(decodeToken(tok)).toBeNull();
    });

    it('returns null when iss is missing', () => {
        const tok = makeToken({ kid: 'k1', scope: ['read'] });
        expect(decodeToken(tok)).toBeNull();
    });

    it('returns null when scope is neither string nor array', () => {
        const tok = makeToken({ kid: 'k1', scope: 42, iss: 'https://example.com' });
        expect(decodeToken(tok)).toBeNull();
    });

    it('returns null when iss is empty string', () => {
        const tok = makeToken({ kid: 'k1', scope: ['read'], iss: '' });
        expect(decodeToken(tok)).toBeNull();
    });

    it('returns null when kid is empty string', () => {
        const tok = makeToken({ kid: '', scope: ['read'], iss: 'https://example.com' });
        expect(decodeToken(tok)).toBeNull();
    });
});

describe('normaliseUrl', () => {
    it('returns "" for null / undefined / non-string', () => {
        expect(normaliseUrl(null)).toBe('');
        expect(normaliseUrl(undefined)).toBe('');
    });

    it('trims whitespace and strips trailing slashes', () => {
        expect(normaliseUrl('  https://example.com//  ')).toBe('https://example.com');
    });

    it('leaves a normalised URL unchanged', () => {
        expect(normaliseUrl('https://example.com')).toBe('https://example.com');
    });

    it('returns empty string for "/" input', () => {
        expect(normaliseUrl('/')).toBe('');
    });
});
