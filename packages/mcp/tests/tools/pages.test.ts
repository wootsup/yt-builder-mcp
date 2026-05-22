/**
 * Tests for the page tools — URL composition + error mapping.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { RestClient } from '../../src/client.js';
import { buildPagesTools } from '../../src/tools/pages.js';

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

function findTool(tools: ReturnType<typeof buildPagesTools>, name: string) {
    const t = tools.find((x) => x.name === name);
    if (!t) throw new Error(`Tool ${name} not found`);
    return t;
}

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('buildPagesTools', () => {
    it('pages_list calls GET /pages and projects items + etag into structuredContent', async () => {
        const seen: string[] = [];
        const tools = buildPagesTools(
            fakeClient((url) => {
                seen.push(url);
                return jsonResponse({
                    pages: [
                        { id: 'home', label: 'Home', type: 'page', elements_count: 5 },
                    ],
                    etag: 'e0',
                });
            }),
        );
        const result = await findTool(tools, 'yootheme_builder_pages_list').handler({});
        expect(seen[0]).toContain('/wp-json/yt-builder-mcp/v1/pages');
        expect(result.structuredContent).toMatchObject({
            items: [{ id: 'home', label: 'Home', type: 'page', elements_count: 5 }],
            total: 1,
            etag: 'e0',
        });
    });

    it('page_get_layout encodes template_id into the URL', async () => {
        const seen: string[] = [];
        const tools = buildPagesTools(
            fakeClient((url) => {
                seen.push(url);
                return jsonResponse({ layout: {}, etag: 'e0' });
            }),
        );
        await findTool(tools, 'yootheme_builder_page_get_layout').handler({
            template_id: 'post-archive',
        });
        expect(seen[0]).toContain('/pages/post-archive/layout');
    });

    it('page_save sends If-Match when etag is provided', async () => {
        let seenIfMatch: string | null = null;
        const tools = buildPagesTools(
            fakeClient((_url, init) => {
                seenIfMatch = new Headers(init.headers).get('If-Match');
                return jsonResponse({ saved: true, etag: 'e1' });
            }),
        );
        await findTool(tools, 'yootheme_builder_page_save').handler({
            template_id: 'default',
            etag: '"e0"',
        });
        expect(seenIfMatch).toBe('"e0"');
    });

    it('returns a structured error on REST failure', async () => {
        const tools = buildPagesTools(
            fakeClient(() => jsonResponse({ code: 'x', message: 'nope' }, 404)),
        );
        const result = await findTool(tools, 'yootheme_builder_page_get_layout').handler({
            template_id: 'missing',
        });
        expect(result.isError).toBe(true);
        const parsed = JSON.parse(result.content[0]!.text) as Record<string, unknown>;
        expect(parsed.error).toBe('nope');
        expect(parsed.status).toBe(404);
        expect(parsed.context).toMatchObject({ template_id: 'missing' });
        expect(parsed.hint).toBeTypeOf('string');
    });
});
