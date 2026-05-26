/**
 * W9 — tests for `listSitesCommand` + `renderSitesTable`.
 *
 * Covers the contract of the public list output:
 *  - empty registry: "(no sites configured)" + discovery hint
 *  - 2 sites: header row + divider + per-site rows
 *  - bearer values NEVER appear in the rendered output (defence
 *    against a future regression that surfaces the raw token)
 *  - default-site summary mentions the default site_id
 *  - lines passed to `deps.log` exactly match the returned array
 *  - label column blank-padded when entry has no label
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    listSitesCommand,
    renderSitesTable,
    type ListSitesDeps,
} from '../../../src/sites/cli/list-sites.js';
import { emptySitesFile } from '../../../src/sites/store.js';
import type { SitesFileT } from '../../../src/sites/schema.js';

const VALID_BEARER =
    'ytb_live_eyJraWQiOiJ0LWtleSIsInNjb3BlIjoid3JpdGUifQ.abc123_xyz-def';

function withSites(file: SitesFileT): ListSitesDeps {
    return { load: async () => file };
}

function twoSiteFile(): SitesFileT {
    return {
        schema_version: 1,
        default_site_id: 'wp-acme',
        sites: [
            {
                site_id: 'wp-acme',
                url: 'https://acme.com',
                platform: 'wordpress',
                bearer: VALID_BEARER,
                is_default: true,
                label: 'Acme',
            },
            {
                site_id: 'joomla-beta',
                url: 'https://beta.example.com',
                platform: 'joomla',
                bearer_ref: 'op://Private/yt/bearer',
                is_default: false,
            },
        ],
    };
}

describe('renderSitesTable — empty registry', () => {
    it('returns the empty-registry hint + add-site discovery line', () => {
        const lines = renderSitesTable(emptySitesFile());
        expect(lines[0]).toBe('(no sites configured)');
        expect(lines.some((l) => l.includes('add-site'))).toBe(true);
    });

    it('still lists exactly 3 lines for empty (sentinel, blank, hint)', () => {
        const lines = renderSitesTable(emptySitesFile());
        expect(lines).toHaveLength(3);
    });
});

describe('renderSitesTable — populated registry', () => {
    it('renders a summary line mentioning the default', () => {
        const lines = renderSitesTable(twoSiteFile());
        expect(lines[0]).toMatch(/2 configured sites/);
        expect(lines[0]).toContain('default: wp-acme');
    });

    // W12-R1.3: BEARER column dropped from the CLI table for
    // cross-surface consistency with the MCP `sites_list` tool +
    // `sites://current` resource. Header now ends at LABEL.
    it('renders a header row + a divider before the per-site rows', () => {
        const lines = renderSitesTable(twoSiteFile());
        // Header is line 2, divider line 3
        expect(lines[2]).toMatch(/SITE_ID/);
        expect(lines[2]).toMatch(/URL/);
        expect(lines[2]).toMatch(/PLATFORM/);
        expect(lines[2]).toMatch(/DEFAULT/);
        expect(lines[2]).toMatch(/LABEL/);
        expect(lines[2]).not.toMatch(/BEARER/);
        expect(lines[3]).toMatch(/^-+/);
    });

    it('renders one row per site (no bearer_source column)', () => {
        const lines = renderSitesTable(twoSiteFile());
        const acmeRow = lines.find((l) => l.startsWith('wp-acme'));
        const betaRow = lines.find((l) => l.startsWith('joomla-beta'));
        expect(acmeRow).toBeDefined();
        expect(betaRow).toBeDefined();
        expect(acmeRow).toContain('acme.com');
        expect(acmeRow).toContain('wordpress');
        expect(acmeRow).toContain('yes');
        expect(betaRow).toContain('joomla');
        // bearer_source values no longer printed.
        expect(acmeRow).not.toMatch(/\bplain\b/);
        expect(betaRow).not.toMatch(/\bop\b/);
    });

    it('NEVER leaks the bearer token into the rendered output', () => {
        const lines = renderSitesTable(twoSiteFile());
        const all = lines.join('\n');
        expect(all).not.toContain(VALID_BEARER);
        expect(all).not.toContain('op://Private/yt/bearer');
    });
});

describe('listSitesCommand — IO + log seam', () => {
    it('returns the same lines it logs', async () => {
        const file = twoSiteFile();
        const captured: string[] = [];
        const deps: ListSitesDeps = {
            ...withSites(file),
            log: (line) => captured.push(line),
        };
        const lines = await listSitesCommand('/x', deps);
        expect(captured).toEqual(lines);
    });

    it('returns the empty-registry contract for an empty file', async () => {
        const captured: string[] = [];
        const deps: ListSitesDeps = {
            load: async () => emptySitesFile(),
            log: (line) => captured.push(line),
        };
        const lines = await listSitesCommand('/x', deps);
        expect(lines[0]).toBe('(no sites configured)');
        expect(captured).toEqual(lines);
    });
});
