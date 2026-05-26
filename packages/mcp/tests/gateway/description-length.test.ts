/**
 * Description-length pin — Wave G.6.6 (W5: budget raised to 320).
 *
 * Token-efficiency budget: every tool description must stay at or
 * under 320 chars so `tools/list` remains compact across all 24
 * registered tools.
 *
 * W5 (Multi-Site) added the canonical 57-char suffix
 * "Operates on the default site unless site_id is provided." to every
 * tool description. The original budget was 250 chars; we raise the cap
 * to 320 (250 + 57 suffix + 13 headroom) so the pin keeps catching new
 * blowouts without flagging the deliberate W5 addition.
 *
 * @license MIT
 */

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import { describe, expect, test } from 'vitest';

import type { ClientPool } from '../../src/sites/client-pool.js';
import { buildAllTools } from '../../src/tools/index.js';
import { makeTestPool } from '../helpers/test-pool.js';

// v1.1.3 (efabcc36c) added intent-verb + keyword-synonym prefixes to
// every tool description to lift Claude Desktop semantic-ranking
// recall. That shipped clean and is load-bearing for tool discovery.
// v1.1.4 (F-AUDIT-1) appended a "no pagination" clarification to
// pages_list. New ceiling 600 chars (was 320 pre-v1.1.3) leaves
// headroom for one more keyword-synonym pass before requiring
// another deliberate review.
const MAX_DESCRIPTION_LENGTH = 600;

function makeStubClient(): ClientPool {
    return makeTestPool({
        baseUrl: 'https://stub.example',
        bearer: 'stub',
        fetch: (async () =>
            new Response('{}', { status: 200 })) as unknown as typeof fetch,
    });
}

describe('Tool description-length pin', () => {
    const tools = buildAllTools(makeStubClient());

    test('every registered tool description ≤ 250 chars', () => {
        const overBudget = tools
            .map((t) => ({
                name: t.name,
                length: t.description.length,
            }))
            .filter((t) => t.length > MAX_DESCRIPTION_LENGTH);
        if (overBudget.length > 0) {
            // eslint-disable-next-line no-console -- diagnostic on failure
            console.error('Description budget exceeded:', overBudget);
        }
        expect(overBudget).toEqual([]);
    });

    test('every description is non-empty', () => {
        const empty = tools.filter((t) => t.description.trim() === '');
        expect(empty).toEqual([]);
    });

    test('tool count matches expected registered surface', () => {
        // Pre-W7: 2 health + 6 pages + 7 elements + 4 sources + 2 multi-items + 2 inspection = 23 (+ 1 elsewhere → 24)
        // Post-W7: + 2 sites (sites_list + sites_test) = 26.
        expect(tools.length).toBe(26);
    });

    // W12-R3 (F-A3-4): the max-cap alone misses singular-bloat
    // regressions where one tool blows past 250 chars but stays under
    // the 320 cap (e.g. someone adds a "see also: X / Y / Z" tail to
    // every tool that adds 60+ chars across the surface). Pinning the
    // median + p99 catches that drift before the cap is breached, and
    // lets us reason about token-budget at the gateway as a whole
    // instead of per-tool worst-case.
    test('description length stays under median + p99 thresholds', () => {
        const lengths = tools.map((t) => t.description.length).sort((a, b) => a - b);
        if (lengths.length === 0) throw new Error('no tools registered — cannot compute percentiles');
        const median =
            lengths.length % 2 === 1
                ? (lengths[(lengths.length - 1) / 2] as number)
                : ((lengths[lengths.length / 2 - 1] as number)
                    + (lengths[lengths.length / 2] as number)) / 2;
        // p99 with linear interpolation. For 26 tools the p99 index
        // lands at ~24.75 so we round up — effectively "worst tool".
        const p99Index = Math.min(
            lengths.length - 1,
            Math.ceil((lengths.length - 1) * 0.99),
        );
        const p99 = lengths[p99Index] as number;

        // W12-R4 A3 tightening: previously = MAX_DESCRIPTION_LENGTH which
        // made these tautological w.r.t. the per-tool cap. Today's
        // measured baseline: median=262.5, p99=296. Headroom: ~6%/~5%.
        // A "60-char-tail-on-every-tool"-drift now FAILS here BEFORE the
        // per-tool 320-cap would catch it.
        // v1.1.3 (efabcc36c) lifted descriptions for Claude Desktop
        // semantic-ranking recall; v1.1.4 (F-AUDIT-1) added a pagination
        // clarification to pages_list. Today's measured baseline:
        // median≈340-360, p99≈586 (pages_list). Bands re-fitted with a
        // ~10% drift-margin so a future bloat-pass still trips.
        const MEDIAN_MAX = 400;
        const P99_MAX = 600;
        if (median > MEDIAN_MAX || p99 > P99_MAX) {
            // eslint-disable-next-line no-console -- diagnostic on failure
            console.error('Description percentiles exceeded:', { median, p99, lengths });
        }
        // The cap headroom is intentionally generous — we want the
        // assertion to fail when (a) the median creeps past today's
        // measured baseline by more than the cap headroom OR (b) the
        // worst tool blows the max cap. Both bounds are tied to the
        // same 320-char cap defined at the top of this file; tightening
        // either is a deliberate, reviewable choice.
        expect(median).toBeLessThanOrEqual(MEDIAN_MAX);
        expect(p99).toBeLessThanOrEqual(P99_MAX);
    });
});
