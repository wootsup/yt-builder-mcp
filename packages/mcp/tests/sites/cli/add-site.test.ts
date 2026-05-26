/**
 * W9 — tests for `addSiteCommand` (sites/cli/add-site.ts).
 *
 * Uses an in-memory store stub (load/save) so we never touch the
 * filesystem. Covers:
 *  - happy-path write with correct shape (site_id / url / bearer /
 *    platform / is_default / added_at)
 *  - first-site auto-default policy (regardless of --default flag)
 *  - subsequent --default flag flips both file.default_site_id and
 *    the per-entry is_default flags (W1 invariant: at most one default)
 *  - duplicate site_id without --yes throws AddSiteError('SITE_ID_EXISTS')
 *  - duplicate site_id with --yes overwrites in place (insertion order
 *    preserved)
 *  - mutual-exclusion: both --token + --token-ref → AddSiteError
 *  - missing both --token + --token-ref → AddSiteError
 *  - --token-ref happy path stores bearer_ref, not bearer
 *  - invalid bearer shape rejected
 *  - invalid op-ref shape rejected
 *  - URL validation rejects garbage
 *  - missing URL rejected
 *  - invalid site_id slug rejected
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    addSiteCommand,
    AddSiteError,
    type AddSiteArgs,
    type AddSiteDeps,
} from '../../../src/sites/cli/add-site.js';
import {
    emptySitesFile,
} from '../../../src/sites/store.js';
import type { SitesFileT } from '../../../src/sites/schema.js';

const VALID_BEARER =
    'ytb_live_eyJraWQiOiJ0LWtleSIsInNjb3BlIjoid3JpdGUifQ.abc123_xyz-def';
const OTHER_BEARER =
    'ytb_test_eyJyIjoieiJ9.qqq_zzz-yyy';
const VALID_REF = 'op://Private/yt-mcp/bearer';
const FROZEN_NOW = '2026-05-25T15:00:00.000Z';

interface MemStore {
    state: SitesFileT;
    saveCount: number;
}

function makeStore(initial?: SitesFileT): { mem: MemStore; deps: AddSiteDeps } {
    const mem: MemStore = {
        state: initial ?? emptySitesFile(),
        saveCount: 0,
    };
    const deps: AddSiteDeps = {
        load: async () => mem.state,
        save: async (_p, data) => {
            mem.state = data;
            mem.saveCount += 1;
        },
        now: () => FROZEN_NOW,
    };
    return { mem, deps };
}

function baseArgs(overrides: Partial<AddSiteArgs> = {}): AddSiteArgs {
    return {
        url: 'https://example.com',
        token: VALID_BEARER,
        siteId: 'wp-acme',
        ...overrides,
    };
}

describe('addSiteCommand — happy path', () => {
    it('writes a fresh entry with the correct shape', async () => {
        const { mem, deps } = makeStore();
        const result = await addSiteCommand(
            baseArgs({ label: 'Acme Marketing' }),
            '/tmp/sites.json',
            deps,
        );
        expect(mem.saveCount).toBe(1);
        expect(mem.state.sites).toHaveLength(1);
        const entry = mem.state.sites[0]!;
        expect(entry.site_id).toBe('wp-acme');
        expect(entry.url).toBe('https://example.com');
        expect(entry.bearer).toBe(VALID_BEARER);
        expect(entry.bearer_ref).toBeUndefined();
        expect(entry.platform).toBe('auto');
        expect(entry.label).toBe('Acme Marketing');
        expect(entry.added_at).toBe(FROZEN_NOW);
        expect(result.becameDefault).toBe(true);
        expect(result.overwritten).toBe(false);
        expect(result.path).toBe('/tmp/sites.json');
    });

    it('auto-promotes the first site to default regardless of --default flag', async () => {
        const { mem } = makeStore();
        const { deps } = makeStore();
        // explicit `default: false` — auto-default still kicks in.
        const args = baseArgs({ default: false });
        const result = await addSiteCommand(args, '/x', deps);
        void mem; // ESLint guard — separate mem used in this test
        expect(result.becameDefault).toBe(true);
        // Re-build with fresh store + assert via mem.state.
        const fresh = makeStore();
        await addSiteCommand(args, '/y', fresh.deps);
        expect(fresh.mem.state.sites[0]?.is_default).toBe(true);
        expect(fresh.mem.state.default_site_id).toBe('wp-acme');
    });
});

describe('addSiteCommand — second site default semantics', () => {
    it('does NOT auto-default a second site without --default', async () => {
        const first = await freshWithFirst();
        await addSiteCommand(
            baseArgs({ siteId: 'wp-beta', url: 'https://beta.com', token: OTHER_BEARER }),
            '/x',
            first.deps,
        );
        expect(first.mem.state.sites).toHaveLength(2);
        expect(first.mem.state.default_site_id).toBe('wp-acme');
        const beta = first.mem.state.sites.find((s) => s.site_id === 'wp-beta');
        expect(beta?.is_default).toBe(false);
    });

    it('promotes second site when --default is passed AND demotes the prior default', async () => {
        const first = await freshWithFirst();
        await addSiteCommand(
            baseArgs({
                siteId: 'wp-beta',
                url: 'https://beta.com',
                token: OTHER_BEARER,
                default: true,
            }),
            '/x',
            first.deps,
        );
        expect(first.mem.state.default_site_id).toBe('wp-beta');
        const acme = first.mem.state.sites.find((s) => s.site_id === 'wp-acme');
        const beta = first.mem.state.sites.find((s) => s.site_id === 'wp-beta');
        expect(acme?.is_default).toBe(false);
        expect(beta?.is_default).toBe(true);
    });
});

describe('addSiteCommand — duplicate site_id', () => {
    it('refuses duplicate without --yes', async () => {
        const first = await freshWithFirst();
        await expect(
            addSiteCommand(baseArgs(), '/x', first.deps),
        ).rejects.toBeInstanceOf(AddSiteError);
        expect(first.mem.state.sites).toHaveLength(1);
        expect(first.mem.saveCount).toBe(1); // unchanged from the first add
    });

    it('overwrites duplicate with --yes (insertion order preserved)', async () => {
        const first = await freshWithFirst();
        await addSiteCommand(
            baseArgs({ siteId: 'wp-beta', url: 'https://beta.com', token: OTHER_BEARER }),
            '/x',
            first.deps,
        );
        // Overwrite wp-acme with a different label
        await addSiteCommand(
            baseArgs({ label: 'Acme v2', yes: true }),
            '/x',
            first.deps,
        );
        expect(first.mem.state.sites).toHaveLength(2);
        expect(first.mem.state.sites[0]?.site_id).toBe('wp-acme');
        expect(first.mem.state.sites[0]?.label).toBe('Acme v2');
        // Overwrite preserved insertion order (wp-acme still index 0)
        expect(first.mem.state.sites[1]?.site_id).toBe('wp-beta');
    });

    it('overwrite preserves prior is_default when --default not passed', async () => {
        const first = await freshWithFirst();
        // Overwrite wp-acme (which IS default) without --default
        await addSiteCommand(
            baseArgs({ label: 'New Label', yes: true }),
            '/x',
            first.deps,
        );
        expect(first.mem.state.sites[0]?.is_default).toBe(true);
        expect(first.mem.state.default_site_id).toBe('wp-acme');
    });
});

describe('addSiteCommand — credential validation', () => {
    it('rejects both --token and --token-ref simultaneously', async () => {
        const { deps } = makeStore();
        await expect(
            addSiteCommand(
                baseArgs({ tokenRef: VALID_REF }),
                '/x',
                deps,
            ),
        ).rejects.toThrow(/mutually exclusive/);
    });

    it('rejects missing credential entirely', async () => {
        const { deps } = makeStore();
        await expect(
            addSiteCommand(
                { url: 'https://example.com', siteId: 'wp-acme' },
                '/x',
                deps,
            ),
        ).rejects.toThrow(/Either --token .*--token-ref.* is required/);
    });

    it('accepts --token-ref and stores it as bearer_ref (no inline bearer)', async () => {
        const { mem, deps } = makeStore();
        await addSiteCommand(
            { url: 'https://example.com', siteId: 'wp-acme', tokenRef: VALID_REF },
            '/x',
            deps,
        );
        const entry = mem.state.sites[0]!;
        expect(entry.bearer).toBeUndefined();
        expect(entry.bearer_ref).toBe(VALID_REF);
    });

    it('rejects malformed bearer shape', async () => {
        const { deps } = makeStore();
        await expect(
            addSiteCommand(
                baseArgs({ token: 'not_a_bearer' }),
                '/x',
                deps,
            ),
        ).rejects.toThrow(/Bearer shape/);
    });

    it('rejects malformed op-ref shape', async () => {
        const { deps } = makeStore();
        await expect(
            addSiteCommand(
                { url: 'https://example.com', siteId: 'wp-acme', tokenRef: 'not://ref' },
                '/x',
                deps,
            ),
        ).rejects.toThrow(/1Password ref shape/);
    });
});

describe('addSiteCommand — URL + site_id validation', () => {
    it('rejects an empty URL', async () => {
        const { deps } = makeStore();
        await expect(
            addSiteCommand(baseArgs({ url: '' }), '/x', deps),
        ).rejects.toThrow(/--url is required/);
    });

    it('rejects a malformed URL', async () => {
        const { deps } = makeStore();
        await expect(
            addSiteCommand(baseArgs({ url: 'not a url' }), '/x', deps),
        ).rejects.toThrow(/not a valid web address/);
    });

    it('rejects a site_id with invalid characters', async () => {
        const { deps } = makeStore();
        await expect(
            addSiteCommand(baseArgs({ siteId: 'has space' }), '/x', deps),
        ).rejects.toThrow(/letters \/ digits \/ dash \/ underscore/);
    });
});

// ── helpers ──────────────────────────────────────────────────────────

async function freshWithFirst(): Promise<{ mem: MemStore; deps: AddSiteDeps }> {
    const store = makeStore();
    await addSiteCommand(baseArgs(), '/x', store.deps);
    return store;
}
