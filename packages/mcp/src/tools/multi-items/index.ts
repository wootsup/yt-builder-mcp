/**
 * Multi-Items tool barrel — public entry-point for the YT-Pro
 * Multi-Items binding pattern tools.
 *
 *   yootheme_builder_inspect_multi_items_binding
 *     → Report the Multi-Items binding state for an element.
 *
 *   yootheme_builder_clean_implode_directives
 *     → Remove legacy implode directives from an element binding.
 *
 * The canonical container ↔ item map lives in `./item-container-map.ts`
 * (mirror of `WootsUp\BuilderMcp\Elements\ItemContainerMap`).
 *
 * @license MIT
 */

export { buildMultiItemsTools } from './builders.js';
export {
    ITEM_CHILDREN_OF_CONTAINER,
    containerOf,
    isContainer,
    isItem,
    itemOf,
} from './item-container-map.js';
