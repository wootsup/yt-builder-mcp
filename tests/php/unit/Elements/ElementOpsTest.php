<?php
/**
 * ElementOps — read-only element operations.
 *
 * Wave 2 Task 2.3. listOnTemplate / get / getSettings — all pure read paths.
 * Write paths (add/move/delete/setSettings) are Wave 3.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Elements;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\Elements\TreeWalker;
use WootsUp\BuilderMcp\State\LayoutReader;

#[CoversClass(ElementOps::class)]
#[CoversClass(TreeWalker::class)]
final class ElementOpsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [
            'yootheme' => [
                'templates' => [
                    'tpl' => [
                        'layout' => [
                            'type' => 'layout',
                            'children' => [
                                [
                                    'type' => 'section',
                                    'props' => ['style' => 'default'],
                                    'children' => [
                                        ['type' => 'headline', 'props' => ['content' => 'Hello']],
                                    ],
                                ],
                                ['type' => 'image', 'props' => ['source' => 'cat.jpg']],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_list_on_template_emits_one_entry_per_element(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $list = $ops->listOnTemplate('tpl');
        self::assertNotNull($list);
        self::assertCount(3, $list); // section + headline + image
    }

    public function test_list_on_template_each_entry_has_path_type_props_summary(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $list = $ops->listOnTemplate('tpl');
        self::assertNotNull($list);
        foreach ($list as $entry) {
            self::assertArrayHasKey('path', $entry);
            self::assertArrayHasKey('type', $entry);
            self::assertArrayHasKey('props_summary', $entry);
        }
    }

    public function test_list_on_template_props_summary_is_keys_only(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $list = $ops->listOnTemplate('tpl');
        self::assertNotNull($list);
        $byType = [];
        foreach ($list as $entry) {
            $byType[$entry['type']] = $entry;
        }
        self::assertSame(['style'], $byType['section']['props_summary']);
        self::assertSame(['content'], $byType['headline']['props_summary']);
        self::assertSame(['source'], $byType['image']['props_summary']);
    }

    // -------------------------------------------------------------
    // F-01 — canonical normalized row shape
    // (element_type / has_binding / child_count).
    // -------------------------------------------------------------

    public function test_list_on_template_exposes_canonical_element_type(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $list = $ops->listOnTemplate('tpl');
        self::assertNotNull($list);
        // Every entry must carry `element_type` (canonical) AND `type`
        // (legacy alias) — they must agree.
        foreach ($list as $entry) {
            self::assertArrayHasKey('element_type', $entry);
            self::assertArrayHasKey('type', $entry);
            self::assertSame($entry['type'], $entry['element_type']);
        }
    }

    public function test_list_on_template_exposes_child_count(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $list = $ops->listOnTemplate('tpl');
        self::assertNotNull($list);
        $byType = [];
        foreach ($list as $entry) {
            $byType[$entry['type']] = $entry;
        }
        // section has 1 direct child (the headline).
        self::assertSame(1, $byType['section']['child_count']);
        // headline + image are leaves.
        self::assertSame(0, $byType['headline']['child_count']);
        self::assertSame(0, $byType['image']['child_count']);
    }

    public function test_list_on_template_exposes_has_binding(): void
    {
        $GLOBALS['ytb_test_options']['yootheme'] = [
            'templates' => [
                'tpl' => [
                    'layout' => [
                        'type' => 'layout',
                        'children' => [
                            ['type' => 'headline', 'props' => ['source' => 'posts.posts', 'content' => 'X']],
                            ['type' => 'image', 'props' => ['source' => ['query' => ['name' => 'posts.singlePost']]]],
                            ['type' => 'text', 'props' => ['content' => 'plain']],
                            ['type' => 'divider'],
                        ],
                    ],
                ],
            ],
        ];
        $ops = new ElementOps(new LayoutReader());
        $list = $ops->listOnTemplate('tpl');
        self::assertNotNull($list);
        $byType = [];
        foreach ($list as $entry) {
            $byType[$entry['type']] = $entry;
        }
        self::assertTrue($byType['headline']['has_binding']); // legacy string shape
        self::assertTrue($byType['image']['has_binding']);    // F-13 structured shape
        self::assertFalse($byType['text']['has_binding']);    // unbound
        self::assertFalse($byType['divider']['has_binding']); // no props
    }

    public function test_flatten_node_emits_canonical_shape(): void
    {
        $node = [
            'type' => 'headline',
            'name' => 'Hero Headline',
            'props' => ['content' => 'Hi', 'source' => 'posts.posts'],
            'children' => [
                ['type' => 'span'],
            ],
        ];
        $view = ElementOps::flattenNode($node, '/x/0');
        self::assertSame('/x/0', $view['path']);
        self::assertSame('headline', $view['element_type']);
        self::assertSame('headline', $view['type']);
        self::assertSame('Hero Headline', $view['label']);
        self::assertSame(['content' => 'Hi', 'source' => 'posts.posts'], $view['props']);
        self::assertCount(1, $view['children']);
        self::assertTrue($view['has_binding']);
        self::assertSame(1, $view['child_count']);
    }

    public function test_flatten_node_handles_unknown_type(): void
    {
        $view = ElementOps::flattenNode(['props' => []], '/x');
        self::assertSame('unknown', $view['element_type']);
        self::assertSame('unknown', $view['type']);
        self::assertSame([], $view['props']);
        self::assertSame([], $view['children']);
        self::assertSame(0, $view['child_count']);
        self::assertFalse($view['has_binding']);
        self::assertArrayNotHasKey('label', $view);
    }

    public function test_flatten_node_skips_non_array_children(): void
    {
        $node = [
            'type' => 'row',
            'children' => [
                ['type' => 'column'],
                'broken-scalar',
                ['type' => 'column'],
                42,
            ],
        ];
        $view = ElementOps::flattenNode($node, '/x');
        self::assertSame(2, $view['child_count']);
        self::assertCount(2, $view['children']);
    }

    public function test_list_on_template_returns_null_for_unknown_template(): void
    {
        $ops = new ElementOps(new LayoutReader());
        self::assertNull($ops->listOnTemplate('nope'));
    }

    public function test_get_returns_node_at_pointer(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $headlinePtr = '/templates/tpl/layout/children/0/children/0';
        $node = $ops->get($headlinePtr);
        self::assertNotNull($node);
        self::assertSame('headline', $node['type']);
    }

    public function test_get_returns_null_for_unknown_pointer(): void
    {
        $ops = new ElementOps(new LayoutReader());
        self::assertNull($ops->get('/templates/tpl/layout/children/99'));
    }

    public function test_get_settings_returns_props(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $headlinePtr = '/templates/tpl/layout/children/0/children/0';
        $settings = $ops->getSettings($headlinePtr);
        self::assertSame(['content' => 'Hello'], $settings);
    }

    public function test_get_settings_returns_null_for_unknown_pointer(): void
    {
        $ops = new ElementOps(new LayoutReader());
        self::assertNull($ops->getSettings('/templates/tpl/layout/children/99'));
    }

    public function test_get_settings_returns_empty_when_node_has_no_props(): void
    {
        $GLOBALS['ytb_test_options']['yootheme'] = [
            'templates' => [
                'tpl' => [
                    'layout' => [
                        'type' => 'layout',
                        'children' => [
                            ['type' => 'divider'],
                        ],
                    ],
                ],
            ],
        ];
        $ops = new ElementOps(new LayoutReader());
        $settings = $ops->getSettings('/templates/tpl/layout/children/0');
        self::assertSame([], $settings);
    }

    // ---------------------------------------------------------------------
    // Wave 3 — write operations
    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function freshState(): array
    {
        return [
            'templates' => [
                'tpl' => [
                    'layout' => [
                        'type' => 'layout',
                        'children' => [
                            [
                                'type' => 'section',
                                'props' => ['style' => 'default'],
                                'children' => [
                                    ['type' => 'headline', 'props' => ['content' => 'Hello']],
                                ],
                            ],
                            ['type' => 'image', 'props' => ['source' => 'cat.jpg']],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_add_appends_to_template_root_when_parent_empty(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $state = $this->freshState();
        $newPath = $ops->add($state, 'tpl', '', 'divider', ['style' => 'thin']);
        self::assertSame('/templates/tpl/layout/children/2', $newPath);
        self::assertSame('divider', $state['templates']['tpl']['layout']['children'][2]['type']);
        self::assertSame('thin', $state['templates']['tpl']['layout']['children'][2]['props']['style']);
    }

    public function test_add_appends_to_explicit_parent(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $state = $this->freshState();
        $newPath = $ops->add(
            $state,
            'tpl',
            '/templates/tpl/layout/children/0', // the section
            'headline',
            ['content' => 'Second']
        );
        self::assertSame('/templates/tpl/layout/children/0/children/1', $newPath);
        self::assertSame('Second', $state['templates']['tpl']['layout']['children'][0]['children'][1]['props']['content']);
    }

    public function test_add_throws_when_parent_missing(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $state = $this->freshState();
        $this->expectException(\InvalidArgumentException::class);
        $ops->add($state, 'tpl', '/templates/tpl/layout/children/99', 'headline');
    }

    public function test_delete_removes_node(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $state = $this->freshState();
        $ops->delete($state, 'tpl', '/templates/tpl/layout/children/0');
        self::assertCount(1, $state['templates']['tpl']['layout']['children']);
        self::assertSame('image', $state['templates']['tpl']['layout']['children'][0]['type']);
    }

    public function test_delete_throws_for_missing_path(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $state = $this->freshState();
        $this->expectException(\InvalidArgumentException::class);
        $ops->delete($state, 'tpl', '/templates/tpl/layout/children/99');
    }

    public function test_move_within_same_parent_adjusts_index(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $state = $this->freshState();
        // Move children[0] (section) past children[1] (image) → final index 1.
        $newPath = $ops->move(
            $state,
            'tpl',
            '/templates/tpl/layout/children/0',
            '/templates/tpl/layout',
            2,
        );
        self::assertSame('/templates/tpl/layout/children/1', $newPath);
        self::assertSame('image', $state['templates']['tpl']['layout']['children'][0]['type']);
        self::assertSame('section', $state['templates']['tpl']['layout']['children'][1]['type']);
    }

    public function test_move_to_different_parent(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $state = $this->freshState();
        $newPath = $ops->move(
            $state,
            'tpl',
            '/templates/tpl/layout/children/1', // image
            '/templates/tpl/layout/children/0', // section
            0,
        );
        self::assertSame('/templates/tpl/layout/children/0/children/0', $newPath);
        self::assertSame('image', $state['templates']['tpl']['layout']['children'][0]['children'][0]['type']);
        // Image removed from top-level → only the section left at top.
        self::assertCount(1, $state['templates']['tpl']['layout']['children']);
    }

    public function test_move_throws_for_missing_source(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $state = $this->freshState();
        $this->expectException(\InvalidArgumentException::class);
        $ops->move($state, 'tpl', '/templates/tpl/layout/children/99', '/templates/tpl/layout', 0);
    }

    public function test_clone_inserts_after_source(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $state = $this->freshState();
        $newPath = $ops->clone($state, 'tpl', '/templates/tpl/layout/children/1');
        self::assertSame('/templates/tpl/layout/children/2', $newPath);
        self::assertCount(3, $state['templates']['tpl']['layout']['children']);
        self::assertSame('image', $state['templates']['tpl']['layout']['children'][2]['type']);
        self::assertSame('cat.jpg', $state['templates']['tpl']['layout']['children'][2]['props']['source']);
    }

    public function test_clone_throws_for_missing_source(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $state = $this->freshState();
        $this->expectException(\InvalidArgumentException::class);
        $ops->clone($state, 'tpl', '/templates/tpl/layout/children/99');
    }

    public function test_update_settings_replaces_props(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $state = $this->freshState();
        $ops->updateSettings(
            $state,
            'tpl',
            '/templates/tpl/layout/children/1',
            ['source' => 'dog.jpg', 'alt' => 'Dog'],
        );
        self::assertSame(
            ['source' => 'dog.jpg', 'alt' => 'Dog'],
            $state['templates']['tpl']['layout']['children'][1]['props'],
        );
    }

    public function test_update_settings_throws_when_target_missing(): void
    {
        $ops = new ElementOps(new LayoutReader());
        $state = $this->freshState();
        $this->expectException(\InvalidArgumentException::class);
        $ops->updateSettings($state, 'tpl', '/templates/tpl/layout/children/99', ['x' => 1]);
    }
}
