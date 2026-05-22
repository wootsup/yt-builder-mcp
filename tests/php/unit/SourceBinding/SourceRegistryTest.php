<?php
/**
 * SourceRegistry — group-aware list of YOOtheme Builder sources.
 *
 * Wave 2 Task 2.5. When YOOtheme is not loaded (unit-test bootstrap),
 * the registry yields the empty `{apimapper: [], wordpress: [], essentials: []}`
 * scaffold so MCP-clients always see the expected group keys.
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
}
