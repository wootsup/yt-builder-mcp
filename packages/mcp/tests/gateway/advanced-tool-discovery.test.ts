/**
 * Integration test — gateway discovery mode end-to-end via createServer.
 *
 *  - Call `yootheme_builder_advanced` with just `{ tool }`.
 *  - Verify the response carries the target's description,
 *    JSON-projected input schema, and annotations.
 *  - Target handler is NOT invoked.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { RestClient } from '../../src/client.js';
import { createServer } from '../../src/server.js';
import { collectAllRegisteredTools } from '../../src/gateway/test-support.js';

function makeClient(): RestClient {
    return new RestClient({
        baseUrl: 'https://example.com',
        bearerToken: 't',
        fetch: vi.fn(async () => {
            throw new Error(
                'no network call expected in discovery mode',
            );
        }) as unknown as typeof fetch,
    });
}

describe('yootheme_builder_advanced — discovery mode', () => {
    it('returns inputSchema (JSON-projected) for a captured tool, without invoking it', async () => {
        const { mcp, capturing } = createServer({ client: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        const gateway = all.yootheme_builder_advanced;
        expect(gateway).toBeDefined();

        // Pick any captured advanced tool — pages.save is mutating.
        const result = await gateway!.handler({ tool: 'yootheme_builder_page_save' });
        expect(result.isError).toBeFalsy();

        const sc = (result as { structuredContent?: { tool: string; inputSchema: unknown; annotations: unknown; description: string } }).structuredContent;
        expect(sc).toBeDefined();
        expect(sc!.tool).toBe('yootheme_builder_page_save');
        const schema = sc!.inputSchema as { type?: string; properties?: Record<string, unknown> };
        expect(schema.type).toBe('object');
        expect(schema.properties).toBeDefined();
        expect(sc!.description.length).toBeGreaterThan(0);
        // Annotations are present (mutating shape)
        expect(sc!.annotations).toBeDefined();
    });

    it('discovery text contains the tool name and a call-back hint', async () => {
        const { mcp, capturing } = createServer({ client: makeClient() });
        const all = collectAllRegisteredTools(mcp, capturing);
        const gateway = all.yootheme_builder_advanced!;

        const result = await gateway.handler({ tool: 'yootheme_builder_element_unbind_source' });
        const text = result.content[0]!.text;
        expect(text).toContain('Discovery for yootheme_builder_element_unbind_source');
        expect(text).toContain('Call yootheme_builder_advanced again');
    });
});
