/**
 * W3 — Registry tests for sites/registry.ts.
 *
 * Coverage focuses on three load-bearing behaviours:
 *
 *  1. **Default resolution.** `defaultSiteId()` MUST consult the
 *     explicit `default_site_id` first, fall back to a flagged
 *     `is_default:true` entry, then return `null`. It MUST NOT
 *     auto-promote a lone site (W4 owns that policy).
 *
 *  2. **Skip-probe hint shortcut.** When a site's `platform` field is
 *     a concrete `wordpress` or `joomla`, `platformFor()` MUST forward
 *     that hint to `platformForUrlAsync` — the probe override receives
 *     the hint and the implementation never falls back to network
 *     probing. We assert this with a spy.
 *
 *  3. **Promise-cache, not result-cache.** When 10 parallel callers
 *     hit `platformFor('a')` for the first time, the probe MUST run
 *     EXACTLY ONCE. This is the race-condition test the plan §W3
 *     calls out by name — storing a `Promise<Platform>` in the cache
 *     (rather than the resolved value) is what makes it pass.
 *
 *  4. **listForDisplay() peek without probe.** Before `platformFor`,
 *     rows have `platform_resolved: undefined`. After a successful
 *     `platformFor('a')`, only row 'a' shows the resolved kind; other
 *     rows remain undefined. listForDisplay() MUST NEVER trigger a
 *     probe.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import {
    JoomlaPlatform,
    type Platform,
    type PlatformKind,
    WordPressPlatform,
} from '../../src/platform/index.js';
import { SiteRegistry } from '../../src/sites/registry.js';
import type { SiteEntryT, SitesFileT } from '../../src/sites/schema.js';

const VALID_BEARER
    = 'ytb_live_eyJraWQiOiJ0LWtleSIsInNjb3BlIjoid3JpdGUifQ.abc123_xyz-def';

function siteEntry(overrides: Partial<SiteEntryT> = {}): SiteEntryT {
    return {
        site_id: 'wp-acme',
        url: 'https://acme.example.com',
        platform: 'auto',
        bearer: VALID_BEARER,
        is_default: false,
        ...overrides,
    };
}

function fileWith(...sites: SiteEntryT[]): SitesFileT {
    return {
        schema_version: 1,
        default_site_id: null,
        sites,
    };
}

describe('SiteRegistry — defaultSiteId()', () => {
    it('returns explicit default_site_id when set and present', () => {
        const file: SitesFileT = {
            schema_version: 1,
            default_site_id: 'wp-acme',
            sites: [siteEntry({ site_id: 'wp-acme' })],
        };
        const reg = new SiteRegistry(file);
        expect(reg.defaultSiteId()).toBe('wp-acme');
    });

    it('falls back to is_default:true entry when no explicit default', () => {
        const reg = new SiteRegistry(
            fileWith(
                siteEntry({ site_id: 'a', is_default: false }),
                siteEntry({ site_id: 'b', is_default: true, url: 'https://b.example.com' }),
            ),
        );
        expect(reg.defaultSiteId()).toBe('b');
    });

    it('returns null when no default and only 1 site (W4 auto-promotion lives elsewhere)', () => {
        const reg = new SiteRegistry(fileWith(siteEntry({ site_id: 'lone' })));
        expect(reg.defaultSiteId()).toBeNull();
    });

    it('returns null on completely empty sites file', () => {
        const reg = new SiteRegistry(fileWith());
        expect(reg.defaultSiteId()).toBeNull();
        expect(reg.listIds()).toHaveLength(0);
    });

    it('ignores stale explicit default that no longer points at any entry', () => {
        const file: SitesFileT = {
            schema_version: 1,
            default_site_id: 'deleted-site',
            sites: [siteEntry({ site_id: 'a' })],
        };
        const reg = new SiteRegistry(file);
        expect(reg.defaultSiteId()).toBeNull();
    });
});

describe('SiteRegistry — listIds() / get()', () => {
    it('listIds() preserves insertion order', () => {
        const reg = new SiteRegistry(
            fileWith(
                siteEntry({ site_id: 'zulu', url: 'https://z.example.com' }),
                siteEntry({ site_id: 'alpha', url: 'https://a.example.com' }),
                siteEntry({ site_id: 'mike', url: 'https://m.example.com' }),
            ),
        );
        expect(reg.listIds()).toEqual(['zulu', 'alpha', 'mike']);
    });

    it('get(id) returns the matching entry', () => {
        const reg = new SiteRegistry(
            fileWith(siteEntry({ site_id: 'foo', url: 'https://foo.example.com' })),
        );
        const found = reg.get('foo');
        expect(found).not.toBeNull();
        expect(found?.site_id).toBe('foo');
        expect(found?.url).toBe('https://foo.example.com');
    });

    it('get(id) returns null for unknown id', () => {
        const reg = new SiteRegistry(fileWith(siteEntry()));
        expect(reg.get('missing')).toBeNull();
    });
});

describe('SiteRegistry — platformFor() hint shortcut', () => {
    it('forwards platform: "wordpress" hint to the probe (skips auto-detection)', async () => {
        const spy = vi.fn(
            async (url: string, hint?: PlatformKind): Promise<Platform> => {
                expect(hint).toBe('wordpress');
                return new WordPressPlatform(url);
            },
        );
        const reg = new SiteRegistry(
            fileWith(siteEntry({ site_id: 'wp', platform: 'wordpress' })),
            { platformForUrlAsync: spy },
        );
        const platform = await reg.platformFor('wp');
        expect(platform.kind).toBe('wordpress');
        expect(spy).toHaveBeenCalledTimes(1);
        expect(spy).toHaveBeenCalledWith(
            'https://acme.example.com',
            'wordpress',
        );
    });

    it('forwards platform: "joomla" hint to the probe (skips auto-detection)', async () => {
        const spy = vi.fn(
            async (url: string, hint?: PlatformKind): Promise<Platform> => {
                expect(hint).toBe('joomla');
                return new JoomlaPlatform(url);
            },
        );
        const reg = new SiteRegistry(
            fileWith(siteEntry({ site_id: 'jo', platform: 'joomla' })),
            { platformForUrlAsync: spy },
        );
        const platform = await reg.platformFor('jo');
        expect(platform.kind).toBe('joomla');
        expect(spy).toHaveBeenCalledTimes(1);
        expect(spy).toHaveBeenCalledWith(
            'https://acme.example.com',
            'joomla',
        );
    });

    it('platform: "auto" calls probe WITHOUT a hint (probe decides)', async () => {
        const spy = vi.fn(
            async (url: string, hint?: PlatformKind): Promise<Platform> => {
                expect(hint).toBeUndefined();
                return new WordPressPlatform(url);
            },
        );
        const reg = new SiteRegistry(
            fileWith(siteEntry({ site_id: 'auto-site', platform: 'auto' })),
            { platformForUrlAsync: spy },
        );
        await reg.platformFor('auto-site');
        expect(spy).toHaveBeenCalledTimes(1);
    });

    it('throws on unknown site_id', async () => {
        const reg = new SiteRegistry(fileWith(siteEntry()));
        await expect(reg.platformFor('does-not-exist')).rejects.toThrow(
            /unknown site_id "does-not-exist"/,
        );
    });
});

describe('SiteRegistry — platformFor() memoisation', () => {
    it('second sequential call reuses cache (probe runs once)', async () => {
        const spy = vi.fn(
            async (url: string): Promise<Platform> =>
                new WordPressPlatform(url),
        );
        const reg = new SiteRegistry(
            fileWith(siteEntry({ site_id: 'auto-site', platform: 'auto' })),
            { platformForUrlAsync: spy },
        );
        const first = await reg.platformFor('auto-site');
        const second = await reg.platformFor('auto-site');
        expect(spy).toHaveBeenCalledTimes(1);
        // Identity-equal because the Promise (and therefore its
        // resolved Platform) is the same object both times.
        expect(second).toBe(first);
    });

    it('10 parallel first-time callers share ONE probe (Promise-cache race test)', async () => {
        // Gate the probe so all 10 callers definitely arrive at the
        // cache BEFORE the first one resolves — that is the only
        // configuration that distinguishes a Promise-cache from a
        // result-cache. With a result-cache, every caller would see
        // an empty map and start its own probe.
        let release!: () => void;
        const gate = new Promise<void>((resolve) => {
            release = resolve;
        });
        const spy = vi.fn(async (url: string): Promise<Platform> => {
            await gate;
            return new WordPressPlatform(url);
        });
        const reg = new SiteRegistry(
            fileWith(siteEntry({ site_id: 'auto-site', platform: 'auto' })),
            { platformForUrlAsync: spy },
        );

        const callers = Array.from({ length: 10 }, () =>
            reg.platformFor('auto-site'),
        );
        // Probe-spy must have been called exactly once already, even
        // though no caller has had a chance to resolve yet.
        expect(spy).toHaveBeenCalledTimes(1);

        release();
        const results = await Promise.all(callers);
        // After all 10 resolve, the spy is STILL at exactly 1
        // invocation — the cache served the other 9.
        expect(spy).toHaveBeenCalledTimes(1);
        // Every caller got the same resolved Platform instance.
        for (const r of results) {
            expect(r).toBe(results[0]);
            expect(r.kind).toBe('wordpress');
        }
    });

    it('different site_ids each get their own probe (cache is per-site)', async () => {
        const spy = vi.fn(
            async (url: string): Promise<Platform> =>
                new WordPressPlatform(url),
        );
        const reg = new SiteRegistry(
            fileWith(
                siteEntry({ site_id: 'a', url: 'https://a.example.com' }),
                siteEntry({ site_id: 'b', url: 'https://b.example.com' }),
            ),
            { platformForUrlAsync: spy },
        );
        await Promise.all([reg.platformFor('a'), reg.platformFor('b')]);
        expect(spy).toHaveBeenCalledTimes(2);
    });
});

describe('SiteRegistry — listForDisplay()', () => {
    it('all rows have platform_resolved undefined before any platformFor call', () => {
        const reg = new SiteRegistry(
            fileWith(
                siteEntry({ site_id: 'a', url: 'https://a.example.com' }),
                siteEntry({ site_id: 'b', url: 'https://b.example.com' }),
            ),
        );
        const rows = reg.listForDisplay();
        expect(rows).toHaveLength(2);
        expect(rows[0]?.platform_resolved).toBeUndefined();
        expect(rows[1]?.platform_resolved).toBeUndefined();
    });

    it('after platformFor("a"), only row "a" has platform_resolved set', async () => {
        const spy = vi.fn(
            async (url: string): Promise<Platform> =>
                new WordPressPlatform(url),
        );
        const reg = new SiteRegistry(
            fileWith(
                siteEntry({ site_id: 'a', url: 'https://a.example.com' }),
                siteEntry({ site_id: 'b', url: 'https://b.example.com' }),
            ),
            { platformForUrlAsync: spy },
        );
        await reg.platformFor('a');
        const rows = reg.listForDisplay();
        const a = rows.find((r) => r.site_id === 'a');
        const b = rows.find((r) => r.site_id === 'b');
        expect(a?.platform_resolved).toBe('wordpress');
        expect(b?.platform_resolved).toBeUndefined();
        // Crucially, listForDisplay() did NOT trigger a probe on 'b'.
        expect(spy).toHaveBeenCalledTimes(1);
    });

    it('listForDisplay() never triggers a probe by itself', () => {
        const spy = vi.fn(
            async (url: string): Promise<Platform> =>
                new WordPressPlatform(url),
        );
        const reg = new SiteRegistry(
            fileWith(
                siteEntry({ site_id: 'a', url: 'https://a.example.com' }),
                siteEntry({ site_id: 'b', url: 'https://b.example.com' }),
                siteEntry({ site_id: 'c', url: 'https://c.example.com' }),
            ),
            { platformForUrlAsync: spy },
        );
        reg.listForDisplay();
        reg.listForDisplay();
        reg.listForDisplay();
        expect(spy).not.toHaveBeenCalled();
    });

    it('reports bearer_source: "plain" for inline bearer entries', () => {
        const reg = new SiteRegistry(
            fileWith(siteEntry({ site_id: 'p', bearer: VALID_BEARER })),
        );
        const row = reg.listForDisplay()[0];
        expect(row?.bearer_source).toBe('plain');
    });

    it('reports bearer_source: "op" for 1Password-ref entries', () => {
        const entry: SiteEntryT = {
            site_id: 'op-site',
            url: 'https://op.example.com',
            platform: 'auto',
            bearer_ref: 'op://Private/yt-builder/bearer',
            is_default: false,
        };
        const reg = new SiteRegistry(fileWith(entry));
        const row = reg.listForDisplay()[0];
        expect(row?.bearer_source).toBe('op');
    });

    it('preserves label and is_default on rows', () => {
        const reg = new SiteRegistry(
            fileWith(
                siteEntry({
                    site_id: 'labelled',
                    label: 'Acme Production',
                    is_default: true,
                }),
            ),
        );
        const row = reg.listForDisplay()[0];
        expect(row?.label).toBe('Acme Production');
        expect(row?.is_default).toBe(true);
        expect(row?.platform_hint).toBe('auto');
    });
});
