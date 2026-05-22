<?php
/**
 * SourcesController PUT /binding — "Node - Item (Source/Items)" field
 * mapping support (Multi-Items binding pattern, D5).
 *
 * Covers the `__node_item__` field_mapping pseudo-source that produces
 * a YT-Pro INHERIT-style binding on the `*_item` child. The on-disk
 * shape mirrors YT's `${builder.source}` template-token used by
 * `themes/yootheme/packages/builder/elements/grid_item/element.json`.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Integration\SourceBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Cache\CacheFlusher;
use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\SourceBinding\SourceRegistry;
use WootsUp\BuilderMcp\SourceBinding\SourcesController;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;
use WootsUp\BuilderMcp\Tests\TestVerifierFactory;

#[CoversClass(SourcesController::class)]
final class NodeItemFieldMappingTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [
            'yootheme' => [
                'templates' => [
                    'tpl' => [
                        'layout' => [
                            'type' => 'layout',
                            'children' => [
                                [
                                    'type' => 'grid',
                                    'props' => [],
                                    'children' => [
                                        ['type' => 'grid_item', 'props' => []],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function controller(): SourcesController
    {
        $reader = new LayoutReader();
        return new SourcesController(
            new SourceRegistry(),
            new ElementOps($reader),
            $reader,
            new LayoutWriter($reader),
            new CacheFlusher(),
            TestVerifierFactory::verifier(),
        );
    }

    private function writeRequest(): \WP_REST_Request
    {
        $req = new \WP_REST_Request('PUT', '/');
        $req->set_header('If-Match', (new LayoutReader())->etag());
        return $req;
    }

    public function test_field_mapping_with_node_item_sentinel_emits_inherit_binding(): void
    {
        $req = $this->writeRequest();
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/children/0/binding';
        $req->set_param('source_name', 'wp_posts');
        $req->set_param('field_mappings', [
            'title' => '__node_item__',
            // Explicit field reference via __node_item__:<field>
            'image' => '__node_item__:featured_image',
            // Plain string field — non-inherit (binds to wp_posts.subtitle)
            'subtitle' => 'subtitle',
        ]);

        $resp = $this->controller()->put_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);

        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        $source = $stored['layout']['children'][0]['children'][0]['props']['source'];
        self::assertIsArray($source);

        // The query.name is still set — YT needs it so the runtime can
        // resolve the parent iteration source. The per-prop binding
        // signals INHERIT via the YT template-token `${builder.source}`.
        self::assertSame('wp_posts', $source['query']['name']);

        // title: sentinel only → inherit-binding, name = prop name itself
        self::assertSame('${builder.source}', $source['props']['title']['name']);
        self::assertArrayHasKey('filters', $source['props']['title']);
        self::assertTrue($source['props']['title']['inherit'] ?? false);

        // image: __node_item__:<field> → inherit-binding with explicit
        // source field name preserved
        self::assertSame('${builder.source}', $source['props']['image']['name']);
        self::assertSame('featured_image', $source['props']['image']['field']);
        self::assertTrue($source['props']['image']['inherit'] ?? false);

        // subtitle: plain field reference — normal mapping
        self::assertSame('subtitle', $source['props']['subtitle']['name']);
        self::assertArrayNotHasKey('inherit', $source['props']['subtitle']);
    }

    public function test_field_mapping_response_echoes_node_item_sentinels(): void
    {
        $req = $this->writeRequest();
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/children/0/binding';
        $req->set_param('source_name', 'wp_posts');
        $req->set_param('field_mappings', ['title' => '__node_item__']);

        $resp = $this->controller()->put_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        // Round-trip: response surfaces the sentinel so MCP-clients can
        // re-read state and recover the same field-mapping shape.
        self::assertSame('__node_item__', $data['binding']['field_mappings']['title']);
    }
}
