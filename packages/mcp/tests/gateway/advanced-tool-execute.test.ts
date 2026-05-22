/**
 * Integration test — gateway execute mode dispatches the real captured
 * handler with validated args via createServer.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { RestClient } from '../../src/client.js';
import { createServer } from '../../src/server.js';
import { collectAllRegisteredTools } from '../../src/gateway/test-support.js';

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('yootheme_builder_advanced — execute mode', () => {
    it('dispatches a captured tool with parsed arguments and the same extra context', async () => {
        // Simulate the platform handler returning a save-success payload.
        const fetchMock = vi.fn(async () => {
            return jsonResponse({ saved: true, etag: 'e1' });
        }) as unknown as typeof fetch;

        const client = new RestClient({
            baseUrl: 'https://example.com',
            bearerToken: 't',
            fetch: fetchMock,
        });
        const { mcp, capturing } = createServer({ client });
        const all = collectAllRegisteredTools(mcp, capturing);
        const gateway = all.yootheme_builder_advanced!;

        const result = await gateway.handler({
            tool: 'yootheme_builder_page_save',
            arguments: { template_id: '123', etag: 'e0' },
        });

        // Captured handler ran (REST client hit). Result text is the
        // jsonResult shape from pages.ts.
        expect(fetchMock).toHaveBeenCalled();
        expect(result.isError).toBeFalsy();
        expect(result.content[0]!.text).toContain('saved');
    });

    it('rejects invalid arguments (unknown key) with a structured error', async () => {
        const client = new RestClient({
            baseUrl: 'https://example.com',
            bearerToken: 't',
        });
        const { mcp, capturing } = createServer({ client });
        const all = collectAllRegisteredTools(mcp, capturing);
        const gateway = all.yootheme_builder_advanced!;

        const result = await gateway.handler({
            tool: 'yootheme_builder_page_save',
            arguments: { template_id: '123', etag: 'e0', unknown: true },
        });
        expect(result.isError).toBe(true);
        const text = result.content[0]!.text;
        expect(text).toContain('Invalid arguments');
        expect(text).toContain('yootheme_builder_page_save');
    });
});
