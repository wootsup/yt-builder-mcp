<?php
/**
 * PIN-TEST: JoomlaArticleLayoutStorage — L2 MEDIUMTEXT overflow guard
 * mirror of the L1 pin in {@see MediumTextOverflowPinTest}.
 *
 * Round-6 A5 polish. Cookbook §4.1.5 (cross-platform). Joomla's
 * `#__content.fulltext` column is MEDIUMTEXT (16 MB) in the canonical
 * J5/J6 schema (matches the L1 `#__extensions.custom_data` ceiling). A
 * sufficiently large per-article Builder state would silently truncate
 * without operator visibility. The `writeArticle()` path emits
 * `SecurityLogger::EVENT_PAYLOAD_NEAR_MEDIUMTEXT_LIMIT` when the encoded
 * payload is within ~2 MB of the ceiling, giving operators time to
 * migrate `#__content.fulltext` to LONGTEXT before truncation occurs.
 *
 * Constants are shared with the L1 path
 * ({@see JoomlaLayoutStorage::MEDIUMTEXT_LIMIT_BYTES} +
 * `MEDIUMTEXT_WARN_BYTES`) so the two storage layers stay byte-for-byte
 * aligned with the MEDIUMTEXT contract.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\L2\JoomlaArticleLayoutStorage;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaLayoutStorage;
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class L2MediumTextOverflowPinTest extends TestCase
{
    private string $errorLogBackup = '';
    private string $errorLogFile   = '';

    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();

        $this->errorLogFile = (string) \tempnam(\sys_get_temp_dir(), 'ytbmcp-l2-securitylog-');
        $this->errorLogBackup = (string) \ini_get('error_log');
        \ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        \ini_set('error_log', $this->errorLogBackup);
        if ($this->errorLogFile !== '' && \file_exists($this->errorLogFile)) {
            @\unlink($this->errorLogFile);
        }
        \MockJoomlaFactory::reset();
    }

    /**
     * @cookbook §4.1.5 + Round-6 A5 polish — warn when L2 payload crosses 14 MB
     */
    public function test_warns_when_l2_payload_exceeds_mediumtext_warn_threshold(): void
    {
        $bigString = \str_repeat('A', JoomlaLayoutStorage::MEDIUMTEXT_WARN_BYTES + 1024);
        $tree      = ['library' => [], 'templates' => ['huge' => $bigString]];

        $storage = new JoomlaArticleLayoutStorage();
        $ok      = $storage->writeArticle(42, $tree);
        self::assertTrue($ok, 'writeArticle must still succeed when payload < 16 MB.');

        $log = (string) \file_get_contents($this->errorLogFile);
        self::assertStringContainsString(
            SecurityLogger::EVENT_PAYLOAD_NEAR_MEDIUMTEXT_LIMIT,
            $log,
            'A near-limit L2 payload MUST trigger EVENT_PAYLOAD_NEAR_MEDIUMTEXT_LIMIT.'
        );
        self::assertStringContainsString(
            'l2_article',
            $log,
            'L2 warning MUST tag scope=l2_article so log readers can distinguish from the L1 warning.'
        );
        self::assertStringContainsString(
            'LONGTEXT',
            $log,
            'Warning MUST include LONGTEXT remediation hint.'
        );
        self::assertStringContainsString(
            '#__content',
            $log,
            'L2 remediation MUST reference the #__content table (NOT the L1 #__extensions table).'
        );
    }

    /**
     * @cookbook §4.1.5 + Round-6 A5 polish — silent for typical L2 payload sizes
     */
    public function test_no_warning_for_typical_l2_payload_size(): void
    {
        $tree = ['library' => [], 'templates' => ['ok' => \str_repeat('B', 1024)]];

        $storage = new JoomlaArticleLayoutStorage();
        $ok      = $storage->writeArticle(1, $tree);
        self::assertTrue($ok);

        $log = (string) \file_get_contents($this->errorLogFile);
        self::assertStringNotContainsString(
            SecurityLogger::EVENT_PAYLOAD_NEAR_MEDIUMTEXT_LIMIT,
            $log,
            'Typical small L2 payloads MUST NOT trigger the MEDIUMTEXT warning.'
        );
    }

    /**
     * @cookbook §4.1.5 — L2 reuses the L1 threshold constants so both
     *           storage layers stay byte-for-byte aligned with the
     *           MEDIUMTEXT contract.
     */
    public function test_l2_reuses_l1_threshold_constants(): void
    {
        // The L2 guard MUST read its thresholds from the L1 class so a
        // future column-type change (e.g. raising LIMIT_BYTES to LONGTEXT)
        // propagates to both storage layers atomically.
        self::assertSame(16 * 1024 * 1024, JoomlaLayoutStorage::MEDIUMTEXT_LIMIT_BYTES);
        self::assertLessThan(
            JoomlaLayoutStorage::MEDIUMTEXT_LIMIT_BYTES - (1024 * 1024),
            JoomlaLayoutStorage::MEDIUMTEXT_WARN_BYTES,
            'Warn threshold must give operators at least 1 MB of runway before truncation.'
        );
    }
}
