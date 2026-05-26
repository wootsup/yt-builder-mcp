/**
 * Page-tool barrel — public entry-point for the 6 page-level tools:
 *
 *   yootheme_builder_pages_list        → list templates (tableResult + structuredContent)
 *   yootheme_builder_page_get_layout   → full layout tree (jsonResult)
 *   yootheme_builder_page_get_schema   → flat schema (tableResult compact)
 *   yootheme_builder_page_save         → re-run save-transforms + persist
 *   yootheme_builder_page_publish      → publish
 *   yootheme_builder_get_etag          → top-level state ETag (detailResult)
 *
 * Round-2 R2-A2-CRIT1 — split out of the original `src/tools/pages.ts`.
 *
 * @license MIT
 */

export { buildPagesTools } from './builders.js';
export {
    ETAG_OUTPUT_SCHEMA,
    PAGES_LIST_OUTPUT_SCHEMA,
    SCHEMA_OUTPUT_SCHEMA,
    TEMPLATE_SUMMARY_OUTPUT_SCHEMA,
} from './schemas.js';
