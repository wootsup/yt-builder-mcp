<?php
/**
 * ImplodeDirectiveCleaner — strips `implode` directives from
 * `props.source.props.*` of an addressed element node.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\SourceBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\SourceBinding\ImplodeDirectiveCleaner;

#[CoversClass(ImplodeDirectiveCleaner::class)]
final class ImplodeDirectiveCleanerTest extends TestCase
{
    public function test_clean_removes_implode_directives_and_reports_count(): void
    {
        $node = [
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
                        'subtitle' => [
                            'name' => 'subtitle',
                            'filters' => [],
                            'implode' => ['join' => "\n", 'glue' => ''],
                        ],
                        'image' => [
                            'name' => 'image',
                            'filters' => [],
                        ],
                    ],
                ],
            ],
        ];

        $result = ImplodeDirectiveCleaner::clean($node);

        $this->assertSame(2, $result['cleaned_count']);
        $this->assertCount(2, $result['removed_directives']);
        $this->assertSame(['title', 'subtitle'], array_column($result['removed_directives'], 'prop_name'));

        $cleanedNode = $result['node'];
        $this->assertArrayNotHasKey('implode', $cleanedNode['props']['source']['props']['title']);
        $this->assertArrayNotHasKey('implode', $cleanedNode['props']['source']['props']['subtitle']);
        // Untouched
        $this->assertSame('image', $cleanedNode['props']['source']['props']['image']['name']);
        $this->assertSame('wp_posts', $cleanedNode['props']['source']['query']['name']);
    }

    public function test_clean_with_no_directives_is_idempotent(): void
    {
        $node = [
            'type' => 'grid_item',
            'props' => [
                'source' => [
                    'query' => ['name' => 'wp_posts'],
                    'props' => [
                        'title' => [
                            'name' => 'title',
                            'filters' => [],
                        ],
                    ],
                ],
            ],
        ];

        $result = ImplodeDirectiveCleaner::clean($node);

        $this->assertSame(0, $result['cleaned_count']);
        $this->assertSame([], $result['removed_directives']);
        $this->assertSame($node, $result['node']);
    }

    public function test_clean_handles_node_without_source(): void
    {
        $node = ['type' => 'section', 'props' => []];
        $result = ImplodeDirectiveCleaner::clean($node);
        $this->assertSame(0, $result['cleaned_count']);
        $this->assertSame($node, $result['node']);
    }

    public function test_clean_ignores_empty_implode_directive(): void
    {
        $node = [
            'type' => 'slideshow',
            'props' => [
                'source' => [
                    'query' => ['name' => 'wp_posts'],
                    'props' => [
                        'title' => [
                            'name' => 'title',
                            'implode' => [],
                        ],
                    ],
                ],
            ],
        ];

        $result = ImplodeDirectiveCleaner::clean($node);
        // Empty implode is still a directive structure we strip — caller
        // expects 'implode' key absence after clean. cleaned_count
        // reflects whether we touched anything.
        $this->assertSame(1, $result['cleaned_count']);
        $this->assertArrayNotHasKey('implode', $result['node']['props']['source']['props']['title']);
    }
}
