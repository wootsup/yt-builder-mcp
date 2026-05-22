<?php
/**
 * ItemContainerMap — single-source-of-truth for the YT-Pro container ↔ item
 * element pairing (Multi-Items binding pattern).
 *
 * YT-Pro 4.5.33+ implements `SourceTransform::repeatSource` (themes/yootheme
 * /packages/builder-source/src/Source/SourceTransform.php:172-249), which
 * clones the *source-bearing element* N-times as siblings inside its parent.
 *
 * Practical consequence for "1 container with N children" bindings:
 *
 *  - Binding on the **container** (`grid`, `slider`, …) → YT clones the
 *    container N times. End-user sees N stacked grids/sliders. Wrong.
 *  - Binding on the **child item** (`grid_item`, `slider_item`, …) → YT
 *    clones the item N times INSIDE the single container. Correct.
 *
 * The map below is the canonical reference consumed by:
 *   • `inspect_multi_items_binding` (MCP tool — surfaces the warning)
 *   • `clean_implode_directives`    (removes `implode` directives on
 *                                    container-level bindings)
 *   • `bind_source` (with `bindingLevel='item'` it auto-resolves a
 *                    container target to its first `*_item` child)
 *
 * The TypeScript mirror lives at
 * `packages/mcp/src/tools/multi-items/item-container-map.ts` —
 * both files MUST agree.
 *
 * References:
 *   - themes/yootheme/packages/builder/elements/grid_item/element.json:190
 *     (`"source": "${builder.source}"` on grid_item — YT's own pattern)
 *   - themes/yootheme/packages/builder-source/config/builder.json
 *     (`_source` / `_sourceField` / `_sourceCondition` declarations)
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Elements
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Elements;

final class ItemContainerMap
{
    /**
     * Canonical YT-Pro container → item pairing. Sorted by group:
     * UIkit grid/list/slider/slideshow → gallery/accordion → map →
     * overlay/panel.
     *
     * @var array<string, string>
     */
    public const MAP = [
        'grid' => 'grid_item',
        'list' => 'list_item',
        'slider' => 'slider_item',
        'slideshow' => 'slideshow_item',
        'switcher' => 'switcher_item',
        'gallery' => 'gallery_item',
        'accordion' => 'accordion_item',
        'map' => 'map_item',
        'overlay-slider' => 'overlay-slider_item',
        'panel-slider' => 'panel-slider_item',
    ];

    /**
     * Given a container type (`grid`, `slider`, …) return the canonical
     * `*_item` child type, or null when the input is not a known
     * multi-item container.
     */
    public static function itemOf(string $containerType): ?string
    {
        return self::MAP[$containerType] ?? null;
    }

    /**
     * Given an item type (`grid_item`, `slider_item`, …) return the
     * container type that wraps it, or null when the input is not a
     * known multi-item child.
     */
    public static function containerOf(string $itemType): ?string
    {
        $hit = array_search($itemType, self::MAP, true);
        return is_string($hit) ? $hit : null;
    }

    /**
     * Surface whether $type is a multi-item-capable container (its
     * children can be bound to a source via the item-level pattern).
     */
    public static function isContainer(string $type): bool
    {
        return array_key_exists($type, self::MAP);
    }

    /**
     * Surface whether $type is a child item of a multi-item container.
     */
    public static function isItem(string $type): bool
    {
        return in_array($type, self::MAP, true);
    }
}
