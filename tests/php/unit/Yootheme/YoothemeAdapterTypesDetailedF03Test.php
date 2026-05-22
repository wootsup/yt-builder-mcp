<?php
/**
 * YoothemeAdapter — getBuilderTypesDetailed() F-03 v2 (Maria-Audit Stream C2).
 *
 * Pins the live-path projection of YT 4.5.33 ElementType registry into
 * the `{name,label,origin,has_children}` catalog shape that
 * `Inspector::listCatalog()` and `/element-types` REST surface.
 *
 * The audit re-verify (2026-05-22) reported every catalog row with
 * `label=""` / `has_children=false`. Two root causes covered here:
 *
 *   1. YT element.json uses key `title` (NOT `label`) for the human
 *      label. The adapter MUST read `title` before falling back to
 *      `label` and then to PascalCase.
 *   2. `element: true` means "is a builder element" — NOT "accepts
 *      children". The canonical container marker is `container: true`.
 *      Item-children (grid_item, slideshow_item, …) accept arbitrary
 *      inner elements per the YT-Pro 4.5.33 Multi-Items pattern; the
 *      canonical map is `ItemContainerMap::MAP`.
 *
 * This file is intentionally separate from `YoothemeAdapterTest.php`
 * to avoid parallel-subagent file collisions during the Stream
 * C1/C2/C3 sweep.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Yootheme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Yootheme\YoothemeAdapter;

#[CoversClass(YoothemeAdapter::class)]
final class YoothemeAdapterTypesDetailedF03Test extends TestCase
{
    /**
     * F-03 v2 happy path: live YT 4.5.33 ElementType registry surfaces
     * `title` as label, distinguishes containers via `container: true`,
     * and routes `*_item` children through the canonical container/item
     * map so they report has_children=true.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_detailed_view_reads_title_as_label_and_distinguishes_containers_from_leaves(): void
    {
        if (!class_exists('\\YOOtheme\\Application', false)) {
            eval('namespace YOOtheme; class Application {}');
        }
        if (!class_exists('\\YOOtheme\\Builder\\ElementType', false)) {
            eval('
                namespace YOOtheme\\Builder {
                    class ElementType {
                        /** @var array<string,mixed> */
                        public array $data;
                        /** @param array<string,mixed> $data */
                        public function __construct(array $data) {
                            $this->data = $data;
                        }
                    }
                }
            ');
        }
        if (!class_exists('\\YOOtheme\\Builder', false)) {
            eval('
                namespace YOOtheme {
                    class Builder {
                        /** @var array<string, mixed> */
                        public array $types = [];
                    }
                }
            ');
        }
        if (!function_exists('\\YOOtheme\\app')) {
            eval('
                namespace YOOtheme {
                    function app($id = null) {
                        static $builder = null;
                        if ($id === "YOOtheme\\\\Builder") {
                            if ($builder === null) {
                                $builder = new \\YOOtheme\\Builder();
                                // YT element.json shape — these literal payloads mirror
                                // the live data on dev.wootsup.com YT 4.5.33.
                                $builder->types["headline"] = new \\YOOtheme\\Builder\\ElementType([
                                    "name" => "headline",
                                    "title" => "Headline",
                                    "group" => "basic",
                                    "element" => true,
                                ]);
                                $builder->types["grid"] = new \\YOOtheme\\Builder\\ElementType([
                                    "name" => "grid",
                                    "title" => "Grid",
                                    "group" => "multiple items",
                                    "element" => true,
                                    "container" => true,
                                ]);
                                $builder->types["section"] = new \\YOOtheme\\Builder\\ElementType([
                                    "name" => "section",
                                    "title" => "Section",
                                    "container" => true,
                                ]);
                                $builder->types["grid_item"] = new \\YOOtheme\\Builder\\ElementType([
                                    "name" => "grid_item",
                                    "title" => "Item",
                                ]);
                                $builder->types["accordion_item"] = new \\YOOtheme\\Builder\\ElementType([
                                    "name" => "accordion_item",
                                    "title" => "Item",
                                ]);
                                $builder->types["divider"] = new \\YOOtheme\\Builder\\ElementType([
                                    "name" => "divider",
                                    "title" => "Divider",
                                    "group" => "basic",
                                    "element" => true,
                                ]);
                                $builder->types["alert"] = new \\YOOtheme\\Builder\\ElementType([
                                    "name" => "alert",
                                    "title" => "Alert",
                                    "group" => "basic",
                                    "element" => true,
                                ]);
                            }
                            return $builder;
                        }
                        return null;
                    }
                }
            ');
        }

        $adapter = new YoothemeAdapter();
        $detailed = $adapter->getBuilderTypesDetailed();
        self::assertIsArray($detailed);
        self::assertCount(7, $detailed);

        $byName = [];
        foreach ($detailed as $entry) {
            $byName[$entry['name']] = $entry;
        }

        // F-03: label MUST come from `title` (YT convention).
        self::assertSame('Headline', $byName['headline']['label']);
        self::assertSame('Grid', $byName['grid']['label']);
        self::assertSame('Section', $byName['section']['label']);
        self::assertSame('Item', $byName['grid_item']['label']);
        self::assertSame('Item', $byName['accordion_item']['label']);
        self::assertSame('Divider', $byName['divider']['label']);
        self::assertSame('Alert', $byName['alert']['label']);

        // F-03: container types (container: true) report has_children=true.
        self::assertTrue($byName['grid']['has_children']);
        self::assertTrue($byName['section']['has_children']);

        // F-03: pure leaves (element: true, no container) report has_children=false.
        // `element: true` alone does NOT imply children — this is the audit fix.
        self::assertFalse(
            $byName['headline']['has_children'],
            'headline must be has_children=false — element:true does NOT imply children'
        );
        self::assertFalse(
            $byName['divider']['has_children'],
            'divider must be has_children=false — element:true does NOT imply children'
        );
        self::assertFalse(
            $byName['alert']['has_children'],
            'alert must be has_children=false — element:true does NOT imply children'
        );

        // F-03: item-children of canonical containers (ItemContainerMap)
        // report has_children=true (Multi-Items pattern target).
        self::assertTrue(
            $byName['grid_item']['has_children'],
            'grid_item must be has_children=true (item-child of grid, accepts inner elements)'
        );
        self::assertTrue(
            $byName['accordion_item']['has_children'],
            'accordion_item must be has_children=true (item-child of accordion)'
        );

        // F-03: origin is never empty — defaults to 'builtin'.
        foreach ($byName as $entry) {
            self::assertNotSame('', $entry['origin']);
            self::assertContains($entry['origin'], ['builtin', 'essentials', 'uessentials']);
        }
    }

    /**
     * F-03 v2: when the adapter is unable to reach a live registry but YT
     * is class-loaded (rare edge case — e.g. partial bootstrap), the
     * method must return null cleanly so Inspector falls through to
     * FALLBACK_CATALOG. No crash, no half-populated rows.
     */
    public function test_detailed_view_returns_null_when_yt_unreachable(): void
    {
        $adapter = new YoothemeAdapter();
        self::assertNull($adapter->getBuilderTypesDetailed());
    }
}
