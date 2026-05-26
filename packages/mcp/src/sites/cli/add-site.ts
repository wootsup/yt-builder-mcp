/**
 * W9 — `add-site` CLI subcommand.
 *
 * Adds (or, with `--yes`, overwrites) a single site entry in the
 * on-disk `sites.json` registry. Pure file-IO surface: loads, mutates
 * in memory, and atomic-saves through {@link saveSitesFile}. NEVER
 * touches the network — the optional connectivity probe lives in
 * `test-site` so callers can compose the two.
 *
 * Design choices (per plan §W9):
 *  - **Mutual-exclusion `--token` vs `--token-ref`.** Exactly one
 *    credential field must be set; reuses the W1 SiteEntry refine() as
 *    the on-disk guard but also pre-checks here for an immediate CLI
 *    error rather than a Zod surprise at save time.
 *  - **Auto-default on first site.** If `sites.json` is empty (or did
 *    not exist), the first added entry gets `is_default: true` AND
 *    `default_site_id` is updated, regardless of whether `--default`
 *    was passed. The plan calls this out explicitly so users don't
 *    end up with a registry that has sites but no default.
 *  - **Idempotent + safe overwrite.** Re-adding the same `site_id`
 *    fails with exit code 2 unless `--yes` is passed; with `--yes` the
 *    existing entry is replaced AND its `is_default` flag is preserved
 *    so an overwrite of the default site stays the default.
 *  - **Defence-in-depth platform default.** When `--platform` is
 *    omitted the entry stores `'auto'`, which the W3 registry resolves
 *    at first use via the runtime probe.
 *
 * @license MIT
 */

import { loadSitesFile, saveSitesFile } from '../store.js';
import {
    BEARER_REGEX,
    OP_REF_REGEX,
    SITE_ID_REGEX,
    type SiteEntryT,
    type SitesFileT,
} from '../schema.js';

/**
 * Parsed shape consumed by {@link addSiteCommand}. The dispatcher
 * (`setup-cli.ts:runCli`) translates argv flags into this object and
 * the public test surface drives it directly so flag parsing is
 * exercised separately from the file-IO and registry-mutation logic.
 */
export interface AddSiteArgs {
    readonly url: string;
    /** Inline plain bearer; mutually exclusive with `tokenRef`. */
    readonly token?: string;
    /** 1Password Secret Reference; mutually exclusive with `token`. */
    readonly tokenRef?: string;
    /** Optional explicit platform hint; defaults to `'auto'`. */
    readonly platform?: 'auto' | 'wordpress' | 'joomla';
    /** Optional human-readable label (≤120 chars). */
    readonly label?: string;
    /** When true, mark the new site as the default. Ignored if it's
     *  the first site (auto-default applies). */
    readonly default?: boolean;
    /** Override the site_id slug; defaults to `'default'`. */
    readonly siteId?: string;
    /** Skip "this site_id exists" guard and overwrite instead. */
    readonly yes?: boolean;
}

/**
 * Outcome of a successful add. The CLI dispatcher uses this to render
 * the "Site '<id>' added (default)." line + the restart hint. Returned
 * as a value (not stdout-side) so tests can assert without parsing
 * captured stdout.
 */
export interface AddSiteResult {
    /** site_id that was written. */
    readonly siteId: string;
    /** Path of the sites.json that was written. */
    readonly path: string;
    /** True when this site was first AND auto-promoted to default. */
    readonly becameDefault: boolean;
    /** True when an existing entry with the same site_id was replaced. */
    readonly overwritten: boolean;
    /** True when --default flag was honoured (and not just auto-default). */
    readonly defaultRequested: boolean;
}

/**
 * Domain-tagged CLI failure. The dispatcher maps these to exit codes
 * (typically 2 for "input invalid" + a friendly stderr line). Carries
 * a `code` so tests / callers can branch without substring matching.
 */
export class AddSiteError extends Error {
    constructor(public readonly code: string, message: string) {
        super(message);
        this.name = 'AddSiteError';
    }
}

/**
 * Build a new {@link SiteEntryT} from validated CLI args. Pure +
 * deterministic so tests can drive it directly. `added_at` uses the
 * supplied `now` so snapshot tests don't drift on clock.
 */
function buildEntry(
    args: AddSiteArgs,
    siteId: string,
    isDefault: boolean,
    now: () => string,
): SiteEntryT {
    const base = {
        site_id: siteId,
        url: args.url,
        platform: args.platform ?? 'auto',
        is_default: isDefault,
        added_at: now(),
        ...(args.label !== undefined && args.label.length > 0
            ? { label: args.label }
            : {}),
    } as const;

    if (args.token !== undefined && args.token.length > 0) {
        return { ...base, bearer: args.token } as SiteEntryT;
    }
    // token-ref guaranteed by validateArgs.
    return { ...base, bearer_ref: args.tokenRef! } as SiteEntryT;
}

/**
 * Validate the CLI arg combination before any file IO. Throws
 * {@link AddSiteError} with a stable `code` on the first violation.
 */
function validateArgs(args: AddSiteArgs): void {
    if (args.url === undefined || args.url.length === 0) {
        throw new AddSiteError('URL_REQUIRED', 'Flag --url is required.');
    }
    try {
        void new URL(args.url);
    } catch {
        throw new AddSiteError(
            'URL_INVALID',
            `--url is not a valid web address: ${args.url}`,
        );
    }

    const hasToken = args.token !== undefined && args.token.length > 0;
    const hasRef = args.tokenRef !== undefined && args.tokenRef.length > 0;

    if (!hasToken && !hasRef) {
        throw new AddSiteError(
            'BEARER_REQUIRED',
            'Either --token <bearer> or --token-ref <op://...> is required.',
        );
    }
    if (hasToken && hasRef) {
        throw new AddSiteError(
            'BEARER_MUTUAL_EXCLUSION',
            '--token and --token-ref are mutually exclusive. Pick one.',
        );
    }
    if (hasToken && !BEARER_REGEX.test(args.token!)) {
        throw new AddSiteError(
            'BEARER_INVALID',
            '--token does not match the expected Bearer shape ' +
                '(ytb_(live|test)_<payload>.<signature>).',
        );
    }
    if (hasRef && !OP_REF_REGEX.test(args.tokenRef!)) {
        throw new AddSiteError(
            'TOKEN_REF_INVALID',
            '--token-ref does not match the expected 1Password ref shape ' +
                '(op://<vault>/<item>/<field>).',
        );
    }

    const siteId = args.siteId ?? 'default';
    if (!SITE_ID_REGEX.test(siteId)) {
        throw new AddSiteError(
            'SITE_ID_INVALID',
            `--site-id "${siteId}" must match letters / digits / dash / underscore only.`,
        );
    }

    if (
        args.platform !== undefined
        && args.platform !== 'auto'
        && args.platform !== 'wordpress'
        && args.platform !== 'joomla'
    ) {
        throw new AddSiteError(
            'PLATFORM_INVALID',
            `--platform must be one of: auto, wordpress, joomla (got "${String(args.platform)}").`,
        );
    }

    if (args.label !== undefined && args.label.length > 120) {
        throw new AddSiteError(
            'LABEL_TOO_LONG',
            `--label exceeds 120 characters (got ${String(args.label.length)}).`,
        );
    }
}

/**
 * Test/IO injection seam. Production callers use the default
 * implementations imported above; tests inject in-memory store
 * helpers and a frozen `now()`.
 */
export interface AddSiteDeps {
    readonly load: (path: string) => Promise<SitesFileT>;
    readonly save: (path: string, data: SitesFileT) => Promise<void>;
    readonly now?: () => string;
}

/**
 * Add (or overwrite-with-`--yes`) a single site entry. See file header
 * for the auto-default / mutual-exclusion / idempotency contract.
 *
 * @throws {AddSiteError} on any input-validation failure or when the
 *   site_id already exists and `--yes` was not passed.
 */
export async function addSiteCommand(
    args: AddSiteArgs,
    path: string,
    deps: AddSiteDeps,
): Promise<AddSiteResult> {
    validateArgs(args);

    const siteId = args.siteId ?? 'default';
    const now = deps.now ?? ((): string => new Date().toISOString());

    const file = await deps.load(path);
    const existingIndex = file.sites.findIndex((s) => s.site_id === siteId);
    const exists = existingIndex >= 0;

    if (exists && args.yes !== true) {
        throw new AddSiteError(
            'SITE_ID_EXISTS',
            `Site "${siteId}" already exists in ${path}. ` +
                `Pass --yes to overwrite or pick a different --site-id.`,
        );
    }

    // Auto-default policy:
    //  - first ever site → always default (regardless of --default).
    //  - subsequent sites with --default → flip is_default + update
    //    file.default_site_id.
    //  - overwrite of an existing site → preserve its prior is_default
    //    UNLESS --default was explicitly passed (then we promote it).
    const isFirstEver = !exists && file.sites.length === 0;
    const wantsDefault = args.default === true || isFirstEver;

    let isDefault = wantsDefault;
    if (exists && !wantsDefault) {
        const prior = file.sites[existingIndex];
        if (prior !== undefined) isDefault = prior.is_default;
    }

    const entry = buildEntry(args, siteId, isDefault, now);

    // Build the new sites array — replace in place on overwrite to
    // preserve insertion order, append otherwise.
    const nextSites: SiteEntryT[] = exists
        ? file.sites.map((s, i) => (i === existingIndex ? entry : s))
        : [...file.sites, entry];

    // When this entry becomes the default, clear any other site's
    // is_default flag so the W1 invariant (at most one default) holds.
    const sitesWithDefaultFixed: SiteEntryT[] = isDefault
        ? nextSites.map((s) =>
              s.site_id === siteId ? s : { ...s, is_default: false },
          )
        : nextSites;

    const defaultSiteId: string | null = isDefault
        ? siteId
        : file.default_site_id;

    const nextFile: SitesFileT = {
        schema_version: 1,
        default_site_id: defaultSiteId,
        sites: sitesWithDefaultFixed,
    };

    await deps.save(path, nextFile);

    return {
        siteId,
        path,
        becameDefault: isFirstEver,
        overwritten: exists,
        defaultRequested: args.default === true,
    };
}

/**
 * Default IO deps wired to the real store. Exported so the dispatcher
 * + the W9 wizard-internal call-site can both share them without
 * duplicating the import surface.
 */
export const DEFAULT_ADD_SITE_DEPS: AddSiteDeps = {
    // W12-R1.3 (A1-N-01): saveSitesFile is already a static import
    // above; switch load to the same static binding so the runtime
    // dependency graph matches the import graph and bundlers/tree-
    // shakers don't have to chase a dynamic import for a path that's
    // always reached.
    load: loadSitesFile,
    save: saveSitesFile,
};
