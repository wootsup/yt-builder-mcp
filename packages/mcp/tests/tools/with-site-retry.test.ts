/**
 * W12-R3 (F-A4-F3 / F-A5-S2) — `withSiteRetry` behavioural pin.
 *
 * The W12-R2A retry-helper wraps every site-bound tool handler in a
 * 1-shot retry-on-401: when the cached `RestClient` returns 401, the
 * pool's cache slot for that site is invalidated so the next
 * `pool.resolve` re-runs `secretResolver.resolve` and constructs a
 * fresh client. A second 401 surfaces a structured `bearer_invalid`
 * error with an actionable hint.
 *
 * The unit-suite previously covered the retry-helper through individual
 * site-bound tools that all happen to use it (pages / elements /
 * sources) — but the helper itself had no isolated pin that:
 *
 *   - on happy-path: invalidate is NEVER called.
 *   - on 1×401-then-200: invalidate is called EXACTLY once.
 *   - on 2×401: structured `bearer_invalid` error is returned with the
 *     actionable hint substring + the resolved site id in context.
 *   - on non-401 RestError: propagates without invalidate (preserves
 *     the outer try/catch's hint-shaping seam).
 *
 * These four behavioural pins are the FULL contract — a future change
 * that, say, swallowed the second 401 or invalidated on every error
 * would fail one or more.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { RestError } from '../../src/errors.js';
import {
    ClientPool,
    type PoolResolution,
} from '../../src/sites/client-pool.js';
import type { ResolvedSite } from '../../src/sites/registry.js';
import { withSiteRetry } from '../../src/tools/pool-resolve-helper.js';
import { WordPressPlatform } from '../../src/platform/index.js';

/**
 * Build a synthetic pool stub whose `resolve` always returns the same
 * `site` descriptor + a placeholder `client` (the helper never reaches
 * INTO the client — it only feeds it to the user-supplied `fn`).
 *
 * `invalidate` is a `vi.fn` so tests can assert the call-count.
 */
function makeStubPool(): { pool: ClientPool; site: ResolvedSite; invalidateSpy: ReturnType<typeof vi.fn> } {
    const platform = new WordPressPlatform('https://acme.com');
    const site: ResolvedSite = {
        id: 'wp-acme',
        url: 'https://acme.com',
        platform,
        isDefault: true,
        bearerSource: 'plain',
    };
    const invalidateSpy = vi.fn();
    const fakeClient = {} as unknown as PoolResolution['client'];

    // Use Object.create to fabricate a structural ClientPool — only
    // `resolve` + `invalidate` are touched by `withSiteRetry` so we
    // narrow the surface intentionally.
    const pool = {
        async resolve(_siteId?: string): Promise<PoolResolution> {
            return { client: fakeClient, site };
        },
        invalidate(siteId: string): void {
            invalidateSpy(siteId);
        },
    } as unknown as ClientPool;

    return { pool, site, invalidateSpy };
}

function rest401(): RestError {
    return new RestError({
        status: 401,
        code: 'rest_forbidden',
        message: 'bearer rejected',
        body: { message: 'bearer rejected' },
    });
}

describe('W12-R3 — withSiteRetry behavioural pins', () => {
    it('happy path: fn returns ToolResult, pool.invalidate is NEVER called, fn invoked exactly once', async () => {
        const { pool, invalidateSpy } = makeStubPool();
        const fn = vi.fn(async () => ({
            content: [{ type: 'text' as const, text: 'ok' }],
        }));

        const result = await withSiteRetry(pool, 'wp-acme', fn);
        expect((result.content[0] as { text?: string }).text).toBe('ok');
        expect(fn).toHaveBeenCalledTimes(1);
        expect(invalidateSpy).not.toHaveBeenCalled();
    });

    it('401 once then 200: pool.invalidate(site_id) called EXACTLY 1x, fn called 2x, final result is the 200', async () => {
        const { pool, invalidateSpy } = makeStubPool();
        const fn = vi.fn()
            .mockImplementationOnce(async () => { throw rest401(); })
            .mockImplementationOnce(async () => ({
                content: [{ type: 'text' as const, text: 'recovered' }],
            }));

        const result = await withSiteRetry(pool, 'wp-acme', fn);
        expect((result.content[0] as { text?: string }).text).toBe('recovered');
        expect(fn).toHaveBeenCalledTimes(2);
        // Invalidation uses the RESOLVED site.id so it works even when
        // siteId was undefined (default-site case) — assert the literal id.
        expect(invalidateSpy).toHaveBeenCalledTimes(1);
        expect(invalidateSpy).toHaveBeenCalledWith('wp-acme');
    });

    it('401 twice: structured bearer_invalid error with hint substrings "test-site" + "sites.json" + site_id in context', async () => {
        const { pool } = makeStubPool();
        const fn = vi.fn(async () => { throw rest401(); });

        const result = await withSiteRetry(pool, 'wp-acme', fn);
        expect(result.isError).toBe(true);
        const text = result.content[0]?.text ?? '';
        const parsed = JSON.parse(text) as {
            code: string;
            status: number;
            context: { site_id: string };
            hint: string;
        };
        expect(parsed.code).toBe('bearer_invalid');
        expect(parsed.status).toBe(401);
        expect(parsed.context.site_id).toBe('wp-acme');
        // Actionable hint must steer the operator to the verification
        // CLI AND the sites.json edit path — both load-bearing for
        // self-recovery.
        expect(parsed.hint).toContain('test-site');
        expect(parsed.hint).toContain('sites.json');
        // fn was called twice (initial attempt + post-invalidate retry).
        expect(fn).toHaveBeenCalledTimes(2);
    });

    it('non-401 RestError propagates to the outer try/catch WITHOUT invalidate (lets handler shape with domain hint)', async () => {
        const { pool, invalidateSpy } = makeStubPool();
        const notFound = new RestError({
            status: 404,
            code: 'rest_no_route',
            message: 'route not found',
            body: { message: 'route not found' },
        });
        const fn = vi.fn(async () => { throw notFound; });

        await expect(withSiteRetry(pool, 'wp-acme', fn)).rejects.toBe(notFound);
        // Critical: invalidate MUST NOT be called for non-401 errors —
        // a 404 means "wrong path", invalidating the bearer cache would
        // mask the real issue + waste a fresh op-read on the next call.
        expect(invalidateSpy).not.toHaveBeenCalled();
        // And only the FIRST attempt ran — non-401s skip the retry.
        expect(fn).toHaveBeenCalledTimes(1);
    });
});
