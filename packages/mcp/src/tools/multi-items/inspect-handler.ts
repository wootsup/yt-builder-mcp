/**
 * `inspect_multi_items_binding` handler — surfaces Multi-Items binding
 * state on an element via REST round-trip.
 *
 * Companion of the PHP `MultiItemsInspector` — see
 * src/modules/builder-source-binding/src/MultiItemsInspector.php
 * for the report shape.
 *
 * @license MIT
 */

import { z } from 'zod';

import { encodeElementPath, type RestClient } from '../../client.js';
import { errorResult, jsonResult, type ToolResult } from '../tool-builder.js';

export const INSPECT_MULTI_ITEMS_OUTPUT_SCHEMA = z.object({
    template_id: z.string(),
    report: z.object({
        element_path: z.string(),
        element_type: z.string(),
        is_container: z.boolean(),
        is_item: z.boolean(),
        container_type: z.string().nullable(),
        item_type: z.string().nullable(),
        current_binding_level: z.enum(['none', 'container', 'item']),
        has_implode_directives: z.boolean(),
        warning: z.string().optional(),
        recommended_fix: z.string().optional(),
    }),
    etag: z.string(),
});

export interface MultiItemsHandlerDeps {
    readonly client: RestClient;
}

export async function handleInspectMultiItemsBinding(
    { client }: MultiItemsHandlerDeps,
    { template_id, element_path }: { template_id: string; element_path: string },
): Promise<ToolResult> {
    const encoded = encodeElementPath(element_path);
    try {
        const data = await client.get<Record<string, unknown>>(
            `/pages/${encodeURIComponent(template_id)}/elements/${encoded}/multi-items/inspect`,
        );
        return jsonResult(data);
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id, element_path },
            hint:
                'Verify element_path via yootheme_builder_element_list. Paths are JSON-Pointer ' +
                'style ("/templates/<id>/layout/children/0").',
        });
    }
}
