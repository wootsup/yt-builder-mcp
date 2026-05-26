/**
 * W9 — `set-default` CLI subcommand.
 *
 * Flips the `is_default` flag on the target entry to `true`, clears
 * it on every other entry, and updates `default_site_id` to the
 * target. Fails with code `'UNKNOWN_SITE'` when the site_id is not
 * present.
 *
 * Idempotent: setting the already-default site as default is a
 * successful no-op (the file is still rewritten so any drift between
 * `default_site_id` and the per-entry `is_default` flag is healed in
 * the same pass).
 *
 * @license MIT
 */

import { loadSitesFile, saveSitesFile } from '../store.js';
import type { SiteEntryT, SitesFileT } from '../schema.js';

export interface SetDefaultOptions {
    /** Skip the confirmation prompt when true. CLI passes through from --yes. */
    readonly yes?: boolean;
}

export interface SetDefaultResult {
    readonly siteId: string;
    readonly path: string;
    /** site_id that was the default BEFORE this call (null when none). */
    readonly previousDefault: string | null;
    /** True when no write happened because the confirm hook rejected. */
    readonly cancelled: boolean;
}

export class SetDefaultError extends Error {
    constructor(public readonly code: string, message: string) {
        super(message);
        this.name = 'SetDefaultError';
    }
}

export interface SetDefaultDeps {
    readonly load: (path: string) => Promise<SitesFileT>;
    readonly save: (path: string, data: SitesFileT) => Promise<void>;
    /**
     * Confirm hook. Production dispatcher wires this to a y/N readline
     * prompt; tests pass `--yes` so this is never invoked. W12-R2A:
     * `set-default` is destructive in the routing sense — every
     * subsequent tool call routes to the new default — so the guard
     * matches the remove-site shape.
     */
    readonly confirm?: (message: string) => Promise<boolean>;
}

export async function setDefaultCommand(
    siteId: string,
    path: string,
    opts: SetDefaultOptions,
    deps: SetDefaultDeps,
): Promise<SetDefaultResult> {
    const file = await deps.load(path);
    if (!file.sites.some((s) => s.site_id === siteId)) {
        throw new SetDefaultError(
            'UNKNOWN_SITE',
            `Site "${siteId}" not found in ${path}. ` +
                (file.sites.length > 0
                    ? `Available: ${file.sites.map((s) => s.site_id).join(', ')}.`
                    : '(no sites configured)'),
        );
    }

    const previousDefault = file.default_site_id;

    // W12-R2A (A2-M1) — destructive-routing-op safeguard. Flipping the
    // default redirects every subsequent tool call that omits `site_id`
    // to a different connected site. That can silently land write-ops
    // on the wrong CMS install. Fail-closed: if `--yes` is not set, a
    // confirm hook is REQUIRED. Idempotent flip (already-default) is
    // still gated — the file is rewritten even on no-op, and an
    // operator confirming "yes" is the only reliable safe-belt.
    if (opts.yes !== true) {
        if (deps.confirm === undefined) {
            throw new SetDefaultError(
                'SET_DEFAULT_CONFIRM_REQUIRED',
                `Setting default to "${siteId}" requires --yes (no interactive `
                    + `confirm available in this context). Re-run with --yes to skip the prompt.`,
            );
        }
        const priorLabel = previousDefault !== null ? `"${previousDefault}"` : '(none)';
        const cont = await deps.confirm(
            `Switch default site from ${priorLabel} to "${siteId}" in ${path}? `
                + `This changes which site every subsequent tool call routes to by default.`,
        );
        if (!cont) {
            return { siteId, path, previousDefault, cancelled: true };
        }
    }

    const nextSites: SiteEntryT[] = file.sites.map((s) => ({
        ...s,
        is_default: s.site_id === siteId,
    }));

    const nextFile: SitesFileT = {
        schema_version: 1,
        default_site_id: siteId,
        sites: nextSites,
    };

    await deps.save(path, nextFile);

    return { siteId, path, previousDefault, cancelled: false };
}

export const DEFAULT_SET_DEFAULT_DEPS: SetDefaultDeps = {
    load: loadSitesFile,
    save: saveSitesFile,
};
