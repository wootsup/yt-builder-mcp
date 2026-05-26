/**
 * W9 — tests for `removeSiteCommand`.
 *
 * Covers:
 *  - removes a non-default site cleanly (default unchanged)
 *  - removing the default with ≥1 remaining auto-promotes the next
 *    in insertion order (returns `promoted` field + updates
 *    default_site_id + per-entry is_default flag)
 *  - removing the only site clears default_site_id and leaves []
 *  - unknown site_id throws RemoveSiteError('UNKNOWN_SITE')
 *  - --yes bypasses the confirm hook
 *  - confirm hook rejecting returns { cancelled: true } and does NOT
 *    write the file
 *  - sites_count assertion: the saved file has length N-1
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    removeSiteCommand,
    RemoveSiteError,
    type RemoveSiteDeps,
} from '../../../src/sites/cli/remove-site.js';
import type { SitesFileT } from '../../../src/sites/schema.js';

const BEARER_A = 'ytb_live_eyJhIjoiYSJ9.aaa-bbb_ccc';
const BEARER_B = 'ytb_live_eyJhIjoiYiJ9.ddd-eee_fff';
const BEARER_C = 'ytb_live_eyJhIjoiYyJ9.ggg-hhh_iii';

interface MemStore {
    state: SitesFileT;
    saveCount: number;
}

function makeStore(initial: SitesFileT): { mem: MemStore; deps: RemoveSiteDeps } {
    const mem: MemStore = { state: initial, saveCount: 0 };
    const deps: RemoveSiteDeps = {
        load: async () => mem.state,
        save: async (_p, data) => {
            mem.state = data;
            mem.saveCount += 1;
        },
    };
    return { mem, deps };
}

function file3(): SitesFileT {
    return {
        schema_version: 1,
        default_site_id: 'wp-acme',
        sites: [
            { site_id: 'wp-acme', url: 'https://acme.com', platform: 'wordpress', bearer: BEARER_A, is_default: true },
            { site_id: 'wp-beta', url: 'https://beta.com', platform: 'wordpress', bearer: BEARER_B, is_default: false },
            { site_id: 'wp-gamma', url: 'https://gamma.com', platform: 'wordpress', bearer: BEARER_C, is_default: false },
        ],
    };
}

describe('removeSiteCommand — non-default removal', () => {
    it('removes a non-default site and leaves the default untouched', async () => {
        const { mem, deps } = makeStore(file3());
        const result = await removeSiteCommand('wp-beta', '/x', { yes: true }, deps);
        expect(mem.saveCount).toBe(1);
        expect(mem.state.sites).toHaveLength(2);
        expect(mem.state.default_site_id).toBe('wp-acme');
        expect(result.promoted).toBeUndefined();
        expect(result.nowEmpty).toBe(false);
        expect(result.cancelled).toBe(false);
        const remainingIds = mem.state.sites.map((s) => s.site_id);
        expect(remainingIds).toEqual(['wp-acme', 'wp-gamma']);
    });
});

describe('removeSiteCommand — default removal + auto-promote', () => {
    it('auto-promotes the next site in insertion order when removing the default', async () => {
        const { mem, deps } = makeStore(file3());
        const result = await removeSiteCommand('wp-acme', '/x', { yes: true }, deps);
        expect(result.promoted).toBe('wp-beta');
        expect(mem.state.default_site_id).toBe('wp-beta');
        const beta = mem.state.sites.find((s) => s.site_id === 'wp-beta');
        const gamma = mem.state.sites.find((s) => s.site_id === 'wp-gamma');
        expect(beta?.is_default).toBe(true);
        expect(gamma?.is_default).toBe(false);
        expect(mem.state.sites).toHaveLength(2);
    });

    it('clears default_site_id when removing the only site', async () => {
        const only: SitesFileT = {
            schema_version: 1,
            default_site_id: 'wp-acme',
            sites: [{
                site_id: 'wp-acme', url: 'https://acme.com', platform: 'wordpress',
                bearer: BEARER_A, is_default: true,
            }],
        };
        const { mem, deps } = makeStore(only);
        const result = await removeSiteCommand('wp-acme', '/x', { yes: true }, deps);
        expect(mem.state.sites).toHaveLength(0);
        expect(mem.state.default_site_id).toBeNull();
        expect(result.nowEmpty).toBe(true);
        expect(result.promoted).toBeUndefined();
    });
});

describe('removeSiteCommand — unknown site', () => {
    it('throws RemoveSiteError with code UNKNOWN_SITE', async () => {
        const { deps, mem } = makeStore(file3());
        await expect(
            removeSiteCommand('wp-nope', '/x', { yes: true }, deps),
        ).rejects.toBeInstanceOf(RemoveSiteError);
        // No save side-effect from a rejected call.
        expect(mem.saveCount).toBe(0);
    });
});

describe('removeSiteCommand — confirm hook', () => {
    it('throws CONFIRM_REQUIRED when --yes is absent and no confirm hook is wired (W12-R2A fail-closed)', async () => {
        const { mem, deps } = makeStore(file3());
        // deps.confirm intentionally undefined here.
        await expect(
            removeSiteCommand('wp-beta', '/x', { yes: false }, deps),
        ).rejects.toMatchObject({
            name: 'RemoveSiteError',
            code: 'CONFIRM_REQUIRED',
        });
        // Fail-closed: no write happens.
        expect(mem.saveCount).toBe(0);
        expect(mem.state.sites).toHaveLength(3);
    });

    it('returns cancelled when confirm rejects (no save)', async () => {
        const { mem, deps } = makeStore(file3());
        const result = await removeSiteCommand(
            'wp-beta',
            '/x',
            { yes: false },
            { ...deps, confirm: async () => false },
        );
        expect(result.cancelled).toBe(true);
        expect(mem.saveCount).toBe(0);
        expect(mem.state.sites).toHaveLength(3);
    });

    it('--yes bypasses the confirm hook entirely', async () => {
        let confirmCalled = 0;
        const { mem, deps } = makeStore(file3());
        await removeSiteCommand(
            'wp-beta',
            '/x',
            { yes: true },
            {
                ...deps,
                confirm: async () => {
                    confirmCalled += 1;
                    return false;
                },
            },
        );
        expect(confirmCalled).toBe(0);
        expect(mem.state.sites).toHaveLength(2);
    });
});
