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

    public function test_update_settings_merge_preserves_untouched_keys(): void
    {
        // T5 / F-12: merge=true reads current props, deep-merges request, writes back.
        // Untouched keys must survive (this is the whole point — it avoids
        // client-side read-modify-write races).
        $controller = $this->controller();
        // Seed an extra key in current props so we can prove merge keeps it.
        $GLOBALS['ytb_test_options']['yootheme']['templates']['tpl']['layout']['children'][1]['props']
            = ['source' => 'cat.jpg', 'alt' => 'Cat', 'class' => 'uk-border-rounded'];

        $req = $this->writeRequest('PUT');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/settings';
        $req->set_param('props', ['source' => 'dog.jpg']);
        $req->set_param('merge', true);

        $resp = $controller->update_settings($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);

        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        // source overwritten, alt + class preserved.
        self::assertSame('dog.jpg', $stored['layout']['children'][1]['props']['source']);
        self::assertSame('Cat', $stored['layout']['children'][1]['props']['alt']);
        self::assertSame(
            'uk-border-rounded',
            $stored['layout']['children'][1]['props']['class'],
        );
    }

    public function test_update_settings_replace_mode_unchanged_when_merge_omitted(): void
    {
        // Default merge=false (omitted) behaviour must remain "full replace".
        $controller = $this->controller();
        $GLOBALS['ytb_test_options']['yootheme']['templates']['tpl']['layout']['children'][1]['props']
            = ['source' => 'cat.jpg', 'alt' => 'Cat', 'class' => 'uk-border-rounded'];

        $req = $this->writeRequest('PUT');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/settings';
        $req->set_param('props', ['source' => 'dog.jpg']);
        // No `merge` param — default replace semantics.

        $resp = $controller->update_settings($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);

        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertSame(['source' => 'dog.jpg'], $stored['layout']['children'][1]['props']);
    }

    /**
     * 1.0.1 Wave-1.8 F-COLD-21: cold-agent S3 (multi-items binding) PUT
     * a `props:{item_element:"article"}` against a grid_item that
     * carried `props.source` (the multi-items binding). Full-replace
     * silently wiped the binding — that's the foot-gun. The fix surfaces
     * `merge_mode:"replace"` plus a `replaced_top_level_props` echo so
     * the caller learns which keys their request dropped.
     */
    public function test_update_settings_full_replace_echoes_dropped_top_level_props(): void
    {
        $controller = $this->controller();
        $GLOBALS['ytb_test_options']['yootheme']['templates']['tpl']['layout']['children'][1]['props']
            = ['source' => 'cat.jpg', 'alt' => 'Cat', 'class' => 'uk-border-rounded'];

        $req = $this->writeRequest('PUT');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/settings';
        // Caller supplies ONLY `class` — `source` + `alt` get dropped.
        $req->set_param('props', ['class' => 'uk-shadow']);

        $resp = $controller->update_settings($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        self::assertSame('replace', $data['merge_mode']);
        self::assertArrayHasKey('replaced_top_level_props', $data);
        $dropped = $data['replaced_top_level_props'];
        sort($dropped);
        self::assertSame(['alt', 'source'], $dropped);
    }

    public function test_update_settings_full_replace_no_dropped_key_omits_echo(): void
    {
        // When the caller's full-replace payload covers every existing
        // top-level key, `replaced_top_level_props` is omitted (response
        // stays slim). `merge_mode` is always present.
        $controller = $this->controller();
        $GLOBALS['ytb_test_options']['yootheme']['templates']['tpl']['layout']['children'][1]['props']
            = ['source' => 'cat.jpg'];

        $req = $this->writeRequest('PUT');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/settings';
        $req->set_param('props', ['source' => 'dog.jpg', 'alt' => 'Dog']);

        $resp = $controller->update_settings($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        self::assertSame('replace', $data['merge_mode']);
        self::assertArrayNotHasKey('replaced_top_level_props', $data);
    }

    public function test_update_settings_merge_mode_response_field(): void
    {
        // `merge:true` flows through with `merge_mode:"merge"` echo.
        $controller = $this->controller();
        $GLOBALS['ytb_test_options']['yootheme']['templates']['tpl']['layout']['children'][1]['props']
            = ['source' => 'cat.jpg', 'alt' => 'Cat'];

        $req = $this->writeRequest('PUT');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/settings';
        $req->set_param('props', ['alt' => 'Updated']);
        $req->set_param('merge', true);

        $resp = $controller->update_settings($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        self::assertSame('merge', $data['merge_mode']);
        // No `replaced_top_level_props` on the merge path.
        self::assertArrayNotHasKey('replaced_top_level_props', $data);
    }

    public function test_update_settings_merge_deep_merges_nested_objects(): void
    {
        // F-12: structured F-13-shape sources must be merge-able by sub-key.
        $controller = $this->controller();
        $GLOBALS['ytb_test_options']['yootheme']['templates']['tpl']['layout']['children'][1]['props']
            = [
                'source' => [
                    'query' => ['name' => 'posts.singlePost'],
                    'props' => ['title' => 'old', 'image' => 'a.jpg'],
                ],
            ];

        $req = $this->writeRequest('PUT');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1/settings';
        $req->set_param('props', [
            'source' => ['props' => ['title' => 'new']],
        ]);
        $req->set_param('merge', true);

        $resp = $controller->update_settings($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);

        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        $props = $stored['layout']['children'][1]['props'];
        // query.name preserved.
        self::assertSame('posts.singlePost', $props['source']['query']['name']);
        // props.title overwritten, props.image preserved.
        self::assertSame('new', $props['source']['props']['title']);
        self::assertSame('a.jpg', $props['source']['props']['image']);
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

    // ------------------------------------------------------------------
    // F-01 — get_element response shape (flat canonical fields).
    // ------------------------------------------------------------------

    public function test_get_element_returns_canonical_flat_shape(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0';

        /** @var \WP_REST_Response $resp */
        $resp = $controller->get_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        // Canonical top-level keys read by the MCP TS handler.
        self::assertSame('section', $data['element_type']);
        self::assertSame('section', $data['type']);
        self::assertSame(['style' => 'default'], $data['props']);
        self::assertCount(1, $data['children']);
        self::assertSame('headline', $data['children'][0]['type']);
        self::assertFalse($data['has_binding']);
        self::assertSame(1, $data['child_count']);
        // Legacy `element` alias preserved for back-compat.
        self::assertArrayHasKey('element', $data);
        self::assertSame('section', $data['element']['type']);
    }

    public function test_get_element_surfaces_has_binding_true_for_bound_node(): void
    {
        $GLOBALS['ytb_test_options']['yootheme']['templates']['tpl']['layout']['children'][1]['props']['source']
            = ['query' => ['name' => 'posts.singlePost']];
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1';

        /** @var \WP_REST_Response $resp */
        $resp = $controller->get_element($req);
        $data = $resp->get_data();
        self::assertTrue($data['has_binding']);
    }

    public function test_list_elements_returns_total_matching_recursive_count(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';

        /** @var \WP_REST_Response $resp */
        $resp = $controller->list_elements($req);
        $data = $resp->get_data();
        self::assertArrayHasKey('total', $data);
        self::assertSame(count($data['elements']), $data['total']);
        // tpl seeded with section + headline + image = 3.
        self::assertSame(3, $data['total']);
    }

    /**
     * 1.0.1 Wave-1.8 F-COLD-10: cold-agents S2/S4 burned an extra GET
     * just to disambiguate two same-type elements with different text
     * content. Pin the additive `content_preview` field on text-bearing
     * elements so a cold caller can pick the right node from the list
     * shape directly.
     */
    public function test_list_elements_surfaces_content_preview_for_text_nodes(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';

        /** @var \WP_REST_Response $resp */
        $resp = $controller->list_elements($req);
        $elements = $resp->get_data()['elements'];

        // Headline has `props.content = "Hello"` → preview surfaces.
        $headline = array_values(array_filter(
            $elements,
            static fn(array $e): bool => $e['element_type'] === 'headline',
        ))[0];
        self::assertArrayHasKey('content_preview', $headline);
        self::assertSame('Hello', $headline['content_preview']);

        // Image has no text-bearing prop → preview is absent (kept slim).
        $image = array_values(array_filter(
            $elements,
            static fn(array $e): bool => $e['element_type'] === 'image',
        ))[0];
        self::assertArrayNotHasKey('content_preview', $image);

        // Section is structural → preview is absent.
        $section = array_values(array_filter(
            $elements,
            static fn(array $e): bool => $e['element_type'] === 'section',
        ))[0];
        self::assertArrayNotHasKey('content_preview', $section);
    }

    /**
     * 1.0.1 Wave-1.8 F-COLD-12: opt-in `?include=props` forwards full
     * props maps for audit workflows (a11y, content scanning) without
     * forcing them to drop down to /layout. Default response stays
     * slim — only callers that explicitly opt in see `props`.
     */
    public function test_list_elements_include_props_forwards_full_props_map(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req->set_param('include', 'props');

        /** @var \WP_REST_Response $resp */
        $resp = $controller->list_elements($req);
        $elements = $resp->get_data()['elements'];
        $image = array_values(array_filter(
            $elements,
            static fn(array $e): bool => $e['element_type'] === 'image',
        ))[0];
        self::assertArrayHasKey('props', $image);
        self::assertSame('cat.jpg', $image['props']['source']);
    }

    public function test_list_elements_default_omits_props(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        // No `include` param → slim default.

        /** @var \WP_REST_Response $resp */
        $resp = $controller->list_elements($req);
        $elements = $resp->get_data()['elements'];
        $image = array_values(array_filter(
            $elements,
            static fn(array $e): bool => $e['element_type'] === 'image',
        ))[0];
        self::assertArrayNotHasKey('props', $image);
        // props_summary is still there (back-compat).
        self::assertArrayHasKey('props_summary', $image);
    }

    /**
     * 1.0.1 Wave-1.8 audit-pass v2 (A5 follow-up): explicit allow-list
     * on the `?include=` query param. Unknown tokens get 400 + hint
     * listing the accepted set — mirrors the F-COLD-9/18 404-hint
     * pattern for actionable error responses.
     */
    public function test_list_elements_rejects_unknown_include_token(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req->set_param('include', 'props,unknown_token,bogus');

        $resp = $controller->list_elements($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.elements.invalid_query', $resp->get_error_code());
        $data = $resp->get_error_data();
        self::assertSame(400, $data['status']);
        self::assertArrayHasKey('hint', $data);
        // Error message lists which tokens were rejected.
        self::assertStringContainsString('unknown_token', $resp->get_error_message());
        self::assertStringContainsString('bogus', $resp->get_error_message());
        // And which are accepted.
        self::assertStringContainsString('props', $resp->get_error_message());
    }

    public function test_list_elements_accepts_known_include_tokens(): void
    {
        // Smoke pin: the allow-list still lets through the canonical
        // `props` token (no false rejection).
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req->set_param('include', 'props');

        $resp = $controller->list_elements($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
    }

    public function test_list_elements_content_preview_strips_html_and_trims(): void
    {
        // Seed a long HTML content string — preview must strip tags and
        // cap to 60 chars + ellipsis.
        $GLOBALS['ytb_test_options']['yootheme']['templates']['tpl']['layout']['children'][0]['children'][0]['props']['content']
            = '<p><strong>Welcome to</strong> the new shop — '
            . 'browse our entire 2026 spring lineup right here today!</p>';

        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        /** @var \WP_REST_Response $resp */
        $resp = $controller->list_elements($req);
        $elements = $resp->get_data()['elements'];
        $headline = array_values(array_filter(
            $elements,
            static fn(array $e): bool => $e['element_type'] === 'headline',
        ))[0];
        // HTML stripped, length capped, ellipsis appended.
        self::assertStringStartsWith('Welcome to the new shop', $headline['content_preview']);
        self::assertStringEndsWith('…', $headline['content_preview']);
        self::assertStringNotContainsString('<strong>', $headline['content_preview']);
    }

    // ------------------------------------------------------------------
    // T2 / N-01 — list_elements parses root_path/depth/limit/cursor query
    // params and forwards the {items,next_cursor,total} pagination envelope.
    // ------------------------------------------------------------------

    public function test_list_elements_returns_flat_shape_without_pagination_params(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';

        /** @var \WP_REST_Response $resp */
        $resp = $controller->list_elements($req);
        $data = $resp->get_data();
        // Backward-compat: plain `?` call keeps the flat `elements` shape.
        self::assertArrayHasKey('elements', $data);
        self::assertArrayNotHasKey('items', $data);
        self::assertArrayNotHasKey('next_cursor', $data);
    }

    public function test_list_elements_limit_returns_pagination_envelope(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req->set_param('limit', 2);

        /** @var \WP_REST_Response $resp */
        $resp = $controller->list_elements($req);
        $data = $resp->get_data();
        // tpl flattens to section + headline + image = 3 → limit=2 pages.
        self::assertArrayHasKey('items', $data);
        self::assertCount(2, $data['items']);
        self::assertSame(3, $data['total']);
        self::assertNotNull($data['next_cursor']);
        self::assertArrayHasKey('etag', $data);
    }

    public function test_list_elements_cursor_returns_final_page_without_next_cursor(): void
    {
        $controller = $this->controller();

        $req1 = new \WP_REST_Request('GET', '/');
        $req1['template_id'] = 'tpl';
        $req1->set_param('limit', 2);
        /** @var \WP_REST_Response $resp1 */
        $resp1 = $controller->list_elements($req1);
        $cursor = $resp1->get_data()['next_cursor'];
        self::assertNotNull($cursor);

        $req2 = new \WP_REST_Request('GET', '/');
        $req2['template_id'] = 'tpl';
        $req2->set_param('limit', 2);
        $req2->set_param('cursor', $cursor);
        /** @var \WP_REST_Response $resp2 */
        $resp2 = $controller->list_elements($req2);
        $data2 = $resp2->get_data();
        self::assertCount(1, $data2['items']);
        self::assertNull($data2['next_cursor']);
    }

    public function test_list_elements_root_path_scopes_to_subtree(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req->set_param('root_path', '/templates/tpl/layout/children/0');
        $req->set_param('limit', 50);

        /** @var \WP_REST_Response $resp */
        $resp = $controller->list_elements($req);
        $data = $resp->get_data();
        // Subtree of the section node = the section + its headline child.
        self::assertArrayHasKey('items', $data);
        foreach ($data['items'] as $row) {
            self::assertStringStartsWith('/templates/tpl/layout/children/0', $row['path']);
        }
    }

    // ------------------------------------------------------------------
    // F-11 — element_add validates element_type against Inspector registry.
    // ------------------------------------------------------------------

    public function test_add_element_returns_400_for_unknown_element_type(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('POST');
        $req['template_id'] = 'tpl';
        $req->set_param('parent_path', '');
        $req->set_param('element_type', 'definitely-not-a-real-type');

        $resp = $controller->add_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        self::assertSame('yootheme_builder_mcp.elements.invalid_type', $resp->get_error_code());
        $data = $resp->get_error_data();
        self::assertSame(400, $data['status']);
        // Hint must reference element_types_list (the discovery tool).
        self::assertStringContainsString('element_types_list', $data['hint']);
        self::assertSame('definitely-not-a-real-type', $data['element_type']);
    }

    public function test_add_element_accepts_canonical_builtin_type(): void
    {
        // F-11 must NOT reject canonical built-in types.
        $controller = $this->controller();
        $req = $this->writeRequest('POST');
        $req['template_id'] = 'tpl';
        $req->set_param('parent_path', '');
        $req->set_param('element_type', 'headline');
        $req->set_param('props', ['content' => 'Hello']);

        $resp = $controller->add_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        self::assertSame(200, $resp->get_status());
    }

    // ------------------------------------------------------------------
    // F-12 — 412 precondition_failed carries a hint pointing at element_get.
    // ------------------------------------------------------------------

    public function test_update_settings_412_response_includes_element_get_hint(): void
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
        // Hint must reference yootheme_builder_element_get so the LLM
        // re-reads the (now-canonical, F-01) element shape before retrying.
        self::assertArrayHasKey('hint', $data);
        self::assertStringContainsString('element_get', $data['hint']);
    }

    /**
     * F-07 fix (Maria-Audit 2026-05-22): a mutation cycle A→B→A must
     * surface three distinct ETags even when the final state byte-equals
     * the starting state. The monotonic revision counter in
     * StateRevision guarantees this property.
     *
     * Scenario:
     *  1. seedState() is state A.
     *  2. add an element  → state B, etag_B != etag_A
     *  3. delete that element → state A' (same shape as A), etag_A' must
     *     differ from BOTH etag_A and etag_B.
     */
    public function test_aba_mutation_cycle_yields_three_distinct_etags(): void
    {
        $controller = $this->controller();

        $etagA = (new LayoutReader())->etag();

        // Step 2: add an element.
        $addReq = $this->writeRequest('POST');
        $addReq['template_id'] = 'tpl';
        $addReq->set_param('parent_path', '/templates/tpl/layout');
        $addReq->set_param('element_type', 'headline');
        $addReq->set_param('props', ['content' => 'ABA-Test']);
        $addReq->set_param('children', []);
        /** @var \WP_REST_Response $addResp */
        $addResp = $controller->add_element($addReq);
        $addData = $addResp->get_data();
        $etagB = $addData['etag'];
        $newPath = $addData['element_path'];

        self::assertNotSame($etagA, $etagB, 'A→B etag must differ.');

        // Step 3: delete the just-added element.
        $delReq = $this->writeRequest('DELETE');
        $delReq['template_id'] = 'tpl';
        // Strip the leading '/templates/tpl/elements/' framing: the
        // controller stores element_path as a JSON-Pointer rooted at the
        // wp_option document, so we feed back the value the controller
        // returned. For pointerFromRequest the element_path arg is the
        // raw path after `elements/` — we set it explicitly.
        $delReq->set_param(
            'element_path',
            ltrim((string) $newPath, '/'),
        );
        /** @var \WP_REST_Response $delResp */
        $delResp = $controller->delete_element($delReq);
        $delData = $delResp->get_data();
        $etagAPrime = $delData['etag'];

        // The structural property the F-07 fix exists to guarantee.
        self::assertNotSame($etagA, $etagAPrime, 'A→B→A etag must NOT collapse back to A.');
        self::assertNotSame($etagB, $etagAPrime, 'A→B→A etag must also differ from B.');
        self::assertNotSame($etagA, $etagB);
    }
}
