<?php
/**
 * Multi-Items 16-pair full-matrix pin test.
 *
 * Asserts that EVERY canonical YT-Pro container ↔ item pair (16 total,
 * verified on YT-Pro 4.5.33) survives the inspect + clean-implode
 * controller paths with the correct surface shape.
 *
 * Companion to {@see \WootsUp\BuilderMcp\Tests\Unit\Elements\MultiItemsPatternPinTest}
 * which covers the binding-resolver path. This file covers the
 * MultiItemsInspector + ImplodeDirectiveCleaner paths.
 *
 * Coverage matrix per pair (data-provider):
 *
 *   1. ItemContainerMap declares the pair (map shape)
 *   2. inspect(container, no binding)  → is_container=true, is_item=false
 *   3. inspect(item, no binding)       → is_container=false, is_item=true
 *   4. inspect(container, container-level binding) → warning + recommended_fix
 *      referencing the canonical `<container>_item` child
 *   5. clean_implode(container) → strips implode directives, idempotent
 *
 * Stream: D4 (Audit-v3 T8 — N-02 Multi-Items full 16-pair live audit).
 *
 * The map covers EVERY container that participates in
 * `SourceTransform::repeatSource` on YT-Pro 4.5.33 (themes/yootheme/
 * packages/builder-source/src/Source/SourceTransform.php:172-249). 12 of
 * the 16 pairs are NOT present in the default dev.wootsup.com templates
 * (I99YS8Ii / WH-IJ_7X / p_B_3mp0 / iQiiho9r / gSIK-2CZ / S12MqLbP /
 * Fp2ntvJd) — see `_internal/audits/2026-05-22-stream-d4-multi-items-16pair-audit.md`
 * for the per-pair live coverage matrix. This pin test guarantees
 * structural parity even when no live fixture exists.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Integration\MultiItems;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Cache\CacheFlusher;
use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\Elements\ItemContainerMap;
use WootsUp\BuilderMcp\SourceBinding\MultiItemsController;
use WootsUp\BuilderMcp\SourceBinding\MultiItemsInspector;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;
use WootsUp\BuilderMcp\Tests\TestVerifierFactory;

#[CoversClass(MultiItemsController::class)]
#[CoversClass(MultiItemsInspector::class)]
#[CoversClass(ItemContainerMap::class)]
final class Per16PairsPinTest extends TestCase
{
    /**
     * The canonical YT-Pro 4.5.33 container ↔ item map. This list is
     * the single source of truth — if YT-Pro adds a new
     * SourceTransform-aware container the map and this list both
     * change together.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function pairProvider(): iterable
    {
        $expected = [
            'accordion' => 'accordion_item',
            'button' => 'button_item',
            'description_list' => 'description_list_item',
            'gallery' => 'gallery_item',
            'grid' => 'grid_item',
            'list' => 'list_item',
            'map' => 'map_item',
            'nav' => 'nav_item',
            'overlay-slider' => 'overlay-slider_item',
            'panel-slider' => 'panel-slider_item',
            'popover' => 'popover_item',
            'slideshow' => 'slideshow_item',
            'social' => 'social_item',
            'subnav' => 'subnav_item',
            'switcher' => 'switcher_item',
            'table' => 'table_item',
        ];
        foreach ($expected as $container => $item) {
            yield $container => [$container, $item];
        }
    }

    public function test_item_container_map_declares_exactly_the_16_canonical_pairs(): void
    {
        $expected = [];
        foreach (self::pairProvider() as $key => [$c, $i]) {
            $expected[$c] = $i;
        }
        self::assertSame($expected, ItemContainerMap::MAP);
        self::assertCount(16, ItemContainerMap::MAP);
    }

    #[DataProvider('pairProvider')]
    public function test_map_round_trips_each_pair(string $container, string $item): void
    {
        self::assertSame($item, ItemContainerMap::itemOf($container));
        self::assertSame($container, ItemContainerMap::containerOf($item));
        self::assertTrue(ItemContainerMap::isContainer($container));
        self::assertTrue(ItemContainerMap::isItem($item));
        // Container is not an item; item is not a container.
        self::assertFalse(ItemContainerMap::isItem($container));
        self::assertFalse(ItemContainerMap::isContainer($item));
    }

    /**
     * @param array<string, mixed> $layoutOverride Optional layout to
     *                                             swap into the fixture.
     */
    private function seedFixture(string $container, string $item, array $layoutOverride = []): void
    {
        $defaultContainer = [
            'type' => $container,
            'props' => [],
            'children' => [
                ['type' => $item, 'props' => []],
            ],
        ];
        $node = $layoutOverride !== [] ? $layoutOverride : $defaultContainer;
        $GLOBALS['ytb_test_options'] = [
            'yootheme' => [
                'templates' => [
                    'tpl' => [
                        'layout' => [
                            'type' => 'layout',
                            'children' => [$node],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function controller(): MultiItemsController
    {
        $reader = new LayoutReader();
        $ops = new ElementOps($reader);
        return new MultiItemsController(
            new MultiItemsInspector($ops),
            $ops,
            $reader,
            new LayoutWriter($reader),
            new CacheFlusher(),
            TestVerifierFactory::verifier(),
        );
    }

    private function inspectRequest(string $pointer): \WP_REST_Request
    {
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = $pointer;
        return $req;
    }

    private function cleanImplodeRequest(string $pointer): \WP_REST_Request
    {
        $req = new \WP_REST_Request('POST', '/');
        $req->set_header('If-Match', (new LayoutReader())->etag());
        $req['template_id'] = 'tpl';
        $req['element_path'] = $pointer;
        return $req;
    }

    #[DataProvider('pairProvider')]
    public function test_inspect_classifies_container_correctly_for_pair(string $container, string $item): void
    {
        $this->seedFixture($container, $item);

        $resp = $this->controller()->inspect(
            $this->inspectRequest('templates/tpl/layout/children/0/multi-items/inspect'),
        );
        self::assertInstanceOf(\WP_REST_Response::class, $resp);

        $data = $resp->get_data();
        $report = $data['report'];
        self::assertSame($container, $report['element_type'], "Container element_type for {$container}");
        self::assertTrue($report['is_container'], "is_container=true for {$container}");
        self::assertFalse($report['is_item'], "is_item=false for {$container}");
        self::assertSame($container, $report['container_type'], "container_type for {$container}");
        self::assertSame($item, $report['item_type'], "item_type for {$container}");
        self::assertSame('none', $report['current_binding_level'], "no binding for {$container}");
        self::assertFalse($report['has_implode_directives'], "no implode for {$container}");
        self::assertArrayNotHasKey('warning', $report, 'No warning on unbound container');
    }

    #[DataProvider('pairProvider')]
    public function test_inspect_classifies_item_correctly_for_pair(string $container, string $item): void
    {
        $this->seedFixture($container, $item);

        $resp = $this->controller()->inspect(
            $this->inspectRequest('templates/tpl/layout/children/0/children/0/multi-items/inspect'),
        );
        self::assertInstanceOf(\WP_REST_Response::class, $resp);

        $data = $resp->get_data();
        $report = $data['report'];
        self::assertSame($item, $report['element_type'], "Item element_type for {$item}");
        self::assertFalse($report['is_container'], "is_container=false for {$item}");
        self::assertTrue($report['is_item'], "is_item=true for {$item}");
        self::assertSame($container, $report['container_type'], "container_type for {$item}");
        self::assertSame($item, $report['item_type'], "item_type for {$item}");
        self::assertSame('none', $report['current_binding_level'], "no binding for {$item}");
    }

    #[DataProvider('pairProvider')]
    public function test_inspect_surfaces_container_binding_warning_for_pair(string $container, string $item): void
    {
        // Pre-seed container with WRONG container-level binding +
        // implode directives.
        $layout = [
            'type' => $container,
            'props' => [
                'source' => [
                    'query' => ['name' => 'wp_posts'],
                    'props' => [
                        'title' => [
                            'name' => 'title',
                            'filters' => [],
                            'implode' => ['join' => ',', 'glue' => ' '],
                        ],
                        'image' => [
                            'name' => 'image',
                            'filters' => [],
                        ],
                    ],
                ],
            ],
            'children' => [
                ['type' => $item, 'props' => []],
            ],
        ];
        $this->seedFixture($container, $item, $layout);

        $resp = $this->controller()->inspect(
            $this->inspectRequest('templates/tpl/layout/children/0/multi-items/inspect'),
        );
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $report = $resp->get_data()['report'];
        self::assertSame('container', $report['current_binding_level']);
        self::assertTrue($report['has_implode_directives'], "implode detected on {$container}");
        self::assertArrayHasKey('warning', $report, "warning emitted on {$container}");
        self::assertStringContainsString(
            $item,
            (string) $report['warning'],
            "Warning text references canonical item type for {$container}",
        );
        self::assertArrayHasKey('recommended_fix', $report);
        self::assertStringContainsString(
            $item,
            (string) $report['recommended_fix'],
            "Recommended-fix text references canonical item type for {$container}",
        );
    }

    #[DataProvider('pairProvider')]
    public function test_clean_implode_strips_directive_and_is_idempotent_for_pair(
        string $container,
        string $item,
    ): void {
        // Seed container with container-level binding carrying ONE
        // implode directive on `title`.
        $layout = [
            'type' => $container,
            'props' => [
                'source' => [
                    'query' => ['name' => 'wp_posts'],
                    'props' => [
                        'title' => [
                            'name' => 'title',
                            'filters' => [],
                            'implode' => ['join' => ',', 'glue' => ' '],
                        ],
                    ],
                ],
            ],
            'children' => [
                ['type' => $item, 'props' => []],
            ],
        ];
        $this->seedFixture($container, $item, $layout);

        $controller = $this->controller();

        // 1st call: directive removed.
        $first = $controller->clean_implode(
            $this->cleanImplodeRequest('templates/tpl/layout/children/0/multi-items/clean-implode'),
        );
        self::assertInstanceOf(\WP_REST_Response::class, $first);
        $firstData = $first->get_data();
        self::assertSame(1, $firstData['cleaned_count'], "1 directive cleaned on {$container}");
        self::assertSame('title', $firstData['removed_directives'][0]['prop_name']);

        // Persisted state: implode key is gone.
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        $source = $stored['layout']['children'][0]['props']['source'];
        self::assertIsArray($source);
        self::assertArrayNotHasKey(
            'implode',
            $source['props']['title'],
            "implode stripped from persisted state on {$container}",
        );

        // 2nd call (fresh etag, same path): nothing to clean.
        $second = $controller->clean_implode(
            $this->cleanImplodeRequest('templates/tpl/layout/children/0/multi-items/clean-implode'),
        );
        self::assertInstanceOf(\WP_REST_Response::class, $second);
        self::assertSame(
            0,
            $second->get_data()['cleaned_count'],
            "idempotent on {$container}",
        );
    }
}
