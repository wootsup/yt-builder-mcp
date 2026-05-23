<?php
/**
 * 1.0.1 Finding 2 — rel_path acceptance across every element endpoint.
 *
 * The element_list table column surfaces a `rel_path` like
 * `/children/0/...` (template-prefix stripped for readability). Live-test
 * against 1.0.0 in Claude Desktop showed agents copy-pasting that value
 * into element_get / element_add / element_update_settings /
 * element_move / element_clone / element_delete / binding endpoints and
 * receiving 404 (or 400 cross_template_write_denied) because those tools
 * expected the fully-qualified `/templates/<id>/layout/...` pointer.
 *
 * The structural fix is in PointerControllerTrait::normalizeElementPath:
 * accept both `/children/...` and fully-qualified forms; the cross-template
 * write defense still runs after normalization.
 *
 * This test exercises EVERY write-tool against the rel_path form and pins
 * the behaviour so future refactors can't silently regress it.
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
use WootsUp\BuilderMcp\Rest\PointerControllerTrait;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;
use WootsUp\BuilderMcp\SourceBinding\MultiItemsController;
use WootsUp\BuilderMcp\SourceBinding\MultiItemsInspector;
use WootsUp\BuilderMcp\SourceBinding\SourceRegistry;
use WootsUp\BuilderMcp\SourceBinding\SourcesController;
use WootsUp\BuilderMcp\Tests\TestVerifierFactory;

#[CoversClass(ElementsController::class)]
#[CoversClass(ElementOps::class)]
#[CoversClass(SourcesController::class)]
#[CoversClass(MultiItemsController::class)]
final class RelPathAcceptanceTest extends TestCase
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
        return new ElementsController($ops, $reader, $writer, $flusher, TestVerifierFactory::verifier());
    }

    private function writeRequest(string $method): \WP_REST_Request
    {
        $req = new \WP_REST_Request($method, '/');
        $req->set_header('If-Match', (new LayoutReader())->etag());
        return $req;
    }

    /** ============== normalizeElementPath unit-level pins ============== */

    public function test_normalize_passes_fully_qualified_pointer_through_unchanged(): void
    {
        $cls = new class {
            use PointerControllerTrait;
            public static function normalize(string $p, ?string $t): string
            {
                return self::normalizeElementPath($p, $t);
            }
        };

        $full = '/templates/tpl/layout/children/0';
        self::assertSame($full, $cls::normalize($full, 'tpl'));
    }

    public function test_normalize_lifts_rel_path_to_full_pointer(): void
    {
        $cls = new class {
            use PointerControllerTrait;
            public static function normalize(string $p, ?string $t): string
            {
                return self::normalizeElementPath($p, $t);
            }
        };

        // Both rel_path forms must land at the same fully-qualified pointer.
        self::assertSame(
            '/templates/tpl/layout/children/0',
            $cls::normalize('/children/0', 'tpl'),
        );
        self::assertSame(
            '/templates/tpl/layout/children/0',
            $cls::normalize('children/0', 'tpl'),
        );
    }

    public function test_normalize_preserves_empty_pointer(): void
    {
        $cls = new class {
            use PointerControllerTrait;
            public static function normalize(string $p, ?string $t): string
            {
                return self::normalizeElementPath($p, $t);
            }
        };

        self::assertSame('', $cls::normalize('', 'tpl'));
        self::assertSame('', $cls::normalize('', null));
    }

    public function test_normalize_handles_templates_without_leading_slash(): void
    {
        $cls = new class {
            use PointerControllerTrait;
            public static function normalize(string $p, ?string $t): string
            {
                return self::normalizeElementPath($p, $t);
            }
        };

        self::assertSame(
            '/templates/tpl/layout/children/0',
            $cls::normalize('templates/tpl/layout/children/0', 'tpl'),
        );
    }

    /** ============== Endpoint integration — every write tool ============== */

    public function test_get_element_accepts_rel_path(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = '/children/1'; // image

        $resp = $controller->get_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        self::assertSame('image', $data['element_type']);
        // Pointer in the response is the canonical fully-qualified form.
        self::assertSame('/templates/tpl/layout/children/1', $data['path']);
    }

    public function test_add_element_accepts_rel_path_parent(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req->set_param('parent_path', '/children/0'); // append into section
        $req->set_param('element_type', 'divider');
        $req->set_param('props', []);

        $resp = $controller->add_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        // Section originally had 1 child (headline); now should have 2.
        self::assertCount(2, $stored['layout']['children'][0]['children']);
        self::assertSame('divider', $stored['layout']['children'][0]['children'][1]['type']);
    }

    public function test_update_settings_accepts_rel_path(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('PUT');
        $req['template_id'] = 'tpl';
        $req['element_path'] = '/children/1'; // image
        $req->set_param('props', ['source' => 'dog.jpg']);

        $resp = $controller->update_settings($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertSame('dog.jpg', $stored['layout']['children'][1]['props']['source']);
    }

    public function test_delete_element_accepts_rel_path(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('DELETE');
        $req['template_id'] = 'tpl';
        $req['element_path'] = '/children/1'; // image

        $resp = $controller->delete_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertCount(1, $stored['layout']['children']);
    }

    public function test_move_element_accepts_rel_path_for_both_source_and_target(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = '/children/0/move'; // section, trailing suffix
        $req->set_param('to_parent_path', '/'); // root of layout via rel_path
        $req->set_param('to_index', 2);

        $resp = $controller->move_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        // After move: image at 0, section at 1.
        self::assertSame('image', $stored['layout']['children'][0]['type']);
        self::assertSame('section', $stored['layout']['children'][1]['type']);
    }

    public function test_clone_element_accepts_rel_path(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = '/children/1/clone'; // image

        $resp = $controller->clone_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertCount(3, $stored['layout']['children']);
    }

    /**
     * Cross-template defense — when an attacker rewrites the rel_path into a
     * different template_id ("/templates/other/..."), the normalizer keeps the
     * inbound prefix verbatim and assertPointerWithinTemplate rejects it.
     */
    public function test_cross_template_write_still_denied_after_normalization(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('DELETE');
        $req['template_id'] = 'tpl';
        // Crafted: pretend rel_path while actually addressing a foreign template.
        $req['element_path'] = '/templates/other/layout/children/0';

        $resp = $controller->delete_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        $data = $resp->get_error_data();
        self::assertIsArray($data);
        self::assertSame(400, $data['status']);
    }

    /** ============== Wave 1.5 B6a — double-prefix detection ============== */

    /**
     * Audit B6a: agents that concatenate rel_path AND fully-qualified
     * forms in the same parameter create a duplicated `/templates/<id>/
     * layout` prefix. Before this fix, that input silently 404'd at the
     * layout walker (the inner `templates` token is a literal child key,
     * not a template selector). The normalizer now leaves the verbatim
     * pointer intact, and `assertPointerWithinTemplate` flags the
     * duplicated prefix with a structured 400.
     */
    public function test_delete_rejects_double_prefix_pointer_with_400(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('DELETE');
        $req['template_id'] = 'tpl';
        $req['element_path'] = '/templates/tpl/layout/templates/tpl/layout/children/0';

        $resp = $controller->delete_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.elements.double_prefix', $resp->get_error_code());
        $data = $resp->get_error_data();
        self::assertIsArray($data);
        self::assertSame(400, $data['status']);
    }

    public function test_get_element_rejects_double_prefix_pointer_with_400(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = '/templates/tpl/layout/templates/tpl/layout/children/0';

        $resp = $controller->get_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.elements.double_prefix', $resp->get_error_code());
        self::assertSame(400, $resp->get_error_data()['status']);
    }

    /** ============== Wave 1.5 B6d — RFC-6901 `..` literal segment ============== */

    /**
     * Audit B6d: RFC-6901 §3 specifies that `..` is a LITERAL two-character
     * segment with the name "..". JsonPointer::parse treats it as such; the
     * layout walker then looks for an array key/index named ".." in the
     * children list, finds nothing, and returns null → controller emits a
     * clean 404 not_found. We pin this deterministic behaviour so a future
     * refactor can't silently turn it into "parent-directory" semantics
     * (which would break path-containment guarantees).
     */
    public function test_normalize_preserves_dot_dot_segment_literally(): void
    {
        $cls = new class {
            use PointerControllerTrait;
            public static function normalize(string $p, ?string $t): string
            {
                return self::normalizeElementPath($p, $t);
            }
        };
        // Verbatim preservation — `..` is NOT collapsed to a parent step.
        self::assertSame(
            '/templates/tpl/layout/children/../something',
            $cls::normalize('/children/../something', 'tpl'),
        );
    }

    public function test_get_element_returns_404_for_dot_dot_segment_in_rel_path(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        // `..` is a literal RFC-6901 segment, NOT a parent traversal. The
        // walker looks for a child key `..` in the layout root, finds none.
        $req['element_path'] = '/children/../something';

        $resp = $controller->get_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.elements.not_found', $resp->get_error_code());
        self::assertSame(404, $resp->get_error_data()['status']);
    }

    /** ============== Wave 1.5 B6b — SourcesController rel_path acceptance ============== */

    private function sourcesController(): SourcesController
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

    public function test_sources_get_binding_accepts_rel_path(): void
    {
        // Setup: bind a source on the image first via direct state.
        $GLOBALS['ytb_test_options']['yootheme']['templates']['tpl']['layout']['children'][1]['props']['source'] = [
            'query' => ['name' => 'posts.singlePost'],
        ];

        $controller = $this->sourcesController();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        // rel_path form copied from element_list — no /templates/<id>/layout prefix.
        $req['element_path'] = '/children/1/binding';

        $resp = $controller->get_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        self::assertSame('posts.singlePost', $data['source_name']);
        self::assertTrue($data['has_binding']);
    }

    public function test_sources_put_binding_accepts_rel_path(): void
    {
        $controller = $this->sourcesController();
        $req = $this->writeRequest('PUT');
        $req['template_id'] = 'tpl';
        $req['element_path'] = '/children/1/binding';
        $req->set_param('source_name', 'wp.posts');

        $resp = $controller->put_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        $source = $stored['layout']['children'][1]['props']['source'];
        self::assertIsArray($source);
        self::assertSame('wp.posts', $source['query']['name']);
    }

    public function test_sources_delete_binding_accepts_rel_path(): void
    {
        // Seed an existing binding so DELETE has something to clear.
        $GLOBALS['ytb_test_options']['yootheme']['templates']['tpl']['layout']['children'][1]['props']['source'] = [
            'query' => ['name' => 'will_be_removed'],
        ];

        $controller = $this->sourcesController();
        $req = $this->writeRequest('DELETE');
        $req['template_id'] = 'tpl';
        $req['element_path'] = '/children/1/binding';

        $resp = $controller->delete_binding($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        // Source removed.
        self::assertArrayNotHasKey('source', $stored['layout']['children'][1]['props']);
    }

    /** ============== Wave 1.5 B6c — MultiItemsController rel_path acceptance ============== */

    private function multiItemsControllerOnGridFixture(): MultiItemsController
    {
        // Replace the fixture so a grid container with a grid_item child
        // exists at /children/0. The default fixture used by ElementsController
        // tests has section/image at the root; the multi-items routes need
        // a recognised container+item pair.
        $GLOBALS['ytb_test_options'] = [
            'yootheme' => [
                'templates' => [
                    'tpl' => [
                        'layout' => [
                            'type' => 'layout',
                            'children' => [
                                [
                                    'type' => 'grid',
                                    'props' => [
                                        'source' => [
                                            'query' => ['name' => 'wp.posts'],
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
                                        ['type' => 'grid_item', 'props' => []],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
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

    public function test_multi_items_inspect_accepts_rel_path(): void
    {
        $controller = $this->multiItemsControllerOnGridFixture();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        // rel_path form — no /templates/<id>/layout prefix; suffix kept for
        // pointerFromRequest's strip step.
        $req['element_path'] = '/children/0/multi-items/inspect';

        $resp = $controller->inspect($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $report = $resp->get_data()['report'];
        self::assertSame('grid', $report['element_type']);
        self::assertTrue($report['is_container']);
    }

    public function test_multi_items_clean_implode_accepts_rel_path(): void
    {
        $controller = $this->multiItemsControllerOnGridFixture();
        $req = new \WP_REST_Request('POST', '/');
        $req->set_header('If-Match', (new LayoutReader())->etag());
        $req['template_id'] = 'tpl';
        $req['element_path'] = '/children/0/multi-items/clean-implode';

        $resp = $controller->clean_implode($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        self::assertSame(1, $resp->get_data()['cleaned_count']);

        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertArrayNotHasKey(
            'implode',
            $stored['layout']['children'][0]['props']['source']['props']['title'],
        );
    }

    /** ============== Wave 1.6 Audit-D-Gap — double-prefix on remaining endpoints ============== */

    /**
     * Audit-D-Gap: Wave-1.5 added double-prefix detection on element_get +
     * element_delete only. The defense lives in PointerControllerTrait::
     * assertPointerWithinTemplate, but Sources + MultiItems controllers
     * each had their OWN inline cross-template guard that did NOT detect
     * double-prefix. Wave-1.6 switches both to the shared trait method
     * (with a controller-specific $errorPrefix so error codes stay in the
     * canonical namespace), and the remaining Elements write endpoints
     * already routed through the trait — we just had no pins on them.
     *
     * These tests pin the structural coverage: every endpoint that accepts
     * an `element_path` / `parent_path` / `to_parent_path` parameter must
     * reject the double-prefix pointer with a structured 400 carrying its
     * controller-namespaced `*.double_prefix` code.
     */
    private const DOUBLE_PREFIX = '/templates/tpl/layout/templates/tpl/layout/children/0';

    public function test_update_settings_rejects_double_prefix_pointer_with_400(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('PUT');
        $req['template_id'] = 'tpl';
        $req['element_path'] = self::DOUBLE_PREFIX;
        $req->set_param('props', ['content' => 'irrelevant']);

        $resp = $controller->update_settings($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.elements.double_prefix', $resp->get_error_code());
        self::assertSame(400, $resp->get_error_data()['status']);
    }

    public function test_add_element_rejects_double_prefix_parent_path_with_400(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req->set_header('If-Match', (new LayoutReader())->etag());
        $req['template_id'] = 'tpl';
        // parent_path is the field that gets the assertion in add_element.
        $req->set_param('parent_path', self::DOUBLE_PREFIX);
        $req->set_param('element_type', 'divider');
        $req->set_param('props', []);

        $resp = $controller->add_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.elements.double_prefix', $resp->get_error_code());
        self::assertSame(400, $resp->get_error_data()['status']);
    }

    public function test_move_element_rejects_double_prefix_pointer_with_400(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('POST');
        $req['template_id'] = 'tpl';
        $req['element_path'] = self::DOUBLE_PREFIX . '/move';
        $req->set_param('to_parent_path', '/');
        $req->set_param('to_index', 0);

        $resp = $controller->move_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.elements.double_prefix', $resp->get_error_code());
        self::assertSame(400, $resp->get_error_data()['status']);
    }

    public function test_move_element_rejects_double_prefix_to_parent_path_with_400(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('POST');
        $req['template_id'] = 'tpl';
        // element_path itself is clean (rel_path form) — the malicious
        // pointer lives in to_parent_path so we cover both assertion sites.
        $req['element_path'] = '/children/0/move';
        $req->set_param('to_parent_path', self::DOUBLE_PREFIX);
        $req->set_param('to_index', 0);

        $resp = $controller->move_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.elements.double_prefix', $resp->get_error_code());
        self::assertSame(400, $resp->get_error_data()['status']);
    }

    public function test_clone_element_rejects_double_prefix_pointer_with_400(): void
    {
        $controller = $this->controller();
        $req = $this->writeRequest('POST');
        $req['template_id'] = 'tpl';
        $req['element_path'] = self::DOUBLE_PREFIX . '/clone';

        $resp = $controller->clone_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.elements.double_prefix', $resp->get_error_code());
        self::assertSame(400, $resp->get_error_data()['status']);
    }

    public function test_sources_get_binding_rejects_double_prefix_pointer_with_400(): void
    {
        $controller = $this->sourcesController();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = self::DOUBLE_PREFIX . '/binding';

        $resp = $controller->get_binding($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.source_binding.double_prefix', $resp->get_error_code());
        self::assertSame(400, $resp->get_error_data()['status']);
    }

    public function test_sources_put_binding_rejects_double_prefix_pointer_with_400(): void
    {
        $controller = $this->sourcesController();
        $req = $this->writeRequest('PUT');
        $req['template_id'] = 'tpl';
        $req['element_path'] = self::DOUBLE_PREFIX . '/binding';
        $req->set_param('source_name', 'wp.posts');

        $resp = $controller->put_binding($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.source_binding.double_prefix', $resp->get_error_code());
        self::assertSame(400, $resp->get_error_data()['status']);
    }

    public function test_sources_delete_binding_rejects_double_prefix_pointer_with_400(): void
    {
        $controller = $this->sourcesController();
        $req = $this->writeRequest('DELETE');
        $req['template_id'] = 'tpl';
        $req['element_path'] = self::DOUBLE_PREFIX . '/binding';

        $resp = $controller->delete_binding($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.source_binding.double_prefix', $resp->get_error_code());
        self::assertSame(400, $resp->get_error_data()['status']);
    }

    public function test_multi_items_inspect_rejects_double_prefix_pointer_with_400(): void
    {
        $controller = $this->multiItemsControllerOnGridFixture();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = self::DOUBLE_PREFIX . '/multi-items/inspect';

        $resp = $controller->inspect($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.multi_items.double_prefix', $resp->get_error_code());
        self::assertSame(400, $resp->get_error_data()['status']);
    }

    public function test_multi_items_clean_implode_rejects_double_prefix_pointer_with_400(): void
    {
        $controller = $this->multiItemsControllerOnGridFixture();
        $req = new \WP_REST_Request('POST', '/');
        $req->set_header('If-Match', (new LayoutReader())->etag());
        $req['template_id'] = 'tpl';
        $req['element_path'] = self::DOUBLE_PREFIX . '/multi-items/clean-implode';

        $resp = $controller->clean_implode($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.multi_items.double_prefix', $resp->get_error_code());
        self::assertSame(400, $resp->get_error_data()['status']);
    }

    /**
     * 1.0.1 Wave-1.7 audit-F F-SEC-2 — defense-in-depth pin against URL-
     * encoded slash smuggling. WP REST routing percent-decodes the path
     * before regex match, so a typical `%2F` body should never reach the
     * controller as a literal. But: nginx/Apache rewrite-rule drift can
     * change that, and an attacker can also try to slip `%2F` past in a
     * body-only field (parent_path, to_parent_path) which bypasses the
     * route-regex decode. If a `%2F`-laden pointer DOES reach the
     * controller, `isWithinPrefix` (segment-equality compare) still
     * rejects it because segment[0] becomes the literal string
     * `%2Ftemplates` — not equal to `templates`. Pin the structured 400.
     */
    public function test_get_element_rejects_percent_encoded_cross_template_pointer(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        // `%2F` literal — simulates a payload that escaped percent-decoding.
        $req['element_path'] = '%2Ftemplates%2Fother%2Flayout%2Fchildren%2F0';

        $resp = $controller->get_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame(
            'yootheme_builder_mcp.elements.cross_template_write_denied',
            $resp->get_error_code(),
        );
        self::assertSame(400, $resp->get_error_data()['status']);
    }

    /**
     * 1.0.1 Wave-1.8 F-COLD-19: cold-agent S3 (multi-items binding) was
     * confused by two `source` keys in element_get with diverging
     * values — `props.source` (authoritative binding the renderer reads)
     * vs a stale `element.source` denormalized cache. Pin the additive
     * `_authoritative_source` pointer so future refactors can't drop the
     * hint without surfacing the legacy/canonical relationship some
     * other way.
     */
    public function test_get_element_surfaces_authoritative_source_pointer_when_bound(): void
    {
        // Seed a binding on the image so the pointer is meaningful —
        // Wave-1.8 audit-pass v2 conditionalized the field to only fire
        // when has_binding=true (no point disambiguating an absent
        // legacy field).
        $GLOBALS['ytb_test_options']['yootheme']['templates']['tpl']['layout']['children'][1]['props']['source'] = [
            'query' => ['name' => 'posts.singlePost'],
        ];

        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = '/children/1'; // image (now bound)

        $resp = $controller->get_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        self::assertTrue($data['has_binding']);
        self::assertArrayHasKey('_authoritative_source', $data);
        self::assertSame('props.source', $data['_authoritative_source']);
    }

    public function test_get_element_omits_authoritative_source_when_not_bound(): void
    {
        // Wave-1.8 audit-pass v2: on bindings-free elements the legacy
        // element.source / source_extended keys are absent too, so the
        // pointer-key would just be noise. Pin the slim response.
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = '/children/1'; // image, no binding

        $resp = $controller->get_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        self::assertFalse($data['has_binding']);
        self::assertArrayNotHasKey('_authoritative_source', $data);
    }

    /**
     * 1.0.1 Wave-1.8 F-COLD-9 / F-COLD-18: 404 not-found responses
     * carry a `hint` field diagnosing the two most common shape
     * mistakes — percent-encoded slashes and missing templates/<id>/
     * prefix. Pin both branches so the hint can't silently regress.
     */
    public function test_get_element_404_hint_diagnoses_percent_encoded_path(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        // Percent-encoded slash — passes the cross-template guard
        // (literal `%2Ftemplates` is not within `/templates/tpl`),
        // hits 400 cross_template_write_denied before reaching the 404
        // path. So this scenario tests the OTHER branch: a literal-`%2F`
        // path INSIDE the right prefix that fails to resolve.
        $req['element_path'] = '/templates/tpl/layout/children%2F0';

        $resp = $controller->get_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.elements.not_found', $resp->get_error_code());
        $data = $resp->get_error_data();
        self::assertIsArray($data);
        self::assertArrayHasKey('hint', $data);
        self::assertStringContainsString('LITERAL slashes', $data['hint']);
        self::assertStringContainsString('%2F', $data['hint']);
    }

    public function test_get_element_404_hint_falls_back_to_generic_when_pointer_matches_prefix(): void
    {
        // Wave-1.8 audit A1+A3 follow-up: the original test was named
        // `_diagnoses_missing_template_prefix` but actually exercises
        // the GENERIC fallback branch (pointer starts with the right
        // `/templates/<id>/` prefix; the missing-prefix branch is
        // structurally unreachable in production because the upstream
        // cross-template guard catches every pointer that doesn't
        // match — `isWithinPrefix('/foo/bar', '/templates/tpl')` is
        // false → cross_template_write_denied 400, never reaching the
        // 404 path. We keep the missing-prefix branch as defense-in-
        // depth and pin the actually-reachable generic-fallback path
        // here.
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = '/templates/tpl/layout/children/99';

        $resp = $controller->get_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('yootheme_builder_mcp.elements.not_found', $resp->get_error_code());
        $data = $resp->get_error_data();
        self::assertIsArray($data);
        self::assertArrayHasKey('hint', $data);
        // Generic hint path — pointer starts with the right prefix.
        self::assertStringContainsString('element_list', $data['hint']);
    }

    /**
     * Wave-1.8 audit A1+A3 follow-up: pin that a pointer escaping the
     * template's prefix is caught by the cross-template guard FIRST
     * (returns 400 cross_template_write_denied), not the 404+hint path.
     * Documents why pathHintFor's prefix-missing branch is structurally
     * unreachable — the upstream guard is the load-bearing defense.
     */
    public function test_get_element_with_wrong_template_prefix_is_caught_upstream(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';
        // `/foo/bar` would be the canonical "prefix-missing" shape, but
        // normalizeElementPath sees it as neither a /templates path nor
        // a /children rel_path; falls through to the bare leading-slash
        // form. assertPointerWithinTemplate then catches it.
        $req['element_path'] = '/foo/bar';

        $resp = $controller->get_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        // The cross-template guard intercepts BEFORE the 404 path,
        // so we see the 400 cross_template_write_denied code, not the
        // 404 not_found with the prefix-missing hint.
        self::assertSame(
            'yootheme_builder_mcp.elements.cross_template_write_denied',
            $resp->get_error_code(),
        );
    }
}
