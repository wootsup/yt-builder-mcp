/**
 * Element-type inspection tools.
 *
 *   yootheme_builder_element_types_list
 *      → catalogue of available element types (tableResult — Wave G.2)
 *   yootheme_builder_element_type_get_schema
 *      → field schema for a single element type (detailResult — Wave G.2)
 *
 * @license MIT
 */

import { detailResult, tableResult } from '@getimo/mcp-toolkit';
import { z } from 'zod';
import type { ClientPool } from '../sites/client-pool.js';
import {
    TYPES_COMPACT_COLUMNS,
    TYPES_TABLE_COLUMNS,
    buildTypeSchemaDetail,
    flattenTypesPayload,
    mapTypeRow,
} from './format/inspection-format.js';
import { SITE_ID_SCHEMA } from './shared-schemas.js';
import { resolveSiteOrError } from './pool-resolve-helper.js';
import {
    defineTool,
    errorResult,
    readOnly,
    structuredResult,
    withSiteMeta,
    type AnyToolDefinition,
} from './tool-builder.js';
import {
    DEFAULT_FIELDS_TYPES_LIST,
    FIELDS,
    projectFields,
    projectedFieldsEcho,
    projectionFeedback,
} from './sparse-fields.js';

// ─── outputSchemas (Wave G.2 §4) ─────────────────────────────────────

const TYPES_LIST_OUTPUT_SCHEMA = z.object({
    items: z.array(z.record(z.string(), z.unknown())),
    total: z.number(),
    projected_fields: z.array(z.string()).optional(),
    // F-004 fix (2026-05-25 exhaustive audit): see sparse-fields::projectionFeedback.
    available_fields: z.array(z.string()).optional(),
    unknown_fields: z.array(z.string()).optional(),
});

const TYPE_SCHEMA_OUTPUT_SCHEMA = z.object({
    name: z.string(),
    label: z.string().optional(),
    origin: z.string().optional(),
    // The REST endpoint returns `fields` as a list of {name,type,label?}
    // field descriptors — each `name` is a valid prop key for element_add /
    // _update_settings.
    fields: z.array(z.record(z.string(), z.unknown())).optional(),
    field_count: z.number(),
});

export function buildInspectionTools(pool: ClientPool): readonly AnyToolDefinition[] {
    return [
        defineTool({
            name: 'yootheme_builder_element_types_list',
            description:
                'List element types registered on this site (built-ins + ' +
                'YOOessentials/uEssentials extras). Names feed `element_type` of ' +
                'element_add. Pass `fields[]` to narrow each row. ' +
                'Operates on the default site unless site_id is provided.',
            inputSchema: {
                site_id: SITE_ID_SCHEMA,
                fields: FIELDS,
            },
            outputSchema: TYPES_LIST_OUTPUT_SCHEMA,
            annotations: readOnly('List Element Types'),
            handler: async ({ site_id, fields }) => {
                const r = await resolveSiteOrError(pool, site_id);
                if (!r.ok) return r.error;
                const { client: siteClient, site } = r;
                try {
                    const data = await siteClient.get('/element-types');
                    const flat = flattenTypesPayload(data);
                    const mapped = flat.map(mapTypeRow);
                    const mappedRecords = mapped as unknown as Record<string, unknown>[];
                    const items = projectFields(mappedRecords, fields, DEFAULT_FIELDS_TYPES_LIST);
                    const echo = projectedFieldsEcho(fields, DEFAULT_FIELDS_TYPES_LIST);
                    const feedback = projectionFeedback(mappedRecords, fields);
                    const unknownNote =
                        feedback !== undefined && feedback.unknown_fields.length > 0
                            ? ` (unknown fields ignored: ${feedback.unknown_fields.join(', ')}; available: ${feedback.available_fields.join(', ')})`
                            : '';
                    const toolkitResult = tableResult(mappedRecords, {
                        columns: [...TYPES_TABLE_COLUMNS],
                        compactColumns: [...TYPES_COMPACT_COLUMNS],
                        header: (count) => `${String(count)} element types${unknownNote}`,
                        footer: 'Use yootheme_builder_element_type_get_schema <name> for fields.',
                    });
                    return withSiteMeta(structuredResult(toolkitResult, {
                        items,
                        total: items.length,
                        ...(echo !== undefined ? { projected_fields: [...echo] } : {}),
                        ...(feedback !== undefined ? {
                            available_fields: [...feedback.available_fields],
                            unknown_fields: [...feedback.unknown_fields],
                        } : {}),
                    }), site);
                } catch (e) {
                    return withSiteMeta(errorResult({
                        error: e,
                        context: { site_id: site.id },
                        hint: 'Run yootheme_builder_diagnose to verify auth.',
                    }), site);
                }
            },
        }),

        buildElementTypeGetSchemaTool(pool),
    ];
}

/**
 * F-203 follow-up (Audit 2026-05-26 reviewer Gap 2): factored out of
 * `buildInspectionTools` so the shape + refined ZodObject pair lives in
 * one place. The SDK's pre-handler validator honours the top-level
 * `.refine()` on `TYPE_SCHEMA_REFINED` and raises
 * JSON-RPC `-32602 InvalidParams` when violated — hosts see the
 * failure BEFORE the handler ever runs. The raw shape stays for
 * handler-type inference (see `inputSchema`).
 */
function buildElementTypeGetSchemaTool(pool: ClientPool): AnyToolDefinition {
    const TYPE_SCHEMA_SHAPE = {
        site_id: SITE_ID_SCHEMA,
        // 1.0.1 cross-tool parameter naming alignment: every other
        // tool in this domain uses `element_type` (element_add,
        // element_update_settings, element_list rows). This tool
        // originally shipped with `type_name`; we accept BOTH for
        // backward-compatibility but the canonical key going
        // forward is `element_type`.
        element_type: z
            .string()
            .min(1)
            .optional()
            .describe(
                'Element type name (e.g. "headline", "text", "grid"). Use ' +
                    'yootheme_builder_element_types_list to discover.',
            ),
        type_name: z
            .string()
            .min(1)
            .optional()
            .describe(
                'DEPRECATED alias of `element_type` — use `element_type` ' +
                    'instead. Kept for 1.0.x backward-compatibility; will be ' +
                    'removed in a future major.',
            ),
    } as const;
    const TYPE_SCHEMA_REFINED = z
        .object(TYPE_SCHEMA_SHAPE)
        .refine(
            (args) =>
                (typeof args.element_type === 'string' && args.element_type !== '') ||
                (typeof args.type_name === 'string' && args.type_name !== ''),
            {
                message:
                    'Provide either `element_type` (canonical) or ' +
                    '`type_name` (deprecated alias).',
            },
        );
    return defineTool({
        name: 'yootheme_builder_element_type_get_schema',
        description:
            '**Call before every `element_add` / `_update_settings`** — unknown ' +
            'prop keys are silently dropped server-side, so guessing fails ' +
            'quietly. Returns `{name,type,label?}` field descriptors. Use ' +
            '`element_type`; `type_name` is DEPRECATED. ' +
            'Operates on the default site unless site_id is provided.',
        inputSchema: TYPE_SCHEMA_SHAPE,
        // F-203 follow-up: the refined object is what the SDK actually
        // validates. The raw shape above stays for handler-type inference.
        inputObjectSchema: TYPE_SCHEMA_REFINED,
        outputSchema: TYPE_SCHEMA_OUTPUT_SCHEMA,
        annotations: readOnly('Get Element Type Schema'),
        handler: async (args) => {
            // Defensive re-assert of the refine — should never fire when
            // reached through the SDK (the boundary rejects with -32602
            // first), but in-process tests bypass the SDK and call the
            // handler directly, so the structured error path stays
            // reachable for unit-test coverage.
            const typeNameInput =
                typeof args.element_type === 'string' && args.element_type !== ''
                    ? args.element_type
                    : typeof args.type_name === 'string'
                      ? args.type_name
                      : '';
            if (typeNameInput === '') {
                return errorResult({
                    error: new Error(
                        'Provide either `element_type` (canonical) or ' +
                            '`type_name` (deprecated alias).',
                    ),
                    context: { site_id: typeof args.site_id === 'string' ? args.site_id : null },
                    hint:
                        'Pass `element_type` (e.g. "headline"). Use ' +
                        'yootheme_builder_element_types_list to discover valid values.',
                });
            }
            const type_name = typeNameInput;
            const r = await resolveSiteOrError(
                pool,
                typeof args.site_id === 'string' ? args.site_id : undefined,
            );
            if (!r.ok) return r.error;
            const { client: siteClient, site } = r;
            try {
                const data = await siteClient.get<Record<string, unknown>>(
                    `/element-types/${encodeURIComponent(type_name)}/schema`,
                );
                // The REST endpoint nests the type schema under `schema`:
                // {type_name, schema:{name,label,origin,has_children,fields:[…]}}.
                // Fall back to the top-level object for older backends.
                const schema =
                    data.schema !== null && typeof data.schema === 'object'
                        ? (data.schema as Record<string, unknown>)
                        : data;
                const name = typeof schema.name === 'string' ? schema.name : type_name;
                const label = typeof schema.label === 'string' ? schema.label : undefined;
                const origin = typeof schema.origin === 'string' ? schema.origin : undefined;
                // `fields` is a list of {name,type,label?} field descriptors.
                const fields = Array.isArray(schema.fields)
                    ? (schema.fields as Record<string, unknown>[])
                    : undefined;
                const fieldCount = fields !== undefined ? fields.length : 0;

                const toolkitResult = detailResult(
                    buildTypeSchemaDetail({ name, label, origin, fields }),
                );
                return withSiteMeta(structuredResult(toolkitResult, {
                    name,
                    ...(label !== undefined ? { label } : {}),
                    ...(origin !== undefined ? { origin } : {}),
                    ...(fields !== undefined ? { fields } : {}),
                    field_count: fieldCount,
                }), site);
            } catch (e) {
                return withSiteMeta(errorResult({
                    error: e,
                    context: { element_type: type_name, site_id: site.id },
                    hint:
                        'Verify element_type via yootheme_builder_element_types_list — names ' +
                        'are case-sensitive.',
                }), site);
            }
        },
    });
}
