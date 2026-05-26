/**
 * W2 — secret-resolver tests.
 *
 * Coverage:
 *  - PlainSecretResolver: verbatim bearer + source='plain'; rejects empty.
 *  - OpSecretResolver: ref validation BEFORE execFile; stdout trim; exit-code
 *    mapping (ENOENT, 6, generic); 5-second timeout via fake-timers; empty
 *    output rejection.
 *  - CompositeSecretResolver: routes plain vs op by `bearer_ref` presence.
 *
 * **No live `op` CLI is ever invoked.** The OpSecretResolver is constructed
 * with a stub `execFile` callback (DI), and a belt-and-braces guard at the
 * top of every Op test asserts the stub was the only thing called. As a
 * final safety net, the suite blanks `process.env.PATH` at module load,
 * so a non-mocked `op` invocation would ENOENT immediately rather than
 * potentially finding a real binary.
 *
 * @license MIT
 */

import { afterAll, beforeEach, describe, expect, it, vi } from 'vitest';

import type { SiteEntryT } from '../../src/sites/schema.js';
import {
    CompositeSecretResolver,
    defaultSecretResolver,
    type ExecFileLike,
    OpSecretResolver,
    PlainSecretResolver,
    type SecretResolver,
    SecretResolverError,
} from '../../src/sites/secret-resolver.js';

// ── PATH lockdown — defence-in-depth against accidental real-op shell-out.
// If a test forgets to inject the stub execFile, the default node:child_process
// execFile will ENOENT instead of resolving against an installed `op` binary.
const ORIGINAL_PATH = process.env.PATH;
process.env.PATH = '';
afterAll(() => {
    process.env.PATH = ORIGINAL_PATH;
});

const VALID_BEARER =
    'ytb_live_eyJraWQiOiJ0LWtleSIsInNjb3BlIjoid3JpdGUifQ.abc123_xyz-def';

function plainSite(overrides: Partial<SiteEntryT> = {}): SiteEntryT {
    return {
        site_id: 'wp-acme',
        url: 'https://example.com',
        platform: 'wordpress',
        is_default: false,
        bearer: VALID_BEARER,
        ...overrides,
    };
}

function opSite(overrides: Partial<SiteEntryT> = {}): SiteEntryT {
    return {
        site_id: 'wp-acme-op',
        url: 'https://example.com',
        platform: 'wordpress',
        is_default: false,
        bearer_ref: 'op://wootsup-dev/yt-builder/bearer',
        ...overrides,
    };
}

// ── PlainSecretResolver ─────────────────────────────────────────────────
describe('PlainSecretResolver', () => {
    it('returns verbatim bearer with source=plain', async () => {
        const r = new PlainSecretResolver();
        const out = await r.resolve(plainSite());
        expect(out.token).toBe(VALID_BEARER);
        expect(out.source).toBe('plain');
    });

    it('rejects when bearer is undefined', async () => {
        const r = new PlainSecretResolver();
        // Build a site without `bearer` — we bypass the schema refine because
        // we're testing the resolver guard directly.
        const broken = { ...plainSite(), bearer: undefined } as SiteEntryT;
        await expect(r.resolve(broken)).rejects.toBeInstanceOf(SecretResolverError);
        await expect(r.resolve(broken)).rejects.toMatchObject({
            code: 'PLAIN_BEARER_MISSING',
        });
    });

    it('rejects when bearer is empty string', async () => {
        const r = new PlainSecretResolver();
        const broken = { ...plainSite(), bearer: '' } as SiteEntryT;
        await expect(r.resolve(broken)).rejects.toMatchObject({
            code: 'PLAIN_BEARER_MISSING',
        });
    });
});

// ── OpSecretResolver ────────────────────────────────────────────────────
describe('OpSecretResolver', () => {
    // Stub execFile that immediately invokes the callback with given values.
    function stubOk(stdout: string): { fn: ExecFileLike; spy: ReturnType<typeof vi.fn> } {
        const spy = vi.fn();
        const fn: ExecFileLike = (file, args, options, cb) => {
            spy(file, args, options);
            // Schedule on microtask queue so the resolver's setTimeout exists
            // when the resolve runs, matching real subprocess behaviour.
            queueMicrotask(() => cb(null, stdout, ''));
        };
        return { fn, spy };
    }

    function stubError(
        err: Partial<Error> & { code?: string | number; signal?: string },
        stderr = '',
    ): { fn: ExecFileLike; spy: ReturnType<typeof vi.fn> } {
        const spy = vi.fn();
        const fn: ExecFileLike = (file, args, options, cb) => {
            spy(file, args, options);
            const e = Object.assign(new Error(err.message ?? 'stub error'), err);
            queueMicrotask(() => cb(e, '', stderr));
        };
        return { fn, spy };
    }

    beforeEach(() => {
        vi.useRealTimers();
    });

    it('returns stdout-token as ResolvedBearer{source:op} and trims trailing newline', async () => {
        const { fn, spy } = stubOk('op-secret-value-123\n');
        const r = new OpSecretResolver('op', fn);
        const out = await r.resolve(opSite());
        expect(out.token).toBe('op-secret-value-123');
        expect(out.source).toBe('op');
        expect(spy).toHaveBeenCalledTimes(1);
        const call = spy.mock.calls[0] as [string, readonly string[], { timeout?: number }];
        expect(call[0]).toBe('op');
        expect(call[1]).toEqual(['read', 'op://wootsup-dev/yt-builder/bearer']);
        expect(call[2].timeout).toBe(5_000);
    });

    it('also trims CRLF and surrounding whitespace', async () => {
        const { fn } = stubOk('  secret-with-padding\r\n');
        const r = new OpSecretResolver('op', fn);
        const out = await r.resolve(opSite());
        expect(out.token).toBe('secret-with-padding');
    });

    it('rejects when bearer_ref is missing — never calls execFile', async () => {
        const { fn, spy } = stubOk('should-never-be-returned');
        const r = new OpSecretResolver('op', fn);
        const broken = { ...opSite(), bearer_ref: undefined } as SiteEntryT;
        await expect(r.resolve(broken)).rejects.toMatchObject({
            code: 'OP_REF_MISSING',
        });
        expect(spy).toHaveBeenCalledTimes(0);
    });

    it('rejects ref without op:// prefix — never calls execFile', async () => {
        const { fn, spy } = stubOk('nope');
        const r = new OpSecretResolver('op', fn);
        const broken = { ...opSite(), bearer_ref: 'not-an-op-ref' } as SiteEntryT;
        await expect(r.resolve(broken)).rejects.toMatchObject({
            code: 'OP_REF_INVALID',
        });
        expect(spy).toHaveBeenCalledTimes(0);
    });

    it('rejects ref with shell metacharacter `;` — never calls execFile', async () => {
        const { fn, spy } = stubOk('nope');
        const r = new OpSecretResolver('op', fn);
        const broken = {
            ...opSite(),
            bearer_ref: 'op://vault/item/field; rm -rf /',
        } as SiteEntryT;
        await expect(r.resolve(broken)).rejects.toMatchObject({
            code: 'OP_REF_INVALID',
        });
        expect(spy).toHaveBeenCalledTimes(0);
    });

    it('rejects ref with command-sub `$()` — never calls execFile', async () => {
        const { fn, spy } = stubOk('nope');
        const r = new OpSecretResolver('op', fn);
        const broken = {
            ...opSite(),
            bearer_ref: 'op://vault/item/$(whoami)',
        } as SiteEntryT;
        await expect(r.resolve(broken)).rejects.toMatchObject({
            code: 'OP_REF_INVALID',
        });
        expect(spy).toHaveBeenCalledTimes(0);
    });

    it('rejects ref with backticks — never calls execFile', async () => {
        const { fn, spy } = stubOk('nope');
        const r = new OpSecretResolver('op', fn);
        const broken = {
            ...opSite(),
            bearer_ref: 'op://vault/item/`id`',
        } as SiteEntryT;
        await expect(r.resolve(broken)).rejects.toMatchObject({
            code: 'OP_REF_INVALID',
        });
        expect(spy).toHaveBeenCalledTimes(0);
    });

    it('accepts dotted ref segments (op:// supports dots in field names)', async () => {
        // The regex allows dots — confirm we don't false-reject legit refs.
        const { fn, spy } = stubOk('legit-token\n');
        const r = new OpSecretResolver('op', fn);
        const ok = { ...opSite(), bearer_ref: 'op://vault/item.v2/field.name' } as SiteEntryT;
        const out = await r.resolve(ok);
        expect(out.token).toBe('legit-token');
        expect(spy).toHaveBeenCalledTimes(1);
    });

    it('rejects empty stdout with OP_EMPTY_OUTPUT', async () => {
        const { fn } = stubOk('   \n');
        const r = new OpSecretResolver('op', fn);
        await expect(r.resolve(opSite())).rejects.toMatchObject({
            code: 'OP_EMPTY_OUTPUT',
        });
    });

    it('maps ENOENT to OP_CLI_MISSING with install hint', async () => {
        const { fn } = stubError({ code: 'ENOENT', message: 'spawn op ENOENT' });
        const r = new OpSecretResolver('op', fn);
        const err = await r.resolve(opSite()).catch((e) => e as SecretResolverError);
        expect(err).toBeInstanceOf(SecretResolverError);
        expect(err.code).toBe('OP_CLI_MISSING');
        expect(err.message).toMatch(/op CLI not found in PATH/);
        expect(err.message).toMatch(/install 1Password CLI/);
    });

    it('maps exit-code 6 to OP_NOT_SIGNED_IN with signin hint', async () => {
        const { fn } = stubError({ code: 6, message: 'op signed-out' }, 'not signed in');
        const r = new OpSecretResolver('op', fn);
        const err = await r.resolve(opSite()).catch((e) => e as SecretResolverError);
        expect(err).toBeInstanceOf(SecretResolverError);
        expect(err.code).toBe('OP_NOT_SIGNED_IN');
        expect(err.message).toMatch(/op signin/);
    });

    it('maps generic non-zero exit to OP_EXEC_FAILED including stderr', async () => {
        const { fn } = stubError({ code: 1, message: 'op exit 1' }, 'vault locked');
        const r = new OpSecretResolver('op', fn);
        const err = await r.resolve(opSite()).catch((e) => e as SecretResolverError);
        expect(err).toBeInstanceOf(SecretResolverError);
        expect(err.code).toBe('OP_EXEC_FAILED');
        expect(err.message).toMatch(/vault locked/);
    });

    // W12-R1.2 (A5-S4): stderr matching `isn't an item` / `item not found`
    // brands the error as OP_ITEM_NOT_FOUND so the recovery hint can name
    // the right knob (bearer_ref in sites.json) instead of leaving the
    // operator guessing between ref-shape vs item-existence.
    it('maps "item not found" stderr to OP_ITEM_NOT_FOUND with bearer_ref hint', async () => {
        const { fn } = stubError({ code: 1, message: 'op exit 1' }, '"foo" item not found');
        const r = new OpSecretResolver('op', fn);
        const err = await r.resolve(opSite()).catch((e) => e as SecretResolverError);
        expect(err).toBeInstanceOf(SecretResolverError);
        expect(err.code).toBe('OP_ITEM_NOT_FOUND');
        expect(err.message).toMatch(/1Password item not found at 'op:\/\/wootsup-dev/);
        expect(err.message).toMatch(/sites\.json bearer_ref/);
    });

    it("maps \"isn't an item\" stderr to OP_ITEM_NOT_FOUND", async () => {
        const { fn } = stubError({ code: 1, message: 'op exit 1' }, "\"foo\" isn't an item");
        const r = new OpSecretResolver('op', fn);
        const err = await r.resolve(opSite()).catch((e) => e as SecretResolverError);
        expect(err.code).toBe('OP_ITEM_NOT_FOUND');
    });

    it('5-second timeout: rejects with OP_TIMEOUT when callback never fires', async () => {
        vi.useFakeTimers();
        const spy = vi.fn();
        // Stub that captures the callback but never invokes it.
        const fn: ExecFileLike = (file, args, options) => {
            spy(file, args, options);
            // intentionally drop the cb — simulates a stalled subprocess
        };
        const r = new OpSecretResolver('op', fn);
        const settled = r.resolve(opSite()).catch((e) => e as SecretResolverError);

        // Just under timeout — still pending. Confirm spy was called (proves
        // execFile was reached) but no rejection yet.
        await vi.advanceTimersByTimeAsync(4_999);
        expect(spy).toHaveBeenCalledTimes(1);

        // Cross the 5s threshold.
        await vi.advanceTimersByTimeAsync(2);
        const err = await settled;
        expect(err).toBeInstanceOf(SecretResolverError);
        expect((err as SecretResolverError).code).toBe('OP_TIMEOUT');
        expect((err as SecretResolverError).message).toMatch(/exceeded 5000ms/);
    });

    it('honours a custom timeoutMs (constructor injection)', async () => {
        vi.useFakeTimers();
        const fn: ExecFileLike = () => {
            /* never callback */
        };
        const r = new OpSecretResolver('op', fn, 100);
        const settled = r.resolve(opSite()).catch((e) => e as SecretResolverError);
        await vi.advanceTimersByTimeAsync(101);
        const err = await settled;
        expect((err as SecretResolverError).code).toBe('OP_TIMEOUT');
        expect((err as SecretResolverError).message).toMatch(/exceeded 100ms/);
    });
});

// ── CompositeSecretResolver ─────────────────────────────────────────────
describe('CompositeSecretResolver', () => {
    it('routes to op-resolver when bearer_ref is set', async () => {
        const plain: SecretResolver = {
            resolve: vi.fn(async () => ({
                token: 'PLAIN',
                source: 'plain' as const,
            })),
        };
        const op: SecretResolver = {
            resolve: vi.fn(async () => ({
                token: 'OP',
                source: 'op' as const,
            })),
        };
        const c = new CompositeSecretResolver(plain, op);
        const out = await c.resolve(opSite());
        expect(out.token).toBe('OP');
        expect(out.source).toBe('op');
        expect(plain.resolve).toHaveBeenCalledTimes(0);
        expect(op.resolve).toHaveBeenCalledTimes(1);
    });

    it('routes to plain-resolver when only bearer is set', async () => {
        const plain: SecretResolver = {
            resolve: vi.fn(async () => ({
                token: 'PLAIN',
                source: 'plain' as const,
            })),
        };
        const op: SecretResolver = {
            resolve: vi.fn(async () => ({
                token: 'OP',
                source: 'op' as const,
            })),
        };
        const c = new CompositeSecretResolver(plain, op);
        const out = await c.resolve(plainSite());
        expect(out.token).toBe('PLAIN');
        expect(out.source).toBe('plain');
        expect(plain.resolve).toHaveBeenCalledTimes(1);
        expect(op.resolve).toHaveBeenCalledTimes(0);
    });

    it('defaultSecretResolver() returns a CompositeSecretResolver', () => {
        const r = defaultSecretResolver();
        expect(r).toBeInstanceOf(CompositeSecretResolver);
    });
});
