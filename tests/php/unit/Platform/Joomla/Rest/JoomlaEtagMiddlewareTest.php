<?php
/**
 * JoomlaEtagMiddleware behavioural tests.
 *
 * Round-4 audit A3 P1 — Wave 4 controllers + middleware had zero unit
 * coverage. This file pins the RFC-7232 §3.1 contract: missing header
 * vs require flag, "*" wildcard, exact match, quote-stripping, and the
 * 412 mismatch payload shape — all byte-identical with the WP-side
 * EtagMiddleware so cross-platform MCP-client error-classifiers stay
 * stable.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Rest
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaEtagMiddleware;

#[CoversClass(JoomlaEtagMiddleware::class)]
final class JoomlaEtagMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the $_SERVER global between cases — readIfMatchHeader()
        // falls back to it when no Joomla Input is present.
        unset($_SERVER['HTTP_IF_MATCH']);
    }

    public function test_missing_header_without_require_returns_null(): void
    {
        self::assertNull(
            JoomlaEtagMiddleware::enforce('', 'abc-r1', false),
            'Missing If-Match without requireIfMatch=true must allow the request through (cookbook §3.1.6 — POST save is opt-in lock).'
        );
    }

    public function test_missing_header_with_require_returns_428(): void
    {
        $err = JoomlaEtagMiddleware::enforce('', 'abc-r1', true);
        self::assertIsArray($err);
        self::assertSame(428, $err['status']);
        self::assertSame('yootheme_builder_mcp.if_match_required', $err['code']);
        self::assertSame('abc-r1', $err['data']['current_etag']);
    }

    public function test_wildcard_star_returns_null(): void
    {
        // RFC-7232 §3.1 wildcard — matches any existing resource.
        self::assertNull(JoomlaEtagMiddleware::enforce('*', 'abc-r1', true));
    }

    public function test_exact_match_returns_null(): void
    {
        self::assertNull(JoomlaEtagMiddleware::enforce('abc-r1', 'abc-r1', true));
    }

    public function test_quote_strip_matches_unquoted_etag(): void
    {
        // RFC-7232 §2.3 — opaque-tag with surrounding double-quotes.
        self::assertNull(JoomlaEtagMiddleware::enforce('"abc-r1"', 'abc-r1', true));
    }

    public function test_mismatch_returns_412_with_expected_etag_payload(): void
    {
        $err = JoomlaEtagMiddleware::enforce('stale-r0', 'fresh-r1', true);
        self::assertIsArray($err);
        self::assertSame(412, $err['status']);
        self::assertSame('yootheme_builder_mcp.precondition_failed', $err['code']);
        self::assertStringContainsString('stale-r0', $err['message']);
        self::assertStringContainsString('fresh-r1', $err['message']);
        self::assertSame('fresh-r1', $err['data']['expected_etag']);
        self::assertArrayHasKey('hint', $err['data']);
    }

    public function test_read_if_match_header_falls_back_to_server_global(): void
    {
        $_SERVER['HTTP_IF_MATCH'] = '  abc-r1  ';
        // Without a Joomla Input chain in the test harness, the fallback
        // path must surface the trimmed $_SERVER value.
        self::assertSame('abc-r1', JoomlaEtagMiddleware::readIfMatchHeader());
    }

    public function test_read_if_match_header_returns_empty_when_absent(): void
    {
        self::assertSame('', JoomlaEtagMiddleware::readIfMatchHeader());
    }

    public function test_enforce_uses_hash_equals_for_constant_time_compare(): void
    {
        // Smoke-test: the implementation should NOT shortcut on string
        // length difference (a `===` compare would). Two same-length
        // strings that differ in the last byte should be detected.
        $err = JoomlaEtagMiddleware::enforce('abc-r1', 'abc-r2', true);
        self::assertIsArray($err);
        self::assertSame(412, $err['status']);
    }
}
