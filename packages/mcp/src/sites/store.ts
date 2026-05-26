/**
 * W1 — Sites-Storage Foundation: load/save the sites file.
 *
 * Guarantees:
 *  - **Atomic replace.** Writes go to a sibling `*.tmp.<rand>` file, are
 *    fsynced, chmod'd to 0600, and then `rename(2)`d over the target.
 *    A SIGINT/SIGKILL between any two of these steps leaves either the
 *    old file intact or the new file fully written — never a torn write.
 *  - **Lock for concurrent writers.** `proper-lockfile` acquires an
 *    advisory lockfile (`<file>.lock`) before any read/modify/write
 *    cycle. Default `stale: 10000` ms; second concurrent save retries
 *    with exponential back-off (`retries: 10, minTimeout: 50`).
 *  - **0600 mode on write.** The temp file is opened/chmod'd to 0600
 *    BEFORE the rename so the new inode never exists with looser perms,
 *    even for one fsync window.
 *  - **Schema-version gate.** Refuses to load a file with a version we
 *    don't understand — explicit error, never silent migration.
 *
 * @license MIT
 */

import {
    chmodSync,
    closeSync,
    existsSync,
    fsyncSync,
    mkdirSync,
    openSync,
    readFileSync,
    renameSync,
    unlinkSync,
    writeSync,
} from 'node:fs';
import { dirname } from 'node:path';

import lockfile from 'proper-lockfile';

import { SitesFile, type SitesFileT } from './schema.js';

/** Base class for any sites-file IO/format failure. */
export class SitesFileError extends Error {
    constructor(message: string, public readonly cause?: unknown) {
        super(message);
        this.name = 'SitesFileError';
    }
}

/** Thrown when the on-disk file declares a `schema_version` we don't know. */
export class SchemaVersionError extends SitesFileError {
    constructor(public readonly found: unknown) {
        super(
            `Unsupported sites-file schema_version: ${String(found)}. ` +
            `This build supports schema_version: 1. ` +
            `Upgrade @wootsup/yt-builder-mcp or revert the file.`,
        );
        this.name = 'SchemaVersionError';
    }
}

/**
 * Discriminated lock-acquisition failure. W12-R1.2 (A4-F1): the
 * pre-W12 `SitesFileError` collapsed three distinct failure modes into
 * one opaque "lock not acquired" string. Operators triaging a stuck
 * setup wizard need to distinguish them to choose the right recovery:
 *
 *   - LOCK_TIMEOUT    → another setup process is currently writing.
 *                       Wait + retry; do NOT touch the lockfile.
 *   - LOCK_ORPHAN     → stale lock from a crashed prior process.
 *                       Manual `rm <lockfilePath>` is the only fix.
 *   - LOCK_PERMISSION → the lock dir itself is not writable. Fix
 *                       file-system permissions.
 *
 * The `lockCode` discriminant + `lockfilePath` field let CLI surfaces
 * (and pre-flight automation) emit precise hints.
 */
export type SitesFileLockCode = 'LOCK_TIMEOUT' | 'LOCK_ORPHAN' | 'LOCK_PERMISSION';

export class SitesFileLockError extends SitesFileError {
    constructor(
        public readonly lockCode: SitesFileLockCode,
        public readonly lockfilePath: string,
        message: string,
        cause?: unknown,
    ) {
        super(message, cause);
        this.name = 'SitesFileLockError';
    }
}

/**
 * Returns the empty seed used when a sites file does not yet exist.
 * Callers (W3 registry) detect "no file → run env-bridge fallback".
 */
export function emptySitesFile(): SitesFileT {
    return { schema_version: 1, default_site_id: null, sites: [] };
}

/**
 * Load + validate a sites file.
 *
 * Returns {@link emptySitesFile} when the path does not exist (no error).
 * Throws {@link SchemaVersionError} for a known-unknown version and
 * {@link SitesFileError} for JSON-parse or Zod-validation failures.
 */
export async function loadSitesFile(path: string): Promise<SitesFileT> {
    if (!existsSync(path)) {
        return emptySitesFile();
    }
    let raw: string;
    try {
        raw = readFileSync(path, 'utf-8');
    } catch (e) {
        throw new SitesFileError(`Failed to read sites file at ${path}`, e);
    }
    let parsed: unknown;
    try {
        parsed = JSON.parse(raw);
    } catch (e) {
        throw new SitesFileError(`Sites file at ${path} is not valid JSON`, e);
    }
    if (
        typeof parsed === 'object' && parsed !== null
        && 'schema_version' in parsed
        && (parsed as { schema_version: unknown }).schema_version !== 1
    ) {
        throw new SchemaVersionError(
            (parsed as { schema_version: unknown }).schema_version,
        );
    }
    const result = SitesFile.safeParse(parsed);
    if (!result.success) {
        throw new SitesFileError(
            `Sites file at ${path} failed schema validation: ` +
            result.error.issues.map((i) => `${i.path.join('.')}: ${i.message}`).join('; '),
        );
    }
    return result.data;
}

/**
 * Persist + validate a sites file atomically with chmod 0600.
 *
 * Pipeline:
 *  1. Acquire `<path>.lock` via proper-lockfile (with retries).
 *  2. Validate `data` through {@link SitesFile} (rejects bad input
 *     before touching the filesystem).
 *  3. Ensure parent directory exists.
 *  4. Write JSON to `<path>.tmp.<pid>.<rand>`, fsync, chmod 0600.
 *  5. `renameSync` over `<path>`.
 *  6. Release lock.
 *
 * Tmp-file cleanup on any failure path.
 */
export async function saveSitesFile(path: string, data: SitesFileT): Promise<void> {
    const validated = SitesFile.safeParse(data);
    if (!validated.success) {
        throw new SitesFileError(
            `Refusing to write invalid sites file: ` +
            validated.error.issues.map((i) => `${i.path.join('.')}: ${i.message}`).join('; '),
        );
    }
    const dir = dirname(path);
    // W12-R1.2 (A2-M3): the on-disk sites file ends up chmod 0600, but
    // the parent directory was previously created with the process
    // umask — typically 0o755 on Linux/macOS, which is world-readable.
    // A world-readable parent dir is harmless for the bearers (they
    // live in 0600 files) but DOES leak the existence of a sites.json
    // to any local user. Tighten to 0700 (owner-only rwx) on creation.
    // Existing directories are left as-is — mkdirSync is a no-op when
    // the dir exists, and chmod'ing an existing dir would surprise
    // operators who chose a shared location intentionally.
    mkdirSync(dir, { recursive: true, mode: 0o700 });

    // proper-lockfile defaults to realpath:true, which throws ENOENT for
    // a not-yet-existing target. Disable realpath + steer the lock at an
    // explicit sibling path. The lock dir always exists (mkdirSync above).
    const lockfilePath = `${path}.lock`;
    let release: () => Promise<void>;
    try {
        release = await lockfile.lock(path, {
            lockfilePath,
            realpath: false,
            stale: 10_000,
            retries: { retries: 10, minTimeout: 50, maxTimeout: 500, factor: 2 },
        });
    } catch (e) {
        // W12-R1.2 (A4-F1): map the underlying error code into a
        // discriminated lock-failure so callers can render the right
        // recovery hint instead of telling the user three possible
        // causes at once.
        const errCode =
            e !== null && typeof e === 'object' && 'code' in e
                ? (e as { code?: unknown }).code
                : undefined;
        let lockCode: SitesFileLockCode;
        let hint: string;
        if (errCode === 'ELOCKED') {
            lockCode = 'LOCK_TIMEOUT';
            hint =
                'Another setup process is currently writing — retry in 30s.';
        } else if (errCode === 'EACCES' || errCode === 'EPERM') {
            lockCode = 'LOCK_PERMISSION';
            hint = `Cannot write to lock dir. Check permissions on ${dir}.`;
        } else {
            lockCode = 'LOCK_ORPHAN';
            hint =
                `Stale lock from prior crashed process. Remove with: rm ${lockfilePath}`;
        }
        throw new SitesFileLockError(
            lockCode,
            lockfilePath,
            `Could not acquire lock on ${lockfilePath}. ${hint}`,
            e,
        );
    }

    const tmpPath = `${path}.tmp.${process.pid}.${Math.random().toString(36).slice(2, 10)}`;
    try {
        const fd = openSync(tmpPath, 'w', 0o600);
        try {
            const payload = JSON.stringify(validated.data, null, 2) + '\n';
            writeSync(fd, payload);
            fsyncSync(fd);
        } finally {
            closeSync(fd);
        }
        // Belt-and-braces: openSync's mode arg may be umask-clamped on
        // some platforms; the explicit chmod guarantees 0600 regardless.
        chmodSync(tmpPath, 0o600);
        renameSync(tmpPath, path);
    } catch (e) {
        // Best-effort tmp cleanup; ignore failures.
        try { if (existsSync(tmpPath)) unlinkSync(tmpPath); } catch { /* swallow */ }
        throw new SitesFileError(`Failed to write sites file at ${path}`, e);
    } finally {
        await release().catch(() => { /* lock already released by stale TTL */ });
    }
}
