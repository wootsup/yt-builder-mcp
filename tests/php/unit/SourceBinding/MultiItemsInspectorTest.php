<?php
/**
 * MultiItemsInspector — surfaces Multi-Items binding state on an
 * element, with recommendations for the "1 container with N children"
 * pattern.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\SourceBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\Elements\ItemContainerMap;
use WootsUp\BuilderMcp\SourceBinding\MultiItemsInspector;
use WootsUp\BuilderMcp\State\LayoutReader;

#[CoversClass(MultiItemsInspector::class)]
#[CoversClass(ItemContainerMap::class)]
final class MultiItemsInspectorTest extends TestCase
{
    private function reader(): LayoutReader
    {
        return new LayoutReader();
    }

    private function ops(): ElementOps
    {
        return new ElementOps($this->reader());
    }

    private function inspector(): MultiItemsInspector
    {
        return new MultiItemsInspector($this->ops());
    }

    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [
            'yootheme' => [
                'templates' => [
                    'tpl' => [
                        'layout' => [
                            'type' => 'layout',
                            'children' => [
                                // [0] container with child item
                                [
                                    'type' => 'grid',
                                    'props' => [],
                                    'children' => [
                                        [
                                            'type' => 'grid_item',
                                            'props' => [
                                                'source' => [
                                                    'query' => ['name' => 'wp_posts'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                // [1] container bound itself (WRONG pattern)
                                [
                                    'type' => 'slideshow',
                                    'props' => [
                                        'source' => [
                                            'query' => ['name' => 'wp_posts'],
                                            'props' => [
                                                'title' => [
                                                    'name' => 'title',
                                                    'filters' => [],
                                                    'implode' => ['join' => ',', 'glue' => ' '],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'children' => [
                                        ['type' => 'slideshow_item', 'props' => []],
                                    ],
                                ],
                                // [2] plain section (neither container nor item)
                                ['type' => 'section', 'props' => []],
                                // [3] container without binding
                                [
                                    'type' => 'list',
                                    'props' => [],
                                    'children' => [
                                        ['type' => 'list_item', 'props' => []],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_item_with_binding_returns_current_binding_level_item(): void
    {
        $report = $this->inspector()->inspect(
            'tpl',
            '/templates/tpl/layout/children/0/children/0',
        );

        $this->assertSame('grid_item', $report['element_type']);
        $this->assertFalse($report['is_container']);
        $this->assertTrue($report['is_item']);
        $this->assertSame('grid', $report['container_type']);
        $this->assertSame('grid_item', $report['item_type']);
        $this->assertSame('item', $report['current_binding_level']);
        $this->assertFalse($report['has_implode_directives']);
        $this->assertArrayNotHasKey('warning', $report);
    }

    public function test_container_with_binding_surfaces_warning_and_recommended_fix(): void
    {
        $report = $this->inspector()->inspect(
            'tpl',
            '/templates/tpl/layout/children/1',
        );

        $this->assertSame('slideshow', $report['element_type']);
        $this->assertTrue($report['is_container']);
        $this->assertFalse($report['is_item']);
        $this->assertSame('slideshow_item', $report['item_type']);
        $this->assertSame('container', $report['current_binding_level']);
        $this->assertTrue($report['has_implode_directives']);
        $this->assertArrayHasKey('warning', $report);
        $this->assertStringContainsString('SourceTransform::repeatSource', (string) $report['warning']);
        $this->assertArrayHasKey('recommended_fix', $report);
        $this->assertStringContainsString('slideshow_item', (string) $report['recommended_fix']);
    }

    public function test_non_multi_item_element_classifies_as_neither(): void
    {
        $report = $this->inspector()->inspect(
            'tpl',
            '/templates/tpl/layout/children/2',
        );

        $this->assertSame('section', $report['element_type']);
        $this->assertFalse($report['is_container']);
        $this->assertFalse($report['is_item']);
        $this->assertSame('none', $report['current_binding_level']);
        $this->assertFalse($report['has_implode_directives']);
    }

    public function test_container_without_binding_surfaces_container_type_no_warning(): void
    {
        $report = $this->inspector()->inspect(
            'tpl',
            '/templates/tpl/layout/children/3',
        );

        $this->assertTrue($report['is_container']);
        $this->assertSame('list_item', $report['item_type']);
        $this->assertSame('none', $report['current_binding_level']);
        $this->assertArrayNotHasKey('warning', $report);
    }

    public function test_inspect_returns_null_for_missing_element(): void
    {
        $this->assertNull(
            $this->inspector()->inspect('tpl', '/templates/tpl/layout/children/99'),
        );
    }
}
