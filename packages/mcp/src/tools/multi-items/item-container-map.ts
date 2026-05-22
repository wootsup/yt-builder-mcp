/**
 * Multi-Items pattern — TypeScript mirror of the PHP
 * `WootsUp\BuilderMcp\Elements\ItemContainerMap` constant.
 *
 * YT-Pro 4.5.33+ implements `SourceTransform::repeatSource` which
 * clones the *source-bearing element* N-times as siblings inside its
 * parent. For "1 container with N children" the binding MUST live on
 * the `*_item` child element, NEVER on the container.
 *
 * The PHP and TS maps MUST stay in lock-step — both files carry a
 * pin-test that fails if the canonical pairings drift.
 *
 * @license MIT
 */

/**
 * Live-verified against `themes/yootheme/packages/builder/elements/
 * *_item/` on YT-Pro 4.5.33 (dev server, 2026-05-22). 16 pairs total.
 *
 * NB: There is NO `slider` / `slider_item` pair on YT-Pro 4.5.33 — the
 * YT slider/carousel widgets render via `slideshow`, `overlay-slider`,
 * and `panel-slider`.
 */
export const ITEM_CHILDREN_OF_CONTAINER: Readonly<Record<string, string>> = Object.freeze({
    accordion: 'accordion_item',
    button: 'button_item',
    description_list: 'description_list_item',
    gallery: 'gallery_item',
    grid: 'grid_item',
    list: 'list_item',
    map: 'map_item',
    nav: 'nav_item',
    'overlay-slider': 'overlay-slider_item',
    'panel-slider': 'panel-slider_item',
    popover: 'popover_item',
    slideshow: 'slideshow_item',
    social: 'social_item',
    subnav: 'subnav_item',
    switcher: 'switcher_item',
    table: 'table_item',
});

/**
 * Surface the `*_item` child type for a known container, or null when
 * the input is not a known multi-item container.
 */
export function itemOf(containerType: string): string | null {
    return Object.prototype.hasOwnProperty.call(ITEM_CHILDREN_OF_CONTAINER, containerType)
        ? (ITEM_CHILDREN_OF_CONTAINER[containerType] as string)
        : null;
}

/**
 * Surface the container type for a known `*_item`, or null otherwise.
 */
export function containerOf(itemType: string): string | null {
    for (const [container, item] of Object.entries(ITEM_CHILDREN_OF_CONTAINER)) {
        if (item === itemType) {
            return container;
        }
    }
    return null;
}

/**
 * `true` when $type is one of the multi-item-capable containers.
 */
export function isContainer(type: string): boolean {
    return Object.prototype.hasOwnProperty.call(ITEM_CHILDREN_OF_CONTAINER, type);
}

/**
 * `true` when $type is a `*_item` child of a multi-item container.
 */
export function isItem(type: string): boolean {
    return Object.values(ITEM_CHILDREN_OF_CONTAINER).includes(type);
}
