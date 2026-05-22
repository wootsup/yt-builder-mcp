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
import type { RestClient } from '../client.js';
import {
    TYPES_COMPACT_COLUMNS,
    TYPES_TABLE_COLUMNS,
    buildTypeSchemaDetail,
    flattenTypesPayload,
    mapTypeRow,
} from './format/inspection-format.js';
import {
    defineTool,
    errorResult,
    readOnly,
    structuredResult,
    type AnyToolDefinition,
} from './tool-builder.js';
import {
    DEFAULT_FIELDS_TYPES_LIST,
    FIELDS,
    projectFields,
    projectedFieldsEcho,
} from './sparse-fields.js';

// ─── outputSchemas (Wave G.2 §4) ─────────────────────────────────────

const TYPES_LIST_OUTPUT_SCHEMA = z.object({
    items: z.array(z.record(z.string(), z.unknown())),
    total: z.number(),
    projected_fields: z.array(z.string()).optional(),
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

export function buildInspectionTools(client: RestClient): readonly AnyToolDefinition[] {
    return [
        defineTool({
            name: 'yootheme_builder_element_types_list',
            description:
                'List element types registered on this site (built-ins + ' +
                'YOOessentials/uEssentials extras). Names feed `element_type` of ' +
                'element_add. Pass `fields[]` to narrow each row.',
            inputSchema: {
                fields: FIELDS,
            },
            outputSchema: TYPES_LIST_OUTPUT_SCHEMA,
            annotations: readOnly('List Element Types'),
            handler: async ({ fields }) => {
                try {
                    const data = await client.get('/element-types');
                    const flat = flattenTypesPayload(data);
                    const mapped = flat.map(mapTypeRow);
                    const mappedRecords = mapped as unknown as Record<string, unknown>[];
                    const items = projectFields(mappedRecords, fields, DEFAULT_FIELDS_TYPES_LIST);
                    const echo = projectedFieldsEcho(fields, DEFAULT_FIELDS_TYPES_LIST);
                    const toolkitResult = tableResult(mappedRecords, {
                        columns: [...TYPES_TABLE_COLUMNS],
                        compactColumns: [...TYPES_COMPACT_COLUMNS],
                        header: (count) => `${String(count)} element types`,
                        footer: 'Use yootheme_builder_element_type_get_schema <name> for fields.',
                    });
                    return structuredResult(toolkitResult, {
                        items,
                        total: items.length,
                        ...(echo !== undefined ? { projected_fields: [...echo] } : {}),
                    });
                } catch (e) {
                    return errorResult({
                        error: e,
                        context: {},
                        hint: 'Run yootheme_builder_diagnose to verify auth.',
                    });
                }
            },
        }),

        defineTool({
            name: 'yootheme_builder_element_type_get_schema',
            description:
                'Get the prop/field schema for a single element type. Use the result to discover ' +
                'valid keys for `props` when calling yootheme_builder_element_add or ' +
                '_update_settings.',
            inputSchema: {
                type_name: z
                    .string()
                    .min(1)
                    .describe(
                        'Element type name (e.g. "headline", "text", "grid"). Use ' +
                            'yootheme_builder_element_types_list to discover.',
                    ),
            },
            outputSchema: TYPE_SCHEMA_OUTPUT_SCHEMA,
            annotations: readOnly('Get Element Type Schema'),
            handler: async ({ type_name }) => {
                try {
                    const data = await client.get<Record<string, unknown>>(
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
                    return structuredResult(toolkitResult, {
                        name,
                        ...(label !== undefined ? { label } : {}),
                        ...(origin !== undefined ? { origin } : {}),
                        ...(fields !== undefined ? { fields } : {}),
                        field_count: fieldCount,
                    });
                } catch (e) {
                    return errorResult({
                        error: e,
                        context: { type_name },
                        hint:
                            'Verify type_name via yootheme_builder_element_types_list — names ' +
                            'are case-sensitive.',
                    });
                }
            },
        }),
    ];
}
