<?php
/**
 * SourcesController PUT /binding — bindingLevel resolution test
 * (Multi-Items binding pattern, D2).
 *
 * Covers the `bindingLevel` request parameter that steers the binding
 * to the correct level inside a container ↔ item pair:
 *
 *   'auto'      — pick item if target is *_item, container otherwise
 *   'item'      — resolve container target to first *_item child
 *   'container' — legacy behavior; response carries a warning
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
final class BindingLevelTest extends TestCase
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
                                // [0] grid with a single child grid_item
                                [
                                    'type' => 'grid',
                                    'props' => [],
                                    'children' => [
                                        ['type' => 'grid_item', 'props' => []],
                                    ],
                                ],
                                // [1] slideshow with leftover implode +
                                // container-level binding (legacy state)
                                [
                                    'type' => 'slideshow',
                                    'props' => [
                                        'source' => [
                                            'query' => ['name' => 'old'],
                                            'props' => [
                                                'title' => [
                                                    'name' => 'title',
                                                    'implode' => ['join' => ','],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'children' => [
                                        ['type' => 'slideshow_item', 'props' => []],
                                    ],
                                ],
                                // [2] grid container WITHOUT any *_item child
                                [
                                    'type' => 'grid',
                                    'props' => [],
                                    'children' => [],
                                ],
                                // [3] plain section (no multi-items pair)
                                ['type' => 'section', 'props' => []],
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

    public function test_binding_level_auto_keeps_item_on_item_target(): void
    {
        $req = $this->writeRequest();
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/children/0/binding';
        $req->set_param('source_name', 'wp_posts');
        $req->set_param('bindingLevel', 'auto');

        $resp = $this->controller()->put_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        self::assertSame('item', $data['binding_level']);
        self::assertSame('/templates/tpl/layout/children/0/children/0', $data['element_path']);

        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertSame(
            'wp_posts',
            $stored['layout']['children'][0]['children'][0]['props']['source']['query']['name'],
        );
        // No binding leaks onto the container.
        self::assertArrayNotHasKey('source', $stored['layout']['children'][0]['props']);
    }

    public function test_binding_level_item_auto_resolves_container_target_to_first_item_child(): void
    {
        $req = $this->writeRequest();
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/binding';
        $req->set_param('source_name', 'wp_posts');
        $req->set_param('bindingLevel', 'item');

        $resp = $this->controller()->put_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        self::assertSame('item', $data['binding_level']);
        // element_path got resolved to the grid_item child.
        self::assertSame('/templates/tpl/layout/children/0/children/0', $data['element_path']);

        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertSame(
            'wp_posts',
            $stored['layout']['children'][0]['children'][0]['props']['source']['query']['name'],
        );
        self::assertArrayNotHasKey('source', $stored['layout']['children'][0]['props']);
    }

    public function test_binding_level_item_errors_when_container_has_no_item_child(): void
    {
        $req = $this->writeRequest();
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/2/binding';
        $req->set_param('source_name', 'wp_posts');
        $req->set_param('bindingLevel', 'item');

        $resp = $this->controller()->put_binding($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        self::assertSame('yootheme_builder_mcp.source_binding.no_item_child', $resp->get_error_code());
        self::assertStringContainsString('grid_item', $resp->get_error_message());
    }

    public function test_binding_level_container_emits_warning_in_response(): void
    {
        $req = $this->writeRequest();
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/binding';
        $req->set_param('source_name', 'wp_posts');
        $req->set_param('bindingLevel', 'container');

        $resp = $this->controller()->put_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        self::assertSame('container', $data['binding_level']);
        self::assertArrayHasKey('warning', $data);
        self::assertStringContainsString('SourceTransform::repeatSource', $data['warning']);

        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertSame(
            'wp_posts',
            $stored['layout']['children'][0]['props']['source']['query']['name'],
        );
    }

    public function test_binding_level_auto_on_container_target_picks_container_and_emits_warning(): void
    {
        // Default 'auto' on a container target — choose container,
        // emit warning since item-level would be the safer pattern.
        $req = $this->writeRequest();
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/binding';
        $req->set_param('source_name', 'wp_posts');
        // No bindingLevel → defaults to 'auto'.

        $resp = $this->controller()->put_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        self::assertSame('container', $data['binding_level']);
        self::assertArrayHasKey('warning', $data);
        self::assertStringContainsString('grid_item', $data['warning']);
    }

    public function test_binding_level_item_strips_existing_implode_on_target_item(): void
    {
        // Pre-condition: slideshow_item has no implode itself, but
        // re-binding shouldn't carry over the container's stale implode.
        // We bind on the container with bindingLevel=item — the source
        // should land on the slideshow_item child cleanly without any
        // implode directives from the previous container-level state.
        $req = $this->writeRequest();
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/binding';
        $req->set_param('source_name', 'new_source');
        $req->set_param('bindingLevel', 'item');
        $req->set_param('field_mappings', ['title' => 'post_title']);

        $resp = $this->controller()->put_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);

        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        // Container-level binding stays untouched (only the item gets
        // re-bound); the warning is emitted by the inspector when the
        // container-level legacy state is reported separately.
        $itemSource = $stored['layout']['children'][1]['children'][0]['props']['source'];
        self::assertIsArray($itemSource);
        self::assertSame('new_source', $itemSource['query']['name']);
        // The new item-level prop has NO implode directive.
        self::assertArrayNotHasKey('implode', $itemSource['props']['title']);
    }

    public function test_binding_level_invalid_value_returns_400(): void
    {
        $req = $this->writeRequest();
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/binding';
        $req->set_param('source_name', 'wp_posts');
        $req->set_param('bindingLevel', 'nonsense');

        $resp = $this->controller()->put_binding($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        self::assertSame(400, $resp->get_error_data()['status']);
    }
}
