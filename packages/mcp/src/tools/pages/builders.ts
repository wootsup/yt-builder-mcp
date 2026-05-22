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
    creating,
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
            'Optional at template level — when provided, enforces precondition ' +
            'and returns 412 on conflict; when omitted, save/publish proceeds ' +
            'unconditionally. For safety in collaborative edits, pass the ETag.',
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
            name: 'yootheme_builder_page_save',
            description:
                'Re-run save-transforms on a template and persist. Useful after a series of ' +
                'low-level writes to trigger the Builder normalization pass. ETag optional ' +
                '(recommended for safety in collaborative edits).',
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
                'Publish a template — persists state, flushes YOOtheme + WP caches, and ' +
                'snapshots the published-state ETag. Subsequent reads serve the freshly ' +
                'published layout. ETag optional (recommended for safety in collaborative edits).',
            inputSchema: {
                template_id: TEMPLATE_ID,
                etag: ETAG,
            },
            annotations: creating('Publish Page'),
            handler: (input, extra) => handlePagePublish(client, input, extra),
        }),
    ];
}
