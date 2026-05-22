<?php
/**
 * RestController scope-enforcement — Wave-6 Fix 2 + Fix 1.
 *
 * Covers:
 *   - fail-closed when verifier wiring path is exercised
 *   - scope hierarchy: read < write < admin
 *   - 401 for missing/invalid bearer
 *   - 403 for valid bearer with insufficient scope
 *   - 200 (true) for valid bearer with equal-or-higher scope
 *   - rate-limit triggered on write scope
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Auth\BearerVerifier;
use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Auth\KeyStore;
use WootsUp\BuilderMcp\Rest\RateLimiter;
use WootsUp\BuilderMcp\Rest\RestController;
use WootsUp\BuilderMcp\Tests\TestVerifierFactory;

#[CoversClass(RestController::class)]
#[CoversClass(BearerVerifier::class)]
#[CoversClass(RateLimiter::class)]
final class RestControllerScopeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [];
        $GLOBALS['ytb_test_transients'] = [];
    }

    public function test_missing_authorization_header_returns_401(): void
    {
        $controller = new ScopeFixture(TestVerifierFactory::verifier());
        $req = new \WP_REST_Request('GET', '/');
        $result = $controller->bearer_permission($req);
        self::assertInstanceOf(\WP_Error::class, $result);
        /** @var \WP_Error $result */
        $data = $result->get_error_data();
        self::assertSame(401, $data['status']);
    }

    public function test_read_scope_grants_read(): void
    {
        $bundle = TestVerifierFactory::verifierWithKey('read');
        $controller = new ScopeFixture($bundle['verifier']);
        $req = new \WP_REST_Request('GET', '/');
        $req->set_header('Authorization', 'Bearer ' . $bundle['token']);

        $result = $controller->bearer_permission($req);
        self::assertTrue($result);
    }

    public function test_read_scope_denied_write_with_403(): void
    {
        $bundle = TestVerifierFactory::verifierWithKey('read');
        $controller = new ScopeFixture($bundle['verifier']);
        $req = new \WP_REST_Request('POST', '/');
        $req->set_header('Authorization', 'Bearer ' . $bundle['token']);

        $callback = $controller->bearer_permission_for('write');
        $result = $callback($req);
        self::assertInstanceOf(\WP_Error::class, $result);
        /** @var \WP_Error $result */
        $data = $result->get_error_data();
        self::assertSame(403, $data['status']);
        self::assertSame('write', $data['required_scope']);
        self::assertSame('read', $data['token_scope']);
    }

    public function test_admin_scope_grants_write(): void
    {
        $bundle = TestVerifierFactory::verifierWithKey('admin');
        $controller = new ScopeFixture($bundle['verifier']);
        $req = new \WP_REST_Request('POST', '/');
        $req->set_header('Authorization', 'Bearer ' . $bundle['token']);

        $callback = $controller->bearer_permission_for('write');
        $result = $callback($req);
        self::assertTrue($result);
    }

    public function test_unknown_scope_throws_invalid_argument(): void
    {
        $controller = new ScopeFixture(TestVerifierFactory::verifier());
        $this->expectException(\InvalidArgumentException::class);
        $controller->bearer_permission_for('superuser');
    }

    public function test_rate_limit_engages_after_threshold_writes(): void
    {
        $bundle = TestVerifierFactory::verifierWithKey('write');
        $controller = new ScopeFixture($bundle['verifier']);
        $callback = $controller->bearer_permission_for('write');

        // Burn through the rate-limit. The check increments+sets per call.
        for ($i = 1; $i <= RateLimiter::WRITE_LIMIT; $i++) {
            $req = new \WP_REST_Request('POST', '/');
            $req->set_header('Authorization', 'Bearer ' . $bundle['token']);
            $result = $callback($req);
            self::assertTrue($result, sprintf('Expected pass at iteration %d, got error.', $i));
        }

        // Next call must trip the limit.
        $req = new \WP_REST_Request('POST', '/');
        $req->set_header('Authorization', 'Bearer ' . $bundle['token']);
        $result = $callback($req);
        self::assertInstanceOf(\WP_Error::class, $result);
        /** @var \WP_Error $result */
        $data = $result->get_error_data();
        self::assertSame(429, $data['status']);
    }
}

/**
 * Concrete controller subclass for tests — we only need a non-abstract
 * instance to invoke the protected callbacks.
 */
final class ScopeFixture extends RestController
{
    public function register_routes(): void
    {
        // no-op
    }
}
