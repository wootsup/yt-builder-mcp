/**
 * `clean_implode_directives` handler — strip `props.source.props.*.implode`
 * directives from an element binding via REST round-trip.
 *
 * @license MIT
 */

import { z } from 'zod';

import { encodeElementPath, type RestClient } from '../../client.js';
import { errorResult, jsonResult, type ToolResult } from '../tool-builder.js';

export const CLEAN_IMPLODE_OUTPUT_SCHEMA = z.object({
    template_id: z.string(),
    element_path: z.string(),
    cleaned_count: z.number(),
    removed_directives: z.array(
        z.object({
            prop_name: z.string(),
            directive: z.record(z.string(), z.unknown()),
        }),
    ),
    new_etag: z.string(),
});

export interface CleanImplodeHandlerDeps {
    readonly client: RestClient;
}

export async function handleCleanImplodeDirectives(
    { client }: CleanImplodeHandlerDeps,
    {
        template_id,
        element_path,
        etag,
    }: { template_id: string; element_path: string; etag: string },
): Promise<ToolResult> {
    const encoded = encodeElementPath(element_path);
    try {
        const data = await client.post(
            `/pages/${encodeURIComponent(template_id)}/elements/${encoded}/multi-items/clean-implode`,
            { body: {}, etag },
        );
        return jsonResult(data);
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id, element_path },
            hint:
                'On 412 refresh via yootheme_builder_get_etag and retry. On 404 verify the ' +
                'element_path via yootheme_builder_element_list.',
        });
    }
}
