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
        // ETag is deterministic on identical content; what matters is that
        // the response carries the *current* ETag.
        self::assertSame((new LayoutReader())->etag(), $data['etag']);
        // Without YT loaded, save-transforms are identity → etag unchanged.
        self::assertSame($before, $data['etag']);
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
}
