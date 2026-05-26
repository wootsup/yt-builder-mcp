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
    // F-Frontend-URL (2026-05-25): each row carries `frontend_url`,
    // `frontend_url_template`, `frontend_url_description` so an MCP-agent
    // can answer "What is the URL of my <type> template?" without a
    // follow-up REST call or operator input. Server-side resolvers
    // (WordPress: get_permalink/get_term_link/get_author_posts_url; Joomla:
    // RouteHelper::getArticleRoute/getCategoryRoute/getTagRoute) emit one
    // of two shapes per row:
    //   - `frontend_url: <permalink>` + `frontend_url_template: null`
    //     when a canonical public URL exists (latest post / first category
    //     / first author etc.).
    //   - `frontend_url: null` + `frontend_url_template: <pattern>` when
    //     the URL needs a runtime parameter (search, 404-test) or when no
    //     public content of the matching type exists yet.
    // `frontend_url_description` is a short human hint ("Append any non-
    // existent path to test." / "Latest published post — rendered with
    // this template.") so the agent can render an instruction to the
    // end-user. All three keys are kept on the row-record (not an extra
    // schema object) so per-item sparse-field projection works.
    items: z.array(z.record(z.string(), z.unknown())),
    total: z.number(),
    etag: z.string().optional(),
    projected_fields: z.array(z.string()).optional(),
    // F-004 fix (2026-05-25 exhaustive audit): projection-feedback —
    // emitted ONLY when the caller passed `fields[]`. `available_fields`
    // is the discovered top-level field vocabulary; `unknown_fields` is
    // the subset of requested fields that did NOT appear in any item
    // (previously: items came back as `[{}, {}, …]` silently).
    available_fields: z.array(z.string()).optional(),
    unknown_fields: z.array(z.string()).optional(),
});

export const SCHEMA_OUTPUT_SCHEMA = z.object({
    // F-006 (2026-05-26): `rel_path` is the agent-preferred path form
    // (matches `element_list` + `page_get_layout` emit). `path` (the
    // fully-qualified `/templates/<id>/layout/...` pointer) stays on the
    // row for back-compat but is optional so callers reading the schema
    // know `rel_path` is the canonical projection column.
    items: z.array(
        z.object({
            rel_path: z.string(),
            path: z.string().optional(),
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

// F-AUDIT-2 (2026-05-26): template_summary outputSchema. The handler
// passes the REST `/pages/{id}/summary` body through verbatim via
// `jsonResult(data)`. Live shape verified against the WP+Joomla dev
// hosts: `template_id`, `counts_by_type` (open-ended map element-type
// → count), `bound_count`, `max_depth`, `total`, `named_sections` (each
// `{path, name}`), and the `etag` string. NOTE: `_meta` is merged at
// the RESULT-LEVEL by `withSiteMeta`, NEVER into `structuredContent`
// (see results.ts W12-R2 commentary) — this schema therefore validates
// only the inner template-summary shape.
export const TEMPLATE_SUMMARY_OUTPUT_SCHEMA = z.object({
    template_id: z.string(),
    counts_by_type: z.record(z.string(), z.number().int().nonnegative()),
    bound_count: z.number().int().nonnegative(),
    max_depth: z.number().int().nonnegative(),
    total: z.number().int().nonnegative(),
    named_sections: z.array(
        z.object({
            path: z.string(),
            name: z.string(),
        }),
    ),
    etag: z.string(),
});
