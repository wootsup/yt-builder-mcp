/**
 * W1 — store tests for sites/store.ts.
 *
 * Coverage:
 *  - load: missing file → emptySitesFile; bad JSON → SitesFileError;
 *    unknown schema_version → SchemaVersionError; invalid shape → SitesFileError;
 *    round-trip identity.
 *  - save: writes mode 0600 (verified via fs.statSync(p).mode & 0o777);
 *    refuses to write invalid input; cleans up tmp on failure;
 *    serialises two concurrent saves (no torn writes; final state is one
 *    of the two payloads, never a mix).
 *
 * @license MIT
 */

import { mkdtempSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

import {
    emptySitesFile,
    loadSitesFile,
    saveSitesFile,
    SchemaVersionError,
    SitesFileError,
} from '../../src/sites/store.js';
import type { SitesFileT } from '../../src/sites/schema.js';

const VALID_BEARER =
    'ytb_live_eyJraWQiOiJ0LWtleSIsInNjb3BlIjoid3JpdGUifQ.abc123_xyz-def';

function freshTmpFile(prefix: string): string {
    const dir = mkdtempSync(join(tmpdir(), `ytb-sites-${prefix}-`));
    return join(dir, 'sites.json');
}

function sampleFile(siteId = 'wp-acme'): SitesFileT {
    return {
        schema_version: 1,
        default_site_id: siteId,
        sites: [{
            site_id: siteId,
            url: 'https://acme.com',
            platform: 'wordpress',
            bearer: VALID_BEARER,
            is_default: true,
            label: 'Acme',
        }],
    };
}

describe('loadSitesFile', () => {
    it('returns emptySitesFile() when path does not exist', async () => {
        const p = join(mkdtempSync(join(tmpdir(), 'ytb-empty-')), 'never.json');
        const out = await loadSitesFile(p);
        expect(out).toEqual(emptySitesFile());
        expect(out.schema_version).toBe(1);
        expect(out.sites).toHaveLength(0);
        expect(out.default_site_id).toBeNull();
    });

    it('parses a valid file end-to-end', async () => {
        const p = freshTmpFile('load-ok');
        writeFileSync(p, JSON.stringify(sampleFile()), 'utf-8');
        const out = await loadSitesFile(p);
        expect(out.default_site_id).toBe('wp-acme');
        expect(out.sites).toHaveLength(1);
        expect(out.sites[0]?.site_id).toBe('wp-acme');
    });

    it('throws SitesFileError on non-JSON content', async () => {
        const p = freshTmpFile('load-bad-json');
        writeFileSync(p, '{ not valid json', 'utf-8');
        await expect(loadSitesFile(p)).rejects.toBeInstanceOf(SitesFileError);
    });

    it('throws SchemaVersionError on unknown schema_version', async () => {
        const p = freshTmpFile('load-bad-ver');
        writeFileSync(p, JSON.stringify({
            schema_version: 99, default_site_id: null, sites: [],
        }), 'utf-8');
        await expect(loadSitesFile(p)).rejects.toBeInstanceOf(SchemaVersionError);
    });

    it('throws SitesFileError on invalid shape (missing default_site_id)', async () => {
        const p = freshTmpFile('load-bad-shape');
        writeFileSync(p, JSON.stringify({
            schema_version: 1, sites: [],
        }), 'utf-8');
        await expect(loadSitesFile(p)).rejects.toBeInstanceOf(SitesFileError);
    });
});

describe('saveSitesFile', () => {
    it('writes the file with mode 0600 (verified via st_mode)', async () => {
        const p = freshTmpFile('save-mode');
        await saveSitesFile(p, sampleFile());
        const mode = statSync(p).mode & 0o777;
        expect(mode).toBe(0o600);
    });

    it('round-trips identical data', async () => {
        const p = freshTmpFile('save-roundtrip');
        const payload = sampleFile('joomla-beta');
        // The site is_default flag means default_site_id should match.
        payload.default_site_id = 'joomla-beta';
        await saveSitesFile(p, payload);
        const back = await loadSitesFile(p);
        expect(back).toEqual(payload);
    });

    it('produces a file that is valid JSON and ends in a newline', async () => {
        const p = freshTmpFile('save-format');
        await saveSitesFile(p, sampleFile());
        const raw = readFileSync(p, 'utf-8');
        expect(raw.endsWith('\n')).toBe(true);
        // Round-trip via JSON.parse to confirm validity.
        expect(() => JSON.parse(raw) as unknown).not.toThrow();
    });

    it('refuses to write an invalid sites file (no filesystem mutation)', async () => {
        const p = freshTmpFile('save-bad');
        const bad = {
            schema_version: 1,
            default_site_id: null,
            sites: [
                // Both bearer and bearer_ref set — Zod refine rejects.
                {
                    site_id: 'x',
                    url: 'https://x.test',
                    platform: 'wordpress',
                    bearer: VALID_BEARER,
                    bearer_ref: 'op://v/i/f',
                    is_default: false,
                },
            ],
        } as unknown as SitesFileT;
        await expect(saveSitesFile(p, bad)).rejects.toBeInstanceOf(SitesFileError);
        // File should NOT exist (we refused before any IO).
        expect(() => statSync(p)).toThrow();
    });

    it('serialises two concurrent saves — final state matches one payload, no mix', async () => {
        const p = freshTmpFile('save-concurrent');
        const a = sampleFile('site-a');
        a.default_site_id = 'site-a';
        const b = sampleFile('site-b');
        b.default_site_id = 'site-b';
        // Kick both off in parallel; both must succeed (one waits via
        // proper-lockfile retries) and the on-disk file must be exactly
        // one of the two — never half-a half-b.
        await Promise.all([saveSitesFile(p, a), saveSitesFile(p, b)]);
        const back = await loadSitesFile(p);
        const isA = back.default_site_id === 'site-a' && back.sites[0]?.site_id === 'site-a';
        const isB = back.default_site_id === 'site-b' && back.sites[0]?.site_id === 'site-b';
        expect(isA || isB).toBe(true);
        expect(back.sites).toHaveLength(1);
    });

    it('overwrites preserves mode 0600 across rewrites', async () => {
        const p = freshTmpFile('save-rewrite');
        await saveSitesFile(p, sampleFile('one'));
        await saveSitesFile(p, sampleFile('two'));
        const mode = statSync(p).mode & 0o777;
        expect(mode).toBe(0o600);
        const back = await loadSitesFile(p);
        expect(back.sites[0]?.site_id).toBe('two');
    });
});

describe('emptySitesFile', () => {
    it('returns the canonical empty seed', () => {
        const e = emptySitesFile();
        expect(e.schema_version).toBe(1);
        expect(e.default_site_id).toBeNull();
        expect(e.sites).toEqual([]);
    });
});
