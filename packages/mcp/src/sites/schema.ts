/**
 * W1 — Sites-Storage Foundation: Zod schemas for the on-disk sites file.
 *
 * Schema spec frozen by `2026-05-25-04-yt-builder-mcp-multi-site-implementation.md`
 * §W1. Do NOT rename fields without bumping `schema_version` and adding a
 * migration in W3 (registry).
 *
 * The on-disk format is JSON. Each entry represents one connected
 * YOOtheme-Pro-equipped site. A site MUST carry either an inline `bearer`
 * (plain text, for local-dev) OR an `op://` 1Password Secret Reference
 * (`bearer_ref`), never both — enforced via {@link SiteEntry}'s refine().
 *
 * @license MIT
 */

import { z } from 'zod';

/**
 * Allowed character set for a `site_id`. Letters, digits, underscore,
 * dash — no spaces, no path separators, no shell metacharacters.
 *
 * Surface: filename-safe, URL-path-safe, log-safe.
 */
export const SITE_ID_REGEX = /^[a-zA-Z0-9_-]+$/;

/**
 * 1Password Secret Reference shape (`op://<vault>/<item>/<field>` and
 * deeper). Letters, digits, underscore, slash, dot, dash only — same
 * defence-in-depth set the W2 SecretResolver will re-validate before
 * shelling out to `op`.
 *
 * W12-R1.3 (A2-L1): the previous pattern allowed `..` path segments
 * because `.` was in the character class. The `op` CLI does not honour
 * filesystem-style traversal but the input was reaching shell argv
 * verbatim, so a ref like `op://vault/../something` (or worse, encoded
 * in a hand-edited sites.json) muddied the trust boundary. The
 * negative lookahead `(?!.*\/\.\.\/)` rejects any ref containing a
 * `/../` segment without disallowing legitimate `.` in vault/item
 * names (e.g. `op://team.acme/...`).
 */
export const OP_REF_REGEX = /^op:\/\/(?!.*\/\.\.\/)[A-Za-z0-9_/.-]+$/;

/**
 * Bearer-token shape produced by `KeyService.php`:
 * `ytb_(live|test)_<payloadB64Url>.<sigB64Url>`. Re-validated here so a
 * hand-edited sites.json can never inject a token shape the server would
 * reject anyway.
 */
export const BEARER_REGEX = /^ytb_(live|test)_[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/;

/**
 * One site entry. Carries identity (`site_id`), reachability (`url` +
 * `platform`), credential (exactly one of `bearer`/`bearer_ref`), and
 * presentation metadata (`is_default`, `label`, `added_at`).
 */
export const SiteEntry = z.object({
    site_id: z.string().min(1).max(64).regex(SITE_ID_REGEX),
    url: z.string().url(),
    platform: z.enum(['wordpress', 'joomla', 'auto']).default('auto'),
    bearer: z.string().regex(BEARER_REGEX).optional(),
    bearer_ref: z.string().regex(OP_REF_REGEX).optional(),
    is_default: z.boolean().default(false),
    label: z.string().max(120).optional(),
    added_at: z.string().datetime().optional(),
}).refine(
    (s) => (s.bearer !== undefined) !== (s.bearer_ref !== undefined),
    { message: 'Exactly one of bearer or bearer_ref must be set' },
);

/**
 * The on-disk sites-file root. `schema_version` is a literal `1` for now;
 * future schema migrations bump it AND add a migration step in the W3
 * registry loader.
 */
export const SitesFile = z.object({
    schema_version: z.literal(1),
    default_site_id: z.string().regex(SITE_ID_REGEX).nullable(),
    sites: z.array(SiteEntry),
}).refine(
    (f) => f.sites.filter((s) => s.is_default).length <= 1,
    { message: 'At most one site can have is_default:true' },
);

export type SiteEntryT = z.infer<typeof SiteEntry>;
export type SitesFileT = z.infer<typeof SitesFile>;
