/**
 * Wave G.4.2 — element_unbind_source elicitation tests.
 *
 * Mirror of element-delete.test.ts. Four cases:
 *   1. confirm:true → executes (DELETE /binding) without elicitation
 *   2. confirm omitted → elicitConfirmation triggered with expected
 *      message + schema shape
 *   3. elicitation accepted → executes
 *   4. elicitation declined → returns preview, no REST call
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { RestClient } from '../../../src/client.js';
import type { McpServerWithElicitation } from '../../../src/tools/elicitation.js';
import { buildSourcesTools } from '../../../src/tools/sources.js';

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

function findTool(tools: ReturnType<typeof buildSourcesTools>, name: string) {
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

describe('element_unbind_source — elicitation (Wave G.4.2)', () => {
    it('1) confirm:true executes DELETE without elicitation', async () => {
        let seenMethod: string | undefined;
        let seenUrl: string | undefined;
        const { elicitation, elicitInput } = makeElicitation({ action: 'accept' });
        const tools = buildSourcesTools(
            fakeClient((url, init) => {
                seenMethod = (init.method ?? 'GET').toUpperCase();
                seenUrl = url;
                return jsonResponse({ unbound: true });
            }),
            { elicitation },
        );
        await findTool(tools, 'yootheme_builder_element_unbind_source').handler({
            template_id: 'default',
            element_path: '/0/children/1',
            etag: '"e0"',
            confirm: true,
        });
        expect(seenMethod).toBe('DELETE');
        expect(seenUrl).toContain('/binding');
        expect(elicitInput).not.toHaveBeenCalled();
    });

    it('2) confirm omitted triggers elicitConfirmation with the expected message + form-mode schema', async () => {
        let restCalled = false;
        const { elicitation, elicitInput } = makeElicitation({ action: 'decline' });
        const tools = buildSourcesTools(
            fakeClient(() => {
                restCalled = true;
                return jsonResponse({});
            }),
            { elicitation },
        );
        await findTool(tools, 'yootheme_builder_element_unbind_source').handler({
            template_id: 'home',
            element_path: '/0/children/3',
            etag: '"e0"',
        });
        expect(elicitInput).toHaveBeenCalledTimes(1);
        const params = elicitInput.mock.calls[0]![0] as { mode: string; message: string; requestedSchema: unknown };
        expect(params.mode).toBe('form');
        expect(params.message).toContain('/0/children/3');
        expect(params.message).toContain('home');
        expect(params.requestedSchema).toMatchObject({
            type: 'object',
            properties: expect.objectContaining({
                confirm: expect.objectContaining({ type: 'boolean' }),
            }),
        });
        expect(restCalled).toBe(false);
    });

    it('3) elicitation accepted executes DELETE', async () => {
        let seenMethod: string | undefined;
        const { elicitation } = makeElicitation({
            action: 'accept',
            content: { confirm: true },
        });
        const tools = buildSourcesTools(
            fakeClient((_url, init) => {
                seenMethod = (init.method ?? 'GET').toUpperCase();
                return jsonResponse({ unbound: true });
            }),
            { elicitation },
        );
        const result = await findTool(tools, 'yootheme_builder_element_unbind_source').handler({
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
        const tools = buildSourcesTools(
            fakeClient(() => {
                restCalled = true;
                return jsonResponse({});
            }),
            { elicitation },
        );
        const result = await findTool(tools, 'yootheme_builder_element_unbind_source').handler({
            template_id: 'default',
            element_path: '/0/children/1',
            etag: '"e0"',
        });
        expect(restCalled).toBe(false);
        const parsed = JSON.parse(result.content[0]!.text) as Record<string, unknown>;
        expect(parsed.preview).toBe(true);
        expect(parsed.warning).toContain('DESTRUCTIVE');
    });
});
