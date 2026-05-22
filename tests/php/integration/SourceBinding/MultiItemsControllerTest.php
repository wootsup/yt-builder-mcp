<?php
/**
 * MultiItemsController — end-to-end behavioural test (inspect + clean-implode).
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Integration\SourceBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Cache\CacheFlusher;
use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\SourceBinding\MultiItemsController;
use WootsUp\BuilderMcp\SourceBinding\MultiItemsInspector;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;
use WootsUp\BuilderMcp\Tests\TestVerifierFactory;

#[CoversClass(MultiItemsController::class)]
final class MultiItemsControllerTest extends TestCase
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
                                // [0] container with WRONG container-level binding +
                                // implode directives
                                [
                                    'type' => 'grid',
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

    private function writeRequest(string $method, string $route): \WP_REST_Request
    {
        $req = new \WP_REST_Request($method, $route);
        $req->set_header('If-Match', (new LayoutReader())->etag());
        return $req;
    }

    public function test_inspect_surfaces_warning_on_container_level_binding(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/multi-items/inspect';

        $resp = $controller->inspect($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        self::assertSame('tpl', $data['template_id']);
        $report = $data['report'];
        self::assertSame('grid', $report['element_type']);
        self::assertTrue($report['is_container']);
        self::assertSame('grid_item', $report['item_type']);
        self::assertSame('container', $report['current_binding_level']);
        self::assertTrue($report['has_implode_directives']);
        self::assertArrayHasKey('warning', $report);
        self::assertArrayHasKey('recommended_fix', $report);
    }

    public function test_inspect_returns_404_for_missing_element(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/99/multi-items/inspect';

        $resp = $controller->inspect($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.multi_items.not_found', $resp->get_error_code());
    }

    public function test_clean_implode_strips_directives_and_persists_state(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('POST', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/multi-items/clean-implode';

        $resp = $controller->clean_implode($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        self::assertSame(1, $data['cleaned_count']);
        self::assertCount(1, $data['removed_directives']);
        self::assertSame('title', $data['removed_directives'][0]['prop_name']);

        // Persisted: implode key gone.
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        $source = $stored['layout']['children'][0]['props']['source'];
        self::assertIsArray($source);
        self::assertArrayNotHasKey('implode', $source['props']['title']);
        self::assertSame('title', $source['props']['title']['name']);
    }

    public function test_clean_implode_is_idempotent_when_nothing_to_clean(): void
    {
        $controller = $this->controller();

        // First call: cleans, returns new etag.
        $req = $this->writeRequest('POST', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/multi-items/clean-implode';
        $first = $controller->clean_implode($req);
        self::assertInstanceOf(\WP_REST_Response::class, $first);
        self::assertSame(1, $first->get_data()['cleaned_count']);

        // Second call: fresh etag, 0 removed.
        $req2 = new \WP_REST_Request('POST', '/');
        $req2->set_header('If-Match', (new LayoutReader())->etag());
        $req2['template_id'] = 'tpl';
        $req2['element_path'] = 'templates/tpl/layout/children/0/multi-items/clean-implode';
        $second = $controller->clean_implode($req2);
        self::assertInstanceOf(\WP_REST_Response::class, $second);
        self::assertSame(0, $second->get_data()['cleaned_count']);
    }

    public function test_clean_implode_requires_if_match_header(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');  // No If-Match.
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/multi-items/clean-implode';
        $resp = $controller->clean_implode($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame(428, $resp->get_error_data()['status']);
    }

    public function test_clean_implode_rejects_cross_template_pointer(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('POST', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/other/layout/children/0/multi-items/clean-implode';

        $resp = $controller->clean_implode($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.multi_items.cross_template_write_denied', $resp->get_error_code());
    }
}
