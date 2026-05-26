/**
 * W9 — `remove-site` CLI subcommand.
 *
 * Removes ONE site entry by `site_id`. Auto-promotion policy (per
 * plan §C.3):
 *  - Removing a non-default site → plain delete, default_site_id and
 *    the other entries' is_default flags are untouched.
 *  - Removing the default AND there is at least one other site →
 *    promote the next-in-insertion-order site to default
 *    (`is_default:true` + `default_site_id` update).
 *  - Removing the only site → `default_site_id: null`, empty array.
 *
 * Returns a structured {@link RemoveSiteResult} so the dispatcher can
 * render the "(default → promoted X)" hint and tests can assert
 * promotion behaviour without parsing stdout.
 *
 * `--yes` skips the confirmation hook supplied by the dispatcher.
 * Production callers always wire a confirm hook; tests pass yes=true
 * to bypass it.
 *
 * @license MIT
 */

import { saveSitesFile, loadSitesFile } from '../store.js';
import type { SiteEntryT, SitesFileT } from '../schema.js';

export interface RemoveSiteOptions {
    /** Skip confirmation when true. CLI passes through from --yes. */
    readonly yes?: boolean;
}

export interface RemoveSiteResult {
    readonly siteId: string;
    /** Path of the sites.json that was written. */
    readonly path: string;
    /** site_id that was promoted to default (only when removing default + ≥1 other). */
    readonly promoted?: string;
    /** True when the registry became empty after the remove. */
    readonly nowEmpty: boolean;
    /** True when no write happened because --yes was missing on confirm. */
    readonly cancelled: boolean;
}

export class RemoveSiteError extends Error {
    constructor(public readonly code: string, message: string) {
        super(message);
        this.name = 'RemoveSiteError';
    }
}

/** Test/IO injection seam. */
export interface RemoveSiteDeps {
    readonly load: (path: string) => Promise<SitesFileT>;
    readonly save: (path: string, data: SitesFileT) => Promise<void>;
    /**
     * Confirm hook. Production dispatcher wires this to a clack
     * prompt; tests pass `--yes` so this is never invoked.
     */
    readonly confirm?: (message: string) => Promise<boolean>;
}

/**
 * Remove a site by `site_id`. See file header for the auto-promotion
 * policy. Throws {@link RemoveSiteError} with code `'UNKNOWN_SITE'`
 * when `siteId` is not in the registry.
 */
export async function removeSiteCommand(
    siteId: string,
    path: string,
    opts: RemoveSiteOptions,
    deps: RemoveSiteDeps,
): Promise<RemoveSiteResult> {
    const file = await deps.load(path);
    const index = file.sites.findIndex((s) => s.site_id === siteId);
    if (index < 0) {
        throw new RemoveSiteError(
            'UNKNOWN_SITE',
            `Site "${siteId}" not found in ${path}. ` +
                (file.sites.length > 0
                    ? `Available: ${file.sites.map((s) => s.site_id).join(', ')}.`
                    : '(no sites configured)'),
        );
    }

    // W12-R2A (A2-H1) — fail-closed guard. Round 1's check silently
    // proceeded when neither `--yes` NOR a confirm hook was supplied
    // (deps.confirm === undefined). That made the destructive op a
    // no-prompt delete in any non-TTY context (CI, piped stdin, MCP
    // server stdio). Tighten to: if `--yes` is not set, a confirm hook
    // is REQUIRED — otherwise throw a typed error so the dispatcher
    // can surface "pass --yes" with a non-zero exit code.
    if (opts.yes !== true) {
        if (deps.confirm === undefined) {
            throw new RemoveSiteError(
                'CONFIRM_REQUIRED',
                `Remove of site "${siteId}" requires --yes (no interactive `
                    + `confirm available in this context). Re-run with --yes to skip the prompt.`,
            );
        }
        const cont = await deps.confirm(
            `Remove site "${siteId}" from ${path}?`,
        );
        if (!cont) {
            return {
                siteId,
                path,
                nowEmpty: false,
                cancelled: true,
            };
        }
    }

    const wasDefault =
        file.sites[index]?.is_default === true
        || file.default_site_id === siteId;

    // Drop the target entry, preserving insertion order.
    const remaining: SiteEntryT[] = file.sites.filter((_, i) => i !== index);

    let promoted: string | undefined;
    let nextSites: SiteEntryT[] = remaining;
    let nextDefaultId: string | null = file.default_site_id;

    if (wasDefault) {
        if (remaining.length === 0) {
            nextDefaultId = null;
        } else {
            const promote = remaining[0];
            if (promote === undefined) {
                // unreachable due to length check, but keeps TS strict.
                nextDefaultId = null;
            } else {
                promoted = promote.site_id;
                nextDefaultId = promote.site_id;
                nextSites = remaining.map((s, i) => ({
                    ...s,
                    is_default: i === 0,
                }));
            }
        }
    } else {
        // Make sure default_site_id still points at a present entry.
        if (
            nextDefaultId !== null
            && !remaining.some((s) => s.site_id === nextDefaultId)
        ) {
            nextDefaultId = null;
        }
    }

    const nextFile: SitesFileT = {
        schema_version: 1,
        default_site_id: nextDefaultId,
        sites: nextSites,
    };

    await deps.save(path, nextFile);

    return {
        siteId,
        path,
        ...(promoted !== undefined ? { promoted } : {}),
        nowEmpty: nextSites.length === 0,
        cancelled: false,
    };
}

export const DEFAULT_REMOVE_SITE_DEPS: RemoveSiteDeps = {
    load: loadSitesFile,
    save: saveSitesFile,
};
