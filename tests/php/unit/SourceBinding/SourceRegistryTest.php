<?php
/**
 * SourceRegistry — group-aware list of YOOtheme Builder sources.
 *
 * Wave 6 audit F-04 fix: the registry classifies sources by YT's own
 * `metadata.group` (canonical), falling back to a name-prefix heuristic
 * only for un-annotated legacy plugins. Each entry exposes
 * `{name, label, group, type}` — enriched from the raw GraphQL field-
 * definition introspection.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\SourceBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\SourceBinding\SourceRegistry;

#[CoversClass(SourceRegistry::class)]
final class SourceRegistryTest extends TestCase
{
    public function test_list_all_returns_three_group_keys(): void
    {
        $registry = new SourceRegistry();
        $list = $registry->listAll();
        self::assertArrayHasKey('apimapper', $list);
        self::assertArrayHasKey('wordpress', $list);
        self::assertArrayHasKey('essentials', $list);
    }

    public function test_list_all_returns_empty_groups_without_yt(): void
    {
        $registry = new SourceRegistry();
        $list = $registry->listAll();
        self::assertSame([], $list['apimapper']);
        self::assertSame([], $list['wordpress']);
        self::assertSame([], $list['essentials']);
    }

    public function test_list_all_each_group_value_is_array(): void
    {
        $registry = new SourceRegistry();
        $list = $registry->listAll();
        foreach ($list as $group => $sources) {
            self::assertIsArray($sources, sprintf('group %s must be an array', $group));
        }
    }

    public function test_classifies_by_yt_metadata_group_when_present(): void
    {
        $registry = $this->makeAdapter([
            ['name' => 'posts.singlePost', 'label' => 'Post', 'group' => 'WordPress', 'type' => 'Post'],
            ['name' => 'apimapper_flow_pexels', 'label' => 'Pexels', 'group' => 'WootsUp - API Mapper', 'type' => 'PexelsResult'],
            ['name' => 'essentials_carousel', 'label' => 'Carousel', 'group' => 'Essentials', 'type' => 'Carousel'],
        ]);
        $list = $registry->listAll();

        self::assertCount(1, $list['wordpress']);
        self::assertSame('posts.singlePost', $list['wordpress'][0]['name']);
        self::assertSame('Post', $list['wordpress'][0]['label']);
        self::assertSame('WordPress', $list['wordpress'][0]['group']);
        self::assertSame('Post', $list['wordpress'][0]['type']);

        self::assertCount(1, $list['apimapper']);
        self::assertSame('apimapper_flow_pexels', $list['apimapper'][0]['name']);

        self::assertCount(1, $list['essentials']);
        self::assertSame('essentials_carousel', $list['essentials'][0]['name']);
    }

    public function test_falls_back_to_name_prefix_when_group_metadata_missing(): void
    {
        $registry = $this->makeAdapter([
            ['name' => 'apimapperFlowXYZ', 'label' => 'Flow XYZ', 'group' => '', 'type' => ''],
            ['name' => 'essentials_thing', 'label' => 'Thing', 'group' => '', 'type' => ''],
            ['name' => 'random_field', 'label' => 'Random', 'group' => '', 'type' => ''],
        ]);
        $list = $registry->listAll();

        self::assertCount(1, $list['apimapper']);
        self::assertSame('apimapperFlowXYZ', $list['apimapper'][0]['name']);
        self::assertCount(1, $list['essentials']);
        self::assertCount(1, $list['wordpress']);
        self::assertSame('random_field', $list['wordpress'][0]['name']);
    }

    public function test_case_insensitive_group_matching(): void
    {
        $registry = $this->makeAdapter([
            ['name' => 'a', 'label' => 'A', 'group' => 'WOOTSUP - API MAPPER', 'type' => ''],
            ['name' => 'b', 'label' => 'B', 'group' => 'YOOessentials', 'type' => ''],
            ['name' => 'c', 'label' => 'C', 'group' => 'UIkit', 'type' => ''],
        ]);
        $list = $registry->listAll();
        self::assertCount(1, $list['apimapper']);
        self::assertCount(2, $list['essentials']);
    }

    /**
     * Build a SourceRegistry that pre-injects the entries via the
     * closure-based test seam — bypasses the YoothemeAdapter so we do not
     * need to extend a `final` class.
     *
     * @param list<array{name: string, label: string, group: string, type: string}> $entries
     */
    private function makeAdapter(array $entries): SourceRegistry
    {
        return new SourceRegistry(null, static fn (): array => $entries);
    }
}
