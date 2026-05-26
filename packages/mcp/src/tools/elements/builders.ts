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

import type { ClientPool } from '../../sites/client-pool.js';
import { withSiteRetry } from '../pool-resolve-helper.js';
import { ELEMENT_PATH, ETAG, PROPS, SITE_ID_SCHEMA, TEMPLATE_ID } from '../shared-schemas.js';
import { FIELDS } from '../sparse-fields.js';
import {
    creating,
    defineTool,
    destructive,
    mutating,
    readOnly,
    withSiteMeta,
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
    pool: ClientPool,
    deps?: Partial<ElementsHandlerDeps>,
): readonly AnyToolDefinition[] {
    // W6.3 — each handler resolves the pool inline (per `site_id`) so
    // the per-handler `siteClient` reaches the right CMS install. The
    // elicitation capability is the only non-client dep we propagate.
    const elicitation = deps?.elicitation;
    const makeDeps = (siteClient: ElementsHandlerDeps['client']): ElementsHandlerDeps =>
        (elicitation !== undefined
            ? { client: siteClient, elicitation }
            : { client: siteClient });

    return [
        defineTool({
            name: 'yootheme_builder_element_list',
            description:
                'List elements in a template as a flat array with JSON-Pointer paths + ' +
                'types. Scope with `root_path`/`depth` for a subtree, paginate with ' +
                '`limit`/`cursor` for large templates. `fields[]` narrows each row. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
                template_id: TEMPLATE_ID,
                fields: FIELDS,
                root_path: z
                    .string()
                    .optional()
                    .describe(
                        'Restrict the walk to the subtree at this JSON-Pointer ' +
                            '(e.g. "/templates/<id>/layout/children/0"). Default: whole layout.',
                    ),
                depth: z
                    .number()
                    .int()
                    .positive()
                    .optional()
                    .describe('Cap recursion to N levels of descendants. Default: unbounded.'),
                limit: z
                    .number()
                    .int()
                    .positive()
                    .optional()
                    .describe(
                        'Page size. When set, the response is paginated — a ' +
                            '`next_cursor` is returned while more rows remain.',
                    ),
                cursor: z
                    .string()
                    .optional()
                    .describe('Continuation token from a prior call\'s `next_cursor`.'),
            },
            outputSchema: ELEMENT_LIST_OUTPUT_SCHEMA,
            annotations: readOnly('List Elements'),
            handler: async ({ site_id, ...rest }) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handleElementList(makeDeps(client), rest), site)),
        }),

        defineTool({
            name: 'yootheme_builder_element_get',
            description:
                'Get the full element object at a specific JSON-Pointer path, including props ' +
                'and children. Use yootheme_builder_element_list to discover paths. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
                template_id: TEMPLATE_ID,
                element_path: ELEMENT_PATH,
            },
            outputSchema: ELEMENT_GET_OUTPUT_SCHEMA,
            annotations: readOnly('Get Element'),
            handler: async ({ site_id, ...rest }) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handleElementGet(makeDeps(client), rest), site)),
        }),

        defineTool({
            name: 'yootheme_builder_element_add',
            description:
                'Add a new element to a template. Provide `parent_path` (or "" for root), ' +
                '`element_type` (e.g. "headline", "text", "grid"), and optional `props` / ' +
                '`children`. Returns the new element\'s JSON-Pointer path. Requires ETag. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
                template_id: TEMPLATE_ID,
                parent_path: z
                    .string()
                    .default('')
                    .describe(
                        'JSON-Pointer of the parent node. Accepts EITHER "" for ' +
                            'template root, a `rel_path` from `element_list` ' +
                            '(`/children/0`, `/children/0/children/2`) — the server ' +
                            'resolves it within this template — OR a fully-qualified ' +
                            'pointer (`/templates/<id>/layout/children/0/...`). The ' +
                            'rel_path form is preferred (copy-paste straight from ' +
                            '`element_list`).',
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
            handler: async ({ site_id, ...rest }, extra) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handleElementAdd(makeDeps(client), rest, extra), site)),
        }),

        defineTool({
            name: 'yootheme_builder_element_update_settings',
            description:
                'Update `props` on an element. Default replaces all props; pass ' +
                '`merge:true` for server-side deep-merge (only request keys overwritten, ' +
                'others survive — avoids read-modify-write races). Requires ETag. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
                template_id: TEMPLATE_ID,
                element_path: ELEMENT_PATH,
                props: PROPS,
                merge: z
                    .boolean()
                    .optional()
                    .describe(
                        'When true, server reads current props and deep-merges the request body ' +
                            '(request wins, untouched keys preserved). Default false (full replace).',
                    ),
                etag: ETAG,
            },
            annotations: mutating('Update Element Settings'),
            handler: async ({ site_id, ...rest }, extra) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handleElementUpdateSettings(makeDeps(client), rest, extra), site)),
        }),

        defineTool({
            name: 'yootheme_builder_element_move',
            description:
                'Move an element to a new parent + index in the tree. Useful for reordering or ' +
                'reparenting (e.g. moving a card from one grid column to another). Requires ETag. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
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
            handler: async ({ site_id, ...rest }, extra) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handleElementMove(makeDeps(client), rest, extra), site)),
        }),

        defineTool({
            name: 'yootheme_builder_element_clone',
            description:
                'Clone an element as a sibling (same parent, immediately after the source). ' +
                'Returns the new element\'s path. Requires ETag. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
                template_id: TEMPLATE_ID,
                element_path: ELEMENT_PATH,
                etag: ETAG,
            },
            annotations: creating('Clone Element'),
            handler: async ({ site_id, ...rest }, extra) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handleElementClone(makeDeps(client), rest, extra), site)),
        }),

        defineTool({
            name: 'yootheme_builder_element_delete',
            description:
                'PERMANENTLY delete an element and all its children. Cannot be undone. Always ' +
                'ask the user to confirm first, then call again with `confirm: true`. Requires ' +
                'ETag. Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
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
            handler: async ({ site_id, ...rest }, extra) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handleElementDelete(makeDeps(client), rest, extra), site)),
        }),
    ];
}
