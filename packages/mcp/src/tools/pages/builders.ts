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

import type { ClientPool } from '../../sites/client-pool.js';
import { withSiteRetry } from '../pool-resolve-helper.js';
import { SITE_ID_SCHEMA, TEMPLATE_ID } from '../shared-schemas.js';
import { FIELDS, FLAT } from '../sparse-fields.js';
import {
    defineTool,
    mutating,
    readOnly,
    withSiteMeta,
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
    TEMPLATE_SUMMARY_OUTPUT_SCHEMA,
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

export function buildPagesTools(pool: ClientPool): readonly AnyToolDefinition[] {
    return [
        defineTool({
            name: 'yootheme_builder_pages_list',
            description:
                'List all pages, templates, and layouts in the YOOtheme Pro builder. ' +
                'Returns template_id, label, type, element count, and frontend_url per row. ' +
                'CALL THIS FIRST to discover available template IDs before any tool that needs ' +
                'a template_id (page_get_layout, element_list, page_get_schema, etc.). ' +
                'Keywords: list pages, list templates, list layouts, discover template_id, ' +
                'index, available templates, what pages exist. ' +
                'Pass `fields:["id","label"]` to slim. ' +
                'Returns ALL pages in one call (no pagination, typically <50 templates per site). ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: { site_id: SITE_ID_SCHEMA, fields: FIELDS },
            outputSchema: PAGES_LIST_OUTPUT_SCHEMA,
            annotations: readOnly('List Pages'),
            handler: async ({ site_id, ...rest }) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handlePagesList(client, rest), site)),
        }),

        defineTool({
            name: 'yootheme_builder_page_get_layout',
            description:
                'Get full layout tree for one template. Default nested `{layout, ' +
                'etag}`. Set `flat:true` for depth-first array `{elements:[...], ' +
                'etag}`; combine with `fields[]` to project per-element. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
                template_id: TEMPLATE_ID,
                flat: FLAT,
                fields: FIELDS,
            },
            // No outputSchema: the response shape is a union (nested OR flat with
            // optional projection) and the JSON-Pointer-keyed nested shape is
            // structurally open-ended — modelling it precisely buys nothing here.
            annotations: readOnly('Get Page Layout'),
            handler: async ({ site_id, ...rest }) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handlePageGetLayout(client, rest), site)),
        }),

        defineTool({
            name: 'yootheme_builder_page_get_schema',
            description:
                'Get the flat schema for a template — a list of nodes with their JSON-Pointer ' +
                'paths and element types. Best entry-point for navigation: lighter than ' +
                'page_get_layout, sufficient to locate elements before editing. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: { site_id: SITE_ID_SCHEMA, template_id: TEMPLATE_ID },
            outputSchema: SCHEMA_OUTPUT_SCHEMA,
            annotations: readOnly('Get Page Schema'),
            handler: async ({ site_id, ...rest }) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handlePageGetSchema(client, rest), site)),
        }),

        defineTool({
            name: 'yootheme_builder_get_etag',
            description:
                'Get the current ETag (state revision) for the YOOtheme builder. ' +
                'Returns sha256+revision string used for optimistic locking on writes. ' +
                'Pass the returned value back as `etag` on any write tool (page_save, page_publish, ' +
                'element_add, element_update_settings, element_clone, element_move, element_delete). ' +
                'The server returns HTTP 412 if the ETag has changed since you read it. ' +
                'Keywords: get etag, current etag, state revision, optimistic lock, version stamp. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: { site_id: SITE_ID_SCHEMA },
            outputSchema: ETAG_OUTPUT_SCHEMA,
            annotations: readOnly('Get ETag'),
            handler: async ({ site_id }) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handleGetEtag(client), site)),
        }),

        defineTool({
            name: 'yootheme_builder_template_summary',
            description:
                'Token-efficient template overview: element counts by type, binding ' +
                'count, max nesting depth, and named landmark sections — computed ' +
                'server-side in one call. Use this to grasp a large template before ' +
                'pulling element_list or page_get_layout. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: { site_id: SITE_ID_SCHEMA, template_id: TEMPLATE_ID },
            outputSchema: TEMPLATE_SUMMARY_OUTPUT_SCHEMA,
            annotations: readOnly('Template Summary'),
            handler: async ({ site_id, ...rest }) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handleTemplateSummary(client, rest), site)),
        }),

        defineTool({
            name: 'yootheme_builder_page_save',
            description:
                'Re-run save-transforms and persist. ETag optional — when provided, 412 on ' +
                'conflict; when omitted, last-write-wins. Recommended for collaborative edits. ' +
                'No-op when state is byte-identical (returns `no_changes:true`, ETag unchanged). ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
                template_id: TEMPLATE_ID,
                etag: ETAG,
            },
            annotations: mutating('Save Page'),
            handler: async ({ site_id, ...rest }, extra) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handlePageSave(client, rest, extra), site)),
        }),

        defineTool({
            name: 'yootheme_builder_page_publish',
            description:
                'Publish a template — persist state, flush YT + WP caches, snapshot the ' +
                'published-state ETag. ETag optional — when provided, 412 on conflict; when ' +
                'omitted, last-write-wins. Recommended for collaborative edits. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
                template_id: TEMPLATE_ID,
                etag: ETAG,
            },
            // Stream D3 T3: publish is idempotent — re-running on an
            // unchanged template snapshots the same ETag and flushes
            // the same caches. Matrix marks `idempotentHint:true`.
            annotations: mutating('Publish Page'),
            handler: async ({ site_id, ...rest }, extra) =>
                withSiteRetry(pool, site_id, async (client, site) =>
                    withSiteMeta(await handlePagePublish(client, rest, extra), site)),
        }),
    ];
}
