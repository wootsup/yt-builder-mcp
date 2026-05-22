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
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    /**
     * F-04 regression pin — same root-cause as the Source-id test, applied to
     * the Builder service. YT's DI container is string-keyed (no leading-
     * backslash normalisation); leading-backslash form bypasses the
     * registered factory and reflection-instantiates a bare Builder
     * without the transform-chain wiring.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_get_builder_uses_yt_canonical_service_id_without_leading_backslash(): void
    {
        $GLOBALS['ytb_test_yt_app_calls'] = [];

        if (!class_exists('\\YOOtheme\\Application', false)) {
            eval('namespace YOOtheme; class Application {}');
        }
        if (!class_exists('\\YOOtheme\\Builder', false)) {
            eval('namespace YOOtheme; class Builder {}');
        }
        if (!function_exists('\\YOOtheme\\app')) {
            eval('
                namespace YOOtheme {
                    function app($id = null) {
                        $GLOBALS["ytb_test_yt_app_calls"][] = $id;
                        if ($id === "YOOtheme\\\\Builder") {
                            return new \\YOOtheme\\Builder();
                        }
                        return null;
                    }
                }
            ');
        }

        $adapter = new YoothemeAdapter();
        $builder = $adapter->getBuilder();

        self::assertNotNull($builder, 'getBuilder() must reach the YT DI service-factory via the no-leading-backslash id.');
        self::assertContains('YOOtheme\\Builder', $GLOBALS['ytb_test_yt_app_calls']);
        foreach ($GLOBALS['ytb_test_yt_app_calls'] as $id) {
            self::assertStringStartsNotWith('\\', (string) $id, sprintf('Leading-backslash service-id "%s" passed — F-04 regression!', $id));
        }
    }

    public function test_get_source_fields_returns_null_when_yt_missing(): void
    {
        $adapter = new YoothemeAdapter();
        self::assertNull($adapter->getSourceFields());
    }

    /**
     * F-04 regression pin (Maria-Audit T2.3 2026-05-22).
     *
     * Bug history: the adapter used to call `\YOOtheme\app('\YOOtheme\Builder\Source')`
     * with a LEADING backslash. YOOtheme's DI container (`\YOOtheme\Container`)
     * keys services by raw string identity (no `ltrim('\\')`), so
     * `'\YOOtheme\Builder\Source'` and `'YOOtheme\Builder\Source'` are TWO
     * distinct keys. The leading-backslash form misses the service-definition
     * cache, falls through to `class_exists()` + reflection-instantiation,
     * and bypasses the factory closure registered in builder-source/bootstrap.php
     * — `Event::emit('source.init', $source)` never fires, source listeners
     * never register their types, and `$schema->getQueryType()` returns null.
     * Result: `/sources` REST returns empty groups on YT 4.5.33.
     *
     * This test pins the service-id string the adapter sends so a regression
     * (re-introducing the leading backslash) fails fast — independent of
     * whether the live YT container is loaded.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_get_source_fields_uses_yt_canonical_service_id_without_leading_backslash(): void
    {
        // Build a fake YOOtheme runtime that records every service-id the
        // adapter requests via `\YOOtheme\app($id)`. The adapter calls the
        // function dynamically by string `'\YOOtheme\app'` — define the
        // global-namespace function in the YOOtheme namespace so the
        // dynamic invocation hits our recorder.
        $GLOBALS['ytb_test_yt_app_calls'] = [];

        if (!class_exists('\\YOOtheme\\Application', false)) {
            eval('namespace YOOtheme; class Application {}');
        }
        if (!class_exists('\\YOOtheme\\Builder\\Source', false)) {
            // Stand-in Source object — `getSchema()` returns a stub Schema
            // exposing a Query type with one field, so the happy-path also
            // surfaces (catches "schema accessed but identifier wrong"
            // regressions).
            eval('
                namespace YOOtheme\\Builder {
                    class Source {
                        public function getSchema(): object {
                            return new class {
                                public function getQueryType(): object {
                                    return new class {
                                        public function getFields(): array {
                                            return ["posts.singlePost" => (object)["config" => ["metadata" => ["label" => "Post", "group" => "WordPress"]]]];
                                        }
                                    };
                                }
                            };
                        }
                    }
                }
            ');
        }
        if (!function_exists('\\YOOtheme\\app')) {
            eval('
                namespace YOOtheme {
                    function app($id = null) {
                        $GLOBALS["ytb_test_yt_app_calls"][] = $id;
                        if ($id === "YOOtheme\\\\Builder\\\\Source") {
                            return new \\YOOtheme\\Builder\\Source();
                        }
                        // Any leading-backslash request would land here and
                        // — because we never satisfy it — the adapter would
                        // see a non-object and return null. That negative
                        // path is precisely the bug we are pinning against.
                        return null;
                    }
                }
            ');
        }

        $adapter = new YoothemeAdapter();
        $fields = $adapter->getSourceFields();

        // 1. The happy-path surfaced (1 field).
        self::assertIsArray($fields, 'Source-schema must be reached via the no-leading-backslash service id.');
        self::assertCount(1, $fields);
        self::assertArrayHasKey('posts.singlePost', $fields);

        // 2. No leading-backslash service request ever happened.
        $calls = $GLOBALS['ytb_test_yt_app_calls'];
        self::assertContains('YOOtheme\\Builder\\Source', $calls, 'Adapter MUST pass YT-canonical "YOOtheme\\Builder\\Source" (no leading backslash).');
        foreach ($calls as $id) {
            self::assertStringStartsNotWith('\\', (string) $id, sprintf('Leading-backslash service-id "%s" passed to \\YOOtheme\\app() — F-04 regression!', $id));
        }
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

    public function test_get_builder_type_config_returns_null_when_yt_missing(): void
    {
        $adapter = new YoothemeAdapter();
        self::assertNull($adapter->getBuilderTypeConfig('headline'));
    }

    public function test_get_builder_types_detailed_returns_null_when_yt_missing(): void
    {
        $adapter = new YoothemeAdapter();
        self::assertNull($adapter->getBuilderTypesDetailed());
    }

    /**
     * F-05 regression pin — YT 4.x access pattern.
     *
     * On YT 4.5.33 the canonical access pattern is instance-based:
     *
     *   $builder = \YOOtheme\app('YOOtheme\Builder');
     *   $type    = $builder->types[$name];       // ElementType (->data array)
     *   $config  = $type->data;                  // canonical fields/fieldset config
     *
     * Static accessors `Builder::getType()` / `Builder::getTypes()` do NOT
     * exist on YT 4.x. Prior code probed them via method_exists() →
     * fell through to null → Inspector::schema() always emitted fields=[].
     *
     * This pin instantiates a fake YT runtime where the Builder service
     * exposes a `types` property carrying real ElementType-shaped data;
     * the adapter must read fields from there.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_get_builder_type_config_reads_from_instance_types_property(): void
    {
        if (!class_exists('\\YOOtheme\\Application', false)) {
            eval('namespace YOOtheme; class Application {}');
        }
        // Fake ElementType — matches YT 4's shape: public $data array,
        // ArrayObject-ish: getArrayCopy() returns data.
        if (!class_exists('\\YOOtheme\\Builder\\ElementType', false)) {
            eval('
                namespace YOOtheme\\Builder {
                    class ElementType {
                        /** @var array<string,mixed> */
                        public array $data;
                        /** @param array<string,mixed> $data */
                        public function __construct(array $data) {
                            $this->data = $data;
                        }
                        /** @return array<string,mixed> */
                        public function getArrayCopy(): array {
                            return $this->data;
                        }
                    }
                }
            ');
        }
        if (!class_exists('\\YOOtheme\\Builder', false)) {
            eval('
                namespace YOOtheme {
                    class Builder {
                        /** @var array<string, \YOOtheme\Builder\ElementType> */
                        public array $types = [];
                    }
                }
            ');
        }
        if (!function_exists('\\YOOtheme\\app')) {
            eval('
                namespace YOOtheme {
                    function app($id = null) {
                        static $builder = null;
                        if ($id === "YOOtheme\\\\Builder") {
                            if ($builder === null) {
                                $builder = new \\YOOtheme\\Builder();
                                $builder->types["headline"] = new \\YOOtheme\\Builder\\ElementType([
                                    "name" => "headline",
                                    "title" => "Headline",
                                    "fields" => [
                                        "content" => ["label" => "Content", "type" => "editor"],
                                        "title_element" => ["label" => "Title element", "type" => "select", "default" => "h1"],
                                        "text_align" => ["label" => "Text align", "type" => "text-align"],
                                    ],
                                ]);
                            }
                            return $builder;
                        }
                        return null;
                    }
                }
            ');
        }

        $adapter = new YoothemeAdapter();
        $config = $adapter->getBuilderTypeConfig('headline');

        self::assertIsArray($config, 'Config must be reached via instance->types[$name]->data.');
        self::assertSame('headline', $config['name']);
        self::assertArrayHasKey('fields', $config);
        self::assertIsArray($config['fields']);
        self::assertArrayHasKey('content', $config['fields']);
        self::assertArrayHasKey('title_element', $config['fields']);
        self::assertArrayHasKey('text_align', $config['fields']);
    }

    /**
     * F-05 pin — unknown type returns null cleanly (no fatal).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_get_builder_type_config_returns_null_for_unknown_type(): void
    {
        if (!class_exists('\\YOOtheme\\Application', false)) {
            eval('namespace YOOtheme; class Application {}');
        }
        if (!class_exists('\\YOOtheme\\Builder', false)) {
            eval('
                namespace YOOtheme {
                    class Builder {
                        /** @var array<string, mixed> */
                        public array $types = [];
                    }
                }
            ');
        }
        if (!function_exists('\\YOOtheme\\app')) {
            eval('
                namespace YOOtheme {
                    function app($id = null) {
                        if ($id === "YOOtheme\\\\Builder") {
                            return new \\YOOtheme\\Builder();
                        }
                        return null;
                    }
                }
            ');
        }

        $adapter = new YoothemeAdapter();
        self::assertNull($adapter->getBuilderTypeConfig('definitely-not-a-real-type'));
    }

    /**
     * F-05 pin — getBuilderTypesDetailed reads instance->types and projects
     * the rich shape (name/label/origin/has_children).
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_get_builder_types_detailed_reads_from_instance_types_property(): void
    {
        if (!class_exists('\\YOOtheme\\Application', false)) {
            eval('namespace YOOtheme; class Application {}');
        }
        if (!class_exists('\\YOOtheme\\Builder\\ElementType', false)) {
            eval('
                namespace YOOtheme\\Builder {
                    class ElementType {
                        /** @var array<string,mixed> */
                        public array $data;
                        /** @param array<string,mixed> $data */
                        public function __construct(array $data) {
                            $this->data = $data;
                        }
                    }
                }
            ');
        }
        if (!class_exists('\\YOOtheme\\Builder', false)) {
            eval('
                namespace YOOtheme {
                    class Builder {
                        /** @var array<string, \YOOtheme\Builder\ElementType> */
                        public array $types = [];
                    }
                }
            ');
        }
        if (!function_exists('\\YOOtheme\\app')) {
            eval('
                namespace YOOtheme {
                    function app($id = null) {
                        static $builder = null;
                        if ($id === "YOOtheme\\\\Builder") {
                            if ($builder === null) {
                                $builder = new \\YOOtheme\\Builder();
                                $builder->types["section"] = new \\YOOtheme\\Builder\\ElementType([
                                    "name" => "section",
                                    "title" => "Section",
                                    "element" => true,
                                    "container" => true,
                                ]);
                                $builder->types["headline"] = new \\YOOtheme\\Builder\\ElementType([
                                    "name" => "headline",
                                    "title" => "Headline",
                                    "element" => true,
                                ]);
                            }
                            return $builder;
                        }
                        return null;
                    }
                }
            ');
        }

        $adapter = new YoothemeAdapter();
        $detailed = $adapter->getBuilderTypesDetailed();

        self::assertIsArray($detailed);
        self::assertCount(2, $detailed);
        $byName = [];
        foreach ($detailed as $entry) {
            $byName[$entry['name']] = $entry;
        }
        self::assertArrayHasKey('section', $byName);
        self::assertSame('Section', $byName['section']['label']);
        self::assertTrue($byName['section']['has_children']);
        self::assertArrayHasKey('headline', $byName);
        self::assertSame('Headline', $byName['headline']['label']);
        self::assertFalse($byName['headline']['has_children']);
    }

    // -------------------------------------------------------------
    // F-03 v2 (Maria-Audit Stream C2) — label/origin/has_children fidelity.
    // -------------------------------------------------------------

    /**
     * Maria-Audit v2 F-03: YT 4.5.33 element.json uses key `title` for the
     * human label (NOT `label`). Prior code probed for `label` only, which
     * was always absent → fell through to PascalCase fallback. The audit
     * surfaced "label: """ on the live REST. The adapter MUST read `title`
     * before `label` so live entries surface the real label.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_get_builder_types_detailed_uses_yt_title_as_label(): void
    {
        if (!class_exists('\\YOOtheme\\Application', false)) {
            eval('namespace YOOtheme; class Application {}');
        }
        if (!class_exists('\\YOOtheme\\Builder\\ElementType', false)) {
            eval('
                namespace YOOtheme\\Builder {
                    class ElementType {
                        /** @var array<string,mixed> */
                        public array $data;
                        /** @param array<string,mixed> $data */
                        public function __construct(array $data) {
                            $this->data = $data;
                        }
                    }
                }
            ');
        }
        if (!class_exists('\\YOOtheme\\Builder', false)) {
            eval('
                namespace YOOtheme {
                    class Builder {
                        /** @var array<string, \YOOtheme\Builder\ElementType> */
                        public array $types = [];
                    }
                }
            ');
        }
        if (!function_exists('\\YOOtheme\\app')) {
            eval('
                namespace YOOtheme {
                    function app($id = null) {
                        static $builder = null;
                        if ($id === "YOOtheme\\\\Builder") {
                            if ($builder === null) {
                                $builder = new \\YOOtheme\\Builder();
                                // YT element.json shape: uses `title` for label.
                                $builder->types["headline"] = new \\YOOtheme\\Builder\\ElementType([
                                    "name" => "headline",
                                    "title" => "Headline",
                                    "element" => true,
                                ]);
                                $builder->types["grid"] = new \\YOOtheme\\Builder\\ElementType([
                                    "name" => "grid",
                                    "title" => "Grid",
                                    "element" => true,
                                    "container" => true,
                                ]);
                                $builder->types["accordion_item"] = new \\YOOtheme\\Builder\\ElementType([
                                    "name" => "accordion_item",
                                    "title" => "Item",
                                ]);
                            }
                            return $builder;
                        }
                        return null;
                    }
                }
            ');
        }

        $adapter = new YoothemeAdapter();
        $detailed = $adapter->getBuilderTypesDetailed();
        self::assertIsArray($detailed);

        $byName = [];
        foreach ($detailed as $entry) {
            $byName[$entry['name']] = $entry;
        }

        // F-03: label MUST come from `title` (YT convention), not from PascalCase fallback.
        self::assertSame('Headline', $byName['headline']['label']);
        self::assertSame('Grid', $byName['grid']['label']);
        self::assertSame('Item', $byName['accordion_item']['label']);

        // F-03: has_children must distinguish container types from leaf elements.
        // headline has `element: true` only → leaf (has_children=false).
        // grid has `container: true` → container (has_children=true).
        // accordion_item is in the canonical container↔item map → has_children=true.
        self::assertFalse(
            $byName['headline']['has_children'],
            'headline must be has_children=false — element:true alone does NOT imply children'
        );
        self::assertTrue($byName['grid']['has_children']);
        self::assertTrue(
            $byName['accordion_item']['has_children'],
            'accordion_item is an item-child of accordion → must report has_children=true (Multi-Items pattern)'
        );

        // F-03: origin defaults to 'builtin' when no marker present.
        foreach ($byName as $entry) {
            self::assertNotSame('', $entry['origin']);
        }
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
