/**
 * W3 — Site Registry + Platform-Cache.
 *
 * Wraps a parsed {@link SitesFileT} and provides:
 *  - O(1) {@link SiteRegistry.get|get(site_id)} lookup.
 *  - Insertion-order {@link SiteRegistry.listIds|listIds()} for stable
 *    UX in the W7 `sites_list` tool.
 *  - {@link SiteRegistry.defaultSiteId|defaultSiteId()} that respects
 *    the explicit `default_site_id` first, then falls back to the
 *    flagged `is_default:true` entry, then `null`. Auto-promotion of a
 *    lone site to default is intentionally NOT done here — that policy
 *    belongs to the W4 caller layer.
 *  - {@link SiteRegistry.platformFor|platformFor(site_id)} that
 *    memoises the resolved {@link Platform} via a
 *    `Map<string, Promise<Platform>>`. Storing the **Promise** (not the
 *    resolved value) is load-bearing: N parallel first-time callers
 *    share the SAME probe (`platformForUrlAsync`) instead of racing N
 *    probes against the same site origin. The plan §W3 calls this out
 *    explicitly ("Promise-cache, not just result-cache").
 *  - {@link SiteRegistry.listForDisplay|listForDisplay()} that surfaces
 *    a tabular view for the upcoming W7 sites_list tool. The
 *    `platform_resolved` field is intentionally only populated AFTER
 *    {@link SiteRegistry.platformFor|platformFor()} has been called for
 *    that site_id — listForDisplay() must NEVER trigger a probe on its
 *    own (that would defeat the "no surprise network calls in
 *    list-tools" contract).
 *
 * @license MIT
 */

import {
    type Platform,
    platformForUrlAsync,
    type PlatformKind,
} from '../platform/index.js';

import type { SiteEntryT, SitesFileT } from './schema.js';

/**
 * Display-side row used by the W7 `sites_list` tool. `bearer_source` is
 * derived from whether the underlying {@link SiteEntryT} has `bearer`
 * (plain) or `bearer_ref` (1Password); the resolver itself is not
 * needed to compute this hint, so we can render the list without ever
 * invoking the 1Password CLI.
 *
 * `platform_resolved` is only set once {@link SiteRegistry.platformFor}
 * has run for that site_id at least once — it is a "peek" into the
 * memo cache, never a trigger for a new probe.
 */
export interface SiteRowT {
    site_id: string;
    url: string;
    platform_resolved?: 'wordpress' | 'joomla';
    platform_hint: 'wordpress' | 'joomla' | 'auto';
    is_default: boolean;
    label?: string;
    bearer_source: 'plain' | 'op';
}

/**
 * Caller-side projection of a registry entry, with the `Platform`
 * already resolved and the bearer-source flagged for downstream logs.
 * Reserved for future call-sites that want the fully-resolved bundle
 * in a single object — the tool builder layer will populate this.
 */
export interface ResolvedSite {
    readonly id: string;
    readonly url: string;
    readonly platform: Platform;
    readonly isDefault: boolean;
    readonly label?: string;
    readonly bearerSource: 'plain' | 'op';
}

/**
 * Optional injection point for tests. Production callers should never
 * pass this — they get the default `platformForUrlAsync` from the
 * platform barrel, which itself does the runtime probe.
 */
export interface SiteRegistryOptions {
    readonly platformForUrlAsync?: (
        url: string,
        hint?: PlatformKind,
    ) => Promise<Platform>;
}

/**
 * In-memory registry around a parsed sites file. NEVER mutates the
 * input `file`; all "writes" go through {@link saveSitesFile} on the
 * store side, which produces a fresh `SitesFileT` to wrap in a new
 * registry instance.
 */
export class SiteRegistry {
    /**
     * Promise-memo for platform resolution. The KEY is the site_id;
     * the VALUE is the in-flight or already-resolved Promise. Parallel
     * callers for the same site_id all receive the same promise and
     * therefore share the one probe.
     */
    private readonly platformCache = new Map<string, Promise<Platform>>();

    /**
     * Sync-side mirror of `platformCache` that records the resolved
     * `PlatformKind` once the probe completes. Powers
     * {@link listForDisplay} without forcing it to await or to trigger
     * a probe. Entries land here only AFTER the probe resolves
     * successfully; rejected probes leave the entry absent.
     */
    private readonly resolvedKinds = new Map<string, PlatformKind>();

    private readonly probe: (
        url: string,
        hint?: PlatformKind,
    ) => Promise<Platform>;

    constructor(
        private readonly file: SitesFileT,
        opts: SiteRegistryOptions = {},
    ) {
        this.probe = opts.platformForUrlAsync ?? platformForUrlAsync;
    }

    /**
     * Returns the configured default site_id. Resolution order:
     *   1. `file.default_site_id` if set AND points at an entry that
     *      actually exists in `file.sites`.
     *   2. The first `is_default:true` entry (W1 schema guarantees at
     *      most one).
     *   3. `null` — no default; the W4 caller layer decides whether
     *      to auto-promote a lone site.
     */
    defaultSiteId(): string | null {
        const explicit = this.file.default_site_id;
        if (
            explicit !== null
            && this.file.sites.some((s) => s.site_id === explicit)
        ) {
            return explicit;
        }
        const flagged = this.file.sites.find((s) => s.is_default);
        return flagged ? flagged.site_id : null;
    }

    /**
     * site_id list in insertion order (the order they appear in
     * `file.sites`). Stable across registry rebuilds because the
     * store preserves array order on save.
     */
    listIds(): readonly string[] {
        return this.file.sites.map((s) => s.site_id);
    }

    /**
     * Returns the raw entry for a site_id, or `null` when unknown.
     * O(n) on the entry count — fine for the realistic upper bound
     * (~100 sites per customer).
     */
    get(siteId: string): SiteEntryT | null {
        return this.file.sites.find((s) => s.site_id === siteId) ?? null;
    }

    /**
     * Resolves the {@link Platform} for `siteId` and memoises the
     * Promise. Subsequent calls (including ones still in-flight)
     * return the same Promise — exactly one probe per site_id, even
     * across N parallel callers.
     *
     * @throws Error when the site_id is unknown. Callers should
     *   pre-check with {@link get}.
     */
    async platformFor(siteId: string): Promise<Platform> {
        const cached = this.platformCache.get(siteId);
        if (cached !== undefined) return cached;

        const entry = this.get(siteId);
        if (entry === null) {
            throw new Error(`SiteRegistry: unknown site_id "${siteId}"`);
        }

        // Build the promise synchronously and insert into the cache
        // BEFORE awaiting anything — this is what makes parallel
        // callers share the probe. If `entry.platform` is a concrete
        // hint we still wrap in Promise.resolve so the cache type is
        // uniform.
        const probePromise: Promise<Platform>
            = entry.platform === 'auto'
                ? this.probe(entry.url)
                : this.probe(entry.url, entry.platform);

        // Tee off a side-channel that records the resolved kind into
        // the sync mirror map so `listForDisplay()` can surface it
        // without awaiting. We swallow rejections here because the
        // original caller (the awaiter of `platformFor`) owns the
        // error contract; the mirror map simply stays empty on
        // failure.
        const memoised: Promise<Platform> = probePromise.then(
            (platform) => {
                this.resolvedKinds.set(siteId, platform.kind);
                return platform;
            },
            (err) => {
                // Re-throw so awaiters see the failure, but do not
                // populate the sync mirror.
                throw err instanceof Error ? err : new Error(String(err));
            },
        );

        this.platformCache.set(siteId, memoised);
        return memoised;
    }

    /**
     * Map view of the registry for the W7 `sites_list` tool. NEVER
     * triggers a probe: `platform_resolved` is undefined for any site
     * whose `platformFor()` has not yet been called.
     *
     * `bearer_source` is derived from the W1 schema invariant
     * (exactly one of `bearer`/`bearer_ref` is set) so listing never
     * needs to consult the W2 secret-resolver.
     */
    listForDisplay(): readonly SiteRowT[] {
        return this.file.sites.map((entry): SiteRowT => {
            const resolved = this.resolvedKinds.get(entry.site_id);
            const bearerSource: 'plain' | 'op'
                = entry.bearer !== undefined ? 'plain' : 'op';

            const row: SiteRowT = {
                site_id: entry.site_id,
                url: entry.url,
                platform_hint: entry.platform,
                is_default: entry.is_default,
                bearer_source: bearerSource,
            };
            if (resolved !== undefined) row.platform_resolved = resolved;
            if (entry.label !== undefined) row.label = entry.label;
            return row;
        });
    }
}
