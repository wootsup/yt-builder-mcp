<?php
/**
 * SecurityLogger — structured security-event sink tests.
 *
 * Wave 6 Round-2 R2.9. The logger MUST never throw, MUST emit a single
 * line per call, and MUST sanitize non-encodable values into safe strings
 * so json_encode() never blows up on resource handles or circular refs.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Util\SecurityLogger;

#[CoversClass(SecurityLogger::class)]
final class SecurityLoggerTest extends TestCase
{
    private string $errorLogFile;
    /** @var string|false */
    private $previousErrorLog;

    protected function setUp(): void
    {
        $this->errorLogFile = tempnam(sys_get_temp_dir(), 'ytb_mcp_seclog_');
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', (string) $this->previousErrorLog);
        }
        if (is_file($this->errorLogFile)) {
            @unlink($this->errorLogFile);
        }
    }

    private function logContents(): string
    {
        return is_file($this->errorLogFile) ? (string) file_get_contents($this->errorLogFile) : '';
    }

    public function test_log_emits_structured_line(): void
    {
        SecurityLogger::log(SecurityLogger::EVENT_BEARER_FAIL, ['reason' => 'invalid_signature']);
        $log = $this->logContents();
        self::assertStringContainsString('[yt-builder-mcp][security]', $log);
        self::assertStringContainsString('bearer_fail', $log);
        self::assertStringContainsString('"reason":"invalid_signature"', $log);
    }

    public function test_log_handles_empty_context(): void
    {
        SecurityLogger::log(SecurityLogger::EVENT_SCOPE_DENY);
        $log = $this->logContents();
        self::assertStringContainsString('scope_deny', $log);
        // PHP encodes [] as "[]" (empty array → empty JSON array). That's the
        // documented behavior — for an object-shape we'd need ArrayObject.
        self::assertStringContainsString('[]', $log);
    }

    public function test_log_sanitizes_resource_handle(): void
    {
        $resource = fopen('php://memory', 'r');
        try {
            SecurityLogger::log(SecurityLogger::EVENT_WRITE_FAILED, [
                'handle' => $resource,
                'reason' => 'persistence_assert_failed',
            ]);
            $log = $this->logContents();
            // Resource is coerced to its type-string; reason survives intact.
            self::assertStringContainsString('persistence_assert_failed', $log);
            self::assertStringContainsString('[resource', $log);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public function test_log_handles_nested_array(): void
    {
        SecurityLogger::log(SecurityLogger::EVENT_RATE_LIMIT, [
            'kid' => 'kid_abc',
            'window' => ['start' => 1234567890, 'end' => 1234567950],
        ]);
        $log = $this->logContents();
        self::assertStringContainsString('"kid":"kid_abc"', $log);
        self::assertStringContainsString('"start":1234567890', $log);
    }

    public function test_log_never_throws_on_problematic_input(): void
    {
        // Closures cannot be json_encoded — sanitizer must coerce.
        SecurityLogger::log(SecurityLogger::EVENT_CACHE_FLUSH_FAILED, [
            'callback' => static fn (): bool => true,
        ]);
        $log = $this->logContents();
        self::assertStringContainsString('cache_flush_failed', $log);
        self::assertStringContainsString('[object', $log);
    }

    public function test_event_constants_are_stable_slugs(): void
    {
        // Stable wire-format: changing one of these is a breaking change for
        // log aggregation / alerting consumers. Pin the values.
        self::assertSame('bearer_fail', SecurityLogger::EVENT_BEARER_FAIL);
        self::assertSame('scope_deny', SecurityLogger::EVENT_SCOPE_DENY);
        self::assertSame('rate_limit', SecurityLogger::EVENT_RATE_LIMIT);
        self::assertSame('write_failed', SecurityLogger::EVENT_WRITE_FAILED);
        self::assertSame('cross_template_deny', SecurityLogger::EVENT_CROSS_TEMPLATE_DENY);
        self::assertSame('cache_flush_failed', SecurityLogger::EVENT_CACHE_FLUSH_FAILED);
        self::assertSame('keystore_race', SecurityLogger::EVENT_KEYSTORE_RACE);
        self::assertSame('lock_timeout', SecurityLogger::EVENT_LOCK_TIMEOUT);
        self::assertSame('pickup_claimed', SecurityLogger::EVENT_PICKUP_CLAIMED);
        self::assertSame('pickup_not_found', SecurityLogger::EVENT_PICKUP_NOT_FOUND);
        self::assertSame('pickup_ip_mismatch', SecurityLogger::EVENT_PICKUP_IP_MISMATCH);
        self::assertSame('pickup_rate_limited', SecurityLogger::EVENT_PICKUP_RATE_LIMITED);
    }
}
