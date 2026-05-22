<?php
/**
 * YoothemeAdapter — single choke-point for YT symbol access.
 *
 * Wave 6 Round-2 R2.7. The adapter is the boundary between every
 * yt-builder-mcp module and the YOOtheme Pro runtime. Unit tests
 * exercise the no-YT path (every method returns a safe fallback) because
 * the test bootstrap never loads YOOtheme — by design, so that the
 * adapter's null-safe contract gets pinned hardest where it matters.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Yootheme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Yootheme\YoothemeAdapter;

#[CoversClass(YoothemeAdapter::class)]
final class YoothemeAdapterTest extends TestCase
{
    public function test_is_loaded_is_false_in_test_environment(): void
    {
        // tests/php/bootstrap.php does NOT load YOOtheme — adapter must
        // report not-loaded so callers fall through to safe defaults.
        $adapter = new YoothemeAdapter();
        self::assertFalse($adapter->isLoaded());
    }

    public function test_get_version_returns_null_when_yt_missing(): void
    {
        $adapter = new YoothemeAdapter();
        self::assertNull($adapter->getVersion());
    }

    public function test_get_builder_returns_null_when_yt_missing(): void
    {
        $adapter = new YoothemeAdapter();
        self::assertNull($adapter->getBuilder());
    }

    public function test_get_source_fields_returns_null_when_yt_missing(): void
    {
        $adapter = new YoothemeAdapter();
        self::assertNull($adapter->getSourceFields());
    }

    public function test_get_builder_types_returns_null_when_yt_missing(): void
    {
        $adapter = new YoothemeAdapter();
        self::assertNull($adapter->getBuilderTypes());
    }

    public function test_load_with_context_returns_null_when_yt_missing(): void
    {
        $adapter = new YoothemeAdapter();
        self::assertNull($adapter->loadWithContext(['foo' => 'bar'], 'save'));
    }

    public function test_get_cache_returns_null_when_yt_missing(): void
    {
        $adapter = new YoothemeAdapter();
        self::assertNull($adapter->getCache());
    }

    public function test_adapter_is_idempotent_per_call(): void
    {
        // No internal state — calling isLoaded() twice must produce the
        // same result without surprises (e.g. cached class_exists fail).
        $adapter = new YoothemeAdapter();
        self::assertSame($adapter->isLoaded(), $adapter->isLoaded());
    }
}
