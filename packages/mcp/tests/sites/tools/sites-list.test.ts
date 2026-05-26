/**
 * W7 — Tests for `yootheme_builder_sites_list`.
 *
 * The L1 tool is platform-agnostic and reads the in-process
 * {@link SiteRegistry} verbatim. Tests cover:
 *
 *  - Empty registry → empty items[] + footer hint visible.
 *  - 2 sites → items[].length === 2, correct site_id/url/platform_hint/
 *    is_default, label optional, bearer_source derived from registry.
 *  - Default-site marker propagates from `registry.defaultSiteId()`.
 *  - Bearer fields NEVER leak into structuredContent or text (the
 *    handler never asks the secret-resolver and the registry projection
 *    only carries `bearer_source: 'plain'|'op'`).
 *  - `structuredContent._meta.tool_kind === 'registry'` marker (W7
 *    spec: distinguishes registry tools from site-bound tools without
 *    string-matching the tool name).
 *  - Description ends with the canonical SITE_ID_SCHEMA suffix (W5 pin).
 *  - readOnly annotation present.
 *
 * Strategy: drive the registry directly (not through `pool.resolve` —
 * the tool never calls `resolve`) via `makeMultiTestPool` which builds
 * the registry from in-memory entries. The handler is bound via
 * `buildSitesListTool(pool.registry)`.
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import type { ClientPool } from '../../../src/sites/client-pool.js';
import { buildSitesListTool } from '../../../src/sites/tools/sites-list.js';
import { makeMultiTestPool } from '../../helpers/test-pool.js';

const TOOL_NAME = 'yootheme_builder_sites_list';

function buildTool(pool: ClientPool) {
    return buildSitesListTool(pool.registry);
}

interface SitesListItem {
    site_id: string;
    url: string;
    platform_hint: 'wordpress' | 'joomla' | 'auto';
    platform_resolved?: 'wordpress' | 'joomla';
    is_default: boolean;
    label?: string;
    // W12-R1.3 (A2-L4): bearer_source intentionally optional/absent
    // on the projection — the pin-test below asserts it is undefined.
    bearer_source?: 'plain' | 'op';
}

interface SitesListStructured {
    items: SitesListItem[];
    total: number;
    default_site_id: string | null;
    _meta?: { tool_kind?: string };
}

function structured(result: {
    structuredContent?: Record<string, unknown>;
}): SitesListStructured {
    const sc = result.structuredContent;
    if (!sc) throw new Error('structuredContent missing');
    return sc as unknown as SitesListStructured;
}

describe('W7 — yootheme_builder_sites_list', () => {
    describe('schema + metadata', () => {
        it('tool name is canonical', () => {
            const pool = makeMultiTestPool({ sites: [] });
            const tool = buildTool(pool);
            expect(tool.name).toBe(TOOL_NAME);
        });

        // W12-R1.3 (A6-F5): the canonical "Operates on the default
        // site unless site_id is provided." suffix was misleading
        // here — sites_list lists ALL configured sites, ignoring
        // site_id (it's accepted purely for schema-uniformity with
        // every other tool). Replaced with explicit wording; the W5
        // site-id-schema pin EXEMPTS this tool via
        // DESCRIPTION_SUFFIX_EXEMPT.
        it('description explains site_id is accepted but ignored', () => {
            const pool = makeMultiTestPool({ sites: [] });
            const tool = buildTool(pool);
            expect(tool.description).toMatch(
                /site_id is accepted for schema-uniformity but ignored/,
            );
        });

        it('annotations declare read-only + the descriptive title', () => {
            const pool = makeMultiTestPool({ sites: [] });
            const tool = buildTool(pool);
            expect(tool.annotations?.readOnlyHint).toBe(true);
            expect(tool.annotations?.title).toBe('Sites — List configured');
        });
    });

    describe('empty registry', () => {
        it('returns an empty items[] with total === 0', async () => {
            const pool = makeMultiTestPool({ sites: [] });
            const tool = buildTool(pool);
            const result = await tool.handler({});
            const sc = structured(result);
            expect(sc.items).toEqual([]);
            expect(sc.total).toBe(0);
        });

        it('default_site_id is null when the file has no default', async () => {
            const pool = makeMultiTestPool({ sites: [] });
            const tool = buildTool(pool);
            const result = await tool.handler({});
            expect(structured(result).default_site_id).toBeNull();
        });

        it('text content collapses to "No items found" when empty', async () => {
            // The toolkit's tableResult intentionally omits the footer
            // when the data row is empty (renders "No items found"
            // instead). The footer hint resurfaces in the non-empty
            // assertions below.
            const pool = makeMultiTestPool({ sites: [] });
            const tool = buildTool(pool);
            const result = await tool.handler({});
            const text = result.content[0]?.text ?? '';
            expect(text).toMatch(/No items found/i);
        });

        it('text content surfaces the "no default configured" header note', async () => {
            const pool = makeMultiTestPool({ sites: [] });
            const tool = buildTool(pool);
            const result = await tool.handler({});
            const text = result.content[0]?.text ?? '';
            expect(text).toMatch(/no default configured/i);
        });
    });

    describe('two-site registry', () => {
        const SITES = [
            {
                site_id: 'wp-acme',
                url: 'https://acme.example.com',
                bearer: 'ytb_live_PAYLOAD.SIG',
                platform: 'wordpress' as const,
                is_default: true,
                label: 'Acme Production',
            },
            {
                site_id: 'jo-beta',
                url: 'https://beta.example.com',
                bearer: 'ytb_test_PAYLOAD.SIG',
                platform: 'joomla' as const,
                is_default: false,
                label: 'Beta Staging',
            },
        ];

        it('returns exactly 2 items in registry order', async () => {
            const pool = makeMultiTestPool({
                sites: SITES,
                defaultSiteId: 'wp-acme',
            });
            const tool = buildTool(pool);
            const result = await tool.handler({});
            const sc = structured(result);
            expect(sc.items.length).toBe(2);
            expect(sc.total).toBe(2);
            expect(sc.items[0]?.site_id).toBe('wp-acme');
            expect(sc.items[1]?.site_id).toBe('jo-beta');
        });

        it('each item carries url + platform_hint + is_default + label', async () => {
            const pool = makeMultiTestPool({
                sites: SITES,
                defaultSiteId: 'wp-acme',
            });
            const tool = buildTool(pool);
            const result = await tool.handler({});
            const items = structured(result).items;
            const acme = items[0];
            expect(acme?.url).toBe('https://acme.example.com');
            expect(acme?.platform_hint).toBe('wordpress');
            expect(acme?.is_default).toBe(true);
            expect(acme?.label).toBe('Acme Production');
            const beta = items[1];
            expect(beta?.url).toBe('https://beta.example.com');
            expect(beta?.platform_hint).toBe('joomla');
            expect(beta?.is_default).toBe(false);
            expect(beta?.label).toBe('Beta Staging');
        });

        it('default_site_id propagates from registry.defaultSiteId()', async () => {
            const pool = makeMultiTestPool({
                sites: SITES,
                defaultSiteId: 'wp-acme',
            });
            const tool = buildTool(pool);
            const result = await tool.handler({});
            expect(structured(result).default_site_id).toBe('wp-acme');
        });

        it('text content surfaces the test-with-id footer hint (non-empty case)', async () => {
            const pool = makeMultiTestPool({
                sites: SITES,
                defaultSiteId: 'wp-acme',
            });
            const tool = buildTool(pool);
            const result = await tool.handler({});
            const text = result.content[0]?.text ?? '';
            expect(text).toContain('yootheme_builder_sites_test');
        });

        it('text content surfaces the (default: <id>) header', async () => {
            const pool = makeMultiTestPool({
                sites: SITES,
                defaultSiteId: 'wp-acme',
            });
            const tool = buildTool(pool);
            const result = await tool.handler({});
            const text = result.content[0]?.text ?? '';
            expect(text).toContain('default: wp-acme');
        });

        it('NEVER leaks bearer fields into structuredContent', async () => {
            const pool = makeMultiTestPool({
                sites: SITES,
                defaultSiteId: 'wp-acme',
            });
            const tool = buildTool(pool);
            const result = await tool.handler({});
            const json = JSON.stringify(result.structuredContent);
            expect(json).not.toContain('ytb_live_PAYLOAD.SIG');
            expect(json).not.toContain('ytb_test_PAYLOAD.SIG');
            // The 'bearer' key itself must NOT appear (only the safe
            // 'bearer_source' projection).
            expect(json).not.toMatch(/"bearer":/);
            expect(json).not.toMatch(/"bearer_ref":/);
        });

        it('NEVER leaks bearer fields into the text content', async () => {
            const pool = makeMultiTestPool({
                sites: SITES,
                defaultSiteId: 'wp-acme',
            });
            const tool = buildTool(pool);
            const result = await tool.handler({});
            const text = result.content[0]?.text ?? '';
            expect(text).not.toContain('ytb_live_PAYLOAD.SIG');
            expect(text).not.toContain('ytb_test_PAYLOAD.SIG');
        });

        // W12-R1.3 (A2-L4): bearer_source was dropped from the output
        // for cross-surface consistency with sites://current. The
        // pin-test below confirms the field is GONE from items[] and
        // the table-rendered text content alike.
        it('bearer_source is NOT projected into items or text', async () => {
            const pool = makeMultiTestPool({
                sites: SITES,
                defaultSiteId: 'wp-acme',
            });
            const tool = buildTool(pool);
            const result = await tool.handler({});
            const items = structured(result).items;
            expect(items[0]?.bearer_source).toBeUndefined();
            expect(items[1]?.bearer_source).toBeUndefined();
            const text = result.content[0]?.text ?? '';
            expect(text).not.toMatch(/BEARER/);
        });
    });

    describe('_meta marker', () => {
        it('structuredContent._meta.tool_kind === "registry"', async () => {
            const pool = makeMultiTestPool({ sites: [] });
            const tool = buildTool(pool);
            const result = await tool.handler({});
            const sc = structured(result);
            expect(sc._meta?.tool_kind).toBe('registry');
        });

        it('does NOT inject the W6 site-prefix `[<id> @ <host>] ` into the text', async () => {
            // sites_list is platform-agnostic — it intentionally does
            // NOT call withSiteMeta, so the text must NOT start with
            // the `[…] ` prefix that every site-bound tool carries.
            const pool = makeMultiTestPool({
                sites: [{
                    site_id: 'wp-acme',
                    url: 'https://acme.example.com',
                    bearer: 'ytb_live_PAYLOAD.SIG',
                    platform: 'wordpress',
                    is_default: true,
                }],
                defaultSiteId: 'wp-acme',
            });
            const tool = buildTool(pool);
            const result = await tool.handler({});
            const text = result.content[0]?.text ?? '';
            // The prefix would look like `[wp-acme @ acme.example.com] `.
            expect(text).not.toMatch(/^\[wp-acme @ acme\.example\.com\] /);
        });
    });
});
