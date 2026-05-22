/**
 * Wave G.4.1 — element_delete elicitation tests.
 *
 * Four cases per the implementation plan:
 *   1. confirm:true → executes (DELETE) without elicitation
 *   2. confirm omitted → elicitConfirmation triggered with expected
 *      message + schema shape
 *   3. elicitation accepted (action:'accept', content:{confirm:true})
 *      → executes
 *   4. elicitation declined → returns preview/cancelled structured
 *      result, no REST call
 *
 * The McpServerWithElicitation surface only needs
 * `{ server: { elicitInput(params) } }`; tests stub it with a vi.fn()
 * so we can assert what the toolkit sent and what we returned.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { RestClient } from '../../../src/client.js';
import { buildElementsTools } from '../../../src/tools/elements/index.js';
import type { McpServerWithElicitation } from '../../../src/tools/elicitation.js';

function fakeClient(handler: (url: string, init: RequestInit) => Response | Promise<Response>): RestClient {
    return new RestClient({
        baseUrl: 'https://example.com',
        bearerToken: 't',
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

function findTool(tools: ReturnType<typeof buildElementsTools>, name: string) {
    const t = tools.find((x) => x.name === name);
    if (!t) throw new Error(`Tool ${name} not found`);
    return t;
}

function makeElicitation(response: { action: 'accept' | 'decline' | 'cancel'; content?: Record<string, unknown> }): {
    elicitation: McpServerWithElicitation;
    elicitInput: ReturnType<typeof vi.fn>;
} {
    const elicitInput = vi.fn(async () => response);
    const elicitation: McpServerWithElicitation = {
        server: { elicitInput },
    };
    return { elicitation, elicitInput };
}

describe('element_delete — elicitation (Wave G.4.1)', () => {
    it('1) confirm:true executes DELETE without elicitation', async () => {
        let seenMethod: string | undefined;
        const { elicitation, elicitInput } = makeElicitation({ action: 'accept' });
        const tools = buildElementsTools(
            fakeClient((_url, init) => {
                seenMethod = (init.method ?? 'GET').toUpperCase();
                return jsonResponse({ deleted: true });
            }),
            { elicitation },
        );
        await findTool(tools, 'yootheme_builder_element_delete').handler({
            template_id: 'default',
            element_path: '/0/children/1',
            etag: '"e0"',
            confirm: true,
        });
        expect(seenMethod).toBe('DELETE');
        expect(elicitInput).not.toHaveBeenCalled();
    });

    it('2) confirm omitted triggers elicitConfirmation with the expected message + form-mode schema', async () => {
        let restCalled = false;
        const { elicitation, elicitInput } = makeElicitation({ action: 'decline' });
        const tools = buildElementsTools(
            fakeClient(() => {
                restCalled = true;
                return jsonResponse({});
            }),
            { elicitation },
        );
        await findTool(tools, 'yootheme_builder_element_delete').handler({
            template_id: 'home',
            element_path: '/0/children/3',
            etag: '"e0"',
        });
        expect(elicitInput).toHaveBeenCalledTimes(1);
        const params = elicitInput.mock.calls[0]![0] as { mode: string; message: string; requestedSchema: unknown };
        expect(params.mode).toBe('form');
        expect(params.message).toContain('/0/children/3');
        expect(params.message).toContain('home');
        // schema must request a boolean confirm
        expect(params.requestedSchema).toMatchObject({
            type: 'object',
            properties: expect.objectContaining({
                confirm: expect.objectContaining({ type: 'boolean' }),
            }),
        });
        // declined → no REST call
        expect(restCalled).toBe(false);
    });

    it('3) elicitation accepted executes DELETE', async () => {
        let seenMethod: string | undefined;
        const { elicitation } = makeElicitation({
            action: 'accept',
            content: { confirm: true },
        });
        const tools = buildElementsTools(
            fakeClient((_url, init) => {
                seenMethod = (init.method ?? 'GET').toUpperCase();
                return jsonResponse({ deleted: true });
            }),
            { elicitation },
        );
        const result = await findTool(tools, 'yootheme_builder_element_delete').handler({
            template_id: 'default',
            element_path: '/0/children/2',
            etag: '"e0"',
        });
        expect(seenMethod).toBe('DELETE');
        expect(result.isError).not.toBe(true);
    });

    it('4) elicitation declined returns the structured cancellation preview, no REST call', async () => {
        let restCalled = false;
        const { elicitation } = makeElicitation({ action: 'decline' });
        const tools = buildElementsTools(
            fakeClient(() => {
                restCalled = true;
                return jsonResponse({});
            }),
            { elicitation },
        );
        const result = await findTool(tools, 'yootheme_builder_element_delete').handler({
            template_id: 'default',
            element_path: '/0/children/1',
            etag: '"e0"',
        });
        expect(restCalled).toBe(false);
        const parsed = JSON.parse(result.content[0]!.text) as Record<string, unknown>;
        expect(parsed.preview).toBe(true);
        expect(parsed.warning).toContain('DESTRUCTIVE');
    });

    it('fallback: without injected elicitation capability, returns preview on omitted confirm', async () => {
        let restCalled = false;
        const tools = buildElementsTools(
            fakeClient(() => {
                restCalled = true;
                return jsonResponse({});
            }),
            // NO elicitation injected — unsupported-host path
        );
        const result = await findTool(tools, 'yootheme_builder_element_delete').handler({
            template_id: 'default',
            element_path: '/0/children/1',
            etag: '"e0"',
        });
        expect(restCalled).toBe(false);
        const parsed = JSON.parse(result.content[0]!.text) as Record<string, unknown>;
        expect(parsed.preview).toBe(true);
    });
});
