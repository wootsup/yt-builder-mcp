<?php
/**
 * Wave-1 Fix C-4 — `Retry-After` header on 429 responses.
 *
 * Pins the HTTP-header contract: every 429 response in the
 * `yt-builder-mcp/v1` namespace MUST carry a `Retry-After` header. HTTP/1.1
 * §7.1.3 says the value is either an HTTP-date OR a delta-seconds integer;
 * we emit the integer form (matches `data.retry_after_seconds`).
 *
 * Outside our namespace the filter is a no-op (no header is injected,
 * other plugins' 429s are not our concern). Non-429 statuses (200, 401,
 * 403, 412 …) are no-op.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Rest\RateLimitHeadersFilter;
use WootsUp\BuilderMcp\Rest\RateLimiter;

#[CoversClass(RateLimitHeadersFilter::class)]
final class RateLimitHeadersFilterTest extends TestCase
{
    public function test_429_in_namespace_sets_retry_after_header(): void
    {
        $response = new \WP_REST_Response(['status' => 429], 429);
        $request = new \WP_REST_Request('POST', '/yt-builder-mcp/v1/pages/abc/elements');

        $result = RateLimitHeadersFilter::inject($response, null, $request);
        self::assertInstanceOf(\WP_REST_Response::class, $result);

        $headers = $result->get_headers();
        self::assertArrayHasKey('Retry-After', $headers);
        self::assertSame((string) RateLimiter::WINDOW_SECONDS, $headers['Retry-After']);
    }

    public function test_429_outside_namespace_is_noop(): void
    {
        $response = new \WP_REST_Response(['status' => 429], 429);
        $request = new \WP_REST_Request('POST', '/wp/v2/posts');

        $result = RateLimitHeadersFilter::inject($response, null, $request);
        self::assertInstanceOf(\WP_REST_Response::class, $result);

        $headers = $result->get_headers();
        self::assertArrayNotHasKey('Retry-After', $headers);
    }

    public function test_non_429_in_namespace_is_noop(): void
    {
        foreach ([200, 401, 403, 412, 500] as $status) {
            $response = new \WP_REST_Response(['status' => $status], $status);
            $request = new \WP_REST_Request('POST', '/yt-builder-mcp/v1/pages/abc/elements');

            $result = RateLimitHeadersFilter::inject($response, null, $request);
            self::assertInstanceOf(\WP_REST_Response::class, $result);
            self::assertArrayNotHasKey('Retry-After', $result->get_headers(), sprintf('Status %d must not get Retry-After.', $status));
        }
    }

    public function test_non_object_response_returned_unchanged(): void
    {
        $request = new \WP_REST_Request('POST', '/yt-builder-mcp/v1/pages/abc/elements');
        self::assertNull(RateLimitHeadersFilter::inject(null, null, $request));
        self::assertSame('whatever', RateLimitHeadersFilter::inject('whatever', null, $request));
    }
}
