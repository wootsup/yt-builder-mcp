/**
 * W9 — tests for `setDefaultCommand`.
 *
 * Covers:
 *  - flips is_default flags + updates default_site_id
 *  - flipping the already-default is a no-op-equivalent (file rewritten
 *    but state matches)
 *  - rejects unknown site_id with SetDefaultError('UNKNOWN_SITE')
 *  - previousDefault is reported correctly
 *  - W1 invariant preserved: at most ONE is_default:true after the call
 *
 * W12-R2A additions:
 *  - confirm hook required when --yes is absent → SET_DEFAULT_CONFIRM_REQUIRED
 *  - confirm rejecting → cancelled:true, no save
 *  - --yes bypasses the confirm hook entirely
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    setDefaultCommand,
    SetDefaultError,
    type SetDefaultDeps,
} from '../../../src/sites/cli/set-default.js';
import type { SitesFileT } from '../../../src/sites/schema.js';

const BEARER_A = 'ytb_live_eyJhIjoiYSJ9.aaa-bbb_ccc';
const BEARER_B = 'ytb_live_eyJhIjoiYiJ9.ddd-eee_fff';

interface MemStore {
    state: SitesFileT;
    saveCount: number;
}

function makeStore(initial: SitesFileT): { mem: MemStore; deps: SetDefaultDeps } {
    const mem: MemStore = { state: initial, saveCount: 0 };
    const deps: SetDefaultDeps = {
        load: async () => mem.state,
        save: async (_p, data) => {
            mem.state = data;
            mem.saveCount += 1;
        },
    };
    return { mem, deps };
}

function file2(defaultId: string): SitesFileT {
    return {
        schema_version: 1,
        default_site_id: defaultId,
        sites: [
            {
                site_id: 'wp-acme', url: 'https://acme.com', platform: 'wordpress',
                bearer: BEARER_A, is_default: defaultId === 'wp-acme',
            },
            {
                site_id: 'wp-beta', url: 'https://beta.com', platform: 'wordpress',
                bearer: BEARER_B, is_default: defaultId === 'wp-beta',
            },
        ],
    };
}

describe('setDefaultCommand — flip behaviour', () => {
    it('promotes the target and demotes the prior default', async () => {
        const { mem, deps } = makeStore(file2('wp-acme'));
        const result = await setDefaultCommand('wp-beta', '/x', { yes: true }, deps);
        expect(mem.state.default_site_id).toBe('wp-beta');
        const acme = mem.state.sites.find((s) => s.site_id === 'wp-acme');
        const beta = mem.state.sites.find((s) => s.site_id === 'wp-beta');
        expect(acme?.is_default).toBe(false);
        expect(beta?.is_default).toBe(true);
        expect(result.previousDefault).toBe('wp-acme');
        expect(result.cancelled).toBe(false);
    });

    it('is a no-op-equivalent when target is already default', async () => {
        const { mem, deps } = makeStore(file2('wp-acme'));
        const result = await setDefaultCommand('wp-acme', '/x', { yes: true }, deps);
        expect(mem.state.default_site_id).toBe('wp-acme');
        const acme = mem.state.sites.find((s) => s.site_id === 'wp-acme');
        expect(acme?.is_default).toBe(true);
        expect(result.previousDefault).toBe('wp-acme');
    });

    it('preserves the W1 invariant: at most one is_default:true', async () => {
        const { mem, deps } = makeStore(file2('wp-acme'));
        await setDefaultCommand('wp-beta', '/x', { yes: true }, deps);
        const flagged = mem.state.sites.filter((s) => s.is_default);
        expect(flagged).toHaveLength(1);
        expect(flagged[0]?.site_id).toBe('wp-beta');
    });
});

describe('setDefaultCommand — unknown site', () => {
    it('throws SetDefaultError with UNKNOWN_SITE and writes nothing', async () => {
        const { mem, deps } = makeStore(file2('wp-acme'));
        await expect(
            setDefaultCommand('wp-nope', '/x', { yes: true }, deps),
        ).rejects.toBeInstanceOf(SetDefaultError);
        expect(mem.saveCount).toBe(0);
        expect(mem.state.default_site_id).toBe('wp-acme');
    });
});

describe('setDefaultCommand — empty registry', () => {
    it('rejects with UNKNOWN_SITE when there are no sites', async () => {
        const empty: SitesFileT = {
            schema_version: 1,
            default_site_id: null,
            sites: [],
        };
        const { deps, mem } = makeStore(empty);
        await expect(
            setDefaultCommand('wp-acme', '/x', { yes: true }, deps),
        ).rejects.toThrow(/no sites configured/);
        expect(mem.saveCount).toBe(0);
    });
});

describe('setDefaultCommand — confirm hook (W12-R2A)', () => {
    it('throws SET_DEFAULT_CONFIRM_REQUIRED when --yes is absent and no confirm hook is wired', async () => {
        const { mem, deps } = makeStore(file2('wp-acme'));
        // deps.confirm intentionally undefined here.
        await expect(
            setDefaultCommand('wp-beta', '/x', { yes: false }, deps),
        ).rejects.toMatchObject({
            name: 'SetDefaultError',
            code: 'SET_DEFAULT_CONFIRM_REQUIRED',
        });
        // Fail-closed: no write happens.
        expect(mem.saveCount).toBe(0);
        expect(mem.state.default_site_id).toBe('wp-acme');
    });

    it('returns cancelled when confirm rejects (no save)', async () => {
        const { mem, deps } = makeStore(file2('wp-acme'));
        const result = await setDefaultCommand(
            'wp-beta',
            '/x',
            { yes: false },
            { ...deps, confirm: async () => false },
        );
        expect(result.cancelled).toBe(true);
        expect(mem.saveCount).toBe(0);
        expect(mem.state.default_site_id).toBe('wp-acme');
    });

    it('--yes bypasses the confirm hook entirely', async () => {
        let confirmCalled = 0;
        const { mem, deps } = makeStore(file2('wp-acme'));
        await setDefaultCommand(
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
        expect(mem.state.default_site_id).toBe('wp-beta');
    });
});
