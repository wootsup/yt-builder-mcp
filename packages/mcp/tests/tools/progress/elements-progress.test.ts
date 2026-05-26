/**
 * Wave G.5 — element_* write-tools progress instrumentation.
 *
 * Five write-handlers (element_add / update_settings / move / clone /
 * delete) report 2-phase progress when the host supplied a
 * `progressToken`:
 *
 *   phase 0 — "Sending write request"  (BEFORE the REST call)
 *   phase 2 — "Confirmed by server"    (AFTER 2xx response)
 *
 * Phase 2 is reported with `current = total = 2` so a host UI can
 * render the bar at 100% on completion.
 *
 * Per tool, three cases:
 *   a) no progress token  → no sendNotification calls, tool still works
 *   b) with progress token → both phases reported in order
 *   c) handler exception   → only the pre-call phase is reported,
 *      "Confirmed" is NOT sent (REST never returned 2xx)
 *
 * `element_delete` skips its destructive elicitation by passing
 * `confirm: true`. The progress contract is identical to the other
 * mutations and must not be coupled to the elicitation branch.
 *
 * @license MIT
 */

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import { describe, expect, it, vi } from 'vitest';

import type { ClientPool } from '../../../src/sites/client-pool.js';
import { buildElementsTools } from '../../../src/tools/elements/index.js';
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

function findTool(tools: ReturnType<typeof buildElementsTools>, name: string) {
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

interface WriteCase {
    readonly name: string;
    readonly toolName: string;
    readonly input: Record<string, unknown>;
}

const CASES: readonly WriteCase[] = [
    {
        name: 'element_add',
        toolName: 'yootheme_builder_element_add',
        input: {
            template_id: 'home',
            parent_path: '',
            element_type: 'headline',
            etag: '"e0"',
        },
    },
    {
        name: 'element_update_settings',
        toolName: 'yootheme_builder_element_update_settings',
        input: {
            template_id: 'home',
            element_path: '/0',
            props: { title: 'x' },
            etag: '"e0"',
        },
    },
    {
        name: 'element_move',
        toolName: 'yootheme_builder_element_move',
        input: {
            template_id: 'home',
            element_path: '/0',
            to_parent_path: '',
            to_index: 0,
            etag: '"e0"',
        },
    },
    {
        name: 'element_clone',
        toolName: 'yootheme_builder_element_clone',
        input: { template_id: 'home', element_path: '/0', etag: '"e0"' },
    },
    {
        name: 'element_delete',
        toolName: 'yootheme_builder_element_delete',
        input: {
            template_id: 'home',
            element_path: '/0',
            etag: '"e0"',
            confirm: true, // bypass elicitation — orthogonal to progress
        },
    },
];

describe('Wave G.5 — element_* progress instrumentation', () => {
    for (const c of CASES) {
        describe(c.name, () => {
            it('a) no progress token → no sendNotification calls, tool still works', async () => {
                const tools = buildElementsTools(fakeClient(() => jsonResponse({ ok: true })));
                const result = await findTool(tools, c.toolName).handler(
                    c.input as never,
                    // no extra — read-style call shape
                );
                expect(result.isError).not.toBe(true);
            });

            it('b) with progress token → reports phase 0 then phase 2 in order', async () => {
                const tools = buildElementsTools(fakeClient(() => jsonResponse({ ok: true })));
                const { extra, sendNotification } = makeExtra();
                const result = await findTool(tools, c.toolName).handler(
                    c.input as never,
                    extra,
                );
                expect(result.isError).not.toBe(true);
                const frames = progressFrames(sendNotification);
                expect(frames).toHaveLength(2);
                expect(frames[0]!.progress).toBe(0);
                expect(frames[0]!.total).toBe(2);
                expect(frames[0]!.message).toBe('Sending write request');
                expect(frames[1]!.progress).toBe(2);
                expect(frames[1]!.total).toBe(2);
                expect(frames[1]!.message).toBe('Confirmed by server');
            });

            it('c) handler error path → only the pre-call frame is reported', async () => {
                const tools = buildElementsTools(
                    fakeClient(() => jsonResponse({ error: 'fail' }, 500)),
                );
                const { extra, sendNotification } = makeExtra();
                const result = await findTool(tools, c.toolName).handler(
                    c.input as never,
                    extra,
                );
                expect(result.isError).toBe(true);
                const frames = progressFrames(sendNotification);
                expect(frames).toHaveLength(1);
                expect(frames[0]!.progress).toBe(0);
                expect(frames[0]!.message).toBe('Sending write request');
            });
        });
    }
});
