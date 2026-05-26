<?php
/**
 * JoomlaKeyStore — DB-backed twin of WP KeyStore. Uses #__ytb_mcp_options.
 *
 * Mirrors the WP-side tests/php/unit/Auth/KeyStoreTest.php surface so that
 * any behavioural regression on either platform surfaces immediately.
 *
 * Cookbook §2.4.2 / Cookbook §4.13.2 — CAS via INSERT IGNORE on a
 * primary-key column is the Joomla-portable analogue to WP add_option.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaKeyStore;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;
use WootsUp\BuilderMcp\Util\SecurityLogger;

#[CoversClass(JoomlaKeyStore::class)]
final class JoomlaKeyStoreTest extends TestCase
{
    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        // Wire a fresh in-memory DB into Factory's container.
        ytb_test_install_mock_db();
    }

    /**
     * @cookbook 2.4.2 versioned envelope + CAS
     */
    public function test_register_and_find_kid(): void
    {
        $store = new JoomlaKeyStore();
        $store->register('kid-test', [
            'label'      => 'My Mac',
            'scope'      => 'write',
            'created_at' => 1700000000,
            'expires_at' => 1700003600,
            'revoked_at' => null,
        ]);

        $found = $store->find('kid-test');

        self::assertNotNull($found);
        self::assertSame('My Mac', $found['label']);
        self::assertSame('write', $found['scope']);
        self::assertNull($found['revoked_at']);
    }

    public function test_find_returns_null_when_kid_unknown(): void
    {
        $store = new JoomlaKeyStore();
        self::assertNull($store->find('does-not-exist'));
    }

    /**
     * @cookbook 2.4.2 list() returns all registered kids
     */
    public function test_list_returns_all_registered_kids(): void
    {
        $store = new JoomlaKeyStore();
        $store->register('kid-a', ['label' => 'A', 'scope' => 'read',  'created_at' => 1, 'expires_at' => null, 'revoked_at' => null]);
        $store->register('kid-b', ['label' => 'B', 'scope' => 'write', 'created_at' => 2, 'expires_at' => null, 'revoked_at' => null]);

        $all = $store->list();

        self::assertCount(2, $all);
        self::assertArrayHasKey('kid-a', $all);
        self::assertArrayHasKey('kid-b', $all);
    }

    public function test_revoke_sets_revoked_at_timestamp(): void
    {
        $store = new JoomlaKeyStore();
        $store->register('kid-rev', [
            'label'      => 'X',
            'scope'      => 'write',
            'created_at' => 1700000000,
            'expires_at' => null,
            'revoked_at' => null,
        ]);

        $store->revoke('kid-rev');
        $found = $store->find('kid-rev');

        self::assertNotNull($found);
        self::assertIsInt($found['revoked_at']);
        self::assertGreaterThan(0, $found['revoked_at']);
    }

    public function test_revoke_on_unknown_kid_is_noop(): void
    {
        $store = new JoomlaKeyStore();
        // Must not throw.
        $store->revoke('never-registered');
        self::assertSame([], $store->list());
    }

    public function test_register_persists_across_instances(): void
    {
        $a = new JoomlaKeyStore();
        $a->register('shared', ['label' => 'L', 'scope' => 'read', 'created_at' => 1, 'expires_at' => null, 'revoked_at' => null]);

        $b = new JoomlaKeyStore();
        $found = $b->find('shared');

        self::assertNotNull($found);
        self::assertSame('L', $found['label']);
    }

    /**
     * @cookbook 2.4.2 legacy flat-envelope migration (pre-versioned shape)
     */
    public function test_register_handles_legacy_unversioned_envelope(): void
    {
        // Plant a legacy (pre-versioned) flat envelope directly into the
        // option-store DB row and confirm read still works AND that the
        // next write upgrades it to the versioned envelope shape.
        $legacyKids = [
            'legacy-kid' => ['label' => 'Old', 'scope' => 'read', 'created_at' => 1, 'expires_at' => null, 'revoked_at' => null],
        ];
        \MockJoomlaDatabase::$tables[\WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore::TABLE] = [
            JoomlaKeyStore::OPTION_KEY => (string) \json_encode($legacyKids),
        ];

        $store = new JoomlaKeyStore();
        self::assertNotNull($store->find('legacy-kid'));

        // Trigger a write — envelope should upgrade to {version, kids}.
        $store->register('new-kid', ['label' => 'New', 'scope' => 'read', 'created_at' => 1, 'expires_at' => null, 'revoked_at' => null]);

        $raw      = \MockJoomlaDatabase::$tables[\WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore::TABLE][JoomlaKeyStore::OPTION_KEY];
        $envelope = \json_decode($raw, true);
        self::assertIsArray($envelope);
        self::assertArrayHasKey('version', $envelope);
        self::assertArrayHasKey('kids', $envelope);
        self::assertSame(1, $envelope['version']);
    }

    /**
     * @cookbook 2.4.2 version bumps on every write
     */
    public function test_register_bumps_version_on_each_write(): void
    {
        $store = new JoomlaKeyStore();

        $store->register('kid-1', ['label' => 'a', 'scope' => 'read', 'created_at' => 1, 'expires_at' => null, 'revoked_at' => null]);
        $v1 = $this->envelopeVersion();

        $store->register('kid-2', ['label' => 'b', 'scope' => 'read', 'created_at' => 2, 'expires_at' => null, 'revoked_at' => null]);
        $v2 = $this->envelopeVersion();

        self::assertSame($v1 + 1, $v2);
    }

    public function test_revoke_bumps_version(): void
    {
        $store = new JoomlaKeyStore();
        $store->register('kid-rev', ['label' => 'r', 'scope' => 'write', 'created_at' => 1, 'expires_at' => null, 'revoked_at' => null]);
        $before = $this->envelopeVersion();

        $store->revoke('kid-rev');
        $after = $this->envelopeVersion();

        self::assertSame($before + 1, $after);
    }

    /**
     * @cookbook 2.4.2 stored envelope MUST be {version, kids} after write
     */
    public function test_envelope_carries_versioned_shape_after_write(): void
    {
        $store = new JoomlaKeyStore();
        $store->register('kid', ['label' => 'x', 'scope' => 'read', 'created_at' => 1, 'expires_at' => null, 'revoked_at' => null]);

        $raw      = \MockJoomlaDatabase::$tables[\WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore::TABLE][JoomlaKeyStore::OPTION_KEY] ?? null;
        self::assertIsString($raw);
        $envelope = \json_decode($raw, true);
        self::assertIsArray($envelope);
        self::assertArrayHasKey('version', $envelope);
        self::assertArrayHasKey('kids', $envelope);
        self::assertGreaterThanOrEqual(1, $envelope['version']);
    }

    private function envelopeVersion(): int
    {
        $raw = \MockJoomlaDatabase::$tables[\WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore::TABLE][JoomlaKeyStore::OPTION_KEY] ?? '';
        $env = \json_decode((string) $raw, true);
        return (int) ($env['version'] ?? 0);
    }

    /**
     * @cookbook 2.4.2 CAS retry — concurrent writer wins the race; loser
     * retries with the observed envelope and the EVENT_KEYSTORE_RACE
     * event is emitted via SecurityLogger.
     *
     * Round-3 audit A3 P2-2 — pins that a CAS retry path exists and is
     * exercised when a concurrent writer bumps the envelope version
     * between load and re-read. We drive this via MockJoomlaDatabase's
     * loadResultOverride hook, swapping the returned envelope between
     * the first read (initial load) and the second read (the re-read
     * after the mutator runs).
     */
    public function test_concurrent_register_race_via_mock_db(): void
    {
        // Redirect SecurityLogger error_log to a temp file so we can
        // observe the race event without polluting the test runner's
        // own error_log destination.
        $logFile = \tempnam(\sys_get_temp_dir(), 'ytbmcp-keystore-race-');
        $backup  = (string) \ini_get('error_log');
        \ini_set('error_log', $logFile);

        // Pre-load an initial envelope into the mock DB. The first
        // loadEnvelope() inside register() returns this. After the
        // mutator runs, the re-read SHOULD return a version-bumped
        // envelope (simulating a concurrent writer) — we patch the
        // in-memory tables right before the mutator-execution boundary
        // by registering a tiny shim that flips the row after one read.
        try {
            $tableId  = \WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore::TABLE;
            $tableKey = JoomlaKeyStore::OPTION_KEY;
            \MockJoomlaDatabase::$tables[$tableId][$tableKey] = (string) \json_encode([
                'version' => 1,
                'kids'    => ['kid-existing' => [
                    'label' => 'A', 'scope' => 'read',
                    'created_at' => 1, 'expires_at' => null, 'revoked_at' => null,
                ]],
            ]);

            // Subclass MockJoomlaDatabase via a typed-bridge wrapper
            // that flips the envelope between reads. We re-register
            // into the container so JoomlaOptionStore sees this driver.
            $racyDb = new class extends \MockJoomlaDatabaseTypedBridge {
                public int $loadCallCount = 0;
                public function loadResult(): mixed
                {
                    // Only race-flip when the SELECT is for our envelope key.
                    $isEnvelopeRead = isset($this->getQueryShim()->binds[':key'])
                        && $this->getQueryShim()->binds[':key'] === JoomlaKeyStore::OPTION_KEY;
                    if (!$isEnvelopeRead) {
                        return parent::loadResult();
                    }
                    $this->loadCallCount++;
                    if ($this->loadCallCount === 2) {
                        // Re-read after the mutator ran. Inject a version
                        // bump to simulate "another writer landed first".
                        return (string) \json_encode([
                            'version' => 99,
                            'kids'    => ['kid-injected' => [
                                'label' => 'INJECTED', 'scope' => 'read',
                                'created_at' => 1, 'expires_at' => null, 'revoked_at' => null,
                            ]],
                        ]);
                    }
                    return parent::loadResult();
                }
                private function getQueryShim(): \MockJoomlaQuery
                {
                    $ref = new \ReflectionProperty(\MockJoomlaDatabase::class, 'query');
                    /** @var \MockJoomlaQuery|null $q */
                    $q = $ref->getValue($this);
                    return $q ?? new \MockJoomlaQuery();
                }
            };
            \MockJoomlaContainer::register('Joomla\\Database\\DatabaseInterface', $racyDb);

            $store = new JoomlaKeyStore();
            $store->register('kid-second', [
                'label'      => 'B',
                'scope'      => 'write',
                'created_at' => 2,
                'expires_at' => null,
                'revoked_at' => null,
            ]);

            // CAS retry should have triggered. The race event MUST be
            // logged at least once.
            $log = (string) \file_get_contents($logFile);
            self::assertStringContainsString(
                SecurityLogger::EVENT_KEYSTORE_RACE,
                $log,
                'Concurrent register() collision MUST emit EVENT_KEYSTORE_RACE.'
            );
        } finally {
            \ini_set('error_log', $backup);
            if (\file_exists($logFile)) {
                @\unlink($logFile);
            }
            // Restore the default DB binding so subsequent tests get a
            // clean MockJoomlaDatabaseTypedBridge.
            ytb_test_install_mock_db();
        }
    }
}
