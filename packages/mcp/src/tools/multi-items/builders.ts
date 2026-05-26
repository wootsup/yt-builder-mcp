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

import { z } from 'zod';

import type { ClientPool } from '../../sites/client-pool.js';
import { withSiteRetry } from '../pool-resolve-helper.js';
import { ELEMENT_PATH, ETAG, SITE_ID_SCHEMA, TEMPLATE_ID } from '../shared-schemas.js';
import {
    defineTool,
    destructive,
    readOnly,
    withSiteMeta,
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

export function buildMultiItemsTools(pool: ClientPool): readonly AnyToolDefinition[] {
    // W6.3 — per-handler resolveSiteOrError. The handlers consume a
    // single-field `MultiItemsHandlerDeps`, so we synthesise it inline
    // with the resolved client.
    return [
        defineTool({
            name: 'yootheme_builder_inspect_multi_items_binding',
            description:
                'Reports Multi-Items binding state: container/item pair (grid↔grid_item, ' +
                'slideshow↔slideshow_item, …), current binding level (none|container|item), ' +
                'and a recommended_fix when the binding sits on the container instead of the child. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
                template_id: TEMPLATE_ID,
                element_path: ELEMENT_PATH,
            },
            outputSchema: INSPECT_MULTI_ITEMS_OUTPUT_SCHEMA,
            annotations: readOnly('Inspect Multi-Items Binding'),
            handler: async ({ site_id, ...rest }) =>
                withSiteRetry(pool, site_id, async (client, site) => {
                    const deps: MultiItemsHandlerDeps = { client };
                    return withSiteMeta(await handleInspectMultiItemsBinding(deps, rest), site);
                }),
        }),

        defineTool({
            name: 'yootheme_builder_clean_implode_directives',
            description:
                'Strips `props.source.props.*.implode` directives from an element binding. ' +
                'Returns audit log + new ETag. Idempotent at the server (cleaned_count: 0 ' +
                'when nothing to remove) but classed as destructive because the affected ' +
                'binding cannot be restored without re-mapping. Always ask the user to ' +
                'confirm first, then call again with `confirm: true`. Requires ETag. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
                template_id: TEMPLATE_ID,
                element_path: ELEMENT_PATH,
                etag: ETAG,
                confirm: z
                    .boolean()
                    .optional()
                    .describe(
                        'Must be true to execute. Without it the call returns a preview ' +
                            'and makes NO server-side change; the agent must ask the user ' +
                            'to confirm and retry with `confirm: true`.',
                    ),
            },
            outputSchema: CLEAN_IMPLODE_OUTPUT_SCHEMA,
            annotations: destructive('Clean Implode Directives'),
            handler: async ({ site_id, ...rest }) =>
                withSiteRetry(pool, site_id, async (client, site) => {
                    const deps: MultiItemsHandlerDeps = { client };
                    return withSiteMeta(await handleCleanImplodeDirectives(deps, rest), site);
                }),
        }),
    ];
}
