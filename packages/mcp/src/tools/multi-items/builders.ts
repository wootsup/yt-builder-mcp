/**
 * Multi-Items tool builders.
 *
 * Surfaces two tools that operate on the YT-Pro Multi-Items binding
 * pattern (container ↔ `*_item` pairings — see
 * `./item-container-map.ts`):
 *
 *   yootheme_builder_inspect_multi_items_binding
 *     → Reports the binding level (none/container/item), the matching
 *       container/item pair, and whether legacy implode directives are
 *       attached. Surfaces a `recommended_fix` when the binding sits on
 *       the container level.
 *
 *   yootheme_builder_clean_implode_directives
 *     → Removes every `props.source.props.*.implode` directive from
 *       the addressed element. Returns the audit log + new etag.
 *
 * @license MIT
 */

import type { RestClient } from '../../client.js';
import { ELEMENT_PATH, ETAG, TEMPLATE_ID } from '../shared-schemas.js';
import {
    defineTool,
    mutating,
    readOnly,
    type AnyToolDefinition,
} from '../tool-builder.js';
import {
    CLEAN_IMPLODE_OUTPUT_SCHEMA,
    handleCleanImplodeDirectives,
} from './clean-implode-handler.js';
import {
    INSPECT_MULTI_ITEMS_OUTPUT_SCHEMA,
    handleInspectMultiItemsBinding,
    type MultiItemsHandlerDeps,
} from './inspect-handler.js';

export function buildMultiItemsTools(client: RestClient): readonly AnyToolDefinition[] {
    const deps: MultiItemsHandlerDeps = { client };

    return [
        defineTool({
            name: 'yootheme_builder_inspect_multi_items_binding',
            description:
                'Reports Multi-Items binding state: container/item pair (grid↔grid_item, ' +
                'slideshow↔slideshow_item, …), current binding level (none|container|item), ' +
                'and a recommended_fix when the binding sits on the container instead of the child.',
            inputSchema: {
                template_id: TEMPLATE_ID,
                element_path: ELEMENT_PATH,
            },
            outputSchema: INSPECT_MULTI_ITEMS_OUTPUT_SCHEMA,
            annotations: readOnly('Inspect Multi-Items Binding'),
            handler: (input) => handleInspectMultiItemsBinding(deps, input),
        }),

        defineTool({
            name: 'yootheme_builder_clean_implode_directives',
            description:
                'Strips `props.source.props.*.implode` directives from an element binding. ' +
                'Returns audit log + new ETag. Idempotent (cleaned_count: 0 when nothing to ' +
                'remove). Requires ETag.',
            inputSchema: {
                template_id: TEMPLATE_ID,
                element_path: ELEMENT_PATH,
                etag: ETAG,
            },
            outputSchema: CLEAN_IMPLODE_OUTPUT_SCHEMA,
            annotations: mutating('Clean Implode Directives'),
            handler: (input) => handleCleanImplodeDirectives(deps, input),
        }),
    ];
}
