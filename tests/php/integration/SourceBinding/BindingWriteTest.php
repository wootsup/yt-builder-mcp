<?php
/**
 * SourcesController PUT/DELETE /binding — end-to-end behavioural test.
 *
 * Wave 3 Task 3.6. Mirrors the in-process WP-stub strategy from
 * WriteOpsTest — no WP-Testbench needed.
 *
 * F-13 (Wave 6 audit fix) rewrites the on-disk shape from a plain-string
 * `props.source = "name"` to a YT-canonical structured object
 * `props.source = {query: {name: ...}, props?: {...}}`. The response
 * mirror'd shape is `{source_name, field_mappings}` for round-trip.
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
final class BindingWriteTest extends TestCase
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
                                ['type' => 'grid', 'props' => ['source' => ['query' => ['name' => 'old_source']]]],
                                ['type' => 'image', 'props' => []],
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

    /**
     * Helper: build a PUT/DELETE request with a valid If-Match header
     * (required for write-methods since Wave-6 Fix 21).
     */
    private function writeRequest(string $method, string $route): \WP_REST_Request
    {
        $req = new \WP_REST_Request($method, $route);
        $req->set_header('If-Match', (new LayoutReader())->etag());
        return $req;
    }

    public function test_put_binding_writes_structured_source_object(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('PUT', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/binding';
        $req->set_param('source_name', 'posts.singlePost');

        $resp = $controller->put_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        // Response: de-structured shape (matches GET /binding).
        self::assertSame('posts.singlePost', $data['binding']['source_name']);
        self::assertSame([], $data['binding']['field_mappings']);

        // On-disk: YT-canonical structured object.
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        $source = $stored['layout']['children'][1]['props']['source'];
        self::assertIsArray($source);
        self::assertSame('posts.singlePost', $source['query']['name']);
        // No props written when no field_mappings.
        self::assertArrayNotHasKey('props', $source);
    }

    public function test_put_binding_with_field_mappings_writes_canonical_props(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('PUT', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/binding';
        $req->set_param('source_name', 'posts.singlePost');
        $req->set_param('field_mappings', ['title' => 'post_title', 'content' => 'post_content']);

        $resp = $controller->put_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        self::assertSame('posts.singlePost', $data['binding']['source_name']);
        self::assertSame(['title' => 'post_title', 'content' => 'post_content'], $data['binding']['field_mappings']);

        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        $source = $stored['layout']['children'][1]['props']['source'];
        self::assertIsArray($source);
        self::assertSame('posts.singlePost', $source['query']['name']);
        self::assertArrayHasKey('props', $source);
        self::assertSame('post_title', $source['props']['title']['name']);
        self::assertSame('post_content', $source['props']['content']['name']);
        // filters property is always present (empty object) — YT's renderer
        // treats absent filters as malformed bindings.
        self::assertArrayHasKey('filters', $source['props']['title']);
    }

    public function test_put_binding_with_null_unbinds(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('PUT', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/binding';
        $req->set_param('source_name', null);

        $resp = $controller->put_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);

        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertArrayNotHasKey('source', $stored['layout']['children'][0]['props']);
    }

    public function test_put_binding_400_when_source_name_missing(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('PUT', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/binding';
        // no source_name

        $resp = $controller->put_binding($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        $data = $resp->get_error_data();
        self::assertSame(400, $data['status']);
    }

    public function test_put_binding_400_when_field_mappings_not_object(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('PUT', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/binding';
        $req->set_param('source_name', 'posts.singlePost');
        $req->set_param('field_mappings', 'not-an-object');

        $resp = $controller->put_binding($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        $data = $resp->get_error_data();
        self::assertSame(400, $data['status']);
    }

    public function test_put_binding_400_when_field_mappings_value_not_string(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('PUT', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/binding';
        $req->set_param('source_name', 'posts.singlePost');
        $req->set_param('field_mappings', ['title' => 123]);

        $resp = $controller->put_binding($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        $data = $resp->get_error_data();
        self::assertSame(400, $data['status']);
    }

    public function test_put_binding_412_on_etag_mismatch(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('PUT', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/binding';
        $req->set_param('source_name', 'wp_posts');
        $req->set_header('If-Match', 'stale-value');

        $resp = $controller->put_binding($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        $data = $resp->get_error_data();
        self::assertSame(412, $data['status']);
    }

    public function test_delete_binding_removes_source_prop(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('DELETE', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/binding';

        $resp = $controller->delete_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertArrayNotHasKey('source', $stored['layout']['children'][0]['props']);
    }

    public function test_put_binding_404_for_unknown_element(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('PUT', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/99/binding';
        $req->set_param('source_name', 'wp_posts');

        $resp = $controller->put_binding($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        $data = $resp->get_error_data();
        self::assertSame(404, $data['status']);
    }

    public function test_get_binding_returns_destructured_shape(): void
    {
        // Seed: structured source with field_mappings already on node 0.
        $GLOBALS['ytb_test_options']['yootheme']['templates']['tpl']['layout']['children'][0]['props']['source'] = [
            'query' => ['name' => 'posts.posts'],
            'props' => [
                'title' => ['name' => 'post_title', 'filters' => new \stdClass()],
                'content' => ['name' => 'post_content', 'filters' => new \stdClass()],
            ],
        ];

        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/binding';

        $resp = $controller->get_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        self::assertSame('posts.posts', $data['binding']['source_name']);
        self::assertSame(['title' => 'post_title', 'content' => 'post_content'], $data['binding']['field_mappings']);
    }

    public function test_get_binding_returns_legacy_string_as_source_name(): void
    {
        // Pre-F-13 state: bare-string source value persists in some user data.
        // The reader must surface it as {source_name: "...", field_mappings: []}
        // so MCP-clients see a single contract.
        $GLOBALS['ytb_test_options']['yootheme']['templates']['tpl']['layout']['children'][1]['props']['source'] = 'legacy_string';

        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/binding';

        $resp = $controller->get_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        self::assertSame('legacy_string', $data['binding']['source_name']);
        self::assertSame([], $data['binding']['field_mappings']);
    }

    public function test_get_binding_returns_null_when_unbound(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/binding';

        $resp = $controller->get_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        self::assertNull($data['binding']['source_name']);
        self::assertSame([], $data['binding']['field_mappings']);
    }

    // -------------------------------------------------------------
    // F-01 — get_binding exposes canonical fields at the top level
    // (`source_name`, `field_mappings`, `has_binding`) so the MCP TS
    // `handleElementGetBinding` reader sees them directly.
    // -------------------------------------------------------------

    public function test_get_binding_surfaces_canonical_fields_at_top_level(): void
    {
        $GLOBALS['ytb_test_options']['yootheme']['templates']['tpl']['layout']['children'][0]['props']['source'] = [
            'query' => ['name' => 'posts.posts'],
            'props' => [
                'title' => ['name' => 'post_title', 'filters' => new \stdClass()],
            ],
        ];

        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/binding';

        /** @var \WP_REST_Response $resp */
        $resp = $controller->get_binding($req);
        $data = $resp->get_data();
        self::assertSame('posts.posts', $data['source_name']);
        self::assertSame(['title' => 'post_title'], $data['field_mappings']);
        self::assertTrue($data['has_binding']);
        // ETag surfaced too (TS handler uses it for optimistic-lock chains).
        self::assertArrayHasKey('etag', $data);
    }

    public function test_get_binding_unbound_returns_has_binding_false(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/binding';

        $resp = $controller->get_binding($req);
        $data = $resp->get_data();
        self::assertNull($data['source_name']);
        self::assertSame([], $data['field_mappings']);
        self::assertFalse($data['has_binding']);
    }
}
