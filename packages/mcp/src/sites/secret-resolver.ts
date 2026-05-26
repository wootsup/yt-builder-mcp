/**
 * W2 — Secret-Resolver: produce a bearer-token for a `SiteEntryT` at call
 * time, supporting either a plain in-file token (`bearer`) or a 1Password
 * Secret Reference (`bearer_ref`) resolved through the `op` CLI.
 *
 * Design choices (per plan §W2):
 *  - **`execFile`, never `exec`/`spawn` with `shell:true`.** `op read <ref>`
 *    must run as a direct `execve` with an argv array — no shell, no
 *    metacharacter expansion. The W1 schema already enforces
 *    {@link OP_REF_REGEX}; this module re-enforces it at the call site as
 *    defence-in-depth (a hand-edited sites.json could otherwise sneak a
 *    payload past validation).
 *  - **5-second hard timeout.** `op` is interactive in some failure modes
 *    (Touch-ID, biometric retry). We cap with a real timer + `kill()` to
 *    avoid agent-loop wedging on a stalled subprocess.
 *  - **Pluggable for testing.** The OpSecretResolver accepts an injected
 *    `execFile` callback so tests can mock subprocess invocation without
 *    monkey-patching the global `node:child_process` module.
 *
 * @license MIT
 */

import { execFile as nodeExecFile } from 'node:child_process';
import { existsSync } from 'node:fs';

import { OP_REF_REGEX, type SiteEntryT } from './schema.js';

/** Hard cap for any single `op read` invocation. */
const OP_TIMEOUT_MS = 5_000;

/**
 * Common absolute install locations for the 1Password CLI. GUI-spawned
 * MCP hosts (Claude Desktop, etc.) launch the server subprocess with a
 * minimal PATH that usually omits `/opt/homebrew/bin` and `/usr/local/bin`,
 * so a bare `op` resolves to ENOENT even when the user has `op` working in
 * their shell. We probe these absolute paths before falling back to PATH.
 *
 * Order: Homebrew (Apple Silicon) → Homebrew/manual (Intel + /usr/local) →
 * Linux package managers → PATH.
 */
const OP_BINARY_CANDIDATES: readonly string[] = [
    '/opt/homebrew/bin/op',
    '/usr/local/bin/op',
    '/usr/bin/op',
];

/**
 * Resolve the `op` binary to an absolute path so it works under a
 * stripped-down GUI PATH. Precedence: explicit `YTB_MCP_OP_BINARY` env
 * override → first existing well-known absolute path → bare `op` (PATH).
 */
export function resolveOpBinary(
    env: NodeJS.ProcessEnv = process.env,
): string {
    const override = (env.YTB_MCP_OP_BINARY ?? '').trim();
    if (override.length > 0) return override;
    for (const candidate of OP_BINARY_CANDIDATES) {
        try {
            if (existsSync(candidate)) return candidate;
        } catch {
            // ignore stat errors and keep probing
        }
    }
    return 'op';
}

/** `op` CLI exit-code → friendly hint mapping. */
const OP_EXIT_CODE_HINTS: Record<number, string> = {
    6: 'Not signed in to 1Password CLI. Run `op signin` and retry.',
    1: 'Generic op CLI error — verify the secret reference exists and the vault is accessible.',
};

/**
 * Shape returned by every resolver. W12-R1.2 (A5-S3): the planned TTL
 * cache that was the original justification for `resolvedAt` never
 * shipped — the W4 ClientPool caches the `RestClient` itself, not the
 * bearer — so the field was dead weight. Dropped to keep the contract
 * minimal. Re-add ONLY when a real consumer appears.
 */
export interface ResolvedBearer {
    readonly token: string;
    readonly source: 'plain' | 'op';
}

/**
 * Pluggable resolver contract. Implementations MUST NOT log the token,
 * MUST NOT mutate the input site, and MUST throw {@link SecretResolverError}
 * on any failure (never return an empty/placeholder bearer).
 */
export interface SecretResolver {
    resolve(site: SiteEntryT): Promise<ResolvedBearer>;
}

/** Domain-tagged error so callers (auth, client) can branch on `code`. */
export class SecretResolverError extends Error {
    constructor(public readonly code: string, message: string) {
        super(message);
        this.name = 'SecretResolverError';
    }
}

/**
 * Signature of `node:child_process.execFile` reduced to the callback we
 * actually rely on. Injecting this lets tests provide a deterministic
 * substitute without `vi.mock('node:child_process')` side-effects.
 *
 * The real `execFile` overload returns a `ChildProcess` and accepts
 * many option shapes; we accept the broadest superset and only use the
 * pieces the resolver needs (`timeout`, `signal`, callback args).
 */
export type ExecFileLike = (
    file: string,
    args: readonly string[],
    options: { timeout?: number; maxBuffer?: number },
    callback: (
        error: (Error & { code?: string | number; signal?: string }) | null,
        stdout: string,
        stderr: string,
    ) => void,
) => void;

/** Default factory wrapping Node's real `execFile`. Adapter for type-shape. */
const defaultExecFile: ExecFileLike = (file, args, options, callback) => {
    // Pass `encoding: 'utf8'` explicitly so stdout/stderr are guaranteed
    // strings, not Buffers. The execFile overload resolution returns `string`
    // for both when `encoding` is set.
    nodeExecFile(
        file,
        args as string[],
        { ...options, encoding: 'utf8' },
        (err, stdout, stderr) => {
            callback(
                err as (Error & { code?: string | number; signal?: string }) | null,
                stdout,
                stderr,
            );
        },
    );
};

/**
 * Plain in-file bearer — used when `site.bearer` is populated. Returns the
 * stored token verbatim, with `source:'plain'`.
 */
export class PlainSecretResolver implements SecretResolver {
    async resolve(site: SiteEntryT): Promise<ResolvedBearer> {
        const token = site.bearer;
        if (token === undefined || token.length === 0) {
            throw new SecretResolverError(
                'PLAIN_BEARER_MISSING',
                `Site '${site.site_id}' has no inline 'bearer' — set one or configure 'bearer_ref'.`,
            );
        }
        return {
            token,
            source: 'plain',
        };
    }
}

/**
 * Resolves `bearer_ref` (`op://vault/item/field`) through the 1Password CLI.
 *
 * Failure surface:
 *  - Missing `bearer_ref`             → `OP_REF_MISSING`
 *  - Malformed ref at call site       → `OP_REF_INVALID`
 *  - `op` binary not on PATH (ENOENT) → `OP_CLI_MISSING`
 *  - `op` exit-code 6 (not signed in) → `OP_NOT_SIGNED_IN`
 *  - 5-second timeout exceeded        → `OP_TIMEOUT`
 *  - Empty/whitespace stdout          → `OP_EMPTY_OUTPUT`
 *  - Any other non-zero exit          → `OP_EXEC_FAILED`
 */
export class OpSecretResolver implements SecretResolver {
    constructor(
        private readonly opBinary: string = resolveOpBinary(),
        private readonly execFile: ExecFileLike = defaultExecFile,
        private readonly timeoutMs: number = OP_TIMEOUT_MS,
    ) {}

    async resolve(site: SiteEntryT): Promise<ResolvedBearer> {
        const ref = site.bearer_ref;
        if (ref === undefined || ref.length === 0) {
            throw new SecretResolverError(
                'OP_REF_MISSING',
                `Site '${site.site_id}' has no 'bearer_ref' — OpSecretResolver requires one.`,
            );
        }

        // Defence-in-depth: re-validate the ref shape at the call site,
        // BEFORE we hand the string to a subprocess. The W1 schema also
        // enforces this on load/save, but a hand-edited or hot-loaded
        // sites.json could in theory bypass that.
        if (!OP_REF_REGEX.test(ref)) {
            throw new SecretResolverError(
                'OP_REF_INVALID',
                `Site '${site.site_id}' has an invalid 'bearer_ref' shape — must match op://<vault>/<item>/<field>.`,
            );
        }

        const stdout = await this.runOpRead(ref);
        const token = stdout.trim();
        if (token.length === 0) {
            throw new SecretResolverError(
                'OP_EMPTY_OUTPUT',
                `op returned empty output for '${ref}' — verify the field exists and is non-empty.`,
            );
        }

        return {
            token,
            source: 'op',
        };
    }

    /**
     * Invokes `op read <ref>` with a hard timeout. The promise is wrapped
     * so a stalled subprocess (e.g. waiting on biometric prompt) cannot
     * outlive {@link timeoutMs}.
     */
    private runOpRead(ref: string): Promise<string> {
        return new Promise<string>((resolve, reject) => {
            let settled = false;
            const timer = setTimeout(() => {
                if (settled) return;
                settled = true;
                reject(
                    new SecretResolverError(
                        'OP_TIMEOUT',
                        `op read '${ref}' exceeded ${this.timeoutMs}ms — is 1Password CLI hung on a biometric prompt?`,
                    ),
                );
            }, this.timeoutMs);

            // Allow the test process to exit even if `op` is still pending.
            if (typeof timer.unref === 'function') timer.unref();

            this.execFile(
                this.opBinary,
                ['read', ref],
                { timeout: this.timeoutMs, maxBuffer: 1024 * 64 },
                (err, stdout, stderr) => {
                    if (settled) return;
                    settled = true;
                    clearTimeout(timer);

                    if (err) {
                        reject(this.mapExecError(err, stderr, ref));
                        return;
                    }
                    resolve(stdout);
                },
            );
        });
    }

    private mapExecError(
        err: Error & { code?: string | number; signal?: string },
        stderr: string,
        ref: string,
    ): SecretResolverError {
        const codeStr = typeof err.code === 'string' ? err.code : '';
        const codeNum = typeof err.code === 'number' ? err.code : NaN;

        if (codeStr === 'ENOENT') {
            return new SecretResolverError(
                'OP_CLI_MISSING',
                `op CLI not found in PATH — install 1Password CLI or use plain bearer field instead of bearer_ref.`,
            );
        }

        // W12-R1.2 (A5-S4): the `op` CLI returns "isn't an item" / "item
        // not found" on stderr when the ref points to a nonexistent item.
        // Surfaced as generic OP_EXEC_FAILED pre-W12, which left the
        // customer guessing whether their ref shape was wrong vs whether
        // the item was deleted. Brand it explicitly so the recovery hint
        // can name the right knob.
        const stderrLower = (stderr || '').toLowerCase();
        if (
            stderrLower.includes(`isn't an item`)
            || stderrLower.includes('item not found')
        ) {
            return new SecretResolverError(
                'OP_ITEM_NOT_FOUND',
                `1Password item not found at '${ref}'. `
                + 'Verify the ref points to an existing item, or update sites.json bearer_ref.',
            );
        }

        if (Number.isFinite(codeNum)) {
            const hint = OP_EXIT_CODE_HINTS[codeNum];
            if (codeNum === 6) {
                return new SecretResolverError(
                    'OP_NOT_SIGNED_IN',
                    hint ?? `op exited 6 — not signed in.`,
                );
            }
            return new SecretResolverError(
                'OP_EXEC_FAILED',
                `op read '${ref}' failed with exit ${codeNum}: ${(stderr || '').trim() || (hint ?? 'no stderr')}`,
            );
        }

        return new SecretResolverError(
            'OP_EXEC_FAILED',
            `op read '${ref}' failed: ${err.message}`,
        );
    }
}

/**
 * Composite resolver — chooses Plain/Op per site at resolve-time. This is
 * the resolver wired into the registry in W4; W2 ships it standalone so
 * unit tests cover the routing logic in isolation from any caching.
 */
export class CompositeSecretResolver implements SecretResolver {
    constructor(
        private readonly plain: SecretResolver = new PlainSecretResolver(),
        private readonly op: SecretResolver = new OpSecretResolver(),
    ) {}

    async resolve(site: SiteEntryT): Promise<ResolvedBearer> {
        if (site.bearer_ref !== undefined) {
            return this.op.resolve(site);
        }
        return this.plain.resolve(site);
    }
}

/** Default factory consumed by the W4 registry / W5 auth pipeline. */
export function defaultSecretResolver(): CompositeSecretResolver {
    return new CompositeSecretResolver();
}
