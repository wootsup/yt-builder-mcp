<?php
/**
 * ElementsController write-endpoints — end-to-end behavioural test.
 *
 * Wave 3 Task 3.4. Uses the in-process WP-stubs from tests/php/bootstrap.php
 * (WP_REST_Request / WP_REST_Response / WP_Error / wp_options) so we never
 * need to spin up WP-Testbench just to verify what is a pure
 * read-mutate-persist flow.
 *
 * Each test drives a controller endpoint directly, asserts on the
 * WP_REST_Response payload, and checks that:
 *   - the wp_option('yootheme') blob reflects the mutation,
 *   - the response carries the new ETag,
 *   - the cache-flusher was invoked (via wp_cache_flush counter).
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Integration\Elements;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Cache\CacheFlusher;
use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\Elements\ElementsController;
use WootsUp\BuilderMcp\Rest\EtagMiddleware;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;
use WootsUp\BuilderMcp\Tests\TestVerifierFactory;

#[CoversClass(ElementsController::class)]
#[CoversClass(ElementOps::class)]
#[CoversClass(LayoutWriter::class)]
#[CoversClass(LayoutReader::class)]
#[CoversClass(CacheFlusher::class)]
#[CoversClass(EtagMiddleware::class)]
final class WriteOpsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [
            'yootheme' => [
                'templates' => [
                    'tpl' => [
                        'name' => 'Home',
                        'layout' => [
                            'type' => 'layout',
                            'children' => [
                                [
                                    'type' => 'section',
                                    'props' => ['style' => 'default'],
                                    'children' => [
                                        ['type' => 'headline', 'props' => ['content' => 'Hello']],
                                    ],
                                ],
                                ['type' => 'image', 'props' => ['source' => 'cat.jpg']],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $GLOBALS['ytb_test_cache_delete_calls'] = [];
    }

    private function controller(): ElementsController
    {
        $reader = new LayoutReader();
        $writer = new LayoutWriter($reader);
        $ops = new ElementOps($reader);
        $flusher = new CacheFlusher();
        // BearerVerifier is now non-null (Wave-6 Fix 1). Tests drive
        // route handlers directly, bypassing permission_callback — so any
        // wired verifier is acceptable.
        return new ElementsController($ops, $reader, $writer, $flusher, TestVerifierFactory::verifier());
    }

    /**
     * Helper: build a write-method request pre-loaded with a valid
     * If-Match header (required since Wave-6 Fix 21 for DELETE/PUT).
     */
    private function writeRequest(string $method, string $route = '/'): \WP_REST_Request
    {
        $req = new \WP_REST_Request($method, $route);
        $req->set_header('If-Match', (new LayoutReader())->etag());
        return $req;
    }

    public function test_add_element_appends_and_returns_new_path_and_etag(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req->set_param('parent_path', '');
        $req->set_param('element_type', 'divider');
        $req->set_param('props', ['style' => 'thin']);

        /** @var \WP_REST_Response $resp */
        $resp = $controller->add_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        self::assertSame(200, $resp->get_status());
        $data = $resp->get_data();
        self::assertSame('tpl', $data['template_id']);
        self::assertSame('/templates/tpl/layout/children/2', $data['element_path']);
        self::assertArrayHasKey('etag', $data);

        // The mutation must have landed in wp_option('yootheme').
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertSame('divider', $stored['layout']['children'][2]['type']);
    }

    public function test_add_element_returns_400_when_element_type_missing(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        // no element_type supplied.

        $resp = $controller->add_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        $data = $resp->get_error_data();
        self::assertSame(400, $data['status']);
    }

    public function test_add_element_returns_404_for_unknown_template(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'does-not-exist';
        $req->set_param('element_type', 'divider');

        $resp = $controller->add_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        $data = $resp->get_error_data();
        self::assertSame(404, $data['status']);
    }

    public function test_update_settings_replaces_props(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('PUT');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/settings';
        $req->set_param('props', ['source' => 'dog.jpg', 'alt' => 'Dog']);

        $resp = $controller->update_settings($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        /** @var \WP_REST_Response $resp */
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertSame(
            ['source' => 'dog.jpg', 'alt' => 'Dog'],
            $stored['layout']['children'][1]['props'],
        );
    }

    public function test_update_settings_returns_400_when_props_missing(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('PUT');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/settings';

        $resp = $controller->update_settings($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
    }

    public function test_update_settings_returns_412_on_etag_mismatch(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('PUT', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/settings';
        $req->set_param('props', ['source' => 'dog.jpg']);
        $req->set_header('If-Match', 'stale-etag-value');

        $resp = $controller->update_settings($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        $data = $resp->get_error_data();
        self::assertSame(412, $data['status']);
    }

    public function test_delete_element_removes_node(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('DELETE');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1'; // image

        $resp = $controller->delete_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertCount(1, $stored['layout']['children']);
        self::assertSame('section', $stored['layout']['children'][0]['type']);
    }

    public function test_move_element_returns_new_path(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/move'; // section
        $req->set_param('to_parent_path', '/templates/tpl/layout');
        $req->set_param('to_index', 2);

        $resp = $controller->move_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        // Section moved past image → final index 1 (after shift-adjustment).
        self::assertSame('/templates/tpl/layout/children/1', $data['element_path']);

        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertSame('image', $stored['layout']['children'][0]['type']);
        self::assertSame('section', $stored['layout']['children'][1]['type']);
    }

    public function test_move_element_400_when_to_index_missing(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/move';
        $req->set_param('to_parent_path', '/templates/tpl/layout');
        // to_index missing.

        $resp = $controller->move_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
    }

    public function test_clone_element_inserts_after(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/clone';

        $resp = $controller->clone_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        self::assertSame('/templates/tpl/layout/children/2', $data['element_path']);

        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertCount(3, $stored['layout']['children']);
        self::assertSame('image', $stored['layout']['children'][2]['type']);
    }

    public function test_writes_invoke_cache_delete(): void
    {
        // Wave-6 Fix 14: cache invalidation is now scoped wp_cache_delete
        // calls, not a wp_cache_flush(). We assert that the plugin-owned
        // options were targeted.
        $GLOBALS['ytb_test_cache_delete_calls'] = [];
        $controller = $this->controller();

        $req = $this->writeRequest('DELETE');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1';
        $controller->delete_element($req);

        $keys = array_column($GLOBALS['ytb_test_cache_delete_calls'], 'key');
        self::assertContains('yootheme', $keys);
    }

    public function test_etag_in_response_reflects_post_write_state(): void
    {
        $beforeEtag = (new LayoutReader())->etag();
        $controller = $this->controller();
        $req = $this->writeRequest('DELETE');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1';
        /** @var \WP_REST_Response $resp */
        $resp = $controller->delete_element($req);
        $data = $resp->get_data();
        self::assertNotSame($beforeEtag, $data['etag']);
        self::assertSame((new LayoutReader())->etag(), $data['etag']);
    }
}
