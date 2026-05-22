/**
 * Element-tool builders — pure `defineTool` factory list.
 *
 * Split out of the original `src/tools/elements.ts` (Wave G.4.0). Each
 * entry pairs a Zod input-schema with the matching handler in
 * `./handlers.ts`. Keeping builders + handlers in separate files lets
 * each file stay under the 200-LoC budget.
 *
 * The exported `buildElementsTools(client, [opts])` is the single public
 * entry-point — see `./index.ts` for the consumer-facing re-export.
 *
 * @license MIT
 */

import { z } from 'zod';

import type { RestClient } from '../../client.js';
import { ELEMENT_PATH, ETAG, PROPS, TEMPLATE_ID } from '../shared-schemas.js';
import { FIELDS } from '../sparse-fields.js';
import {
    creating,
    defineTool,
    destructive,
    mutating,
    readOnly,
    type AnyToolDefinition,
} from '../tool-builder.js';
import {
    ELEMENT_GET_OUTPUT_SCHEMA,
    ELEMENT_LIST_OUTPUT_SCHEMA,
    type ElementsHandlerDeps,
    handleElementAdd,
    handleElementClone,
    handleElementDelete,
    handleElementGet,
    handleElementList,
    handleElementMove,
    handleElementUpdateSettings,
} from './handlers.js';

/**
 * Build the 7 element tools. The optional `deps` carries the
 * elicitation capability used by `element_delete` (Wave G.4.1). When
 * omitted, the destructive tool falls back to the explicit
 * `confirm: true` parameter.
 */
export function buildElementsTools(
    client: RestClient,
    deps?: Partial<ElementsHandlerDeps>,
): readonly AnyToolDefinition[] {
    const handlerDeps: ElementsHandlerDeps = {
        client,
        elicitation: deps?.elicitation,
    };

    return [
        defineTool({
            name: 'yootheme_builder_element_list',
            description:
                'List all elements in a template as a flat array with JSON-Pointer paths + ' +
                'element types. Best starting-point for "find the element I want to edit". ' +
                'Pass `fields:["path","element_type"]` to narrow each row.',
            inputSchema: {
                template_id: TEMPLATE_ID,
                fields: FIELDS,
            },
            outputSchema: ELEMENT_LIST_OUTPUT_SCHEMA,
            annotations: readOnly('List Elements'),
            handler: (input) => handleElementList(handlerDeps, input),
        }),

        defineTool({
            name: 'yootheme_builder_element_get',
            description:
                'Get the full element object at a specific JSON-Pointer path, including props ' +
                'and children. Use yootheme_builder_element_list to discover paths.',
            inputSchema: {
                template_id: TEMPLATE_ID,
                element_path: ELEMENT_PATH,
            },
            outputSchema: ELEMENT_GET_OUTPUT_SCHEMA,
            annotations: readOnly('Get Element'),
            handler: (input) => handleElementGet(handlerDeps, input),
        }),

        defineTool({
            name: 'yootheme_builder_element_add',
            description:
                'Add a new element to a template. Provide `parent_path` (or "" for root), ' +
                '`element_type` (e.g. "headline", "text", "grid"), and optional `props` / ' +
                '`children`. Returns the new element\'s JSON-Pointer path. Requires ETag.',
            inputSchema: {
                template_id: TEMPLATE_ID,
                parent_path: z
                    .string()
                    .default('')
                    .describe(
                        'JSON-Pointer of the parent node ("" for template root, ' +
                            '"/0/children/2" for nested). Use yootheme_builder_element_list to find.',
                    ),
                element_type: z
                    .string()
                    .min(1)
                    .describe(
                        'Type of element to create (e.g. "headline", "text", "grid"). ' +
                            'Use yootheme_builder_element_types_list for the catalogue.',
                    ),
                props: PROPS.optional(),
                children: z
                    .array(z.record(z.string(), z.unknown()))
                    .optional()
                    .describe('Optional initial children — array of element objects.'),
                etag: ETAG,
            },
            annotations: creating('Add Element'),
            handler: (input, extra) => handleElementAdd(handlerDeps, input, extra),
        }),

        defineTool({
            name: 'yootheme_builder_element_update_settings',
            description:
                'Replace the `props` on an element. Use this for any setting change — title, ' +
                'margins, classes, sources, etc. Requires ETag. Existing props NOT in the ' +
                'request are removed.',
            inputSchema: {
                template_id: TEMPLATE_ID,
                element_path: ELEMENT_PATH,
                props: PROPS,
                etag: ETAG,
            },
            annotations: mutating('Update Element Settings'),
            handler: (input, extra) => handleElementUpdateSettings(handlerDeps, input, extra),
        }),

        defineTool({
            name: 'yootheme_builder_element_move',
            description:
                'Move an element to a new parent + index in the tree. Useful for reordering or ' +
                'reparenting (e.g. moving a card from one grid column to another). Requires ETag.',
            inputSchema: {
                template_id: TEMPLATE_ID,
                element_path: ELEMENT_PATH,
                to_parent_path: z
                    .string()
                    .describe('Destination parent JSON-Pointer ("" for template root).'),
                to_index: z
                    .number()
                    .int()
                    .min(0)
                    .describe('Zero-based index within the destination parent.'),
                etag: ETAG,
            },
            annotations: mutating('Move Element'),
            handler: (input, extra) => handleElementMove(handlerDeps, input, extra),
        }),

        defineTool({
            name: 'yootheme_builder_element_clone',
            description:
                'Clone an element as a sibling (same parent, immediately after the source). ' +
                'Returns the new element\'s path. Requires ETag.',
            inputSchema: {
                template_id: TEMPLATE_ID,
                element_path: ELEMENT_PATH,
                etag: ETAG,
            },
            annotations: creating('Clone Element'),
            handler: (input, extra) => handleElementClone(handlerDeps, input, extra),
        }),

        defineTool({
            name: 'yootheme_builder_element_delete',
            description:
                'PERMANENTLY delete an element and all its children. Cannot be undone. Always ' +
                'ask the user to confirm first, then call again with `confirm: true`. Requires ' +
                'ETag.',
            inputSchema: {
                template_id: TEMPLATE_ID,
                element_path: ELEMENT_PATH,
                etag: ETAG,
                confirm: z
                    .boolean()
                    .optional()
                    .describe(
                        'Must be true to execute. On supporting hosts the agent is prompted ' +
                            'via MCP elicitation when omitted; otherwise the call returns a ' +
                            'preview and the agent must retry with `confirm: true`.',
                    ),
            },
            annotations: destructive('Delete Element'),
            handler: (input, extra) => handleElementDelete(handlerDeps, input, extra),
        }),
    ];
}
