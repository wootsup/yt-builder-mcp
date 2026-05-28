<?php
/**
 * Wave-1 Fix C-4 — Audit event-name `mcp_write_rate_limited`.
 *
 * Pre-Wave-1: every rate-limit rejection emitted SecurityLogger event
 * `rate_limit`. Wave-1 splits this into `mcp_write_rate_limited` (per-kid
 * write quota) so SIEM/forensic queries can grep one stable token for the
 * "compromised bearer / runaway client" signal without false-positives
 * from the per-IP pickup limiter (`pickup_rate_limited`).
 *
 * The test asserts:
 *  1. SecurityLogger::EVENT_MCP_WRITE_RATE_LIMITED constant exists.
 *  2. Constant value is `mcp_write_rate_limited`.
 *  3. RateLimiter::checkWrite emits this exact event-name on the 429
 *     boundary (kid, limit, window_seconds context).
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Rest\RateLimiter;
use WootsUp\BuilderMcp\Util\SecurityLogger;

#[CoversClass(RateLimiter::class)]
#[CoversClass(SecurityLogger::class)]
final class RateLimitAuditEventTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_transients'] = [];
    }

    public function test_event_constant_exists_with_canonical_token(): void
    {
        self::assertTrue(
            \defined(SecurityLogger::class . '::EVENT_MCP_WRITE_RATE_LIMITED'),
            'SecurityLogger must expose EVENT_MCP_WRITE_RATE_LIMITED.',
        );
        self::assertSame(
            'mcp_write_rate_limited',
            SecurityLogger::EVENT_MCP_WRITE_RATE_LIMITED,
            'Event-name MUST be the canonical token "mcp_write_rate_limited".',
        );
    }

    public function test_rate_limiter_emits_mcp_write_rate_limited_on_boundary(): void
    {
        // Burn through the budget without tripping the limit.
        $kid = 'audit-event-kid';
        for ($i = 1; $i <= RateLimiter::WRITE_LIMIT; $i++) {
            $err = RateLimiter::checkWrite($kid);
            self::assertNull($err, sprintf('Iteration %d must still be within budget.', $i));
        }

        // Trip the limit and capture stderr (SecurityLogger writes via error_log()).
        $logFile = \tempnam(\sys_get_temp_dir(), 'ytb-mcp-audit-');
        self::assertIsString($logFile);
        $originalLog = \ini_get('error_log');
        \ini_set('error_log', $logFile);
        try {
            $err = RateLimiter::checkWrite($kid);
            self::assertInstanceOf(\WP_Error::class, $err);
        } finally {
            \ini_set('error_log', $originalLog === false ? '' : $originalLog);
        }

        $logged = (string) \file_get_contents($logFile);
        @\unlink($logFile);

        self::assertStringContainsString(
            '[yt-builder-mcp][security] mcp_write_rate_limited',
            $logged,
            sprintf('Logged output must carry the mcp_write_rate_limited token. Got: %s', $logged),
        );
        self::assertStringContainsString('"kid":"audit-event-kid"', $logged);
        self::assertStringContainsString('"limit":60', $logged);
        self::assertStringContainsString('"window_seconds":60', $logged);
    }
}
