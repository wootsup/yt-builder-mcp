<?php
/**
 * Inspector catalog — F-03 v2 (Maria-Audit Stream C2).
 *
 * Pins the element-type catalog metadata fidelity surfaced by
 * `/element-types` and the MCP `yootheme_builder_element_types_list`
 * tool. The audit re-verify (2026-05-22) reported every catalog row
 * with `label=""`, `origin=""`, `has_children=false` — even for
 * canonical containers like grid/section/tabs. Root causes:
 *
 *   1. The live-path probe `\YOOtheme\Builder::getTypes()` does NOT
 *      exist on YT 4.5.33; the registry lives at the INSTANCE property
 *      `\YOOtheme\app('YOOtheme\Builder')->types`.
 *   2. Each ElementType wraps the raw config under public `->data` —
 *      `(array) $type` does not surface `data` keys; the unwrap helper
 *      must read `$type->data` (or `getArrayCopy()` / JsonSerializable).
 *   3. YT element.json uses key `title` (NOT `label`) for the human label.
 *   4. `element: true` means "is a builder element" — NOT "accepts children".
 *      The canonical container marker is `container: true`. Item-children
 *      (grid_item, slideshow_item, …) accept arbitrary inner elements
 *      per the YT-Pro 4.5.33 Multi-Items pattern; the canonical map is
 *      `WootsUp\BuilderMcp\Elements\ItemContainerMap::MAP`.
 *
 * This file lives separately from `InspectorTest.php` to avoid parallel-
 * subagent file collisions during the Stream C1/C2/C3 sweep.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Inspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Elements\ItemContainerMap;
use WootsUp\BuilderMcp\Inspection\Inspector;
use WootsUp\BuilderMcp\Yootheme\YoothemeAdapter;

#[CoversClass(Inspector::class)]
final class InspectorCatalogF03Test extends TestCase
{
    public function test_fallback_catalog_no_entry_has_empty_label(): void
    {
        // Maria-Audit v2 F-03: every entry must carry a human label, even
        // the static fallback. Empty labels in the wire shape break the
        // MCP element_types_list table column.
        $inspector = new Inspector();
        foreach ($inspector->listCatalog() as $entry) {
            self::assertNotSame(
                '',
                $entry['label'],
                "label must be non-empty for type '{$entry['name']}'",
            );
        }
    }

    public function test_fallback_catalog_no_entry_has_empty_origin(): void
    {
        $inspector = new Inspector();
        foreach ($inspector->listCatalog() as $entry) {
            self::assertNotSame(
                '',
                $entry['origin'],
                "origin must be non-empty for type '{$entry['name']}'",
            );
        }
    }

    public function test_fallback_catalog_includes_all_item_children_from_canonical_map(): void
    {
        // Maria-Audit v2 F-03: the 16 *_item child types from
        // ItemContainerMap MUST appear in the catalog with has_children=true.
        // Without them present, MCP-clients cannot suggest item-level
        // bindings (the Multi-Items pattern from yootheme-development skill).
        $inspector = new Inspector();
        $byName = [];
        foreach ($inspector->listCatalog() as $entry) {
            $byName[$entry['name']] = $entry;
        }
        foreach (ItemContainerMap::MAP as $container => $itemType) {
            self::assertArrayHasKey(
                $container,
                $byName,
                "Missing container type '$container' (canonical YT-Pro container/item map)"
            );
            self::assertArrayHasKey(
                $itemType,
                $byName,
                "Missing item-child type '$itemType' (canonical YT-Pro container/item map)"
            );
            self::assertTrue(
                $byName[$container]['has_children'],
                "$container must report has_children=true (it accepts $itemType children)"
            );
            self::assertTrue(
                $byName[$itemType]['has_children'],
                "$itemType must report has_children=true (it accepts inner elements per Multi-Items pattern)"
            );
        }
    }

    public function test_fallback_catalog_structural_containers_have_children(): void
    {
        // Per Stream C2 contract: section/row/column are structural
        // containers, always has_children=true.
        $inspector = new Inspector();
        $byName = [];
        foreach ($inspector->listCatalog() as $entry) {
            $byName[$entry['name']] = $entry;
        }
        foreach (['section', 'row', 'column'] as $structural) {
            self::assertArrayHasKey($structural, $byName, "Missing structural container '$structural'");
            self::assertTrue(
                $byName[$structural]['has_children'],
                "$structural must report has_children=true"
            );
        }
    }

    public function test_fallback_catalog_leaves_have_has_children_false(): void
    {
        $inspector = new Inspector();
        $byName = [];
        foreach ($inspector->listCatalog() as $entry) {
            $byName[$entry['name']] = $entry;
        }
        // Pure leaf types (no inner elements) — Stream C2 contract.
        $leaves = ['headline', 'text', 'image', 'icon', 'divider', 'video', 'html', 'code'];
        foreach ($leaves as $leaf) {
            self::assertArrayHasKey($leaf, $byName, "Missing leaf type '$leaf'");
            self::assertFalse(
                $byName[$leaf]['has_children'],
                "$leaf must report has_children=false (pure leaf — no inner elements)"
            );
        }
    }

    public function test_live_path_label_comes_from_adapter_detailed_view(): void
    {
        // F-03 v2: When the adapter surfaces a non-empty detailed view, the
        // Inspector MUST relay it through — not silently fall back to the
        // static catalog. This guards against a future refactor where the
        // live path returns data but the Inspector ignores it.
        $adapter = new class extends YoothemeAdapter {
            public function getBuilderTypesDetailed(): ?array
            {
                return [
                    ['name' => 'headline', 'label' => 'Headline', 'origin' => 'builtin', 'has_children' => false],
                    ['name' => 'grid', 'label' => 'Grid', 'origin' => 'builtin', 'has_children' => true],
                    ['name' => 'grid_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
                    ['name' => 'breadcrumbs', 'label' => 'Breadcrumbs', 'origin' => 'essentials', 'has_children' => false],
                ];
            }
        };
        $inspector = new Inspector($adapter);
        $catalog = $inspector->listCatalog();
        self::assertCount(4, $catalog);
        $byName = [];
        foreach ($catalog as $entry) {
            $byName[$entry['name']] = $entry;
        }
        self::assertSame('Headline', $byName['headline']['label']);
        self::assertSame('Item', $byName['grid_item']['label']);
        self::assertSame('essentials', $byName['breadcrumbs']['origin']);
        self::assertTrue($byName['grid']['has_children']);
        self::assertTrue($byName['grid_item']['has_children']);
        self::assertFalse($byName['headline']['has_children']);
        self::assertFalse($byName['breadcrumbs']['has_children']);
    }
}
