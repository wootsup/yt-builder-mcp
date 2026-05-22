/**
 * Page-tool builders — pure `defineTool` factory list.
 *
 * Split out of `src/tools/pages.ts` (Round-2 R2-A2-CRIT1). Handler
 * bodies live in `./handlers-read.ts` (pages_list, page_get_layout,
 * page_get_schema, get_etag) and `./handlers-write.ts` (page_save,
 * page_publish — 3-phase progress contract).
 *
 * @license MIT
 */

import { z } from 'zod';

import type { RestClient } from '../../client.js';
import { TEMPLATE_ID } from '../shared-schemas.js';
import { FIELDS, FLAT } from '../sparse-fields.js';
import {
    defineTool,
    mutating,
    readOnly,
    type AnyToolDefinition,
} from '../tool-builder.js';
import {
    handleGetEtag,
    handlePageGetLayout,
    handlePageGetSchema,
    handlePagesList,
    handleTemplateSummary,
} from './handlers-read.js';
import { handlePagePublish, handlePageSave } from './handlers-write.js';
import {
    ETAG_OUTPUT_SCHEMA,
    PAGES_LIST_OUTPUT_SCHEMA,
    SCHEMA_OUTPUT_SCHEMA,
} from './schemas.js';

// F-14: page-level save/publish accept OPTIONAL ETag (no precondition lock
// at template level — element/binding mutations REQUIRE one via the strict
// ETAG in shared-schemas.ts). Schema and description now agree: optional
// here, recommended for safety. When provided, 412 Precondition Failed is
// raised on stale snapshots; when omitted, the save proceeds unconditionally.
const ETAG = z
    .string()
    .optional()
    .describe(
        'Optimistic-lock ETag (from yootheme_builder_get_etag). ' +
            'Optional; when provided, optimistic-lock is enforced (412 on conflict). ' +
            'When omitted, last-write-wins applies. Recommended for collaborative edits.',
    );

export function buildPagesTools(client: RestClient): readonly AnyToolDefinition[] {
    return [
        defineTool({
            name: 'yootheme_builder_pages_list',
            description:
                'List all YOOtheme templates ("pages") on the site. Returns id, label and ' +
                'usage metadata for each. Use this first to discover template IDs. ' +
                'Pass `fields:["id","label"]` to project per-item to a smaller shape.',
            inputSchema: { fields: FIELDS },
            outputSchema: PAGES_LIST_OUTPUT_SCHEMA,
            annotations: readOnly('List Pages'),
            handler: (input) => handlePagesList(client, input),
        }),

        defineTool({
            name: 'yootheme_builder_page_get_layout',
            description:
                'Get full layout tree for one template. Default nested `{layout, ' +
                'etag}`. Set `flat:true` for depth-first array `{elements:[...], ' +
                'etag}`; combine with `fields[]` to project per-element.',
            inputSchema: {
                template_id: TEMPLATE_ID,
                flat: FLAT,
                fields: FIELDS,
            },
            // No outputSchema: the response shape is a union (nested OR flat with
            // optional projection) and the JSON-Pointer-keyed nested shape is
            // structurally open-ended — modelling it precisely buys nothing here.
            annotations: readOnly('Get Page Layout'),
            handler: (input) => handlePageGetLayout(client, input),
        }),

        defineTool({
            name: 'yootheme_builder_page_get_schema',
            description:
                'Get the flat schema for a template — a list of nodes with their JSON-Pointer ' +
                'paths and element types. Best entry-point for navigation: lighter than ' +
                'page_get_layout, sufficient to locate elements before editing.',
            inputSchema: { template_id: TEMPLATE_ID },
            outputSchema: SCHEMA_OUTPUT_SCHEMA,
            annotations: readOnly('Get Page Schema'),
            handler: (input) => handlePageGetSchema(client, input),
        }),

        defineTool({
            name: 'yootheme_builder_get_etag',
            description:
                'Get the current top-level state ETag. Pass this back via the `etag` parameter ' +
                'on any write tool to prevent overwriting concurrent edits.',
            inputSchema: {},
            outputSchema: ETAG_OUTPUT_SCHEMA,
            annotations: readOnly('Get ETag'),
            handler: () => handleGetEtag(client),
        }),

        defineTool({
            name: 'yootheme_builder_template_summary',
            description:
                'Token-efficient template overview: element counts by type, binding ' +
                'count, max nesting depth, and named landmark sections — computed ' +
                'server-side in one call. Use this to grasp a large template before ' +
                'pulling element_list or page_get_layout.',
            inputSchema: { template_id: TEMPLATE_ID },
            annotations: readOnly('Template Summary'),
            handler: (input) => handleTemplateSummary(client, input),
        }),

        defineTool({
            name: 'yootheme_builder_page_save',
            description:
                'Re-run save-transforms and persist. ETag optional — when provided, 412 on ' +
                'conflict; when omitted, last-write-wins. Recommended for collaborative edits. ' +
                'No-op when state is byte-identical (returns `no_changes:true`, ETag unchanged).',
            inputSchema: {
                template_id: TEMPLATE_ID,
                etag: ETAG,
            },
            annotations: mutating('Save Page'),
            handler: (input, extra) => handlePageSave(client, input, extra),
        }),

        defineTool({
            name: 'yootheme_builder_page_publish',
            description:
                'Publish a template — persist state, flush YT + WP caches, snapshot the ' +
                'published-state ETag. ETag optional — when provided, 412 on conflict; when ' +
                'omitted, last-write-wins. Recommended for collaborative edits.',
            inputSchema: {
                template_id: TEMPLATE_ID,
                etag: ETAG,
            },
            // Stream D3 T3: publish is idempotent — re-running on an
            // unchanged template snapshots the same ETag and flushes
            // the same caches. Matrix marks `idempotentHint:true`.
            annotations: mutating('Publish Page'),
            handler: (input, extra) => handlePagePublish(client, input, extra),
        }),
    ];
}
