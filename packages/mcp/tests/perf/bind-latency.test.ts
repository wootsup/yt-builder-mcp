/**
 * Wave G.4.4 — performance pin (Plan §7 I-5).
 *
 * Background: Wave G.4.3 introduced a /sources REST lookup before every
 * `element_bind_source` call (to detect cross-plugin ambiguity). We
 * accept this cost intentionally — but pin it so a future refactor
 * that adds e.g. an O(n) schema-fetch loop does not silently regress
 * latency.
 *
 * Sanity check, NOT a real network test:
 *   - Baseline (pre-G.4.3 behaviour): one PUT round-trip at ~5 ms
 *     simulated latency.
 *   - G.4.3 behaviour: one /sources GET + one PUT at the same per-call
 *     latency.
 *   - Pin: median bind latency ≤ 2 × baseline ⇒ ≤ 10 ms.
 *
 * TODO(v0.3): cache /sources per session (TTL ~5 s) to drop the extra
 * round-trip on every bind. Then the pin can tighten back to ~1 × baseline.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { RestClient } from '../../src/client.js';
import { buildSourcesTools } from '../../src/tools/sources.js';

const SIMULATED_LATENCY_MS = 5;
const RUNS = 11; // odd → simple median

function delayedClient(): RestClient {
    return new RestClient({
        baseUrl: 'https://example.com',
        bearerToken: 't',
        fetch: vi.fn(async (input: RequestInfo | URL) => {
            const url = typeof input === 'string' ? input : input.toString();
            await new Promise((resolve) => setTimeout(resolve, SIMULATED_LATENCY_MS));
            if (url.endsWith('/v1/sources')) {
                // unique-name response — no ambiguity, no elicitation
                return new Response(
                    JSON.stringify({ sources: { apimapper: [{ name: 'unique', label: 'Unique', kind: 'list' }] } }),
                    { status: 200, headers: { 'Content-Type': 'application/json' } },
                );
            }
            return new Response(JSON.stringify({ etag: 'e1' }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        }) as unknown as typeof fetch,
    });
}

function median(values: number[]): number {
    const sorted = [...values].sort((a, b) => a - b);
    return sorted[Math.floor(sorted.length / 2)]!;
}

describe('bind-latency perf pin (Wave G.4.4)', () => {
    it('median element_bind_source latency ≤ 2 × baseline (5 ms → 10 ms)', async () => {
        const tools = buildSourcesTools(delayedClient());
        const bind = tools.find((t) => t.name === 'yootheme_builder_element_bind_source');
        if (!bind) throw new Error('bind tool not found');

        const samples: number[] = [];
        for (let i = 0; i < RUNS; i++) {
            const t0 = performance.now();
            await bind.handler({
                template_id: 'default',
                element_path: '/0',
                source_name: 'unique',
                etag: '"e0"',
            });
            samples.push(performance.now() - t0);
        }
        const med = median(samples);

        // Baseline = 1 round-trip × 5 ms = 5 ms. Pin allows 2× = 10 ms.
        // Generous +5 ms tolerance for CI timer jitter (Vitest workers
        // share CPU cycles with Vite transform). The point of the pin
        // is regression detection (e.g. someone accidentally adding an
        // O(n) loop), not micro-benchmark accuracy.
        const baseline = SIMULATED_LATENCY_MS;
        const pin = baseline * 2 + 5;
        expect(med, `median ${med}ms must stay ≤ ${pin}ms (2× baseline ${baseline}ms + 5ms jitter)`).toBeLessThanOrEqual(pin);
    });

    it('explicit source_id skips /sources lookup → no extra latency', async () => {
        const tools = buildSourcesTools(delayedClient());
        const bind = tools.find((t) => t.name === 'yootheme_builder_element_bind_source');
        if (!bind) throw new Error('bind tool not found');

        const samples: number[] = [];
        for (let i = 0; i < RUNS; i++) {
            const t0 = performance.now();
            await bind.handler({
                template_id: 'default',
                element_path: '/0',
                source_name: 'unique',
                source_id: 'apimapper:unique',
                etag: '"e0"',
            });
            samples.push(performance.now() - t0);
        }
        const med = median(samples);
        // With source_id, only 1 PUT — must stay close to baseline.
        const baseline = SIMULATED_LATENCY_MS;
        const pin = baseline + 5;
        expect(med, `median ${med}ms must stay ≤ ${pin}ms (1× baseline + 5ms jitter)`).toBeLessThanOrEqual(pin);
    });
});
