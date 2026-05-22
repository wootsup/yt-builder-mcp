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

    // Audit v4 F-05 — the REST endpoint nests the schema under `schema` and
    // returns `fields` as a LIST. The handler must read schema.fields, not
    // the (non-existent) top-level data.fields, else field_count is always 0.
    it('element_type_get_schema extracts the nested schema.fields list', async () => {
        const tools = buildInspectionTools(
            fakeClient(() =>
                jsonResponse({
                    type_name: 'headline',
                    schema: {
                        name: 'headline',
                        label: 'Headline',
                        origin: 'core',
                        has_children: false,
                        fields: [
                            { name: 'content', type: 'editor', label: 'Content' },
                            { name: 'link', type: 'link', label: 'Link' },
                            { name: 'link_target', type: 'checkbox' },
                        ],
                    },
                }),
            ),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_type_get_schema');
        const result = (await tool!.handler({ type_name: 'headline' })) as {
            structuredContent?: { field_count?: number; fields?: unknown; label?: string };
        };
        expect(result.structuredContent?.field_count).toBe(3);
        expect(result.structuredContent?.label).toBe('Headline');
        expect(Array.isArray(result.structuredContent?.fields)).toBe(true);
        expect((result.structuredContent?.fields as unknown[]).length).toBe(3);
    });
});
