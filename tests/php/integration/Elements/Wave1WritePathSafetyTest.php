<?php
/**
 * Wave-1 write-path safety contract — failing-first tests for the
 * five findings (C-2, C-3, H-10, H-11, H-12) on the WP-side
 * ElementsController.
 *
 * Each test is a one-defect pin that fails on the pre-fix tree and
 * passes after the controller / EtagMiddleware fix lands. Mirrors the
 * Joomla-side test in
 * tests/php/unit/Platform/Joomla/Wave1WritePathSafetyJoomlaTest.php.
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
use WootsUp\BuilderMcp\State\StateLockInterface;
use WootsUp\BuilderMcp\Tests\TestVerifierFactory;

#[CoversClass(ElementsController::class)]
#[CoversClass(EtagMiddleware::class)]
final class Wave1WritePathSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        // Seeded layout matches WriteOpsTest so behaviour-comparisons stay
        // 1:1. tpl has section[+headline child] + image at the top level.
        // Plus a `grid` container at index 2 (used by the H-11 parent/child
        // compatibility tests).
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
                                // For H-11: a multi-item container.
                                ['type' => 'grid', 'props' => [], 'children' => []],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $GLOBALS['ytb_test_cache_delete_calls'] = [];
    }

    private function controller(?StateLockInterface $lock = null): ElementsController
    {
        $reader = new LayoutReader();
        $writer = new LayoutWriter($reader, null, $lock);
        $ops = new ElementOps($reader);
        $flusher = new CacheFlusher();
        return new ElementsController($ops, $reader, $writer, $flusher, TestVerifierFactory::verifier());
    }

    // ------------------------------------------------------------------
    // C-2 — add_element requires If-Match (428 when missing).
    // ------------------------------------------------------------------

    public function test_C2_add_element_returns_428_when_if_match_missing(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req->set_param('parent_path', '');
        $req->set_param('element_type', 'divider');
        // No If-Match header set.

        $resp = $controller->add_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        self::assertSame('yootheme_builder_mcp.if_match_required', $resp->get_error_code());
        $data = $resp->get_error_data();
        self::assertSame(428, $data['status']);
        // The 428 must carry the current ETag so the client can immediately
        // retry without an extra read round-trip.
        self::assertArrayHasKey('current_etag', $data);
    }

    public function test_C2_add_element_proceeds_with_valid_if_match(): void
    {
        // Pin the inverse: a valid If-Match still lets the add proceed.
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req->set_header('If-Match', (new LayoutReader())->etag());
        $req->set_param('parent_path', '');
        $req->set_param('element_type', 'divider');

        $resp = $controller->add_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        self::assertSame(200, $resp->get_status());
    }

    public function test_C2_add_element_accepts_wildcard_if_match(): void
    {
        // RFC-7232 §3.1 `If-Match: *` matches any existing resource — keep
        // the wildcard escape-hatch so power-users can opt out of the lock.
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req->set_header('If-Match', '*');
        $req->set_param('parent_path', '');
        $req->set_param('element_type', 'divider');

        $resp = $controller->add_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        self::assertSame(200, $resp->get_status());
    }

    // ------------------------------------------------------------------
    // C-3 — delete_element preview/confirm two-call protocol.
    // ------------------------------------------------------------------

    public function test_C3_delete_without_confirm_returns_preview_without_mutating(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('DELETE', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1'; // image
        $req->set_header('If-Match', (new LayoutReader())->etag());
        // No `confirm` parameter — must return preview only.

        $resp = $controller->delete_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        self::assertTrue($data['requires_confirm'] ?? false);
        self::assertArrayHasKey('preview', $data);
        self::assertSame('/templates/tpl/layout/children/1', $data['preview']['element_path']);
        self::assertSame('image', $data['preview']['element_type']);
        self::assertArrayHasKey('child_count', $data['preview']);

        // State MUST be unchanged.
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertCount(3, $stored['layout']['children']);
        self::assertSame('image', $stored['layout']['children'][1]['type']);
    }

    public function test_C3_delete_with_confirm_true_actually_deletes(): void
    {
        $controller = $this->controller();
        $req = new \WP_REST_Request('DELETE', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1';
        $req->set_header('If-Match', (new LayoutReader())->etag());
        $req->set_param('confirm', true);

        $resp = $controller->delete_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        self::assertArrayNotHasKey('requires_confirm', $data);
        self::assertArrayNotHasKey('preview', $data);
        self::assertSame('/templates/tpl/layout/children/1', $data['element_path']);

        // The image is gone, section+grid remain.
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertCount(2, $stored['layout']['children']);
        self::assertSame('section', $stored['layout']['children'][0]['type']);
        self::assertSame('grid', $stored['layout']['children'][1]['type']);
    }

    public function test_C3_delete_preview_surfaces_child_count_for_subtree(): void
    {
        // Deleting the section (which carries 1 headline child) must
        // surface child_count=1 so the caller knows the blast radius.
        $controller = $this->controller();
        $req = new \WP_REST_Request('DELETE', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0'; // section
        $req->set_header('If-Match', (new LayoutReader())->etag());

        $resp = $controller->delete_element($req);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        self::assertSame('section', $data['preview']['element_type']);
        self::assertSame(1, $data['preview']['child_count']);
    }

    // ------------------------------------------------------------------
    // H-10 — move_element returns the correct post-move element_path.
    // ------------------------------------------------------------------

    public function test_H10_move_into_different_parent_returns_target_parent_relative_path(): void
    {
        // Move the headline (at /templates/tpl/layout/children/0/children/0)
        // into the grid container (/templates/tpl/layout/children/2/children/0).
        // Bug: ops::move sets parent.children if missing — but the H-10
        // assertion pins that the returned path is *exactly*
        // `target_parent_path/children/N`, NOT a flat top-level index.
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/children/0/move';
        $req->set_header('If-Match', (new LayoutReader())->etag());
        $req->set_param('to_parent_path', '/templates/tpl/layout/children/2');
        $req->set_param('to_index', 0);

        /** @var \WP_REST_Response $resp */
        $resp = $controller->move_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        // Canonical: target_parent_path + '/children/' + N.
        self::assertSame(
            '/templates/tpl/layout/children/2/children/0',
            $data['element_path'],
        );

        // Cross-check: walk the stored state and verify the element is
        // actually at the returned path.
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertSame('headline', $stored['layout']['children'][2]['children'][0]['type']);
    }

    public function test_H10_move_same_parent_shift_returns_post_move_path(): void
    {
        // Regression pin for the harder same-parent case: moving element
        // 0 (section) to index 2 in the same parent. Removal shifts every
        // subsequent index down by 1, so the section lands at adjusted
        // index 1 — the response MUST reflect the post-shift path.
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/0/move'; // section
        $req->set_header('If-Match', (new LayoutReader())->etag());
        $req->set_param('to_parent_path', '/templates/tpl/layout');
        $req->set_param('to_index', 2);

        /** @var \WP_REST_Response $resp */
        $resp = $controller->move_element($req);
        $data = $resp->get_data();
        self::assertSame('/templates/tpl/layout/children/1', $data['element_path']);

        // Verify state: image is at 0, section at 1, grid at 2.
        $stored = (new LayoutReader())->readTemplate('tpl');
        self::assertNotNull($stored);
        self::assertSame('image', $stored['layout']['children'][0]['type']);
        self::assertSame('section', $stored['layout']['children'][1]['type']);
        self::assertSame('grid', $stored['layout']['children'][2]['type']);
    }

    // ------------------------------------------------------------------
    // H-11 — add_element validates parent/child container/item pairing.
    // ------------------------------------------------------------------

    public function test_H11_add_text_inside_grid_returns_400_with_required_item_type(): void
    {
        // Grid is a multi-item container — its direct children MUST be
        // `grid_item` (ItemContainerMap). Adding a bare `headline` (or any
        // non-item type) into a grid is a layout-corrupting request.
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req->set_header('If-Match', (new LayoutReader())->etag());
        $req->set_param('parent_path', '/templates/tpl/layout/children/2'); // grid
        $req->set_param('element_type', 'headline');

        $resp = $controller->add_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        self::assertSame('yootheme_builder_mcp.elements.invalid_parent_child', $resp->get_error_code());
        $data = $resp->get_error_data();
        self::assertSame(400, $data['status']);
        // Error data MUST point the caller at the canonical item type.
        self::assertSame('grid', $data['parent_type']);
        self::assertSame('grid_item', $data['expected_child_type']);
        self::assertSame('headline', $data['actual_child_type']);
        self::assertArrayHasKey('hint', $data);
    }

    public function test_H11_add_grid_item_inside_grid_proceeds(): void
    {
        // Pin the happy path: the canonical pairing IS accepted.
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req->set_header('If-Match', (new LayoutReader())->etag());
        $req->set_param('parent_path', '/templates/tpl/layout/children/2');
        $req->set_param('element_type', 'grid_item');

        $resp = $controller->add_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        self::assertSame(200, $resp->get_status());
    }

    public function test_H11_add_headline_into_section_proceeds(): void
    {
        // Section is NOT in the ItemContainerMap — so its children are
        // unrestricted. The H-11 rule MUST NOT regress this case.
        $controller = $this->controller();
        $req = new \WP_REST_Request('POST', '/');
        $req['template_id'] = 'tpl';
        $req->set_header('If-Match', (new LayoutReader())->etag());
        $req->set_param('parent_path', '/templates/tpl/layout/children/0'); // section
        $req->set_param('element_type', 'headline');
        $req->set_param('props', ['content' => 'New headline']);

        $resp = $controller->add_element($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        self::assertSame(200, $resp->get_status());
    }

    // ------------------------------------------------------------------
    // H-12 — concurrent-write lock contention → HTTP 409, not 500.
    // ------------------------------------------------------------------

    public function test_H12_lock_timeout_returns_409_not_500(): void
    {
        // Wire a always-fails StateLock so writeTemplate throws the
        // canonical lock-timeout RuntimeException synthesised by
        // StateLock::withTemplateLock. The controller MUST classify
        // this as 409 Conflict (concurrent-write), not 500 (write_failed).
        $lock = new class () implements StateLockInterface {
            public function acquireForTemplate(string $templateId, int $timeoutMs = 5000): bool
            {
                return false;
            }

            public function releaseForTemplate(string $templateId): void
            {
            }

            public function withTemplateLock(string $templateId, callable $callback, int $timeoutMs = 5000): mixed
            {
                throw new \RuntimeException(
                    sprintf('Could not acquire lock for template "%s" within %dms.', $templateId, $timeoutMs),
                );
            }
        };
        $controller = $this->controller($lock);

        $req = new \WP_REST_Request('DELETE', '/');
        $req['template_id'] = 'tpl';
        $req['element_path'] = 'templates/tpl/layout/children/1';
        $req->set_header('If-Match', (new LayoutReader())->etag());
        $req->set_param('confirm', true);

        $resp = $controller->delete_element($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        self::assertSame('yootheme_builder_mcp.concurrent_write_in_progress', $resp->get_error_code());
        $data = $resp->get_error_data();
        self::assertSame(409, $data['status']);
        // Caller-actionable retry hint.
        self::assertArrayHasKey('retry_after_ms', $data);
        self::assertGreaterThan(0, $data['retry_after_ms']);
    }
}
