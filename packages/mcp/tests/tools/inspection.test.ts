/**
 * Tests for inspection tools.
 *
 * W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
 *
 * @license MIT
 */

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { describe, expect, it, vi } from 'vitest';

import { createServer } from '../../src/server.js';
import type { ClientPool } from '../../src/sites/client-pool.js';
import { buildInspectionTools } from '../../src/tools/inspection.js';
import { makeTestPool } from '../helpers/test-pool.js';

function fakeClient(handler: (url: string) => Response | Promise<Response>): ClientPool {
    return makeTestPool({
        baseUrl: 'https://example.com',
        bearer: 't',
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

    it('element_type_get_schema encodes element_type (canonical key)', async () => {
        let seenUrl: string | undefined;
        const tools = buildInspectionTools(
            fakeClient((url) => {
                seenUrl = url;
                return jsonResponse({ type_name: 'headline', schema: {} });
            }),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_type_get_schema');
        await tool!.handler({ element_type: 'headline' });
        expect(seenUrl).toContain('/element-types/headline/schema');
    });

    // 1.0.1 cross-tool parameter naming alignment: every other tool in this
    // domain uses `element_type`, so element_type_get_schema accepts it as
    // the canonical key while keeping `type_name` as a deprecated alias.
    it('element_type_get_schema still accepts the deprecated type_name alias', async () => {
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

    it('element_type_get_schema returns an error when neither key is supplied', async () => {
        const tools = buildInspectionTools(
            fakeClient(() => jsonResponse({ type_name: '', schema: {} })),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_type_get_schema');
        const result = (await tool!.handler({})) as {
            isError?: boolean;
            content?: Array<{ text?: string }>;
        };
        expect(result.isError).toBe(true);
    });

    it('element_type_get_schema prefers element_type when both keys are supplied', async () => {
        let seenUrl: string | undefined;
        const tools = buildInspectionTools(
            fakeClient((url) => {
                seenUrl = url;
                return jsonResponse({ type_name: 'headline', schema: {} });
            }),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_type_get_schema');
        await tool!.handler({ element_type: 'headline', type_name: 'legacy_ignored' });
        expect(seenUrl).toContain('/element-types/headline/schema');
        expect(seenUrl).not.toContain('legacy_ignored');
    });

    // Wave 1.5 B7 — pin the empty-string fallback path. Some callers pass
    // `element_type: ''` explicitly when wrapping the call from a UI layer
    // that always sets the key; the handler must fall back to `type_name`
    // rather than emit the "required" error.
    it('element_type_get_schema falls back to alias when element_type is empty string', async () => {
        let seenUrl: string | undefined;
        const tools = buildInspectionTools(
            fakeClient((url) => {
                seenUrl = url;
                return jsonResponse({ type_name: 'headline', schema: {} });
            }),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_type_get_schema');
        const result = (await tool!.handler({ element_type: '', type_name: 'headline' })) as {
            isError?: boolean;
            structuredContent?: { name?: string };
        };
        expect(result.isError).toBeUndefined();
        expect(result.structuredContent?.name).toBe('headline');
        expect(seenUrl).toContain('/element-types/headline/schema');
    });

    // Wave 1.5 B7 — description-drift guard. type_name is the deprecated
    // alias for element_type (Wave-1 0fd83ddb8 L1 promotion); the alias's
    // Zod `.describe()` MUST keep the word "DEPRECATED" verbatim so cold
    // agents reading the schema see the migration signal. Without this
    // pin a future refactor could quietly drop the marker.
    it('element_type_get_schema type_name description carries the DEPRECATED marker', () => {
        const tools = buildInspectionTools(fakeClient(() => jsonResponse({})));
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_type_get_schema');
        expect(tool).toBeDefined();
        // Zod inputSchema is the raw shape map for defineTool-built tools.
        const inputSchema = (tool!.inputSchema as Record<string, { description?: string }>);
        const typeNameField = inputSchema.type_name;
        expect(typeNameField).toBeDefined();
        expect(typeNameField.description).toBeDefined();
        expect(typeNameField.description).toContain('DEPRECATED');
    });

    // ─── F-203 follow-up (reviewer Gap 2) — SDK-boundary refine ──────
    // The empty-input check fires AT THE SDK BOUNDARY (pre-handler),
    // not inside the handler. Drive a real `Client` over an in-memory
    // transport and assert that `tools/call element_type_get_schema {}`
    // is REJECTED BY THE SDK before the handler runs.
    //
    // The SDK's `validateToolInput` throws an `McpError(InvalidParams)`
    // (JSON-RPC code -32602) when the refine fails; the server-side
    // catch in `mcp.js` then wraps the McpError into the CallToolResult
    // shape `{isError: true, content: [{text: "Input validation error:
    // Invalid arguments for tool <name>: <zod-message>"}]}`. Either
    // surface is acceptable evidence the SDK was the rejecter; this
    // test asserts the canonical "Input validation error" prefix +
    // the refine message tokens that prove the refine actually fired
    // (a generic Zod-shape error would NOT mention `type_name`).

    it('element_type_get_schema {} is rejected at the SDK boundary, NOT by the handler', async () => {
        let restCalled = false;
        const pool = makeTestPool({
            baseUrl: 'https://example.com',
            bearer: 't',
            fetch: vi.fn(async () => {
                restCalled = true;
                return new Response(JSON.stringify({ schema: {} }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                });
            }) as unknown as typeof fetch,
        });
        const { mcp } = createServer({ pool });
        const [clientT, serverT] = InMemoryTransport.createLinkedPair();
        const client = new Client(
            { name: 'test', version: '0.0.1' },
            { capabilities: {} },
        );
        await Promise.all([client.connect(clientT), mcp.connect(serverT)]);

        const result = await client.callTool({
            name: 'yootheme_builder_element_type_get_schema',
            arguments: {},
        });

        // The handler must NOT have run — proof the rejecter is the SDK,
        // not the handler. The handler path goes through `siteClient.get`
        // which would call our fake fetch.
        expect(restCalled).toBe(false);

        // The SDK wraps `McpError(InvalidParams, "Input validation error:
        // Invalid arguments for tool <name>: <zod-msg>")` into the result.
        expect(result.isError).toBe(true);
        const content = result.content as Array<{ text?: string }>;
        const txt = content[0]?.text ?? '';
        // SDK-canonical prefix — only emitted by `validateToolInput`,
        // never by our handler's structured `errorResult` (which emits
        // JSON `{error, context, hint}`).
        expect(txt).toMatch(/Input validation error/);
        // Tool-name surfaced by the SDK error message.
        expect(txt).toMatch(/yootheme_builder_element_type_get_schema/);
        // The refine MESSAGE was reached (proves the refine fired,
        // not a generic shape-only check).
        expect(txt).toMatch(/element_type/);
        expect(txt).toMatch(/type_name/);

        await client.close();
    });

    it('element_type_get_schema handler still emits its defensive structured-error envelope when called directly (in-process tests bypass SDK)', async () => {
        // Defense-in-depth: the handler-level check stays so unit tests
        // that bypass the SDK still see the structured error. The SDK
        // path is covered by the boundary test above.
        const tools = buildInspectionTools(
            fakeClient(() => jsonResponse({ schema: {} })),
        );
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_type_get_schema');
        expect(tool).toBeDefined();
        const result = (await tool!.handler({})) as {
            isError?: boolean;
            content?: Array<{ text?: string }>;
        };
        expect(result.isError).toBe(true);
        const txt = result.content?.[0]?.text ?? '';
        expect(txt).toMatch(/element_type/);
        expect(txt).toMatch(/type_name/);
        expect(txt).toMatch(/canonical/);
        expect(txt).toMatch(/deprecated/);
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
        const result = (await tool!.handler({ element_type: 'headline' })) as {
            structuredContent?: { field_count?: number; fields?: unknown; label?: string };
        };
        expect(result.structuredContent?.field_count).toBe(3);
        expect(result.structuredContent?.label).toBe('Headline');
        expect(Array.isArray(result.structuredContent?.fields)).toBe(true);
        expect((result.structuredContent?.fields as unknown[]).length).toBe(3);
    });
});
