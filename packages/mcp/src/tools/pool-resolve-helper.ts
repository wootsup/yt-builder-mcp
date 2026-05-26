/**
 * W6.3 — shared helper for the per-handler `pool.resolve(site_id)`
 * pattern.
 *
 * Every tool handler does the same dance:
 *   1. Extract `site_id` from args (optional, undefined → default site).
 *   2. Call `pool.resolve(site_id)`.
 *   3. Translate `NoDefaultSiteError` / `UnknownSiteError` into a
 *      structured `errorResult` so the agent can self-recover.
 *   4. Hand back `{client, site}` for downstream use.
 *
 * Centralising the dance keeps the 24 handler bodies short and ensures
 * the error wording stays consistent across the tool surface.
 *
 * @license MIT
 */

import { RestError } from '../errors.js';
import {
    type ClientPool,
    NoDefaultSiteError,
    type PoolResolution,
    UnknownSiteError,
} from '../sites/client-pool.js';
import type { ResolvedSite } from '../sites/registry.js';
import type { RestClient } from '../client.js';

import { jsonResult, type ToolResult } from './tool-builder.js';

/**
 * Discriminated union returned by {@link resolveSiteOrError}: callers
 * check the `ok` boolean before destructuring. When `ok === false`,
 * the `error` field is a fully-formed `ToolResult` ready to be
 * returned from the handler verbatim (no further wrapping needed).
 *
 * The dual-channel shape lets handlers keep their flat top-level
 * control flow:
 *
 *   const r = await resolveSiteOrError(pool, args.site_id);
 *   if (!r.ok) return r.error;
 *   const { client, site } = r;
 *   // ... existing logic ...
 *   return withSiteMeta(result, site);
 */
export type SiteResolution =
    | { readonly ok: true; readonly client: PoolResolution['client']; readonly site: PoolResolution['site'] }
    | { readonly ok: false; readonly error: ToolResult };

/**
 * Resolve a site_id through the pool, translating the W4 typed errors
 * into structured `ToolResult` envelopes. Non-typed errors (genuine
 * fetch failures during the platform probe, secret-resolver crashes,
 * etc.) re-throw so the handler's outer try/catch can shape them with
 * domain-specific hints.
 */
export async function resolveSiteOrError(
    pool: ClientPool,
    siteId: string | undefined,
): Promise<SiteResolution> {
    try {
        const { client, site } = await pool.resolve(siteId);
        return { ok: true, client, site };
    } catch (e) {
        if (e instanceof NoDefaultSiteError) {
            // Custom payload to surface `code: 'no_default_site'` —
            // `errorResult` only forwards `code` for RestError, but
            // agents branch on `error.code === 'no_default_site'` to
            // route the user into the setup wizard.
            return {
                ok: false,
                error: jsonResult(
                    {
                        error: e.message,
                        code: e.code,
                        context: { site_id: siteId ?? null },
                        hint:
                            'No site_id supplied and no default site is configured. '
                            + 'Use yootheme_builder_sites_list to discover available IDs '
                            + 'and pass one as site_id, or run `yt-builder-mcp setup '
                            + 'add-site --default` to configure a default.',
                    },
                    { isError: true },
                ),
            };
        }
        if (e instanceof UnknownSiteError) {
            return {
                ok: false,
                error: jsonResult(
                    {
                        error: e.message,
                        code: e.code,
                        context: {
                            site_id: siteId ?? null,
                            available: [...e.available],
                        },
                        hint:
                            'The requested site_id is not in the registry. '
                            + 'Use yootheme_builder_sites_list to list configured IDs '
                            + 'and retry with one of them.',
                    },
                    { isError: true },
                ),
            };
        }
        // Unknown failure (probe crash, secret-resolver crash, etc.) —
        // re-throw so the handler's domain-specific catch can shape it
        // with the right hint (e.g. "verify YTB_MCP_SITE_URL …" for a
        // health probe, "verify the template_id …" for a page op).
        throw e;
    }
}

/**
 * W12-R2A (A4-F3 / A5-S2) — resolve a site + execute a handler-body with
 * a 1-shot 401 retry.
 *
 * Bearer-rotation use case: a site's bearer is revoked / rotated /
 * expired AFTER the pool cached a {@link RestClient} for it. The next
 * call returns HTTP 401. Without retry the agent has to be told
 * "restart your client". With retry we drop the cached client (so the
 * next `pool.resolve` re-runs `secretResolver.resolve`), then call the
 * handler-body again exactly once. If the second attempt also returns
 * 401, we surface a structured `bearer_invalid` error with an
 * actionable hint pointing at `sites_test` / sites.json.
 *
 * Scope: deliberately ONE retry. A second 401 means the bearer is
 * genuinely revoked at the source — looping forever wastes tokens and
 * masks the real problem.
 *
 * The wrapper handles {@link NoDefaultSiteError} / {@link
 * UnknownSiteError} via the same `resolveSiteOrError` envelope so
 * call-sites don't have to duplicate the structured-error wording.
 * Non-401 thrown errors propagate to the handler's outer try/catch
 * (or the SDK boundary) untouched — only HTTP 401 triggers the retry
 * / structured-error path.
 *
 * Usage (post-migration):
 *
 *   handler: async ({ site_id, ...rest }) =>
 *     withSiteRetry(pool, site_id, async (client, site) =>
 *       withSiteMeta(await handleX(makeDeps(client), rest), site)),
 */
export async function withSiteRetry(
    pool: ClientPool,
    siteId: string | undefined,
    fn: (client: RestClient, site: ResolvedSite) => Promise<ToolResult>,
): Promise<ToolResult> {
    const r1 = await resolveSiteOrError(pool, siteId);
    if (!r1.ok) return r1.error;

    try {
        return await fn(r1.client, r1.site);
    } catch (e) {
        if (!(e instanceof RestError) || e.status !== 401) {
            throw e;
        }
        // First attempt got 401 — invalidate the cached client so the
        // pool re-runs `secretResolver.resolve` (this picks up a rotated
        // Bearer from `op` / a freshly-edited sites.json) and try once
        // more. Use the resolved id from r1.site so the invalidation
        // works even when `siteId` was undefined (default-site case).
        pool.invalidate(r1.site.id);

        const r2 = await resolveSiteOrError(pool, siteId);
        if (!r2.ok) return r2.error;

        try {
            return await fn(r2.client, r2.site);
        } catch (e2) {
            if (e2 instanceof RestError && e2.status === 401) {
                // Second 401 — surface a structured error so the agent
                // can route the user to verification + sites.json edit
                // instead of silently failing.
                return jsonResult(
                    {
                        error: e2.message,
                        status: 401,
                        code: 'bearer_invalid',
                        context: { site_id: r2.site.id },
                        hint:
                            'Bearer rejected twice in a row (cached + freshly-resolved). '
                            + 'The bearer is either rotated or revoked. Run '
                            + '`npx -y @wootsup/yt-builder-mcp test-site '
                            + `${r2.site.id}\` to verify, then update sites.json `
                            + '(or the 1Password item if you used --token-ref).',
                    },
                    { isError: true },
                );
            }
            throw e2;
        }
    }
}
