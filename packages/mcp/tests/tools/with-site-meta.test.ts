/**
 * W6.2 pin-test — `withSiteMeta` envelope-stamper.
 *
 * Two surfaces under test (Web-Research-Addendum §B):
 *
 *  - TEXT-PREFIX PRIMARY (load-bearing): `[<label or id> @ <host>] `
 *    MUST be injected as the very first characters of `content[0].text`
 *    so Maria-the-Claude-Desktop-user SEES which site she's targeting,
 *    independent of any host-side `_meta` rendering.
 *
 *  - `_meta` SECONDARY: the RESULT-level `_meta.{site_id, site_url,
 *    platform}` MUST mirror the same fact for hosts that surface
 *    structured metadata natively. W12-R2: this lives on the result
 *    envelope `_meta`, NOT inside `structuredContent` — the latter is
 *    validated against the tool's `outputSchema` (additionalProperties:
 *    false) by real hosts, so an undeclared `_meta` key there triggers
 *    a `-32602` "Failed to call tool" reject in Claude Desktop even on
 *    success. `structuredContent` MUST stay schema-pure.
 *
 * Behaviour pins beyond the two surfaces: non-text content untouched,
 * existing structuredContent fields preserved, empty content[]
 * tolerated, label-fallback works, malformed URL falls back to raw
 * site.url for host display.
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    type Platform,
    WordPressPlatform,
} from '../../src/platform/index.js';
import type { ResolvedSite } from '../../src/sites/registry.js';
import type { ToolResult } from '../../src/tools/tool-builder.js';
import { withSiteMeta } from '../../src/tools/tool-builder.js';

function siteOf(over: Partial<ResolvedSite> = {}): ResolvedSite {
    const platform: Platform = over.platform ?? new WordPressPlatform('https://acme.com');
    const base: ResolvedSite = {
        id: 'wp-acme',
        url: 'https://acme.com',
        platform,
        isDefault: true,
        bearerSource: 'plain',
    };
    return { ...base, ...over };
}

function textResult(text: string, structured: Record<string, unknown> = {}): ToolResult {
    return {
        content: [{ type: 'text', text }],
        structuredContent: structured,
    };
}

describe('withSiteMeta — W6.2 envelope-stamper', () => {
    it('attaches result-level _meta with site_id / site_url / platform', () => {
        const out = withSiteMeta(textResult('hello'), siteOf());
        const meta = out._meta as Record<string, unknown>;
        expect(meta).toBeDefined();
        expect(meta.site_id).toBe('wp-acme');
        expect(meta.site_url).toBe('https://acme.com');
        expect(meta.platform).toBe('wordpress');
    });

    // W12-R2 ANTI-REGRESSION: the exact bug that produced "Failed to
    // call tool" in Claude Desktop. site meta must NEVER be stamped into
    // structuredContent — that payload is validated against the tool's
    // outputSchema (additionalProperties:false) and an undeclared _meta
    // key is rejected (-32602) by real MCP hosts. structuredContent must
    // come out byte-identical to what the handler produced.
    it('NEVER injects _meta into structuredContent (schema-purity guard)', () => {
        const out = withSiteMeta(
            textResult('hello', { items: [{ id: 1 }], total: 1 }),
            siteOf(),
        );
        expect(out.structuredContent).toEqual({ items: [{ id: 1 }], total: 1 });
        expect((out.structuredContent as Record<string, unknown>)._meta).toBeUndefined();
    });

    it('TEXT-PREFIX PRIMARY: injects `[<id> @ <host>] ` at the very start of content[0].text (LOAD-BEARING per web-addendum)', () => {
        const out = withSiteMeta(textResult('hello world'), siteOf());
        const firstText = out.content[0];
        expect(firstText?.type).toBe('text');
        expect((firstText?.text as string)).toBe('[wp-acme @ acme.com] hello world');
        // The exact start-anchor is the load-bearing surface; assert
        // startsWith independently of the rest.
        expect((firstText?.text as string).startsWith('[wp-acme @ acme.com] ')).toBe(true);
    });

    it('label-fallback: site.label wins over site.id when present', () => {
        const out = withSiteMeta(textResult('hi'), siteOf({ label: 'Acme — Production' }));
        const firstText = out.content[0];
        expect((firstText?.text as string).startsWith('[Acme — Production @ acme.com] ')).toBe(true);
    });

    it('label-fallback: falls back to site_id when label is undefined', () => {
        const out = withSiteMeta(textResult('hi'), siteOf({ label: undefined }));
        const firstText = out.content[0];
        expect((firstText?.text as string).startsWith('[wp-acme @ acme.com] ')).toBe(true);
    });

    it('extracts hostname via URL parsing (default port stripped)', () => {
        const platform = new WordPressPlatform('https://example.com:443/some/path');
        const out = withSiteMeta(
            textResult('x'),
            siteOf({ url: 'https://example.com:443/some/path', platform }),
        );
        const firstText = out.content[0];
        // URL.host strips the default :443 port; the path is dropped.
        expect((firstText?.text as string)).toContain('@ example.com] ');
    });

    it('malformed URL falls back to raw site.url for the host segment (defensive)', () => {
        // A site.url that fails URL parsing must not throw — withSiteMeta
        // is on every tool's hot path so a crashed URL parse would 500 the
        // whole call. The fallback puts the raw string into the prefix
        // instead, which is degraded UX but never blocking.
        const platform = new WordPressPlatform('not a url');
        const out = withSiteMeta(
            textResult('x'),
            siteOf({ url: 'not a url', platform }),
        );
        const firstText = out.content[0];
        expect((firstText?.text as string).startsWith('[wp-acme @ not a url] ')).toBe(true);
    });

    // W12-R1.2 (A4-F2): when content[0] is a non-text item the prefix
    // would be invisible to Maria the Claude-Desktop user, so we PREPEND
    // a synthetic text block carrying the site-prefix. The original
    // non-text item and any following text item are preserved untouched
    // (shifted by one index).
    it('non-text content[0] gets a synthetic prefix block prepended', () => {
        const result: ToolResult = {
            content: [
                { type: 'image', data: 'BASE64==', mimeType: 'image/png' },
                { type: 'text', text: 'follow-up' },
            ],
            structuredContent: {},
        };
        const out = withSiteMeta(result, siteOf());
        // Synthetic text block prepended carrying the prefix.
        expect(out.content[0]?.type).toBe('text');
        expect(out.content[0]?.text).toBe('[wp-acme @ acme.com]');
        // Original image preserved at index 1.
        expect(out.content[1]?.type).toBe('image');
        expect(out.content[1]?.data).toBe('BASE64==');
        // Original follow-up text preserved at index 2, NOT mutated.
        expect(out.content[2]?.text).toBe('follow-up');
    });

    it('structuredContent fields are preserved verbatim (site meta goes to result _meta)', () => {
        const input = textResult('hi', {
            items: [{ id: 1 }, { id: 2 }],
            total: 2,
            template_id: 'home',
        });
        const out = withSiteMeta(input, siteOf());
        expect(out.structuredContent?.items).toEqual([{ id: 1 }, { id: 2 }]);
        expect(out.structuredContent?.total).toBe(2);
        expect(out.structuredContent?.template_id).toBe('home');
        // structuredContent stays schema-pure; site meta lands on result _meta.
        expect((out.structuredContent as Record<string, unknown>)._meta).toBeUndefined();
        expect((out._meta as Record<string, unknown>).site_id).toBe('wp-acme');
    });

    it('existing result-level _meta (e.g. toolkit _meta.ui) is merged, not overwritten', () => {
        const input: ToolResult = {
            content: [{ type: 'text', text: 'hi' }],
            structuredContent: {},
            _meta: { ui: { foo: 'bar' }, custom: 'preserved' },
        };
        const out = withSiteMeta(input, siteOf());
        const meta = out._meta as Record<string, unknown>;
        expect(meta.ui).toEqual({ foo: 'bar' });
        expect(meta.custom).toBe('preserved');
        expect(meta.site_id).toBe('wp-acme');
    });

    // W12-R1.2 (A4-F2): empty content[] now gets a synthetic prefix
    // block so site-awareness remains the LOAD-BEARING customer-visible
    // signal even on responses that had no text body (image-only,
    // resource-only, empty list).
    it('empty content[] gets a synthetic prefix block prepended', () => {
        const input: ToolResult = { content: [], structuredContent: {} };
        const out = withSiteMeta(input, siteOf());
        expect(out.content).toHaveLength(1);
        expect(out.content[0]?.type).toBe('text');
        expect(out.content[0]?.text).toBe('[wp-acme @ acme.com]');
        // site meta still lands on the result-level _meta.
        expect((out._meta as Record<string, unknown>).site_id).toBe('wp-acme');
    });

    it('undefined structuredContent stays undefined; site meta lands on result _meta', () => {
        const input: ToolResult = {
            content: [{ type: 'text', text: 'hi' }],
        };
        const out = withSiteMeta(input, siteOf());
        // No outputSchema-bearing payload to pollute — leave it absent.
        expect(out.structuredContent).toBeUndefined();
        const meta = out._meta as Record<string, unknown>;
        expect(meta.site_id).toBe('wp-acme');
    });

    it('platform.kind is propagated verbatim (wordpress)', () => {
        const out = withSiteMeta(textResult('hi'), siteOf());
        const meta = out._meta as Record<string, unknown>;
        expect(meta.platform).toBe('wordpress');
    });

    it('platform.kind is propagated verbatim (joomla)', () => {
        const joomlaPlatform: Platform = {
            kind: 'joomla',
            baseUrl: 'https://example.com/joomla',
            restNamespacePath: '/api/index.php/v1/yt-builder-mcp',
        };
        const out = withSiteMeta(
            textResult('hi'),
            siteOf({
                id: 'joomla-beta',
                url: 'https://example.com/joomla',
                platform: joomlaPlatform,
                bearerSource: 'op',
                isDefault: false,
            }),
        );
        const meta = out._meta as Record<string, unknown>;
        expect(meta.platform).toBe('joomla');
        expect(meta.site_id).toBe('joomla-beta');
    });

    it('does not mutate the input result object (pure function)', () => {
        const input = textResult('hi', { items: [] });
        const originalText = input.content[0]?.text;
        const originalStructured = input.structuredContent;
        const _out = withSiteMeta(input, siteOf());
        expect(input.content[0]?.text).toBe(originalText);
        expect(input.structuredContent).toBe(originalStructured);
        // The original structuredContent should NOT have been augmented.
        expect((input.structuredContent as Record<string, unknown>)._meta).toBeUndefined();
    });

    it('preserves isError flag from input result', () => {
        const input: ToolResult = {
            content: [{ type: 'text', text: 'boom' }],
            isError: true,
        };
        const out = withSiteMeta(input, siteOf());
        expect(out.isError).toBe(true);
    });

    it('text-prefix appears even on isError results (so the user knows which site failed)', () => {
        const input: ToolResult = {
            content: [{ type: 'text', text: 'auth failed' }],
            isError: true,
        };
        const out = withSiteMeta(input, siteOf());
        expect((out.content[0]?.text as string).startsWith('[wp-acme @ acme.com] ')).toBe(true);
    });

    // W12-R3 (F-A4-F2): the empty-content + non-text-first synthetic
    // fallback cases ARE pinned above (lines 114-131 and 162-170). The
    // following tests pin the load-bearing CUSTOMER-VISIBLE side of
    // those fallbacks: the synthetic block MUST land at content[0],
    // MUST be of type 'text', MUST carry the resolved-site marker as
    // its full text (not concatenated with later content), and MUST
    // NOT mutate any pre-existing non-text blocks.

    it('F-A4-F2 (empty content[]): synthetic prefix block is content[0], type:text, text is EXACTLY "[<id> @ <host>]"', () => {
        const input: ToolResult = { content: [], structuredContent: {} };
        const out = withSiteMeta(input, siteOf());
        expect(out.content[0]?.type).toBe('text');
        // Exact match — proves no later content was concatenated onto
        // the synthetic block (a regression where the wrapper instead
        // copy-stamped the existing text would fail this).
        expect(out.content[0]?.text).toBe('[wp-acme @ acme.com]');
        // Synthetic block is the SOLE content item — empty input means
        // no follow-up blocks to preserve.
        expect(out.content).toHaveLength(1);
    });

    it('F-A4-F2 (non-text first block): synthetic prefix prepended at content[0], original blocks shifted to [1..N] unchanged', () => {
        const originalImage = { type: 'image', data: 'BASE64==', mimeType: 'image/png' } as const;
        const originalFollowUp = { type: 'text', text: 'follow-up' } as const;
        const result: ToolResult = {
            content: [originalImage, originalFollowUp],
            structuredContent: {},
        };
        const out = withSiteMeta(result, siteOf());
        // Synthetic prefix block is content[0].
        expect(out.content[0]?.type).toBe('text');
        expect(out.content[0]?.text).toBe('[wp-acme @ acme.com]');
        // Original image preserved BYTE-IDENTICALLY at index 1
        // (deep-equal — a regression that re-encoded the data would
        // fail here even if the bytes still decoded).
        expect(out.content[1]).toEqual(originalImage);
        // Original follow-up text preserved at index 2 — crucially NOT
        // prefixed (only the synthetic block at [0] carries the prefix;
        // shifting + re-prefixing would double-stamp).
        expect(out.content[2]).toEqual(originalFollowUp);
        expect(out.content[2]?.text).toBe('follow-up');
    });
});
