/**
 * Token-baseline harness — Wave G.8 / Design §11 Achse 5.
 *
 * Measures the wire-visible byte-size an MCP host sees in `tools/list`
 * after Gateway-Hub + token-efficiency hardening (Waves G.1, G.3, G.6).
 * The gateway reduces an LLM-host's discovery surface from 22 tools to
 * 10 (7 essentials + 2 direct + 1 gateway), and sparse-fields trims the
 * heavy per-tool payloads — together these are expected to reduce the
 * total `tools/list` payload by ≥40% versus the pre-gateway baseline.
 *
 * Baseline snapshot file:
 *   tests/perf/baselines/tools-list-pre-gateway.json
 *
 * The snapshot mirrors what `buildAllTools()` would emit if EVERY tool
 * were registered top-level (the pre-G.1 state). Wave G.9 will regenerate
 * this snapshot from a known-good baseline run; until then we ship a
 * deterministic synthetic baseline (built from the current full tool
 * surface, so it reflects real per-tool sizes) so the harness can pin
 * the reduction target without depending on history.
 *
 * If the snapshot file is missing, the reduction-Δ assertions are skipped
 * (test still asserts the post-gateway absolute size is bounded). This
 * keeps the test useful in fresh checkouts while G.9 wires it up.
 *
 * @license MIT
 */

import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { z } from 'zod';

import { RestClient } from '../../src/client.js';
import { createServer } from '../../src/server.js';
import { buildAllTools } from '../../src/tools/index.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const BASELINE_DIR = resolve(__dirname, 'baselines');
const BASELINE_PATH = resolve(BASELINE_DIR, 'tools-list-pre-gateway.json');
/**
 * Real pre-G.0 baseline captured by Wave G.9 from the actual baseline
 * worktree (`git worktree add` + spawn stdio server + tools/list). This
 * is the SDK-emitted shape — what an MCP host would have seen pre-G.0.
 *
 * Capture script: `scripts/capture-baseline.mjs`
 * Refresh procedure: see `docs/LIVE-VERIFY-REPORT.md` (Real Baseline section).
 */
const REAL_BASELINE_PATH = resolve(BASELINE_DIR, 'tools-list-pre-g0-REAL.json');

interface ToolsListEntry {
    name: string;
    description?: string;
    annotations?: Record<string, unknown>;
    inputSchema?: unknown;
}

function makeClient(): RestClient {
    return new RestClient({ baseUrl: 'https://example.com', bearerToken: 't' });
}

/**
 * The post-G.7 surface as the SDK would emit it on `tools/list` —
 * only the 10 real-server-registered tools (7 L1 + 2 L3 + 1 gateway).
 * Captured (advanced) tools are NOT in tools/list.
 */
function postGatewayToolsList(): ToolsListEntry[] {
    const { mcp } = createServer({ client: makeClient() });
    const real = mcp as unknown as {
        _registeredTools: Record<
            string,
            {
                title?: string;
                description?: string;
                inputSchema?: Record<string, unknown>;
                annotations?: Record<string, unknown>;
            }
        >;
    };
    const entries: ToolsListEntry[] = [];
    for (const [name, t] of Object.entries(real._registeredTools)) {
        entries.push({
            name,
            description: t.description,
            annotations: t.annotations,
            inputSchema: zodShapeToJsonSchema(t.inputSchema),
        });
    }
    return entries.sort((a, b) => a.name.localeCompare(b.name));
}

/**
 * The pre-G.1 baseline surface — synthetically derived from the current
 * full 22-tool catalogue (`buildAllTools`). This is what `tools/list`
 * would have shown if every tool were registered top-level.
 *
 * We project the input schema the same way the SDK does (via
 * `z.toJSONSchema(z.object(shape))`) so the byte-counts are apples-to-
 * apples with the post-gateway projection.
 */
function preGatewayFullToolsList(): ToolsListEntry[] {
    const client = makeClient();
    const tools = buildAllTools(client);
    const entries: ToolsListEntry[] = [];
    for (const t of tools) {
        entries.push({
            name: t.name,
            description: t.description,
            annotations: t.annotations as Record<string, unknown> | undefined,
            inputSchema: zodShapeToJsonSchema(t.inputSchema),
        });
    }
    return entries.sort((a, b) => a.name.localeCompare(b.name));
}

function zodShapeToJsonSchema(shape: unknown): unknown {
    if (!shape || typeof shape !== 'object') return { type: 'object' };
    try {
        return z.toJSONSchema(z.object(shape as Record<string, z.ZodTypeAny>));
    } catch {
        return { type: 'object', _note: 'projection-failed' };
    }
}

function jsonBytes(value: unknown): number {
    return JSON.stringify(value).length;
}

describe('token-baseline harness — tools/list payload reduction', () => {
    it('post-gateway tools/list contains exactly 10 entries', () => {
        const post = postGatewayToolsList();
        expect(post.length).toBe(10);
    });

    it('post-gateway tools/list payload is bounded (<10000 bytes for 10 tools)', () => {
        // Sanity ceiling — if a future wave bloats descriptions massively,
        // this trips. Today it sits around 4-6 KB.
        const post = postGatewayToolsList();
        const bytes = jsonBytes(post);
        // eslint-disable-next-line no-console -- benchmark surface
        console.log(`[token-baseline] post-gateway tools/list = ${String(bytes)}B (10 tools)`);
        expect(bytes).toBeLessThan(10_000);
    });

    it('full pre-gateway surface (22 tools) is materially larger than post-gateway (10 tools)', () => {
        // Synthetic pre-gateway: project every tool's full schema as if
        // it lived on tools/list. This is the WORST case (no gateway).
        const post = postGatewayToolsList();
        const pre = preGatewayFullToolsList();
        const postBytes = jsonBytes(post);
        const preBytes = jsonBytes(pre);
        const reductionPct = ((preBytes - postBytes) / preBytes) * 100;
        // eslint-disable-next-line no-console -- benchmark surface
        console.log(
            `[token-baseline] synthetic-pre=${String(preBytes)}B post=${String(postBytes)}B Δ=${reductionPct.toFixed(2)}%`,
        );
        // Per Design §11 Achse 5 lower-bound: ≥30% reduction (target 40-60%).
        expect(reductionPct).toBeGreaterThanOrEqual(30);
    });

    it('per-tool sparse-fields response shrinks the payload by ≥30% on element_list', async () => {
        // Sanity check that the sparse-fields lever (G.3) still reduces a
        // representative per-tool response. Mirrors the bench in
        // sparse-fields-bench.test.ts but pins the floor at 30% explicitly
        // so a future G.10 regression on element_list surfaces here.
        const { buildElementsTools } = await import('../../src/tools/elements.js');
        const fetchImpl: typeof fetch = async () =>
            new Response(
                JSON.stringify({
                    elements: Array.from({ length: 50 }, (_, i) => ({
                        path: `/0/children/${String(i)}`,
                        element_type: 'card',
                        label: `Card ${String(i)}`,
                        props: {
                            title: `Card ${String(i)} headline goes here and is reasonably long`,
                            class: 'uk-card uk-card-default uk-card-body uk-card-hover',
                            image: `https://example.com/images/card-${String(i)}.jpg`,
                        },
                    })),
                }),
                { status: 200, headers: { 'Content-Type': 'application/json' } },
            );
        const fakeClient = new RestClient({
            baseUrl: 'https://example.com',
            bearerToken: 't',
            fetch: fetchImpl,
        });
        const tools = buildElementsTools(fakeClient);
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_list');
        if (!tool) throw new Error('element_list tool not found');
        const full = await tool.handler({ template_id: 'home' });
        const sparse = await tool.handler({ template_id: 'home', fields: ['path', 'element_type'] });
        const fullBytes = JSON.stringify(full.structuredContent?.items ?? []).length;
        const sparseBytes = JSON.stringify(sparse.structuredContent?.items ?? []).length;
        const delta = ((fullBytes - sparseBytes) / fullBytes) * 100;
        expect(delta).toBeGreaterThanOrEqual(30);
    });

    it('compares against the synthetic snapshot baseline file when present (skip-fallback)', () => {
        if (!existsSync(BASELINE_PATH)) {
            // Synthetic baseline (full 22-tool projection from current src).
            // Kept as a worst-case floor; the REAL baseline (test below) is
            // the source of truth for Design §11 Achse 5.
            // eslint-disable-next-line no-console -- benchmark surface
            console.log(`[token-baseline] synthetic baseline file not present at ${BASELINE_PATH} — skip`);
            return;
        }
        const baselineRaw = readFileSync(BASELINE_PATH, 'utf-8');
        const baseline = JSON.parse(baselineRaw) as ToolsListEntry[];
        const baselineBytes = jsonBytes(baseline);
        const postBytes = jsonBytes(postGatewayToolsList());
        const reductionPct = ((baselineBytes - postBytes) / baselineBytes) * 100;
        // eslint-disable-next-line no-console -- benchmark surface
        console.log(
            `[token-baseline] synthetic-snapshot-pre=${String(baselineBytes)}B post=${String(postBytes)}B Δ=${reductionPct.toFixed(2)}%`,
        );
        expect(reductionPct).toBeGreaterThanOrEqual(30);
    });

    it('compares against the REAL pre-G.0 baseline (SDK-emitted tools/list) — Design §11 Achse 5 target ≥40%', () => {
        // Real baseline captured by Wave G.9 from the actual baseline
        // worktree (git worktree add 5895bb8b1 + spawn stdio server +
        // JSON-RPC tools/list). This is the truth-of-record for the
        // token-Δ claim in the design doc and the live customer-facing
        // metric used in the Approval-Gate.
        if (!existsSync(REAL_BASELINE_PATH)) {
            // eslint-disable-next-line no-console -- benchmark surface
            console.log(
                `[token-baseline] REAL baseline file not present at ${REAL_BASELINE_PATH} — capture with scripts/capture-baseline.mjs`,
            );
            return;
        }
        const baselineRaw = readFileSync(REAL_BASELINE_PATH, 'utf-8');
        const baseline = JSON.parse(baselineRaw) as ToolsListEntry[];
        const baselineBytes = jsonBytes(baseline);
        const postBytes = jsonBytes(postGatewayToolsList());
        const reductionPct = ((baselineBytes - postBytes) / baselineBytes) * 100;
        // eslint-disable-next-line no-console -- benchmark surface
        console.log(
            `[token-baseline] REAL-pre-g0=${String(baselineBytes)}B post=${String(postBytes)}B Δ=${reductionPct.toFixed(2)}% (target ≥40%)`,
        );
        // Design §11 Achse 5 lower-bound for the REAL baseline is 40%.
        // (Synthetic baseline's 30% floor stays because it's a different,
        // more pessimistic projection — both must hold.)
        expect(reductionPct).toBeGreaterThanOrEqual(40);
    });
});

describe('token-baseline harness — utility helpers', () => {
    it('writes a baseline snapshot the FIRST time the harness runs with --write-baseline', () => {
        // Opt-in: dev runs `YTB_WRITE_BASELINE=1 npx vitest run tests/perf/token-baseline`
        // once after a clean checkout. Not enabled in CI.
        if (process.env.YTB_WRITE_BASELINE !== '1') return;
        const pre = preGatewayFullToolsList();
        mkdirSync(BASELINE_DIR, { recursive: true });
        writeFileSync(BASELINE_PATH, JSON.stringify(pre, null, 2), 'utf-8');
        expect(existsSync(BASELINE_PATH)).toBe(true);
    });
});
