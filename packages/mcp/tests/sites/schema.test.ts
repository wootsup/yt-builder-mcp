/**
 * W1 — schema tests for sites/schema.ts.
 *
 * Coverage: reject (unknown platform, both bearer+bearer_ref, neither,
 * two is_default:true, malformed op-ref, malformed bearer, malformed
 * site_id, oversize label) and accept (minimal+full sites).
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    BEARER_REGEX,
    OP_REF_REGEX,
    SITE_ID_REGEX,
    SiteEntry,
    SitesFile,
} from '../../src/sites/schema.js';

const VALID_BEARER =
    'ytb_live_eyJraWQiOiJ0LWtleSIsInNjb3BlIjoid3JpdGUifQ.abc123_xyz-def';
const VALID_OP_REF = 'op://Claude-Secrets/some-item/password';

const baseSite = {
    site_id: 'wp-acme',
    url: 'https://acme.com',
    platform: 'wordpress' as const,
    bearer: VALID_BEARER,
};

describe('SiteEntry — accept', () => {
    it('accepts a minimal site with inline bearer', () => {
        const out = SiteEntry.safeParse(baseSite);
        expect(out.success).toBe(true);
    });

    it('accepts a site with bearer_ref (no bearer)', () => {
        const out = SiteEntry.safeParse({
            site_id: 'wp-prod',
            url: 'https://prod.example.com',
            platform: 'wordpress',
            bearer_ref: VALID_OP_REF,
        });
        expect(out.success).toBe(true);
    });

    it('accepts the full surface — label, added_at, is_default', () => {
        const out = SiteEntry.safeParse({
            ...baseSite,
            is_default: true,
            label: 'Acme — Production',
            added_at: '2026-05-25T12:00:00.000Z',
        });
        expect(out.success).toBe(true);
        if (out.success) {
            expect(out.data.label).toBe('Acme — Production');
            expect(out.data.is_default).toBe(true);
        }
    });

    it('defaults platform to "auto" when omitted', () => {
        const { platform: _drop, ...withoutPlatform } = baseSite;
        const out = SiteEntry.safeParse(withoutPlatform);
        expect(out.success).toBe(true);
        if (out.success) expect(out.data.platform).toBe('auto');
    });

    it('defaults is_default to false', () => {
        const out = SiteEntry.safeParse(baseSite);
        expect(out.success).toBe(true);
        if (out.success) expect(out.data.is_default).toBe(false);
    });
});

describe('SiteEntry — reject', () => {
    it('rejects an unknown platform value', () => {
        const out = SiteEntry.safeParse({ ...baseSite, platform: 'drupal' });
        expect(out.success).toBe(false);
    });

    it('rejects when BOTH bearer and bearer_ref are set', () => {
        const out = SiteEntry.safeParse({
            ...baseSite,
            bearer_ref: VALID_OP_REF,
        });
        expect(out.success).toBe(false);
        if (!out.success) {
            expect(out.error.issues.some((i) =>
                i.message.includes('Exactly one of bearer or bearer_ref'),
            )).toBe(true);
        }
    });

    it('rejects when NEITHER bearer nor bearer_ref is set', () => {
        const { bearer: _drop, ...noBearer } = baseSite;
        const out = SiteEntry.safeParse(noBearer);
        expect(out.success).toBe(false);
        if (!out.success) {
            expect(out.error.issues.some((i) =>
                i.message.includes('Exactly one of bearer or bearer_ref'),
            )).toBe(true);
        }
    });

    it('rejects a malformed op:// reference (no scheme)', () => {
        const out = SiteEntry.safeParse({
            site_id: 'x',
            url: 'https://x.test',
            platform: 'wordpress',
            bearer_ref: 'not-an-op-ref',
        });
        expect(out.success).toBe(false);
    });

    it('rejects an op:// ref containing shell metacharacters', () => {
        const out = SiteEntry.safeParse({
            site_id: 'x',
            url: 'https://x.test',
            platform: 'wordpress',
            bearer_ref: 'op://vault/item/$(whoami)',
        });
        expect(out.success).toBe(false);
    });

    it('rejects a malformed bearer (wrong prefix)', () => {
        const out = SiteEntry.safeParse({
            ...baseSite,
            bearer: 'Bearer-prefix-payload.sig',
        });
        expect(out.success).toBe(false);
    });

    it('rejects a malformed bearer (missing dot-separator)', () => {
        const out = SiteEntry.safeParse({ ...baseSite, bearer: 'ytb_live_payloadonly' });
        expect(out.success).toBe(false);
    });

    it('rejects an invalid site_id (contains space)', () => {
        const out = SiteEntry.safeParse({ ...baseSite, site_id: 'wp acme' });
        expect(out.success).toBe(false);
    });

    it('rejects an empty site_id', () => {
        const out = SiteEntry.safeParse({ ...baseSite, site_id: '' });
        expect(out.success).toBe(false);
    });

    it('rejects a label longer than 120 chars', () => {
        const out = SiteEntry.safeParse({ ...baseSite, label: 'x'.repeat(121) });
        expect(out.success).toBe(false);
    });

    it('rejects a non-URL value in the url field', () => {
        const out = SiteEntry.safeParse({ ...baseSite, url: 'not://valid url' });
        expect(out.success).toBe(false);
    });
});

describe('SitesFile — accept', () => {
    it('accepts an empty sites list', () => {
        const out = SitesFile.safeParse({
            schema_version: 1, default_site_id: null, sites: [],
        });
        expect(out.success).toBe(true);
    });

    it('accepts a single-site file with default set', () => {
        const out = SitesFile.safeParse({
            schema_version: 1,
            default_site_id: 'wp-acme',
            sites: [{ ...baseSite, is_default: true }],
        });
        expect(out.success).toBe(true);
    });

    it('accepts a multi-site file with exactly one default', () => {
        const out = SitesFile.safeParse({
            schema_version: 1,
            default_site_id: 'wp-acme',
            sites: [
                { ...baseSite, is_default: true },
                { ...baseSite, site_id: 'joomla-beta', url: 'https://beta.test/joomla' },
            ],
        });
        expect(out.success).toBe(true);
    });
});

describe('SitesFile — reject', () => {
    it('rejects schema_version other than 1', () => {
        const out = SitesFile.safeParse({
            schema_version: 2, default_site_id: null, sites: [],
        });
        expect(out.success).toBe(false);
    });

    it('rejects two sites with is_default:true', () => {
        const out = SitesFile.safeParse({
            schema_version: 1,
            default_site_id: 'wp-acme',
            sites: [
                { ...baseSite, is_default: true },
                { ...baseSite, site_id: 'wp-other', is_default: true },
            ],
        });
        expect(out.success).toBe(false);
        if (!out.success) {
            expect(out.error.issues.some((i) =>
                i.message.includes('At most one site can have is_default'),
            )).toBe(true);
        }
    });

    it('rejects a default_site_id that violates SITE_ID_REGEX', () => {
        const out = SitesFile.safeParse({
            schema_version: 1,
            default_site_id: 'invalid site id',
            sites: [],
        });
        expect(out.success).toBe(false);
    });
});

describe('regexes', () => {
    it('SITE_ID_REGEX accepts letters/digits/dash/underscore', () => {
        expect(SITE_ID_REGEX.test('wp-acme_1')).toBe(true);
        expect(SITE_ID_REGEX.test('A0_-')).toBe(true);
    });

    it('SITE_ID_REGEX rejects dot, slash, space', () => {
        expect(SITE_ID_REGEX.test('wp.acme')).toBe(false);
        expect(SITE_ID_REGEX.test('wp/acme')).toBe(false);
        expect(SITE_ID_REGEX.test('wp acme')).toBe(false);
    });

    it('OP_REF_REGEX accepts canonical op:// shape', () => {
        expect(OP_REF_REGEX.test('op://vault/item/field')).toBe(true);
        expect(OP_REF_REGEX.test('op://Vault.with-dot/item_1/credential')).toBe(true);
    });

    it('OP_REF_REGEX rejects shell-injection payloads', () => {
        expect(OP_REF_REGEX.test('op://vault/item/$(id)')).toBe(false);
        expect(OP_REF_REGEX.test('op://vault/item/`id`')).toBe(false);
        expect(OP_REF_REGEX.test('op://vault/item/field; rm -rf /')).toBe(false);
    });

    it('BEARER_REGEX accepts live and test prefixes', () => {
        expect(BEARER_REGEX.test(VALID_BEARER)).toBe(true);
        expect(BEARER_REGEX.test('ytb_test_payload.sig')).toBe(true);
    });

    it('BEARER_REGEX rejects unknown prefix', () => {
        expect(BEARER_REGEX.test('ytb_prod_payload.sig')).toBe(false);
    });
});
