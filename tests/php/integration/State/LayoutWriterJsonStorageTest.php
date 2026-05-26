<?php
/**
 * LayoutWriter — `yootheme` option is persisted as a JSON STRING.
 *
 * Regression guard for the 2026-05-25 incident: a YT-Builder-MCP write
 * (Claude-Desktop audit: page_save / element clone) stored the `yootheme`
 * wp_option as a PHP-serialized ARRAY instead of a JSON string. YOOtheme's
 * front-end `Storage::addJson()` runs `json_decode()` on read and fataled
 * with "json_decode(): Argument #1 ($json) must be of type string, array
 * given" → HTTP 500 on EVERY front-end page (dev.wootsup.com down ~22:46).
 *
 * Root cause: LayoutWriter::persist() passed a raw PHP array to
 * update_option and relied on an EXTERNAL `pre_update_option_yootheme`
 * mu-plugin to json-encode it. On hosts without that filter the array was
 * serialized → corruption. The fix encodes the canonical JSON string in
 * persist() itself and pins it via a highest-priority one-shot filter so
 * the stored bytes are deterministic regardless of which other pre_update
 * filters are (or are not) registered.
 *
 * These tests model the WP filter contract faithfully (the bootstrap
 * `update_option` stub applies `pre_update_option_{$option}` filters).
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Integration\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;

#[CoversClass(LayoutWriter::class)]
final class LayoutWriterJsonStorageTest extends TestCase
{
    protected function setUp(): void
    {
        // Seed as a PHP array (LayoutReader tolerates both shapes on read).
        $GLOBALS['ytb_test_options'] = [
            'yootheme' => [
                'templates' => [
                    'tpl-A' => [
                        'name' => 'A',
                        'layout' => ['type' => 'layout', 'children' => []],
                    ],
                ],
            ],
        ];
        // Start every test with a clean filter registry.
        $GLOBALS['ytb_test_filters'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['ytb_test_filters'] = [];
    }

    /**
     * THE incident guard: with NO pre_update_option_yootheme filter
     * registered (the dev.wootsup.com condition), a write MUST leave the
     * option as a JSON STRING that json_decode() accepts — never a PHP
     * array. A regression here reproduces the front-end 500.
     */
    public function test_persist_stores_yootheme_option_as_json_string_not_php_array(): void
    {
        $writer = new LayoutWriter(new LayoutReader());
        $writer->writeByPointer('/templates/tpl-A/name', 'A-renamed');

        $stored = $GLOBALS['ytb_test_options']['yootheme'];
        self::assertIsString(
            $stored,
            'yootheme option MUST be a JSON string; a PHP array fatals YOOtheme front-end json_decode()',
        );
        self::assertIsNotArray($stored);

        // YOOtheme's read path is literally json_decode($stored): it must
        // succeed and yield the written structure.
        $decoded = json_decode($stored, true);
        self::assertIsArray($decoded);
        self::assertSame('A-renamed', $decoded['templates']['tpl-A']['name']);
    }

    /**
     * Pin defeats double-encoding: even when an AGGRESSIVE competing
     * `pre_update_option_yootheme` filter is registered (one that
     * unconditionally json-encodes its input — the worst-case mu-plugin /
     * YOOtheme combination), the stored value must be SINGLE-encoded JSON.
     * If the pin failed, the value would be double-encoded and
     * `json_decode($stored, true)` would return a STRING, not an array.
     */
    public function test_persist_single_encodes_even_with_competing_pre_update_filter(): void
    {
        // Simulate a naive encoder that always json-encodes (would double-
        // encode the string LayoutWriter passes if the pin did not win).
        add_filter(
            'pre_update_option_yootheme',
            static fn (mixed $v): string => (string) wp_json_encode($v),
            10,
        );

        $writer = new LayoutWriter(new LayoutReader());
        $writer->writeByPointer('/templates/tpl-A/name', 'A-pinned');

        $stored = $GLOBALS['ytb_test_options']['yootheme'];
        self::assertIsString($stored);

        $decoded = json_decode($stored, true);
        self::assertIsArray(
            $decoded,
            'stored value is double-encoded — the pin filter did not override the competing encoder',
        );
        self::assertSame('A-pinned', $decoded['templates']['tpl-A']['name']);
    }

    /**
     * The one-shot pin filter must be removed after the write so it cannot
     * leak into unrelated `update_option('yootheme', ...)` calls later in
     * the request.
     */
    public function test_persist_removes_its_pin_filter_after_write(): void
    {
        $writer = new LayoutWriter(new LayoutReader());
        $writer->writeByPointer('/templates/tpl-A/name', 'A-clean');

        $registered = $GLOBALS['ytb_test_filters']['pre_update_option_yootheme'] ?? [];
        self::assertSame(
            [],
            $registered,
            'LayoutWriter::persist must remove its pinned pre_update_option_yootheme filter in its finally block',
        );
    }
}
