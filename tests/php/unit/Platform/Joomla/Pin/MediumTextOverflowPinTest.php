<?php
/**
 * PIN-TEST: JoomlaLayoutStorage — MEDIUMTEXT overflow guard.
 *
 * Round-3 audit A5 P1-3. Cookbook §4.1.5. YT's
 * `#__extensions.custom_data` column is MEDIUMTEXT (16 MB) in the
 * canonical J5/J6 schema. A sufficiently large Builder state would
 * silently truncate without operator visibility. The writeState path
 * emits SecurityLogger::EVENT_PAYLOAD_NEAR_MEDIUMTEXT_LIMIT when the
 * encoded payload is within ~2 MB of the ceiling, giving operators
 * time to migrate to LONGTEXT before truncation occurs.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaLayoutStorage;
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class MediumTextOverflowPinTest extends TestCase
{
    /** @var array<int, array{event: string, context: array<string, mixed>}> */
    private array $capturedErrorLogLines = [];
    private string $errorLogBackup = '';
    private string $errorLogFile = '';

    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();
        JoomlaLayoutStorage::resetForTests();

        // SecurityLogger writes via error_log(). Redirect to a temp file
        // so we can read back the structured events. We don't ini_restore
        // a previous error_log because PHPUnit may run with output-buffering
        // strictness flags.
        $this->errorLogFile = \tempnam(\sys_get_temp_dir(), 'ytbmcp-securitylog-');
        $this->errorLogBackup = (string) \ini_get('error_log');
        \ini_set('error_log', $this->errorLogFile);

        // Seed an extension row so resolveExtensionId() returns an int.
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride    = 99;
    }

    protected function tearDown(): void
    {
        \ini_set('error_log', $this->errorLogBackup);
        if ($this->errorLogFile !== '' && \file_exists($this->errorLogFile)) {
            @\unlink($this->errorLogFile);
        }
        JoomlaLayoutStorage::resetForTests();
        \MockJoomlaFactory::reset();
    }

    /**
     * @cookbook §4.1.5 + Audit-A5 P1-3 — warn when payload crosses 14 MB
     */
    public function test_warns_when_payload_exceeds_mediumtext_warn_threshold(): void
    {
        // Build a state whose JSON-encoded size exceeds 14 MB but stays
        // under 16 MB. A single string of repeated characters is the
        // cheapest way to fabricate it.
        $bigString = \str_repeat('A', JoomlaLayoutStorage::MEDIUMTEXT_WARN_BYTES + 1024);
        $state = ['library' => [], 'templates' => ['big' => $bigString]];

        $storage = new JoomlaLayoutStorage();
        $ok = $storage->writeState($state);
        self::assertTrue($ok, 'writeState must still succeed when payload < 16 MB.');

        $log = (string) \file_get_contents($this->errorLogFile);
        self::assertStringContainsString(
            SecurityLogger::EVENT_PAYLOAD_NEAR_MEDIUMTEXT_LIMIT,
            $log,
            'A near-limit payload MUST trigger EVENT_PAYLOAD_NEAR_MEDIUMTEXT_LIMIT.'
        );
        self::assertStringContainsString(
            'LONGTEXT',
            $log,
            'Warning MUST include LONGTEXT remediation hint.'
        );
    }

    /**
     * @cookbook §4.1.5 + Audit-A5 P1-3 — silent for typical payload sizes
     */
    public function test_no_warning_for_typical_payload_size(): void
    {
        $state = ['library' => [], 'templates' => ['ok' => \str_repeat('B', 1024)]];

        $storage = new JoomlaLayoutStorage();
        $ok = $storage->writeState($state);
        self::assertTrue($ok);

        $log = (string) \file_get_contents($this->errorLogFile);
        self::assertStringNotContainsString(
            SecurityLogger::EVENT_PAYLOAD_NEAR_MEDIUMTEXT_LIMIT,
            $log,
            'Typical small payloads MUST NOT trigger the MEDIUMTEXT warning.'
        );
    }

    /**
     * @cookbook §4.1.5 — threshold constants reflect MEDIUMTEXT contract
     */
    public function test_threshold_constants_match_mediumtext_contract(): void
    {
        // The hard limit MUST be the strict MySQL MEDIUMTEXT ceiling.
        self::assertSame(16 * 1024 * 1024, JoomlaLayoutStorage::MEDIUMTEXT_LIMIT_BYTES);
        // The warning MUST fire at least 1 MB before the ceiling so an
        // operator has runway to migrate before truncation occurs.
        self::assertLessThan(
            JoomlaLayoutStorage::MEDIUMTEXT_LIMIT_BYTES - (1024 * 1024),
            JoomlaLayoutStorage::MEDIUMTEXT_WARN_BYTES,
            'Warn threshold must give operators at least 1 MB of runway.'
        );
    }
}
