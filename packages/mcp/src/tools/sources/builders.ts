/**
 * Source-tool builders — pure `defineTool` factory list.
 *
 * Split out of `src/tools/sources.ts` (Wave G.4.0b). See `./handlers.ts`
 * for the handler bodies.
 *
 * @license MIT
 */

import { z } from 'zod';

import type { ClientPool } from '../../sites/client-pool.js';
import { withSiteRetry } from '../pool-resolve-helper.js';
import { ELEMENT_PATH, ETAG, SITE_ID_SCHEMA, TEMPLATE_ID } from '../shared-schemas.js';
import { FIELDS } from '../sparse-fields.js';
import {
    defineTool,
    destructive,
    mutating,
    readOnly,
    withSiteMeta,
    type AnyToolDefinition,
} from '../tool-builder.js';
import {
    BINDING_OUTPUT_SCHEMA,
    SOURCES_LIST_OUTPUT_SCHEMA,
    type SourcesHandlerDeps,
    handleElementBindSource,
    handleElementGetBinding,
    handleElementUnbindSource,
    handleSourcesList,
} from './handlers.js';

export function buildSourcesTools(
    pool: ClientPool,
    deps?: Partial<SourcesHandlerDeps>,
): readonly AnyToolDefinition[] {
    // W6.3 — handlers receive a per-call `siteClient` via the
    // resolveSiteOrError helper; the elicitation capability is the
    // only stable dep we propagate.
    const elicitation = deps?.elicitation;
    const makeDeps = (siteClient: SourcesHandlerDeps['client']): SourcesHandlerDeps =>
        (elicitation !== undefined
            ? { client: siteClient, elicitation }
            : { client: siteClient });

    return [
        defineTool({
            name: 'yootheme_builder_sources_list',
            description:
                'List all data sources, feeds, and dynamic content sources available in the ' +
                'YOOtheme Pro builder. Returns name + label + origin (apimapper / wordpress / ' +
                'joomla / essentials) per source. CALL THIS BEFORE binding any element to a data source. ' +
                'Pick a source name from the list and pass it to `element_bind_source`. ' +
                'Keywords: list sources, list data sources, list feeds, list bindings, ' +
                'dynamic content, available data, what sources exist. ' +
                'Pass `fields[]` to narrow each row. Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
                fields: FIELDS,
            },
            outputSchema: SOURCES_LIST_OUTPUT_SCHEMA,
            annotations: readOnly('List Sources'),
            handler: async ({ site_id, ...rest }) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handleSourcesList(makeDeps(client), rest), site)),
        }),

        defineTool({
            name: 'yootheme_builder_element_get_binding',
            description:
                'Read the source binding attached to an element — the bound source name, ' +
                'the field-mappings (which source field feeds which element prop) and the ' +
                'query arguments/directives. Returns the empty object if the element is not bound. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
                template_id: TEMPLATE_ID,
                element_path: ELEMENT_PATH,
            },
            outputSchema: BINDING_OUTPUT_SCHEMA,
            annotations: readOnly('Get Source Binding'),
            handler: async ({ site_id, ...rest }) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handleElementGetBinding(makeDeps(client), rest), site)),
        }),

        defineTool({
            name: 'yootheme_builder_element_bind_source',
            description:
                'Binds a Builder source to an element (sets `props.source`). Use bindingLevel ' +
                '"item" on Multi-Items containers (grid/slideshow/switcher/…) to bind on the ' +
                'first *_item child instead of the container itself. Requires ETag. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
                template_id: TEMPLATE_ID,
                element_path: ELEMENT_PATH,
                source_name: z
                    .string()
                    .min(1)
                    .describe(
                        'Source name as listed by yootheme_builder_sources_list ' +
                            '(e.g. "wp_posts", "apimapper_my-flow").',
                    ),
                source_id: z
                    .string()
                    .optional()
                    .describe(
                        'Optional explicit "<origin>:<name>" id; skips ambiguity ' +
                            'resolution when ≥2 sources share `source_name`.',
                    ),
                field_mappings: z
                    .record(z.string(), z.string())
                    .optional()
                    .describe(
                        'Optional {prop_name: source_field_name} map written to `source.props`. ' +
                            'Pass "__node_item__" (or "__node_item__:<field>") as the value to ' +
                            'emit YT-Pro INHERIT bindings ("Node - Item (Source/Items)" picker ' +
                            'entry) on child *_item elements.',
                    ),
                bindingLevel: z
                    .enum(['auto', 'container', 'item'])
                    .optional()
                    .describe(
                        'Multi-Items binding level. "auto" (default): item if target is a *_item, ' +
                            'container otherwise. "item": container targets resolve to their first ' +
                            '*_item child. "container": binds on the container, response carries a ' +
                            'warning because YT-Pro will clone the container N times.',
                    ),
                etag: ETAG,
            },
            annotations: mutating('Bind Source'),
            handler: async ({ site_id, ...rest }) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handleElementBindSource(makeDeps(client), rest), site)),
        }),

        defineTool({
            name: 'yootheme_builder_element_unbind_source',
            description:
                'Remove the source binding from an element. Clears `props.source`. Destructive ' +
                'in the sense that it may break dynamic-content rendering — always ask the ' +
                'user to confirm. Requires ETag. ' +
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
                        'Must be true to execute. On supporting hosts the agent is prompted ' +
                            'via MCP elicitation when omitted; otherwise the call returns a ' +
                            'preview and the agent must retry with `confirm: true`.',
                    ),
            },
            annotations: destructive('Unbind Source'),
            handler: async ({ site_id, ...rest }) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handleElementUnbindSource(makeDeps(client), rest), site)),
        }),
    ];
}
