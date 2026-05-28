<?php
/**
 * Wave-1 Fix C-4 — Joomla parity: rate-limit 429 contract.
 *
 * Mirrors the WordPress contract pinned by:
 *   - RateLimit429ResponseShapeTest (data shape: error / retry_after_seconds / scope)
 *   - RateLimitHeadersFilterTest    (Retry-After header on 429)
 *   - RateLimitAuditEventTest       (mcp_write_rate_limited event token)
 *
 * Joomla flows the 429 through {@see JoomlaRateLimiter::checkWrite} +
 * {@see AbstractApiController::dispatch}; the limiter returns the
 * envelope `{error_code, status, payload}` and the dispatcher renders
 * it via JoomlaJsonResponse. We pin the payload directly here.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Rest
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaRateLimiter;
use WootsUp\BuilderMcp\Util\SecurityLogger;

#[CoversClass(JoomlaRateLimiter::class)]
#[CoversClass(SecurityLogger::class)]
final class JoomlaRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        ytb_test_install_mock_db();
    }

    public function test_within_budget_returns_null(): void
    {
        $limiter = new JoomlaRateLimiter();
        for ($i = 1; $i <= JoomlaRateLimiter::WRITE_LIMIT; $i++) {
            $err = $limiter->checkWrite('budget-kid');
            self::assertNull($err, sprintf('Iteration %d must be within budget.', $i));
        }
    }

    public function test_one_over_budget_returns_429_envelope(): void
    {
        $limiter = new JoomlaRateLimiter();
        $kid = 'over-budget-kid';

        for ($i = 1; $i <= JoomlaRateLimiter::WRITE_LIMIT; $i++) {
            self::assertNull($limiter->checkWrite($kid));
        }

        $err = $limiter->checkWrite($kid);
        self::assertIsArray($err);
        self::assertSame(429, $err['status']);
        self::assertSame('yootheme_builder_mcp.rate_limited', $err['error_code']);
    }

    public function test_429_payload_carries_well_known_rate_limit_exceeded_token(): void
    {
        $err = $this->burnThroughAndCapture();
        self::assertArrayHasKey('error', $err['payload']['data']);
        self::assertSame(
            'rate_limit_exceeded',
            $err['payload']['data']['error'],
            'Joomla payload.data.error must equal "rate_limit_exceeded".',
        );
    }

    public function test_429_payload_carries_retry_after_seconds(): void
    {
        $err = $this->burnThroughAndCapture();
        self::assertArrayHasKey('retry_after_seconds', $err['payload']['data']);
        self::assertSame(
            JoomlaRateLimiter::WINDOW_SECONDS,
            $err['payload']['data']['retry_after_seconds'],
        );
    }

    public function test_429_payload_carries_scope_kid(): void
    {
        $err = $this->burnThroughAndCapture();
        self::assertArrayHasKey('scope', $err['payload']['data']);
        self::assertSame('kid', $err['payload']['data']['scope']);
    }

    public function test_429_emits_mcp_write_rate_limited_event(): void
    {
        $logFile = \tempnam(\sys_get_temp_dir(), 'ytb-mcp-joomla-audit-');
        self::assertIsString($logFile);
        $originalLog = \ini_get('error_log');
        \ini_set('error_log', $logFile);
        try {
            $this->burnThroughAndCapture();
        } finally {
            \ini_set('error_log', $originalLog === false ? '' : $originalLog);
        }

        $logged = (string) \file_get_contents($logFile);
        @\unlink($logFile);

        self::assertStringContainsString(
            '[yt-builder-mcp][security] mcp_write_rate_limited',
            $logged,
            sprintf('Joomla limiter must emit the same canonical token. Got: %s', $logged),
        );
        self::assertStringContainsString('"platform":"joomla"', $logged);
    }

    /**
     * @return array{error_code:string, status:int, payload:array<string,mixed>}
     */
    private function burnThroughAndCapture(): array
    {
        $limiter = new JoomlaRateLimiter();
        $kid = 'shape-kid';

        for ($i = 1; $i <= JoomlaRateLimiter::WRITE_LIMIT; $i++) {
            self::assertNull($limiter->checkWrite($kid));
        }

        $err = $limiter->checkWrite($kid);
        self::assertIsArray($err);
        return $err;
    }
}
