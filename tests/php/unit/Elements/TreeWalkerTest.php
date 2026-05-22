<?php
/**
 * TreeWalker — generator-based DFS over a layout tree.
 *
 * Wave 2 Task 2.3. Yields `[path, node]` tuples in pre-order. Pure
 * read-only — never mutates the input tree.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Elements;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Elements\TreeWalker;

#[CoversClass(TreeWalker::class)]
final class TreeWalkerTest extends TestCase
{
    public function test_walk_emits_top_level_children_in_order(): void
    {
        $tree = [
            'type' => 'layout',
            'children' => [
                ['type' => 'section', 'props' => []],
                ['type' => 'section', 'props' => []],
            ],
        ];

        $entries = iterator_to_array(TreeWalker::walk($tree, '/templates/tpl/layout'), false);
        self::assertCount(2, $entries);
        self::assertSame('/templates/tpl/layout/children/0', $entries[0][0]);
        self::assertSame('section', $entries[0][1]['type']);
        self::assertSame('/templates/tpl/layout/children/1', $entries[1][0]);
    }

    public function test_walk_is_depth_first_preorder(): void
    {
        $tree = [
            'type' => 'layout',
            'children' => [
                [
                    'type' => 'section',
                    'children' => [
                        ['type' => 'row', 'children' => [
                            ['type' => 'column'],
                        ]],
                    ],
                ],
                ['type' => 'section'],
            ],
        ];

        $entries = iterator_to_array(TreeWalker::walk($tree, ''), false);
        $types = array_map(static fn (array $tuple): string => $tuple[1]['type'], $entries);
        self::assertSame(['section', 'row', 'column', 'section'], $types);
    }

    public function test_walk_yields_no_entries_for_leaf(): void
    {
        $tree = ['type' => 'headline', 'props' => ['content' => 'Hi']];
        $entries = iterator_to_array(TreeWalker::walk($tree, '/'), false);
        self::assertSame([], $entries);
    }

    public function test_walk_skips_non_array_children(): void
    {
        $tree = [
            'type' => 'layout',
            'children' => [
                'broken-scalar',
                ['type' => 'section'],
            ],
        ];

        $entries = iterator_to_array(TreeWalker::walk($tree, ''), false);
        self::assertCount(1, $entries);
        self::assertSame('section', $entries[0][1]['type']);
    }

    public function test_walk_handles_missing_children_key(): void
    {
        $tree = ['type' => 'layout'];
        $entries = iterator_to_array(TreeWalker::walk($tree, ''), false);
        self::assertSame([], $entries);
    }
}
