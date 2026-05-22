<?php
/**
 * PagesMetaStore — per-template `modified_at` tracking option.
 *
 * F-08 fix coverage. The store must:
 *  - return null for never-touched templates,
 *  - stamp an ISO-8601 timestamp on touch(),
 *  - drop entries via forget(),
 *  - survive corruption of the wp_option blob.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Pages;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Pages\PagesMetaStore;

#[CoversClass(PagesMetaStore::class)]
final class PagesMetaStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [];
    }

    public function test_all_returns_empty_when_option_missing(): void
    {
        self::assertSame([], (new PagesMetaStore())->all());
    }

    public function test_modified_at_returns_null_when_template_never_touched(): void
    {
        self::assertNull((new PagesMetaStore())->modifiedAt('tpl'));
    }

    public function test_touch_stamps_iso_timestamp(): void
    {
        $store = new PagesMetaStore();
        $store->touch('tpl');
        $ts = $store->modifiedAt('tpl');
        self::assertIsString($ts);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/',
            (string) $ts,
        );
    }

    public function test_touch_with_empty_template_id_is_noop(): void
    {
        // Root/library writes don't correspond to a single template — the
        // store must not stamp a blank key (would pollute the map).
        $store = new PagesMetaStore();
        $store->touch('');
        self::assertSame([], $store->all());
    }

    public function test_forget_drops_entry(): void
    {
        $store = new PagesMetaStore();
        $store->touch('tpl');
        $store->forget('tpl');
        self::assertNull($store->modifiedAt('tpl'));
    }

    public function test_all_skips_corrupt_entries(): void
    {
        $GLOBALS['ytb_test_options'][PagesMetaStore::OPTION] = [
            'good' => ['modified_at' => '2026-05-22T10:00:00+00:00'],
            'bad-shape' => 'not-an-array',
            'missing-key' => ['something_else' => 'x'],
            'empty-string' => ['modified_at' => ''],
        ];
        $all = (new PagesMetaStore())->all();
        self::assertArrayHasKey('good', $all);
        self::assertCount(1, $all);
    }

    public function test_option_uses_ytb_mcp_prefix(): void
    {
        // Thomas-rule: WP-options prefix `ytb_mcp_*` unchanged (no DB migration).
        self::assertStringStartsWith('ytb_mcp_', PagesMetaStore::OPTION);
    }
}
