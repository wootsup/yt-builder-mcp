/**
 * Tests for inspection tools.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { RestClient } from '../../src/client.js';
import { buildInspectionTools } from '../../src/tools/inspection.js';

function fakeClient(handler: (url: string) => Response | Promise<Response>): RestClient {
    return new RestClient({
        baseUrl: 'https://example.com',
        bearerToken: 't',
        fetch: vi.fn(async (input: RequestInfo | URL) => {
            const url = typeof input === 'string' ? input : input.toString();
            return handler(url);
        }) as unknown as typeof fetch,
    });
}

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('buildInspectionTools', () => {
    it('element_types_list GETs /element-types', async () => {
        let seenUrl: string | undefined;
        const tools = buildInspectionTools(
            fakeClient((url) => {
                seenUrl = url;
                return jsonResponse({ element_types: ['headline', 'text'] });
            }),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_types_list');
        await tool!.handler({});
        expect(seenUrl).toContain('/v1/element-types');
    });

    it('element_type_get_schema encodes type_name', async () => {
        let seenUrl: string | undefined;
        const tools = buildInspectionTools(
            fakeClient((url) => {
                seenUrl = url;
                return jsonResponse({ type_name: 'headline', schema: {} });
            }),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_type_get_schema');
        await tool!.handler({ type_name: 'headline' });
        expect(seenUrl).toContain('/element-types/headline/schema');
    });
});
