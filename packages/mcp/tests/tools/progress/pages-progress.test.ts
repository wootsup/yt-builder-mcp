/**
 * Wave G.5 + Round-1.5 — page_save / page_publish progress instrumentation.
 *
 * THREE-phase contract for page-level mutations (Round-1.5 revert of the
 * Round-1 2-phase amendment per Thomas-mandate "alle Findings ausnahmslos
 * strukturell+systemisch im Code"):
 *
 *   0/3  "Sending write request"   — BEFORE the REST dispatch
 *   1/3  "Server processing"       — synthetic intermediate, emitted
 *                                    AFTER dispatch starts, BEFORE await
 *                                    completes; lets the MCP-client UI
 *                                    surface a mid-flight indicator
 *                                    matching the reference UX intent
 *                                    without requiring an SSE-streaming
 *                                    REST endpoint.
 *   2/3  "Confirmed by server"     — AFTER the 2xx response
 *
 * On error (REST 4xx/5xx), phases 0/3 and 1/3 are emitted (the synthetic
 * intermediate fires before the await resolves) but the final 2/3 is NOT.
 * The contract is symmetric with element_* tools, which retain the
 * coarser 2-phase pattern (those mutations are quick — design spec §4
 * scopes the 3-phase intermediate to page_save / page_publish only).
 *
 * @license MIT
 */

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import { describe, expect, it, vi } from 'vitest';

import type { ClientPool } from '../../../src/sites/client-pool.js';
import { buildPagesTools } from '../../../src/tools/pages.js';
import type { HandlerExtra } from '../../../src/tools/tool-builder.js';
import { makeTestPool } from '../../helpers/test-pool.js';

function fakeClient(handler: (url: string, init: RequestInit) => Response | Promise<Response>): ClientPool {
    return makeTestPool({
        baseUrl: 'https://example.com',
        bearer: 't',
        fetch: vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = typeof input === 'string' ? input : input.toString();
            return handler(url, init ?? {});
        }) as unknown as typeof fetch,
    });
}

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function makeExtra(): { extra: HandlerExtra; sendNotification: ReturnType<typeof vi.fn> } {
    const sendNotification = vi.fn(async () => undefined);
    const extra: HandlerExtra = {
        _meta: { progressToken: 'tok' },
        sendNotification,
    };
    return { extra, sendNotification };
}

function findTool(tools: ReturnType<typeof buildPagesTools>, name: string) {
    const t = tools.find((x) => x.name === name);
    if (!t) throw new Error(`Tool ${name} not found`);
    return t;
}

function progressFrames(send: ReturnType<typeof vi.fn>): Array<{ progress: number; total: number; message?: string }> {
    return send.mock.calls.map((c) => {
        const n = c[0] as { params: { progress: number; total: number; message?: string } };
        return n.params;
    });
}

const CASES: ReadonlyArray<{ name: string; toolName: string }> = [
    { name: 'page_save', toolName: 'yootheme_builder_page_save' },
    { name: 'page_publish', toolName: 'yootheme_builder_page_publish' },
];

describe('Wave G.5 + R1.5 — page_* 3-phase progress instrumentation', () => {
    for (const c of CASES) {
        describe(c.name, () => {
            it('a) no progress token → no sendNotification calls, tool still works', async () => {
                const tools = buildPagesTools(fakeClient(() => jsonResponse({ ok: true })));
                const result = await findTool(tools, c.toolName).handler({
                    template_id: 'home',
                    etag: '"e0"',
                } as never);
                expect(result.isError).not.toBe(true);
            });

            it('b) with progress token → reports phase 0, phase 1 (synthetic server-processing), phase 2 in order', async () => {
                const tools = buildPagesTools(fakeClient(() => jsonResponse({ ok: true })));
                const { extra, sendNotification } = makeExtra();
                const result = await findTool(tools, c.toolName).handler(
                    { template_id: 'home', etag: '"e0"' } as never,
                    extra,
                );
                expect(result.isError).not.toBe(true);
                const frames = progressFrames(sendNotification);
                expect(frames).toHaveLength(3);
                expect(frames[0]!.progress).toBe(0);
                expect(frames[0]!.total).toBe(3);
                expect(frames[0]!.message).toBe('Sending write request');
                expect(frames[1]!.progress).toBe(1);
                expect(frames[1]!.total).toBe(3);
                expect(frames[1]!.message).toBe('Server processing');
                expect(frames[2]!.progress).toBe(2);
                expect(frames[2]!.total).toBe(3);
                expect(frames[2]!.message).toBe('Confirmed by server');
            });

            it('c) handler error path → pre-call + synthetic-intermediate frames reported; final confirm NOT sent', async () => {
                const tools = buildPagesTools(
                    fakeClient(() => jsonResponse({ error: 'fail' }, 500)),
                );
                const { extra, sendNotification } = makeExtra();
                const result = await findTool(tools, c.toolName).handler(
                    { template_id: 'home', etag: '"e0"' } as never,
                    extra,
                );
                expect(result.isError).toBe(true);
                const frames = progressFrames(sendNotification);
                expect(frames).toHaveLength(2);
                expect(frames[0]!.progress).toBe(0);
                expect(frames[0]!.total).toBe(3);
                expect(frames[0]!.message).toBe('Sending write request');
                expect(frames[1]!.progress).toBe(1);
                expect(frames[1]!.total).toBe(3);
                expect(frames[1]!.message).toBe('Server processing');
            });
        });
    }
});
