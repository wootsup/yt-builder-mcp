/**
 * W12-R3 (F-A4-F1) — `SitesFileLockError.lockCode` discriminant pin.
 *
 * The W12-R1.2 remediation collapsed three distinct lock-acquisition
 * failure modes (timeout / permission / orphan) into a single
 * `SitesFileLockError` with a `lockCode` discriminant + actionable hint
 * in the message. The unit-suite did not previously verify that the
 * underlying `proper-lockfile` error-code → discriminant mapping is
 * intact — a future refactor that swallowed the cause or normalised
 * the codes would silently regress the recovery experience.
 *
 * Strategy: mock `proper-lockfile.lock` to throw each error variant in
 * turn and assert the produced `SitesFileLockError` carries the right
 * `lockCode` + a substring hint matching the documented recovery.
 *
 * Mocking the default export of an ESM CJS-interop module via
 * `vi.mock` requires the explicit `{ default: { lock, unlock } }`
 * shape — `saveSitesFile` imports `lockfile.lock` from the default.
 *
 * @license MIT
 */

import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('proper-lockfile', () => {
    const lock = vi.fn();
    const unlock = vi.fn(async () => undefined);
    return {
        default: { lock, unlock },
    };
});

// Importing AFTER vi.mock so the mocked default lands.
import lockfile from 'proper-lockfile';
import { saveSitesFile, SitesFileLockError } from '../../src/sites/store.js';
import type { SitesFileT } from '../../src/sites/schema.js';

const VALID_BEARER =
    'ytb_live_eyJraWQiOiJ0LWtleSIsInNjb3BlIjoid3JpdGUifQ.abc123_xyz-def';

function sampleFile(): SitesFileT {
    return {
        schema_version: 1,
        default_site_id: 'wp-acme',
        sites: [{
            site_id: 'wp-acme',
            url: 'https://acme.com',
            platform: 'wordpress',
            bearer: VALID_BEARER,
            is_default: true,
        }],
    };
}

function freshTmpFile(): string {
    const dir = mkdtempSync(join(tmpdir(), 'ytb-store-lock-'));
    return join(dir, 'sites.json');
}

interface LockfileMock {
    lock: ReturnType<typeof vi.fn>;
    unlock: ReturnType<typeof vi.fn>;
}

function lockMock(): LockfileMock {
    // `lockfile` is the mocked default import — vi.mock above
    // guarantees both methods are vi.fn instances at this point.
    return lockfile as unknown as LockfileMock;
}

describe('W12-R3 — SitesFileLockError discriminant', () => {
    beforeEach(() => {
        const m = lockMock();
        m.lock.mockReset();
        m.unlock.mockReset();
    });

    it('ELOCKED error from proper-lockfile → lockCode: LOCK_TIMEOUT + hint mentions retry', async () => {
        const m = lockMock();
        const err = Object.assign(new Error('locked'), { code: 'ELOCKED' });
        m.lock.mockRejectedValueOnce(err);

        const path = freshTmpFile();
        try {
            await saveSitesFile(path, sampleFile());
            throw new Error('expected SitesFileLockError');
        } catch (e) {
            expect(e).toBeInstanceOf(SitesFileLockError);
            const lockErr = e as SitesFileLockError;
            expect(lockErr.lockCode).toBe('LOCK_TIMEOUT');
            expect(lockErr.lockfilePath).toBe(`${path}.lock`);
            // Hint must steer the operator toward retry, NOT toward
            // manual lockfile-rm (that's the LOCK_ORPHAN path).
            expect(lockErr.message).toMatch(/retry/i);
        }
    });

    it('EACCES error from proper-lockfile → lockCode: LOCK_PERMISSION + hint mentions permissions', async () => {
        const m = lockMock();
        const err = Object.assign(new Error('permission denied'), { code: 'EACCES' });
        m.lock.mockRejectedValueOnce(err);

        const path = freshTmpFile();
        try {
            await saveSitesFile(path, sampleFile());
            throw new Error('expected SitesFileLockError');
        } catch (e) {
            expect(e).toBeInstanceOf(SitesFileLockError);
            const lockErr = e as SitesFileLockError;
            expect(lockErr.lockCode).toBe('LOCK_PERMISSION');
            // Actionable hint must point at the parent dir + permissions
            // — NOT at retry (retrying with the same uid would loop).
            expect(lockErr.message).toMatch(/permission/i);
        }
    });

    it('unknown error code from proper-lockfile → lockCode: LOCK_ORPHAN + hint mentions "rm <lockfilePath>"', async () => {
        const m = lockMock();
        // ENOENT is the typical "stale lockfile pointing at a removed
        // target" failure mode; it is NOT one of the two named codes
        // so the mapping falls through to LOCK_ORPHAN.
        const err = Object.assign(new Error('no such file'), { code: 'ENOENT' });
        m.lock.mockRejectedValueOnce(err);

        const path = freshTmpFile();
        try {
            await saveSitesFile(path, sampleFile());
            throw new Error('expected SitesFileLockError');
        } catch (e) {
            expect(e).toBeInstanceOf(SitesFileLockError);
            const lockErr = e as SitesFileLockError;
            expect(lockErr.lockCode).toBe('LOCK_ORPHAN');
            // LOAD-BEARING: operator runbook says "remove with rm <path>".
            // The hint must contain BOTH the literal "rm " AND the
            // resolved lockfilePath so the operator can copy-paste-fix.
            expect(lockErr.message).toContain('rm ');
            expect(lockErr.message).toContain(`${path}.lock`);
        }
    });

    it('every lockCode variant carries the lockfilePath on the error (not just in the message)', async () => {
        // Defense-in-depth: the message is for humans; the lockfilePath
        // field is for programmatic recovery scripts (CLI, post-mortem
        // automation). All three variants must expose it.
        const variants: { code: string; expectedLockCode: SitesFileLockError['lockCode'] }[] = [
            { code: 'ELOCKED', expectedLockCode: 'LOCK_TIMEOUT' },
            { code: 'EACCES', expectedLockCode: 'LOCK_PERMISSION' },
            { code: 'EUNKNOWN', expectedLockCode: 'LOCK_ORPHAN' },
        ];
        for (const v of variants) {
            const m = lockMock();
            m.lock.mockReset();
            m.lock.mockRejectedValueOnce(
                Object.assign(new Error('x'), { code: v.code }),
            );
            const path = freshTmpFile();
            try {
                await saveSitesFile(path, sampleFile());
                throw new Error(`expected SitesFileLockError for code ${v.code}`);
            } catch (e) {
                expect(e).toBeInstanceOf(SitesFileLockError);
                const lockErr = e as SitesFileLockError;
                expect(lockErr.lockCode).toBe(v.expectedLockCode);
                expect(lockErr.lockfilePath).toBe(`${path}.lock`);
            }
        }
    });
});
