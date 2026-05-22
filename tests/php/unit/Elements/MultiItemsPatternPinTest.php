<?php
/**
 * Multi-Items pattern smoke-test — verifies every canonical YT-Pro
 * container ↔ item pair survives a round-trip through
 * `SourcesController::resolveBindingLevel` AND emits the structural
 * warning when the binding lands on the container level.
 *
 * Tracking matrix: 16 pairs × {bindingLevel='auto', 'item', 'container'}.
 *
 * The pin is wire-shape only — it does NOT exercise the WP REST stack;
 * the resolver lives behind `private static SourcesController::*` so we
 * exercise it indirectly via the `put_binding` controller method.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Elements;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Cache\CacheFlusher;
use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\Elements\ItemContainerMap;
use WootsUp\BuilderMcp\SourceBinding\SourceRegistry;
use WootsUp\BuilderMcp\SourceBinding\SourcesController;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;
use WootsUp\BuilderMcp\Tests\TestVerifierFactory;

#[CoversClass(SourcesController::class)]
#[CoversClass(ItemContainerMap::class)]
final class MultiItemsPatternPinTest extends TestCase
{
    /**
     * Smoke-pin: every container ↔ item pair from {@see ItemContainerMap}
     * is covered by both a 'container' and an 'item' resolution path.
     */
    public function test_every_canonical_pair_is_covered_by_the_resolver(): void
    {
        $expected = [
            'accordion',
            'button',
            'description_list',
            'gallery',
            'grid',
            'list',
            'map',
            'nav',
            'overlay-slider',
            'panel-slider',
            'popover',
            'slideshow',
            'social',
            'subnav',
            'switcher',
            'table',
        ];
        $this->assertSame($expected, array_keys(ItemContainerMap::MAP));
        $this->assertCount(16, ItemContainerMap::MAP);
    }

    /**
     * For every pair: bindingLevel='item' on a container target with
     * exactly one *_item child resolves the binding onto the child.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function pairProvider(): iterable
    {
        foreach (ItemContainerMap::MAP as $container => $item) {
            yield $container => [$container, $item];
        }
    }

    #[DataProvider('pairProvider')]
    public function test_binding_level_item_resolves_container_to_item_child_for_pair(
        string $container,
        string $item,
    ): void {
        $GLOBALS['ytb_test_options'] = [
            'yootheme' => [
                'templates' => [
                    'tpl' => [
                        'layout' => [
                            'type' => 'layout',
                            'children' => [
                                [
                                    'type' => $container,
                                    'props' => [],
                                    'children' => [
                                        ['type' => $item, 'props' => []],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $reader = new LayoutReader();
        $controller = new SourcesController(
            new SourceRegistry(),
            new ElementOps($reader),
            $reader,
            new LayoutWriter($reader),
            new CacheFlusher(),
            TestVerifierFactory::verifier(),
        );

        $req = new \WP_REST_Request('PUT', '/');
        $req->set_header('If-Match', $reader->etag());
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/binding';
        $req->set_param('source_name', 'wp_posts');
        $req->set_param('bindingLevel', 'item');

        $resp = $controller->put_binding($req);
        $this->assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        $this->assertSame('item', $data['binding_level']);
        $this->assertSame(
            '/templates/tpl/layout/children/0/children/0',
            $data['element_path'],
            sprintf('Expected resolver to walk into the %s child of %s.', $item, $container),
        );

        $stored = $reader->readTemplate('tpl');
        $this->assertNotNull($stored);
        $this->assertSame(
            'wp_posts',
            $stored['layout']['children'][0]['children'][0]['props']['source']['query']['name'],
        );
        // Container has NO source — clean separation.
        $this->assertArrayNotHasKey('source', $stored['layout']['children'][0]['props']);
    }

    #[DataProvider('pairProvider')]
    public function test_binding_level_container_emits_warning_for_pair(string $container, string $item): void
    {
        $GLOBALS['ytb_test_options'] = [
            'yootheme' => [
                'templates' => [
                    'tpl' => [
                        'layout' => [
                            'type' => 'layout',
                            'children' => [
                                [
                                    'type' => $container,
                                    'props' => [],
                                    'children' => [
                                        ['type' => $item, 'props' => []],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $reader = new LayoutReader();
        $controller = new SourcesController(
            new SourceRegistry(),
            new ElementOps($reader),
            $reader,
            new LayoutWriter($reader),
            new CacheFlusher(),
            TestVerifierFactory::verifier(),
        );

        $req = new \WP_REST_Request('PUT', '/');
        $req->set_header('If-Match', $reader->etag());
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/binding';
        $req->set_param('source_name', 'wp_posts');
        $req->set_param('bindingLevel', 'container');

        $resp = $controller->put_binding($req);
        $this->assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        $this->assertSame('container', $data['binding_level']);
        $this->assertArrayHasKey(
            'warning',
            $data,
            sprintf('Expected container-level binding warning on %s.', $container),
        );
        $this->assertStringContainsString(
            $item,
            (string) $data['warning'],
            sprintf('Warning should reference the canonical %s child type.', $item),
        );
    }
}
