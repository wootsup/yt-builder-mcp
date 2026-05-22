<?php
/**
 * LayoutWriter — canonical write path into wp_option('yootheme').
 *
 * Wave 3 Task 3.1. Uses the in-process wp_options stub from
 * tests/php/bootstrap.php. YOOtheme classes are not loaded here, so
 * runSaveTransforms() falls back to no-op identity — that fallback path
 * is itself part of the documented contract.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;

#[CoversClass(LayoutWriter::class)]
#[CoversClass(LayoutReader::class)]
#[CoversClass(JsonPointer::class)]
final class LayoutWriterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [
            'yootheme' => [
                'library' => [],
                'templates' => [
                    'tpl' => [
                        'name' => 'Home',
                        'layout' => [
                            'type' => 'layout',
                            'children' => [
                                ['type' => 'section', 'props' => ['style' => 'a']],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_write_template_replaces_template_tree(): void
    {
        $writer = new LayoutWriter(new LayoutReader());
        $newTree = [
            'name' => 'Renamed',
            'layout' => ['type' => 'layout', 'children' => []],
        ];
        $writer->writeTemplate('tpl', $newTree);

        $reader = new LayoutReader();
        $tpl = $reader->readTemplate('tpl');
        self::assertNotNull($tpl);
        self::assertSame('Renamed', $tpl['name']);
        self::assertSame([], $tpl['layout']['children']);
    }

    public function test_write_template_creates_template_when_missing(): void
    {
        $writer = new LayoutWriter(new LayoutReader());
        $writer->writeTemplate('brand-new', [
            'name' => 'Created',
            'layout' => ['type' => 'layout', 'children' => []],
        ]);
        $reader = new LayoutReader();
        self::assertContains('brand-new', $reader->listTemplateIds());
    }

    public function test_write_template_creates_templates_key_if_state_missing(): void
    {
        $GLOBALS['ytb_test_options']['yootheme'] = ['library' => []];
        $writer = new LayoutWriter(new LayoutReader());
        $writer->writeTemplate('fresh', [
            'name' => 'Fresh',
            'layout' => ['type' => 'layout'],
        ]);
        $reader = new LayoutReader();
        self::assertSame(['fresh'], $reader->listTemplateIds());
    }

    public function test_write_by_pointer_sets_nested_value(): void
    {
        $writer = new LayoutWriter(new LayoutReader());
        $writer->writeByPointer('/templates/tpl/name', 'Pointed');
        $reader = new LayoutReader();
        $tpl = $reader->readTemplate('tpl');
        self::assertNotNull($tpl);
        self::assertSame('Pointed', $tpl['name']);
    }

    public function test_write_by_pointer_appends_to_list(): void
    {
        $writer = new LayoutWriter(new LayoutReader());
        $writer->writeByPointer('/templates/tpl/layout/children/-', [
            'type' => 'headline',
            'props' => ['content' => 'New'],
        ]);
        $reader = new LayoutReader();
        $tpl = $reader->readTemplate('tpl');
        self::assertNotNull($tpl);
        self::assertCount(2, $tpl['layout']['children']);
        self::assertSame('headline', $tpl['layout']['children'][1]['type']);
    }

    public function test_delete_removes_pointer_target(): void
    {
        $writer = new LayoutWriter(new LayoutReader());
        $writer->delete('/templates/tpl/layout/children/0');
        $reader = new LayoutReader();
        $tpl = $reader->readTemplate('tpl');
        self::assertNotNull($tpl);
        self::assertSame([], $tpl['layout']['children']);
    }

    public function test_delete_is_noop_for_missing_pointer(): void
    {
        $writer = new LayoutWriter(new LayoutReader());
        // Snapshot before
        $before = (new LayoutReader())->read();
        $writer->delete('/templates/tpl/layout/children/999');
        // Nothing should have changed (and no exception thrown).
        $after = (new LayoutReader())->read();
        self::assertSame($before, $after);
    }

    public function test_run_save_transforms_is_identity_without_yt(): void
    {
        $writer = new LayoutWriter(new LayoutReader());
        $tree = ['name' => 'X', 'layout' => ['type' => 'layout', 'children' => []]];
        self::assertSame($tree, $writer->runSaveTransforms($tree));
    }

    public function test_etag_changes_after_write_template(): void
    {
        $reader = new LayoutReader();
        $writer = new LayoutWriter($reader);
        $before = $reader->etag();
        $writer->writeTemplate('tpl', [
            'name' => 'After',
            'layout' => ['type' => 'layout', 'children' => []],
        ]);
        $after = (new LayoutReader())->etag();
        self::assertNotSame($before, $after);
    }
}
