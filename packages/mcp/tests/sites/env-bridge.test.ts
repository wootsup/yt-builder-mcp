/**
 * W3 — Env-Bridge tests for sites/env-bridge.ts.
 *
 * Coverage:
 *  - synthesiseFromEnv: full path (both env-vars set) → one-site file.
 *  - synthesiseFromEnv: missing/empty either env-var → null.
 *  - synthesiseFromEnv: legacy YTB_MCP_WP_URL still honoured when the
 *    new YTB_MCP_SITE_URL is unset.
 *  - synthesiseFromEnv: YTB_MCP_PLATFORM hint honoured; defaults to
 *    'auto' when unset or empty; THROWS ConfigError for unknown values
 *    (W12-R1.1 fail-fast — silent downgrade hid typos like `woodpress`).
 *  - stripTrailingSlash: empty, single-slash, double-slash, no-slash.
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import { stripTrailingSlash, synthesiseFromEnv } from '../../src/sites/env-bridge.js';

const VALID_BEARER
    = 'ytb_live_eyJraWQiOiJ0LWtleSIsInNjb3BlIjoid3JpdGUifQ.abc123_xyz-def';

describe('stripTrailingSlash()', () => {
    it('empty string stays empty', () => {
        expect(stripTrailingSlash('')).toBe('');
    });

    it('removes a single trailing slash', () => {
        expect(stripTrailingSlash('https://example.com/')).toBe('https://example.com');
    });

    it('no-op when no trailing slash', () => {
        expect(stripTrailingSlash('https://example.com')).toBe('https://example.com');
    });

    it('only removes the LAST slash on double-slash input', () => {
        expect(stripTrailingSlash('https://example.com//')).toBe('https://example.com/');
    });

    it('lone "/" becomes empty', () => {
        expect(stripTrailingSlash('/')).toBe('');
    });
});

describe('synthesiseFromEnv() — happy paths', () => {
    it('builds a one-site registry with both required env-vars set', () => {
        const file = synthesiseFromEnv({
            YTB_MCP_SITE_URL: 'https://acme.example.com',
            YTB_MCP_BEARER_TOKEN: VALID_BEARER,
        });
        expect(file).not.toBeNull();
        if (!file) return; // narrow for TS
        expect(file.schema_version).toBe(1);
        expect(file.default_site_id).toBe('default');
        expect(file.sites).toHaveLength(1);
        const only = file.sites[0];
        expect(only?.site_id).toBe('default');
        expect(only?.url).toBe('https://acme.example.com');
        expect(only?.is_default).toBe(true);
        expect(only?.bearer).toBe(VALID_BEARER);
        expect(only?.label).toBe('Default site (env)');
        expect(only?.platform).toBe('auto');
    });

    it('strips a trailing slash from YTB_MCP_SITE_URL', () => {
        const file = synthesiseFromEnv({
            YTB_MCP_SITE_URL: 'https://acme.example.com/',
            YTB_MCP_BEARER_TOKEN: VALID_BEARER,
        });
        expect(file?.sites[0]?.url).toBe('https://acme.example.com');
    });
});

describe('synthesiseFromEnv() — early returns', () => {
    it('returns null when URL is missing', () => {
        expect(
            synthesiseFromEnv({ YTB_MCP_BEARER_TOKEN: VALID_BEARER }),
        ).toBeNull();
    });

    it('returns null when bearer is missing', () => {
        expect(
            synthesiseFromEnv({ YTB_MCP_SITE_URL: 'https://acme.example.com' }),
        ).toBeNull();
    });

    it('returns null when both env-vars are missing', () => {
        expect(synthesiseFromEnv({})).toBeNull();
    });

    it('returns null on empty-string URL', () => {
        expect(
            synthesiseFromEnv({
                YTB_MCP_SITE_URL: '',
                YTB_MCP_BEARER_TOKEN: VALID_BEARER,
            }),
        ).toBeNull();
    });

    it('returns null on empty-string bearer', () => {
        expect(
            synthesiseFromEnv({
                YTB_MCP_SITE_URL: 'https://acme.example.com',
                YTB_MCP_BEARER_TOKEN: '',
            }),
        ).toBeNull();
    });
});

describe('synthesiseFromEnv() — legacy + platform-hint handling', () => {
    it('honours legacy YTB_MCP_WP_URL when YTB_MCP_SITE_URL is unset', () => {
        const file = synthesiseFromEnv({
            YTB_MCP_WP_URL: 'https://legacy.example.com',
            YTB_MCP_BEARER_TOKEN: VALID_BEARER,
        });
        expect(file?.sites[0]?.url).toBe('https://legacy.example.com');
    });

    it('prefers YTB_MCP_SITE_URL over YTB_MCP_WP_URL when both are set', () => {
        const file = synthesiseFromEnv({
            YTB_MCP_SITE_URL: 'https://new.example.com',
            YTB_MCP_WP_URL: 'https://legacy.example.com',
            YTB_MCP_BEARER_TOKEN: VALID_BEARER,
        });
        expect(file?.sites[0]?.url).toBe('https://new.example.com');
    });

    it('YTB_MCP_PLATFORM=joomla sets platform to joomla', () => {
        const file = synthesiseFromEnv({
            YTB_MCP_SITE_URL: 'https://j.example.com',
            YTB_MCP_BEARER_TOKEN: VALID_BEARER,
            YTB_MCP_PLATFORM: 'joomla',
        });
        expect(file?.sites[0]?.platform).toBe('joomla');
    });

    it('YTB_MCP_PLATFORM=wordpress sets platform to wordpress', () => {
        const file = synthesiseFromEnv({
            YTB_MCP_SITE_URL: 'https://w.example.com',
            YTB_MCP_BEARER_TOKEN: VALID_BEARER,
            YTB_MCP_PLATFORM: 'wordpress',
        });
        expect(file?.sites[0]?.platform).toBe('wordpress');
    });

    it('YTB_MCP_PLATFORM unset defaults to auto', () => {
        const file = synthesiseFromEnv({
            YTB_MCP_SITE_URL: 'https://a.example.com',
            YTB_MCP_BEARER_TOKEN: VALID_BEARER,
        });
        expect(file?.sites[0]?.platform).toBe('auto');
    });

    it('YTB_MCP_PLATFORM with garbage value throws ConfigError', () => {
        expect(() =>
            synthesiseFromEnv({
                YTB_MCP_SITE_URL: 'https://a.example.com',
                YTB_MCP_BEARER_TOKEN: VALID_BEARER,
                YTB_MCP_PLATFORM: 'mySpecialCMS',
            }),
        ).toThrow(/Invalid YTB_MCP_PLATFORM.*mySpecialCMS/);
    });

    it('YTB_MCP_PLATFORM with whitespace-only value falls back to auto', () => {
        const file = synthesiseFromEnv({
            YTB_MCP_SITE_URL: 'https://a.example.com',
            YTB_MCP_BEARER_TOKEN: VALID_BEARER,
            YTB_MCP_PLATFORM: '   ',
        });
        expect(file?.sites[0]?.platform).toBe('auto');
    });

    it('whitespace-only YTB_MCP_SITE_URL is treated as missing', () => {
        expect(
            synthesiseFromEnv({
                YTB_MCP_SITE_URL: '   ',
                YTB_MCP_BEARER_TOKEN: VALID_BEARER,
            }),
        ).toBeNull();
    });

    it('whitespace-only YTB_MCP_BEARER_TOKEN is treated as missing', () => {
        expect(
            synthesiseFromEnv({
                YTB_MCP_SITE_URL: 'https://a.example.com',
                YTB_MCP_BEARER_TOKEN: '\t\n ',
            }),
        ).toBeNull();
    });
});
