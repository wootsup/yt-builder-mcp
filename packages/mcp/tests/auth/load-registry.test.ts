/**
 * W10 — `auth.loadRegistry()` boot-time resolution tests.
 *
 * Verifies the canonical resolution order documented in
 * `src/auth.ts`:
 *   1. YTB_MCP_SITES_FILE (preferred)
 *   2. legacy env-bridge (YTB_MCP_SITE_URL + YTB_MCP_BEARER_TOKEN)
 *   3. ConfigError with the three setup options
 *
 * @license MIT
 */

import { chmodSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import {
    ENV_BEARER,
    ENV_PLATFORM,
    ENV_SITE_URL,
    ENV_SITES_FILE,
    loadRegistry,
} from '../../src/auth.js';
import { ConfigError } from '../../src/errors.js';
import { SiteRegistry } from '../../src/sites/registry.js';
import { SitesFileError } from '../../src/sites/store.js';
import type { SitesFileT } from '../../src/sites/schema.js';

const VALID_BEARER =
    'ytb_live_eyJraWQiOiJ0ZXN0LWtleSIsInNjb3BlIjoid3JpdGUifQ.abc123_xyz-def';

function makeSitesFile(sites: SitesFileT['sites']): SitesFileT {
    return {
        schema_version: 1,
        default_site_id: sites[0]?.site_id ?? null,
        sites,
    };
}

describe('loadRegistry', () => {
    let scratchDir: string;

    beforeEach(() => {
        scratchDir = mkdtempSync(join(tmpdir(), 'ytb-loadreg-'));
    });

    afterEach(() => {
        // Best-effort cleanup; chmod first to defeat 0600 mode.
        try {
            chmodSync(scratchDir, 0o700);
        } catch {
            /* swallow */
        }
        rmSync(scratchDir, { recursive: true, force: true });
    });

    describe('(1) sites-file path', () => {
        it('returns SiteRegistry built from a valid sites.json when SITES_FILE points at it', async () => {
            const sitesPath = join(scratchDir, 'sites.json');
            const file = makeSitesFile([
                {
                    site_id: 'wp-acme',
                    url: 'https://acme.example.com',
                    platform: 'auto',
                    bearer: VALID_BEARER,
                    is_default: true,
                    label: 'Acme WP',
                },
            ]);
            writeFileSync(sitesPath, JSON.stringify(file), 'utf-8');

            const registry = await loadRegistry({
                env: { [ENV_SITES_FILE]: sitesPath },
            });

            expect(registry).toBeInstanceOf(SiteRegistry);
            expect(registry.listIds()).toEqual(['wp-acme']);
            expect(registry.defaultSiteId()).toBe('wp-acme');
            const entry = registry.get('wp-acme');
            expect(entry).not.toBeNull();
            expect(entry?.url).toBe('https://acme.example.com');
        });

        it('loads a multi-site registry preserving insertion order + the explicit default_site_id', async () => {
            const sitesPath = join(scratchDir, 'sites.json');
            const file: SitesFileT = {
                schema_version: 1,
                default_site_id: 'jm-beta',
                sites: [
                    {
                        site_id: 'wp-acme',
                        url: 'https://acme.example.com',
                        platform: 'wordpress',
                        bearer: VALID_BEARER,
                        is_default: false,
                    },
                    {
                        site_id: 'jm-beta',
                        url: 'https://beta.example.com/joomla',
                        platform: 'joomla',
                        bearer: VALID_BEARER,
                        is_default: true,
                    },
                ],
            };
            writeFileSync(sitesPath, JSON.stringify(file), 'utf-8');

            const registry = await loadRegistry({
                env: { [ENV_SITES_FILE]: sitesPath },
            });
            expect(registry.listIds()).toEqual(['wp-acme', 'jm-beta']);
            expect(registry.defaultSiteId()).toBe('jm-beta');
        });

        it('relays a SitesFileError when SITES_FILE points at malformed JSON', async () => {
            const sitesPath = join(scratchDir, 'broken.json');
            writeFileSync(sitesPath, '{ not valid json', 'utf-8');

            await expect(
                loadRegistry({ env: { [ENV_SITES_FILE]: sitesPath } }),
            ).rejects.toBeInstanceOf(SitesFileError);
        });

        it('falls through to env-bridge when SITES_FILE points at a path that does not yet exist (empty seed)', async () => {
            const sitesPath = join(scratchDir, 'will-be-created.json');
            const registry = await loadRegistry({
                env: {
                    [ENV_SITES_FILE]: sitesPath,
                    [ENV_SITE_URL]: 'https://acme.example.com',
                    [ENV_BEARER]: VALID_BEARER,
                },
            });
            // env-bridge synthesised a one-site registry under
            // site_id="default" — the empty-seed sites-file deferred.
            expect(registry.listIds()).toEqual(['default']);
            expect(registry.get('default')?.url).toBe(
                'https://acme.example.com',
            );
        });

        it('prefers sites-file when BOTH SITES_FILE (non-empty) and legacy env-vars are set (precedence)', async () => {
            const sitesPath = join(scratchDir, 'sites.json');
            const file = makeSitesFile([
                {
                    site_id: 'from-file',
                    url: 'https://from-file.example.com',
                    platform: 'auto',
                    bearer: VALID_BEARER,
                    is_default: true,
                },
            ]);
            writeFileSync(sitesPath, JSON.stringify(file), 'utf-8');

            const registry = await loadRegistry({
                env: {
                    [ENV_SITES_FILE]: sitesPath,
                    // Legacy env-vars would otherwise produce a
                    // 'default' site; verify the file wins.
                    [ENV_SITE_URL]: 'https://from-env.example.com',
                    [ENV_BEARER]: VALID_BEARER,
                },
            });
            expect(registry.listIds()).toEqual(['from-file']);
            expect(registry.get('from-file')?.url).toBe(
                'https://from-file.example.com',
            );
        });
    });

    describe('(2) legacy env-bridge path', () => {
        it('synthesises a one-site registry when SITE_URL + BEARER are set and SITES_FILE is empty', async () => {
            const registry = await loadRegistry({
                env: {
                    [ENV_SITE_URL]: 'https://example.com',
                    [ENV_BEARER]: VALID_BEARER,
                },
            });
            expect(registry.listIds()).toEqual(['default']);
            expect(registry.defaultSiteId()).toBe('default');
            const entry = registry.get('default');
            expect(entry?.is_default).toBe(true);
            expect(entry?.platform).toBe('auto');
        });

        it('honours an explicit PLATFORM hint when synthesising', async () => {
            const registry = await loadRegistry({
                env: {
                    [ENV_SITE_URL]: 'https://example.com/joomla',
                    [ENV_BEARER]: VALID_BEARER,
                    [ENV_PLATFORM]: 'joomla',
                },
            });
            expect(registry.get('default')?.platform).toBe('joomla');
        });
    });

    describe('(3) fail-fast path', () => {
        it('throws ConfigError when SITES_FILE is unset AND only SITE_URL is configured', async () => {
            await expect(
                loadRegistry({
                    env: { [ENV_SITE_URL]: 'https://example.com' },
                }),
            ).rejects.toBeInstanceOf(ConfigError);
        });

        it('throws ConfigError when SITES_FILE is unset AND only BEARER is configured', async () => {
            await expect(
                loadRegistry({ env: { [ENV_BEARER]: VALID_BEARER } }),
            ).rejects.toBeInstanceOf(ConfigError);
        });

        it('throws ConfigError when no env-vars at all are set', async () => {
            await expect(loadRegistry({ env: {} })).rejects.toBeInstanceOf(
                ConfigError,
            );
        });

        it('ConfigError message lists all three setup options (a/b/c) so the operator sees every escape hatch', async () => {
            let caught: unknown;
            try {
                await loadRegistry({ env: {} });
            } catch (e) {
                caught = e;
            }
            expect(caught).toBeInstanceOf(ConfigError);
            const msg = (caught as Error).message;
            expect(msg).toMatch(/\(a\)/);
            expect(msg).toMatch(/\(b\)/);
            expect(msg).toMatch(/\(c\)/);
            expect(msg).toMatch(/YTB_MCP_SITES_FILE/);
            expect(msg).toMatch(/YTB_MCP_SITE_URL/);
            expect(msg).toMatch(/YTB_MCP_BEARER_TOKEN/);
            expect(msg).toMatch(/yt-builder-mcp setup/);
        });

        it('ConfigError message also fires when SITES_FILE points at an absent path AND no env-bridge fallback exists', async () => {
            const sitesPath = join(scratchDir, 'never-existed.json');
            await expect(
                loadRegistry({ env: { [ENV_SITES_FILE]: sitesPath } }),
            ).rejects.toBeInstanceOf(ConfigError);
        });
    });
});
