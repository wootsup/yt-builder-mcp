/**
 * Element-tool barrel — public entry-point for the 7 element tools.
 *
 *   yootheme_builder_element_list
 *      → list all elements in a template (flat, with paths)
 *   yootheme_builder_element_get
 *      → fetch one element by JSON-Pointer
 *   yootheme_builder_element_add
 *      → POST new element
 *   yootheme_builder_element_update_settings
 *      → PUT props on an existing element
 *   yootheme_builder_element_move
 *      → relocate within tree
 *   yootheme_builder_element_clone
 *      → duplicate as sibling
 *   yootheme_builder_element_delete   (destructive — elicit/confirm)
 *
 * Wave G.4.0 — file split from the monolithic `src/tools/elements.ts`
 * (382 LoC) into `index.ts` (this file, re-exports), `builders.ts`
 * (defineTool factory list, ≤200 LoC), and `handlers.ts` (handler
 * bodies, ≤300 LoC). Tool surface is unchanged — see
 * `tests/tools/elements-split.test.ts` for the snapshot proof.
 *
 * @license MIT
 */

export { buildElementsTools } from './builders.js';
export type { ElementsHandlerDeps } from './handlers.js';
