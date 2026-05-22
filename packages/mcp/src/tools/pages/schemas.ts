/**
 * Page-tool output-schemas (Wave G.2 §4 structuredContent contracts).
 *
 * Split out of `src/tools/pages.ts` (Round-2 R2-A2-CRIT1) so the
 * handler files stay focused on logic.
 *
 * @license MIT
 */

import { z } from 'zod';

export const PAGES_LIST_OUTPUT_SCHEMA = z.object({
    items: z.array(z.record(z.string(), z.unknown())),
    total: z.number(),
    etag: z.string().optional(),
    projected_fields: z.array(z.string()).optional(),
});

export const SCHEMA_OUTPUT_SCHEMA = z.object({
    items: z.array(
        z.object({
            path: z.string(),
            element_type: z.string(),
            label: z.string(),
            has_binding: z.boolean(),
        }),
    ),
    total: z.number(),
    template_id: z.string(),
});

export const ETAG_OUTPUT_SCHEMA = z.object({
    etag: z.string(),
    generated_at: z.string().optional(),
});
