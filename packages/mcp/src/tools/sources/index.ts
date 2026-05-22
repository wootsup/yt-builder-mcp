/**
 * Source-tool barrel — public entry-point for the 4 source-binding tools.
 *
 *   yootheme_builder_sources_list
 *      → list available Builder sources
 *   yootheme_builder_element_get_binding
 *      → read source binding on an element
 *   yootheme_builder_element_bind_source
 *      → set source binding (PUT; G.4.3 ambiguity resolution)
 *   yootheme_builder_element_unbind_source
 *      → remove binding (DELETE; G.4.2 elicit-confirm)
 *
 * Wave G.4.0b — split out of the original `src/tools/sources.ts`.
 *
 * @license MIT
 */

export { buildSourcesTools } from './builders.js';
export type { SourcesHandlerDeps } from './handlers.js';
