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

    // ------------------------------------------------------------------
    // T6 / R-01 — belt-and-braces: cache-delete before verify-read; handle
    // update_option no-op when verify byte-equals state; first-write uses
    // add_option with explicit autoload=false.
    // ------------------------------------------------------------------

    public function test_persist_invalidates_object_cache_before_verify_read(): void
    {
        // T6 R-01: writes must invalidate the wp_object_cache entries for
        // the option BEFORE the verify-read. Otherwise an object-cache
        // backend (Redis/Memcached/W3TC) returns stale `get_option` and
        // the write throws a spurious 500.
        $GLOBALS['ytb_test_cache_delete_calls'] = [];
        $writer = new LayoutWriter(new LayoutReader());
        $writer->writeTemplate('tpl', [
            'name' => 'After',
            'layout' => ['type' => 'layout', 'children' => []],
        ]);
        $keys = array_map(
            static fn (array $c): string => ($c['group'] ?? '') . ':' . $c['key'],
            $GLOBALS['ytb_test_cache_delete_calls'],
        );
        // Both the per-option entry AND alloptions must be invalidated —
        // alloptions is what holds autoloaded options in a single cache key.
        self::assertContains('options:yootheme', $keys, 'expected wp_cache_delete(yootheme, options)');
        self::assertContains('options:alloptions', $keys, 'expected wp_cache_delete(alloptions, options)');
    }

    public function test_persist_treats_update_option_noop_as_success(): void
    {
        // T6 R-01: when the state happens to byte-equal a previous state
        // (no-op write), update_option returns false on real WP. The
        // verify-read must succeed because the option already holds the
        // expected value — we must NOT throw.
        $writer = new LayoutWriter(new LayoutReader());
        // Stamp the store with an identity state first.
        $tree = ['name' => 'A', 'layout' => ['type' => 'layout', 'children' => []]];
        $writer->writeTemplate('tpl', $tree);

        // Simulate update_option returning false on the NEXT call (no-op).
        $GLOBALS['ytb_test_update_option_force_return'] = false;
        // The state-write itself is identity (we feed the same tree); the
        // verify-read will see the persisted value. persist() should not throw.
        $writer->writeTemplate('tpl', $tree);
        unset($GLOBALS['ytb_test_update_option_force_return']);

        // Survives — and the reader sees the expected value.
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertSame('A', $stored['name']);
    }

    public function test_persist_uses_add_option_with_autoload_false_on_first_write(): void
    {
        // T6 R-01: when wp_option('yootheme') does NOT yet exist, persist
        // must use add_option(..., autoload=false). Subsequent writes via
        // update_option(...,null) preserve the autoload=false setting.
        unset($GLOBALS['ytb_test_options']['yootheme']);
        $GLOBALS['ytb_test_add_option_calls'] = [];

        $writer = new LayoutWriter(new LayoutReader());
        $writer->writeTemplate('fresh', [
            'name' => 'First',
            'layout' => ['type' => 'layout', 'children' => []],
        ]);

        $matching = array_filter(
            $GLOBALS['ytb_test_add_option_calls'],
            static fn (array $c): bool => ($c['option'] ?? '') === 'yootheme',
        );
        self::assertNotEmpty($matching, 'expected add_option(yootheme, ...) on first write');
        $first = array_values($matching)[0];
        self::assertFalse($first['autoload'], 'expected autoload=false on first add_option');
    }

    public function test_write_template_stamps_pages_meta_store(): void
    {
        // F-08 fix (Maria-Audit 2026-05-22): writeTemplate must touch the
        // per-template tracking option so pages_list.modified_at is
        // non-null on cold start even when the YT-blob has no `modified`.
        $writer = new LayoutWriter(new LayoutReader());
        $writer->writeTemplate('tpl', [
            'name' => 'After',
            'layout' => ['type' => 'layout', 'children' => []],
        ]);
        $store = new \WootsUp\BuilderMcp\Pages\PagesMetaStore();
        $ts = $store->modifiedAt('tpl');
        self::assertIsString($ts);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/',
            (string) $ts,
        );
    }
}
