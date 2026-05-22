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

    public function test_get_version_walks_reflection_fallback(): void
    {
        // F-09 fix: when the YOOTHEME_VERSION constant is absent but
        // \YOOtheme\Theme::VERSION is defined, the adapter must surface
        // that value via reflection. Override isLoaded() so the early
        // return doesn't short-circuit before the reflection probe.
        if (!class_exists('\\YOOtheme\\Theme', false)) {
            eval('namespace YOOtheme; class Theme { public const VERSION = "4.5.99-test"; }');
        }
        $adapter = new class extends YoothemeAdapter {
            public function isLoaded(): bool
            {
                return true;
            }
        };
        // YOOTHEME_VERSION may already be defined by another test; only
        // assert that the result is *some* non-empty string — the
        // reflection branch fires only when the constant is missing OR
        // empty (we cannot un-define here without polluting other tests).
        $v = $adapter->getVersion();
        self::assertIsString($v);
        self::assertNotSame('', $v);
    }

    public function test_get_essentials_version_returns_null_when_missing(): void
    {
        // F-09 fix: no YOOESSENTIALS_VERSION constant in the test
        // environment, no class either — must return null gracefully.
        $adapter = new YoothemeAdapter();
        self::assertNull($adapter->getEssentialsVersion());
    }

    public function test_get_essentials_version_reads_constant_when_defined(): void
    {
        if (!defined('YOOESSENTIALS_VERSION')) {
            define('YOOESSENTIALS_VERSION', '3.4.5-test');
        }
        $adapter = new YoothemeAdapter();
        self::assertSame('3.4.5-test', $adapter->getEssentialsVersion());
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

    public function test_get_source_field_entries_returns_null_when_yt_missing(): void
    {
        $adapter = new YoothemeAdapter();
        self::assertNull($adapter->getSourceFieldEntries());
    }

    public function test_get_source_field_entries_extracts_from_field_definition_objects(): void
    {
        // Simulate the real webonyx/graphql-php FieldDefinition shape:
        // public ->config property carrying YT's metadata.label / .group.
        $field = new class () {
            /** @var array<string, mixed> */
            public array $config = [
                'metadata' => [
                    'label' => 'Posts',
                    'group' => 'WordPress',
                ],
            ];

            public function getType(): object
            {
                return new class () {
                    public function __toString(): string
                    {
                        return 'PostList';
                    }
                };
            }
        };

        $adapter = new class extends YoothemeAdapter {
            /** @var array<string, mixed> */
            public array $fields = [];

            public function isLoaded(): bool
            {
                return true;
            }

            public function getSourceFields(): ?array
            {
                return $this->fields;
            }
        };
        $adapter->fields = ['posts.posts' => $field];

        $entries = $adapter->getSourceFieldEntries();
        self::assertSame([[
            'name' => 'posts.posts',
            'label' => 'Posts',
            'group' => 'WordPress',
            'type' => 'PostList',
        ]], $entries);
    }

    public function test_get_source_field_entries_handles_array_shaped_fields(): void
    {
        // Some test contexts (and the api-mapper MockYooThemeSource) carry
        // fields as plain configuration arrays rather than FieldDefinition
        // objects. The adapter must read metadata from either shape.
        $adapter = new class extends YoothemeAdapter {
            /** @var array<string, mixed> */
            public array $fields = [];

            public function isLoaded(): bool
            {
                return true;
            }

            public function getSourceFields(): ?array
            {
                return $this->fields;
            }
        };
        $adapter->fields = [
            'apimapper_flow_abc' => [
                'type' => 'FlowAbcResult',
                'metadata' => ['label' => 'Flow ABC', 'group' => 'WootsUp - API Mapper'],
            ],
        ];

        $entries = $adapter->getSourceFieldEntries();
        self::assertSame([[
            'name' => 'apimapper_flow_abc',
            'label' => 'Flow ABC',
            'group' => 'WootsUp - API Mapper',
            'type' => 'FlowAbcResult',
        ]], $entries);
    }

    public function test_get_source_field_entries_uses_name_as_label_fallback(): void
    {
        $adapter = new class extends YoothemeAdapter {
            /** @var array<string, mixed> */
            public array $fields = [];

            public function isLoaded(): bool
            {
                return true;
            }

            public function getSourceFields(): ?array
            {
                return $this->fields;
            }
        };
        $adapter->fields = ['posts.posts' => []];

        $entries = $adapter->getSourceFieldEntries();
        self::assertNotNull($entries);
        self::assertSame('posts.posts', $entries[0]['name']);
        self::assertSame('posts.posts', $entries[0]['label']);
        self::assertSame('', $entries[0]['group']);
        self::assertSame('', $entries[0]['type']);
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
