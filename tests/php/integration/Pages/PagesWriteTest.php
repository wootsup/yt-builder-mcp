<?php
/**
 * PagesController save/publish endpoints — end-to-end behavioural test.
 *
 * Wave 3 Task 3.7.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Integration\Pages;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Cache\CacheFlusher;
use WootsUp\BuilderMcp\Pages\PageQuery;
use WootsUp\BuilderMcp\Pages\PagesController;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;
use WootsUp\BuilderMcp\Tests\TestVerifierFactory;

#[CoversClass(PagesController::class)]
final class PagesWriteTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [
            'yootheme' => [
                'templates' => [
                    'tpl' => [
                        'name' => 'Home',
                        'layout' => ['type' => 'layout', 'children' => []],
                    ],
                ],
            ],
        ];
    }

    private function controller(): PagesController
    {
        $reader = new LayoutReader();
        return new PagesController(
            new PageQuery($reader),
            $reader,
            new LayoutWriter($reader),
            new CacheFlusher(),
            TestVerifierFactory::verifier(),
        );
    }

    public function test_save_page_returns_new_etag(): void
    {
        $before = (new LayoutReader())->etag();
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';

        $resp = $controller->save_page($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        self::assertTrue($data['saved']);
        self::assertArrayHasKey('etag', $data);
        // The response carries the *current* (post-mutation) ETag.
        self::assertSame((new LayoutReader())->etag(), $data['etag']);
        // F-07 fix (Maria-Audit 2026-05-22): every save bumps the
        // monotonic revision counter inside LayoutWriter::persist(), so
        // the ETag MUST differ from `before` even though the underlying
        // state happens to byte-equal (identity save-transforms without YT).
        self::assertNotSame($before, $data['etag']);
    }

    public function test_save_page_404_for_unknown_template(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'nope';

        $resp = $controller->save_page($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        $data = $resp->get_error_data();
        self::assertSame(404, $data['status']);
    }

    public function test_save_page_412_on_etag_mismatch(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req->set_header('If-Match', 'stale');

        $resp = $controller->save_page($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        $data = $resp->get_error_data();
        self::assertSame(412, $data['status']);
    }

    public function test_publish_page_returns_published_flag(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';

        $resp = $controller->publish_page($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        self::assertTrue($data['saved']);
        self::assertTrue($data['published']);
    }

    /**
     * F-10 fix (Maria-Audit 2026-05-22): GET /etag must carry a
     * `generated_at` ISO-8601 timestamp so callers can distinguish a
     * fresh server probe from a stale cached response.
     */
    public function test_get_etag_carries_generated_at_iso_timestamp(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        /** @var \WP_REST_Response $resp */
        $resp = $controller->get_etag($req);
        $data = $resp->get_data();

        self::assertArrayHasKey('etag', $data);
        self::assertArrayHasKey('generated_at', $data);
        // RFC-3339 / ISO-8601 with explicit zone offset (`+00:00` or `Z`).
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/',
            $data['generated_at'],
        );
    }

    /**
     * F-15 fix (Maria-Audit 2026-05-22): page_publish must surface a
     * persisted "published-state etag" snapshot so callers can diff
     * draft-vs-published, plus an explanatory `note` (YT Pro templates
     * publish-on-save — this is a cache-flush + state-snapshot op).
     */
    public function test_publish_page_persists_published_state_etag_and_note(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';

        /** @var \WP_REST_Response $resp */
        $resp = $controller->publish_page($req);
        $data = $resp->get_data();

        self::assertTrue($data['published']);
        self::assertArrayHasKey('published_state_etag', $data);
        self::assertSame($data['etag'], $data['published_state_etag']);
        self::assertArrayHasKey('note', $data);

        // The published_state_etag must persist to wp_option for diffing.
        $persisted = $GLOBALS['ytb_test_options']['ytb_mcp_published_state_etag'] ?? null;
        self::assertSame($data['etag'], $persisted);
    }

    /**
     * 1.0.1 Wave-1.7 F-COLD-3: cold-agent S2 probe (2026-05-23) showed
     * agents building `/templates/<id>/layout/layout/children/...` from
     * the get_layout payload because the response `layout` field carries
     * the WHOLE template object (which itself has a `layout` child). Pin
     * the additive `layout_root_pointer` + `pointer_hint` fields so that
     * agents have a copy-pasteable canonical pointer base directly in
     * the response, no archaeology required.
     */
    public function test_get_layout_surfaces_canonical_pointer_base(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'tpl';

        /** @var \WP_REST_Response $resp */
        $resp = $controller->get_layout($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();

        self::assertArrayHasKey('layout_root_pointer', $data);
        self::assertSame('/templates/tpl/layout', $data['layout_root_pointer']);
        self::assertArrayHasKey('pointer_hint', $data);
        self::assertStringContainsString('/templates/tpl/layout', $data['pointer_hint']);
        self::assertStringContainsString('rel_path', $data['pointer_hint']);
    }

    /**
     * 1.0.1 Wave-1.7 F-COLD-3 — same canonical base with RFC-6901-encoded
     * template-ids. Defends against a future template-id containing `/`
     * or `~` (route regex currently rejects, but `JsonPointer::compile`
     * gives correct encoding for free if the regex is ever relaxed).
     */
    public function test_get_layout_pointer_base_handles_rfc6901_template_id(): void
    {
        $GLOBALS['ytb_test_options']['yootheme']['templates']['has~tilde'] = [
            'name' => 'Tilde',
            'layout' => ['type' => 'layout', 'children' => []],
        ];

        $controller = $this->controller();
        $req = new \WP_REST_Request('GET', '/');
        $req['template_id'] = 'has~tilde';

        /** @var \WP_REST_Response $resp */
        $resp = $controller->get_layout($req);
        // RFC-6901 §3: `~` encodes as `~0`.
        self::assertSame('/templates/has~0tilde/layout', $resp->get_data()['layout_root_pointer']);
    }
}
