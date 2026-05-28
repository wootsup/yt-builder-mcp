<?php
/**
 * Wave-1 Fix C-4 — Rate-limit 429 response shape contract.
 *
 * Pins the customer-facing 429 contract emitted by RateLimiter::checkWrite
 * and surfaced via the REST permission_callback when a write-scope bearer
 * exceeds 60 writes/60s. Three things MUST be on the response so MCP
 * clients can render a useful retry-toast:
 *
 *  1. `data.error` MUST equal the well-known token `rate_limit_exceeded`
 *     (mirror of OAuth 2.0 / RFC-6585 problem-token style).
 *  2. `data.retry_after_seconds` MUST carry the wait hint in seconds.
 *  3. `data.scope` MUST equal `kid` (the bucket dimension — per-key, not
 *     per-IP, not global).
 *
 * Companion test pins the `Retry-After` HTTP header injection
 * ({@see RateLimitHeadersFilterTest}) and the AuditLogger event-name
 * ({@see RateLimitAuditEventTest}).
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Rest\RateLimiter;
use WootsUp\BuilderMcp\Rest\RestController;
use WootsUp\BuilderMcp\Tests\TestVerifierFactory;

#[CoversClass(RateLimiter::class)]
#[CoversClass(RestController::class)]
final class RateLimit429ResponseShapeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [];
        $GLOBALS['ytb_test_transients'] = [];
    }

    public function test_429_data_carries_well_known_rate_limit_exceeded_error_token(): void
    {
        $err = $this->burnThroughLimitAndCaptureError();
        $data = $err->get_error_data();
        self::assertIsArray($data);
        self::assertArrayHasKey('error', $data);
        self::assertSame(
            'rate_limit_exceeded',
            $data['error'],
            'Wave-1 C-4: data.error must equal "rate_limit_exceeded" — MCP clients pivot on this token.',
        );
    }

    public function test_429_data_carries_retry_after_seconds_field(): void
    {
        $err = $this->burnThroughLimitAndCaptureError();
        $data = $err->get_error_data();
        self::assertIsArray($data);
        self::assertArrayHasKey('retry_after_seconds', $data);
        self::assertSame(
            RateLimiter::WINDOW_SECONDS,
            $data['retry_after_seconds'],
            'retry_after_seconds must equal the window length (60s).',
        );
    }

    public function test_429_data_carries_scope_kid_field(): void
    {
        $err = $this->burnThroughLimitAndCaptureError();
        $data = $err->get_error_data();
        self::assertIsArray($data);
        self::assertArrayHasKey('scope', $data);
        self::assertSame(
            'kid',
            $data['scope'],
            'scope must equal "kid" — disambiguates per-key rate-limit from per-IP / global.',
        );
    }

    public function test_429_status_remains_429(): void
    {
        // Sanity pin — back-compat: WP REST surfaces status from data.status.
        $err = $this->burnThroughLimitAndCaptureError();
        $data = $err->get_error_data();
        self::assertIsArray($data);
        self::assertSame(429, $data['status']);
    }

    /**
     * Drive the permission_callback past the write-limit and return the
     * WP_Error from the very first rejected call.
     */
    private function burnThroughLimitAndCaptureError(): \WP_Error
    {
        $bundle = TestVerifierFactory::verifierWithKey('write');
        $controller = new ScopeFixture($bundle['verifier']);
        $callback = $controller->bearer_permission_for('write');

        for ($i = 1; $i <= RateLimiter::WRITE_LIMIT; $i++) {
            $req = new \WP_REST_Request('POST', '/');
            $req->set_header('Authorization', 'Bearer ' . $bundle['token']);
            $result = $callback($req);
            self::assertTrue($result, sprintf('Iteration %d must still be within budget.', $i));
        }

        $req = new \WP_REST_Request('POST', '/');
        $req->set_header('Authorization', 'Bearer ' . $bundle['token']);
        $result = $callback($req);

        self::assertInstanceOf(\WP_Error::class, $result);
        /** @var \WP_Error $result */
        return $result;
    }
}
