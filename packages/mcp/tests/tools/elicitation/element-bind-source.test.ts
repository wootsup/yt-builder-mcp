/**
 * Wave G.4.3 — element_bind_source ambiguity-resolution tests.
 *
 * Unlike G.4.1/G.4.2 (destructive-confirm), bind_source uses
 * `elicitChoice` to disambiguate when `source_name` matches ≥2 sources
 * (cross-plugin collision, e.g. an apimapper flow + a wordpress source
 * both named "Posts").
 *
 * Four cases:
 *   1. explicit `source_id` → skips /sources lookup AND elicitation,
 *      binds directly
 *   2. unique `source_name` (1 match) → binds directly, no elicit
 *   3. ambiguous `source_name` (3 matches) → elicitChoice triggered
 *      with the 3 candidate ids
 *   4. elicit accept → binds with the chosen source_id
 *   5. elicit decline / unsupported host → returns
 *      `ambiguityFallbackError` listing every candidate (no REST PUT)
 *
 * @license MIT
 */

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import { describe, expect, it, vi } from 'vitest';

import type { ClientPool } from '../../../src/sites/client-pool.js';
import type { McpServerWithElicitation } from '../../../src/tools/elicitation.js';
import { buildSourcesTools } from '../../../src/tools/sources.js';
import { makeTestPool, stripSitePrefix } from '../../helpers/test-pool.js';

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

/**
 * Compose a /sources REST payload (grouped shape) where N copies of
 * `source_name` exist across different origins — for ambiguity tests.
 */
function multiSourcesPayload(source_name: string, copies: Array<{ origin: string; label?: string; kind?: string }>): unknown {
    const grouped: Record<string, Array<{ name: string; label: string; kind: string }>> = {
        apimapper: [],
        wordpress: [],
        essentials: [],
    };
    for (const c of copies) {
        const row = { name: source_name, label: c.label ?? source_name, kind: c.kind ?? 'list' };
        const bucket = grouped[c.origin] ?? (grouped[c.origin] = []);
        bucket.push(row);
    }
    return { sources: grouped };
}

describe('element_bind_source — ambiguity resolution (Wave G.4.3)', () => {
    it('1) explicit source_id skips /sources lookup AND elicitation, binds directly', async () => {
        const calls: Array<{ url: string; method: string; body?: string }> = [];
        const { elicitation, elicitInput } = makeElicitation({ action: 'accept' });
        const tools = buildSourcesTools(
            fakeClient((url, init) => {
                calls.push({
                    url,
                    method: (init.method ?? 'GET').toUpperCase(),
                    body: init.body as string | undefined,
                });
                return jsonResponse({ etag: 'e1' });
            }),
            { elicitation },
        );
        await findTool(tools, 'yootheme_builder_element_bind_source').handler({
            template_id: 'default',
            element_path: '/0/children/1',
            source_name: 'Posts',
            source_id: 'apimapper:Posts',
            etag: '"e0"',
        });
        // No /sources GET, only the bind PUT
        expect(calls.length).toBe(1);
        expect(calls[0]!.method).toBe('PUT');
        expect(calls[0]!.url).toContain('/binding');
        expect(elicitInput).not.toHaveBeenCalled();
    });

    it('2) unique source_name (1 match) binds directly, no elicit', async () => {
        const calls: Array<{ url: string; method: string }> = [];
        const { elicitation, elicitInput } = makeElicitation({ action: 'accept' });
        const tools = buildSourcesTools(
            fakeClient((url, init) => {
                const method = (init.method ?? 'GET').toUpperCase();
                calls.push({ url, method });
                if (url.endsWith('/v1/sources')) {
                    return jsonResponse(
                        multiSourcesPayload('Posts', [{ origin: 'apimapper', label: 'Posts' }]),
                    );
                }
                return jsonResponse({ etag: 'e1' });
            }),
            { elicitation },
        );
        await findTool(tools, 'yootheme_builder_element_bind_source').handler({
            template_id: 'default',
            element_path: '/0/children/1',
            source_name: 'Posts',
            etag: '"e0"',
        });
        // /sources GET (lookup) + bind PUT, no elicitation
        expect(calls.some((c) => c.url.includes('/v1/sources') && c.method === 'GET')).toBe(true);
        expect(calls.some((c) => c.method === 'PUT')).toBe(true);
        expect(elicitInput).not.toHaveBeenCalled();
    });

    it('3) ambiguous source_name (3 matches) triggers elicitChoice with the 3 candidate ids', async () => {
        const { elicitation, elicitInput } = makeElicitation({
            action: 'accept',
            content: { choice: 'wordpress:Posts' },
        });
        const tools = buildSourcesTools(
            fakeClient((url) => {
                if (url.endsWith('/v1/sources')) {
                    return jsonResponse(
                        multiSourcesPayload('Posts', [
                            { origin: 'apimapper' },
                            { origin: 'wordpress' },
                            { origin: 'essentials' },
                        ]),
                    );
                }
                return jsonResponse({ etag: 'e1' });
            }),
            { elicitation },
        );
        await findTool(tools, 'yootheme_builder_element_bind_source').handler({
            template_id: 'default',
            element_path: '/0/children/1',
            source_name: 'Posts',
            etag: '"e0"',
        });
        expect(elicitInput).toHaveBeenCalledTimes(1);
        const params = elicitInput.mock.calls[0]![0] as { mode: string; message: string; requestedSchema: { properties: { choice: { enum: string[] } } } };
        expect(params.mode).toBe('form');
        expect(params.message).toContain('Posts');
        // toolkit's elicitChoice emits a `choice` property with an enum
        // listing every candidate id.
        const enumIds = params.requestedSchema.properties.choice.enum;
        expect(new Set(enumIds)).toEqual(
            new Set(['apimapper:Posts', 'wordpress:Posts', 'essentials:Posts']),
        );
    });

    it('4) elicit accept binds with the chosen source_id', async () => {
        let putBody: string | undefined;
        const { elicitation } = makeElicitation({
            action: 'accept',
            content: { choice: 'wordpress:Posts' },
        });
        const tools = buildSourcesTools(
            fakeClient((url, init) => {
                if (url.endsWith('/v1/sources')) {
                    return jsonResponse(
                        multiSourcesPayload('Posts', [
                            { origin: 'apimapper' },
                            { origin: 'wordpress' },
                        ]),
                    );
                }
                if ((init.method ?? 'GET').toUpperCase() === 'PUT') {
                    putBody = init.body as string | undefined;
                }
                return jsonResponse({ etag: 'e1' });
            }),
            { elicitation },
        );
        await findTool(tools, 'yootheme_builder_element_bind_source').handler({
            template_id: 'default',
            element_path: '/0/children/1',
            source_name: 'Posts',
            etag: '"e0"',
        });
        expect(putBody).toBeDefined();
        const body = JSON.parse(putBody!) as Record<string, unknown>;
        expect(body.source_name).toBe('Posts');
        expect(body.source_id).toBe('wordpress:Posts');
    });

    it('5) elicit decline returns ambiguityFallbackError, no PUT', async () => {
        let putCalled = false;
        const { elicitation } = makeElicitation({ action: 'decline' });
        const tools = buildSourcesTools(
            fakeClient((url, init) => {
                if (url.endsWith('/v1/sources')) {
                    return jsonResponse(
                        multiSourcesPayload('Posts', [
                            { origin: 'apimapper' },
                            { origin: 'wordpress' },
                        ]),
                    );
                }
                if ((init.method ?? 'GET').toUpperCase() === 'PUT') {
                    putCalled = true;
                }
                return jsonResponse({ etag: 'e1' });
            }),
            { elicitation },
        );
        const result = await findTool(tools, 'yootheme_builder_element_bind_source').handler({
            template_id: 'default',
            element_path: '/0/children/1',
            source_name: 'Posts',
            etag: '"e0"',
        });
        expect(putCalled).toBe(false);
        expect(result.isError).toBe(true);
        const parsed = JSON.parse(stripSitePrefix(result.content[0]!.text as string)) as { context: { code: string; candidates: Array<{ id: string }> }; hint: string };
        expect(parsed.context.code).toBe('source_ambiguous');
        expect(parsed.context.candidates).toHaveLength(2);
        expect(parsed.hint).toContain('source_id');
        expect(parsed.hint).toContain('apimapper:Posts');
        expect(parsed.hint).toContain('wordpress:Posts');
    });

    it('5b) unsupported host (no elicitation injected) → ambiguityFallbackError, no PUT', async () => {
        let putCalled = false;
        const tools = buildSourcesTools(
            fakeClient((url, init) => {
                if (url.endsWith('/v1/sources')) {
                    return jsonResponse(
                        multiSourcesPayload('Posts', [
                            { origin: 'apimapper' },
                            { origin: 'wordpress' },
                        ]),
                    );
                }
                if ((init.method ?? 'GET').toUpperCase() === 'PUT') {
                    putCalled = true;
                }
                return jsonResponse({ etag: 'e1' });
            }),
            // NO elicitation injected
        );
        const result = await findTool(tools, 'yootheme_builder_element_bind_source').handler({
            template_id: 'default',
            element_path: '/0/children/1',
            source_name: 'Posts',
            etag: '"e0"',
        });
        expect(putCalled).toBe(false);
        expect(result.isError).toBe(true);
    });
});
