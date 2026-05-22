/**
 * Multi-Items pattern pin-test (TS side) — every container ↔ item
 * pair is classified the same way by the TS map and produces a coherent
 * inspect tool payload.
 *
 * The PHP-side pin lives at tests/php/unit/Elements/MultiItemsPatternPinTest.php
 * and exercises the REST resolver. This pin checks the TS map's
 * classification helpers and the inspect tool's wire shape.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { RestClient } from '../../../src/client.js';
import {
    ITEM_CHILDREN_OF_CONTAINER,
    buildMultiItemsTools,
    containerOf,
    isContainer,
    isItem,
    itemOf,
} from '../../../src/tools/multi-items/index.js';

function fakeClient(
    handler: (url: string, init: RequestInit) => Response | Promise<Response>,
): RestClient {
    return new RestClient({
        baseUrl: 'https://example.com',
        bearerToken: 't',
        fetch: vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = typeof input === 'string' ? input : input.toString();
            return handler(url, init ?? {});
        }) as unknown as typeof fetch,
    });
}

function findTool(tools: ReturnType<typeof buildMultiItemsTools>, name: string) {
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

describe('Multi-Items pattern pin (TS side)', () => {
    const pairs = Object.entries(ITEM_CHILDREN_OF_CONTAINER) as readonly [string, string][];

    it.each(pairs)('pair %s ↔ %s round-trips through itemOf/containerOf', (container, item) => {
        expect(itemOf(container)).toBe(item);
        expect(containerOf(item)).toBe(container);
        expect(isContainer(container)).toBe(true);
        expect(isItem(item)).toBe(true);
        expect(isItem(container)).toBe(false);
        expect(isContainer(item)).toBe(false);
    });

    it.each(pairs)(
        'inspect tool surfaces container_type=%s / item_type=%s for the container',
        async (container, item) => {
            const tools = buildMultiItemsTools(
                fakeClient(() =>
                    jsonResponse({
                        template_id: 'tpl',
                        report: {
                            element_path: '/templates/tpl/layout/children/0',
                            element_type: container,
                            is_container: true,
                            is_item: false,
                            container_type: container,
                            item_type: item,
                            current_binding_level: 'none',
                            has_implode_directives: false,
                        },
                        etag: '"e1"',
                    }),
                ),
            );

            const result = await findTool(
                tools,
                'yootheme_builder_inspect_multi_items_binding',
            ).handler({
                template_id: 'tpl',
                element_path: '/templates/tpl/layout/children/0',
            });

            const parsed = JSON.parse(result.content[0]!.text) as {
                report: { container_type: string; item_type: string };
            };
            expect(parsed.report.container_type).toBe(container);
            expect(parsed.report.item_type).toBe(item);
        },
    );

    it('map has exactly 16 pairs (live-verified against YT-Pro 4.5.33)', () => {
        expect(pairs.length).toBe(16);
    });

    it('does not contain the non-existent slider pair', () => {
        expect(ITEM_CHILDREN_OF_CONTAINER).not.toHaveProperty('slider');
    });
});
