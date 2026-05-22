<?php
/**
 * LayoutReader — read-only window into wp_option('yootheme').
 *
 * Wave 2 Task 2.1. Uses the in-process wp_options stub from
 * tests/php/bootstrap.php so we never need to spin up WP-Testbench just to
 * verify what is a pure read-wrapper.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\State\LayoutReader;

#[CoversClass(LayoutReader::class)]
#[CoversClass(JsonPointer::class)]
final class LayoutReaderTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [];
    }

    /**
     * Seed the wp_option('yootheme') stub with a small but representative
     * shape — modelled on the dev5 spike-2 dump but trimmed to two templates.
     *
     * @return array<string, mixed>
     */
    private function seedState(): array
    {
        $state = [
            'library' => [
                'something' => ['hello' => 'world'],
            ],
            'templates' => [
                'bFIb-syj' => [
                    'name' => 'Home',
                    'layout' => [
                        'type' => 'layout',
                        'children' => [
                            ['type' => 'section', 'props' => [], 'children' => [
                                ['type' => 'row', 'props' => [], 'children' => [
                                    ['type' => 'column', 'props' => [], 'children' => [
                                        ['type' => 'headline', 'props' => ['content' => 'Hello'], 'children' => []],
                                    ]],
                                ]],
                            ]],
                        ],
                    ],
                ],
                'Fp2ntvJd' => [
                    'name' => 'About',
                    'layout' => [
                        'type' => 'layout',
                        'children' => [],
                    ],
                ],
            ],
        ];
        $GLOBALS['ytb_test_options']['yootheme'] = $state;
        return $state;
    }

    public function test_read_returns_full_state(): void
    {
        $expected = $this->seedState();
        $reader = new LayoutReader();
        self::assertSame($expected, $reader->read());
    }

    public function test_read_returns_empty_array_when_option_missing(): void
    {
        $reader = new LayoutReader();
        self::assertSame([], $reader->read());
    }

    public function test_read_returns_empty_array_when_option_not_an_array(): void
    {
        // Defensive: if some other plugin clobbered wp_option('yootheme') with
        // a string we must not blow up.
        $GLOBALS['ytb_test_options']['yootheme'] = 'corrupt';
        $reader = new LayoutReader();
        self::assertSame([], $reader->read());
    }

    public function test_read_template_returns_single_tree(): void
    {
        $this->seedState();
        $reader = new LayoutReader();
        $tpl = $reader->readTemplate('bFIb-syj');
        self::assertNotNull($tpl);
        self::assertSame('Home', $tpl['name']);
        self::assertSame('layout', $tpl['layout']['type']);
    }

    public function test_read_template_returns_null_when_id_unknown(): void
    {
        $this->seedState();
        $reader = new LayoutReader();
        self::assertNull($reader->readTemplate('does-not-exist'));
    }

    public function test_read_template_returns_null_when_state_lacks_templates_key(): void
    {
        $GLOBALS['ytb_test_options']['yootheme'] = ['library' => []];
        $reader = new LayoutReader();
        self::assertNull($reader->readTemplate('bFIb-syj'));
    }

    public function test_list_template_ids_returns_top_level_keys(): void
    {
        $this->seedState();
        $reader = new LayoutReader();
        $ids = $reader->listTemplateIds();
        self::assertCount(2, $ids);
        self::assertContains('bFIb-syj', $ids);
        self::assertContains('Fp2ntvJd', $ids);
    }

    public function test_list_template_ids_returns_empty_when_no_state(): void
    {
        $reader = new LayoutReader();
        self::assertSame([], $reader->listTemplateIds());
    }

    public function test_etag_is_stable_hash_of_state(): void
    {
        $this->seedState();
        $reader = new LayoutReader();
        $etag1 = $reader->etag();
        $etag2 = $reader->etag();
        self::assertSame($etag1, $etag2, 'Same state + revision must produce same ETag.');
        // F-07 (Maria-Audit 2026-05-22): ETag is `<sha256>-r<revision>`.
        // sha256 is 64 hex chars, revision is `>=0`, separated by `-r`.
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}-r\d+$/', $etag1);
    }

    public function test_etag_changes_when_state_changes(): void
    {
        $this->seedState();
        $reader = new LayoutReader();
        $before = $reader->etag();

        $GLOBALS['ytb_test_options']['yootheme']['templates']['bFIb-syj']['name'] = 'Renamed';
        $after = $reader->etag();

        self::assertNotSame($before, $after);
    }

    public function test_etag_for_empty_state_is_deterministic(): void
    {
        $reader = new LayoutReader();
        $a = $reader->etag();
        $b = $reader->etag();
        self::assertSame($a, $b);
    }

    public function test_etag_carries_monotonic_revision_suffix(): void
    {
        // F-07 fix: ETag must encode the StateRevision counter so that
        // ABA mutation sequences (A→B→A) yield three distinct ETags. The
        // monotonic suffix is the structural guarantee — the content hash
        // alone cannot achieve this property by construction.
        $this->seedState();
        $reader = new LayoutReader();
        $etag = $reader->etag();
        self::assertMatchesRegularExpression('/-r\d+$/', $etag);
    }

    public function test_read_by_pointer_walks_into_template_tree(): void
    {
        $this->seedState();
        $reader = new LayoutReader();
        $headline = $reader->readByPointer(
            '/templates/bFIb-syj/layout/children/0/children/0/children/0/children/0',
        );
        self::assertIsArray($headline);
        self::assertSame('headline', $headline['type']);
        self::assertSame('Hello', $headline['props']['content']);
    }

    public function test_read_by_pointer_returns_null_on_missing_path(): void
    {
        $this->seedState();
        $reader = new LayoutReader();
        self::assertNull($reader->readByPointer('/templates/bFIb-syj/layout/children/99'));
    }

    public function test_read_by_pointer_root_returns_whole_state(): void
    {
        $expected = $this->seedState();
        $reader = new LayoutReader();
        self::assertSame($expected, $reader->readByPointer(''));
    }

    public function test_read_by_pointer_with_escaped_segment(): void
    {
        // Spike-2 reference: template-IDs can contain dashes. Verify the
        // pointer-resolver handles names that would need ~1 escaping if they
        // contained slashes (we just check normal-name case here).
        $this->seedState();
        $reader = new LayoutReader();
        self::assertNotNull($reader->readByPointer('/templates/bFIb-syj'));
    }
}
