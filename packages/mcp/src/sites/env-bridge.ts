/**
 * W3 — Env-Bridge: synthesise a one-site {@link SitesFileT} from the
 * legacy single-site env-vars when no `sites.json` exists on disk.
 *
 * Why: pre-multi-site customers configured the MCP server via
 * `YTB_MCP_SITE_URL` (or the older `YTB_MCP_WP_URL`) + `YTB_MCP_BEARER_TOKEN`.
 * Multi-site infra must NEVER break that experience. When no
 * `sites.json` is found, the boot path consults this bridge; if both
 * required env-vars are present we materialise an in-memory one-site
 * file with `site_id: 'default'` and `is_default: true`. The rest of
 * the system (registry, resolver, tool builder) then operates on
 * `SitesFileT` uniformly — there is no second code path for the
 * legacy single-site case.
 *
 * Returns `null` when either required env-var is missing or empty so
 * the caller can fall through to "no sites configured" UX.
 *
 * W12-R1.1 consolidation:
 *   - Env-var names are imported from `../auth.js` (single source of
 *     truth) instead of duplicated as string-literals.
 *   - Raw URL + bearer are `.trim()`ed; whitespace-only values are
 *     treated as missing (return null), matching `loadConfig` behaviour.
 *   - Unknown `YTB_MCP_PLATFORM` values throw `ConfigError` so a typo
 *     (`woodpress`, `joomlaa`) surfaces immediately instead of silently
 *     downgrading to `'auto'` and confusing the runtime probe.
 *
 * @license MIT
 */

import {
    ENV_BEARER,
    ENV_PLATFORM,
    ENV_SITE_URL,
    ENV_WP_URL,
} from '../auth.js';
import { ConfigError } from '../errors.js';

import type { SitesFileT } from './schema.js';

/**
 * Trim exactly one trailing `/` from a string. `""` stays `""`. A
 * string with two trailing slashes keeps the inner one (`"a//"` →
 * `"a/"`), preserving the operator's apparent intent — single-slash
 * normalisation is a paste-error fix, not an aggressive cleanup.
 */
export function stripTrailingSlash(s: string): string {
    if (s.length === 0) return s;
    return s.endsWith('/') ? s.slice(0, -1) : s;
}

/**
 * Build a one-site {@link SitesFileT} from `env`, or return `null` if
 * neither URL nor bearer is configured. Order of URL precedence:
 *   1. {@link ENV_SITE_URL} (new, multi-site-aware name)
 *   2. {@link ENV_WP_URL}   (legacy single-site name, still honoured)
 *
 * The platform hint comes from {@link ENV_PLATFORM} when set to
 * `wordpress` / `joomla` / `auto`. An empty / unset value falls back to
 * `'auto'` so the runtime probe (see `platformForUrlAsync`) decides.
 * Any OTHER non-empty value throws `ConfigError` — a typo like
 * `woodpress` is loud-failed at boot rather than silently degraded.
 *
 * Note: this function does NOT validate the URL or bearer-token shape
 * beyond non-empty trimming — the W1 {@link SitesFile} schema does that
 * the moment the caller hands the synthesised file to a registry
 * constructor.
 */
export function synthesiseFromEnv(
    env: NodeJS.ProcessEnv,
): SitesFileT | null {
    const rawUrl = (env[ENV_SITE_URL] ?? env[ENV_WP_URL] ?? '').trim();
    const bearer = (env[ENV_BEARER] ?? '').trim();
    if (rawUrl === '' || bearer === '') return null;

    const rawPlatform = env[ENV_PLATFORM];
    const platformHint = rawPlatform === undefined ? '' : rawPlatform.trim();
    let platform: 'wordpress' | 'joomla' | 'auto';
    if (platformHint === '') {
        platform = 'auto';
    } else if (
        platformHint === 'wordpress'
        || platformHint === 'joomla'
        || platformHint === 'auto'
    ) {
        platform = platformHint;
    } else {
        throw new ConfigError(
            `Invalid ${ENV_PLATFORM}: "${platformHint}". `
            + 'Must be one of: wordpress, joomla, auto (or unset for auto-detect).',
        );
    }

    return {
        schema_version: 1,
        default_site_id: 'default',
        sites: [
            {
                site_id: 'default',
                url: stripTrailingSlash(rawUrl),
                platform,
                bearer,
                is_default: true,
                label: 'Default site (env)',
            },
        ],
    };
}
