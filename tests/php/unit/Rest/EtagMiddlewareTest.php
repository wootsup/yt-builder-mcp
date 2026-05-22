<?php
/**
 * EtagMiddleware — optimistic-lock enforcement via If-Match header.
 *
 * Wave 3 Task 3.2.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Rest\EtagMiddleware;

#[CoversClass(EtagMiddleware::class)]
final class EtagMiddlewareTest extends TestCase
{
    public function test_returns_null_when_header_missing(): void
    {
        $req = new \WP_REST_Request('POST', '/foo');
        self::assertNull(EtagMiddleware::enforce($req, 'abc'));
    }

    public function test_returns_null_when_header_matches(): void
    {
        $req = new \WP_REST_Request('POST', '/foo');
        $req->set_header('If-Match', 'abc');
        self::assertNull(EtagMiddleware::enforce($req, 'abc'));
    }

    public function test_returns_null_for_wildcard_header(): void
    {
        $req = new \WP_REST_Request('POST', '/foo');
        $req->set_header('If-Match', '*');
        self::assertNull(EtagMiddleware::enforce($req, 'abc'));
    }

    public function test_returns_412_when_mismatch(): void
    {
        $req = new \WP_REST_Request('POST', '/foo');
        $req->set_header('If-Match', 'stale');
        $err = EtagMiddleware::enforce($req, 'fresh');
        self::assertNotNull($err);
        self::assertSame('yootheme_builder_mcp.precondition_failed', $err->get_error_code());
        $data = $err->get_error_data();
        self::assertIsArray($data);
        self::assertSame(412, $data['status']);
        self::assertSame('fresh', $data['expected_etag']);
    }

    public function test_strips_surrounding_quotes_per_rfc7232(): void
    {
        $req = new \WP_REST_Request('POST', '/foo');
        $req->set_header('If-Match', '"abc"');
        self::assertNull(EtagMiddleware::enforce($req, 'abc'));
    }

    public function test_uses_timing_safe_comparison(): void
    {
        // Indirect check: hash_equals returns true only on byte-identical
        // strings. We exercise a non-matching same-length pair to be sure
        // the comparison is content-based, not length-based.
        $req = new \WP_REST_Request('POST', '/foo');
        $req->set_header('If-Match', str_repeat('a', 64));
        $err = EtagMiddleware::enforce($req, str_repeat('b', 64));
        self::assertNotNull($err);
    }
}
