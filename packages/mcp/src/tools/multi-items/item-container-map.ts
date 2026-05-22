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

export const ITEM_CHILDREN_OF_CONTAINER: Readonly<Record<string, string>> = Object.freeze({
    grid: 'grid_item',
    list: 'list_item',
    slider: 'slider_item',
    slideshow: 'slideshow_item',
    switcher: 'switcher_item',
    gallery: 'gallery_item',
    accordion: 'accordion_item',
    map: 'map_item',
    'overlay-slider': 'overlay-slider_item',
    'panel-slider': 'panel-slider_item',
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
