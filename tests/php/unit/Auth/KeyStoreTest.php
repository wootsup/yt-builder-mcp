<?php
/**
 * KeyStore — wp_options wrapper for kid metadata.
 *
 * Wave 1 Task 1.2. Uses in-process wp_options stubs (see tests/php/bootstrap.php)
 * to avoid the cost of spinning up a full WP-Testbench for what is a pure
 * data-access wrapper.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Auth\KeyStore;

#[CoversClass(KeyStore::class)]
final class KeyStoreTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the in-process wp_options stub between tests.
        $GLOBALS['ytb_test_options'] = [];
    }

    public function test_register_and_find_kid(): void
    {
        $store = new KeyStore();
        $store->register('kid-test', [
            'label' => 'My Mac',
            'scope' => 'write',
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
        $store = new KeyStore();
        self::assertNull($store->find('does-not-exist'));
    }

    public function test_list_returns_all_registered_kids(): void
    {
        $store = new KeyStore();
        $store->register('kid-a', ['label' => 'A', 'scope' => 'read', 'created_at' => 1, 'expires_at' => null, 'revoked_at' => null]);
        $store->register('kid-b', ['label' => 'B', 'scope' => 'write', 'created_at' => 2, 'expires_at' => null, 'revoked_at' => null]);

        $all = $store->list();

        self::assertCount(2, $all);
        self::assertArrayHasKey('kid-a', $all);
        self::assertArrayHasKey('kid-b', $all);
    }

    public function test_revoke_sets_revoked_at_timestamp(): void
    {
        $store = new KeyStore();
        $store->register('kid-rev', [
            'label' => 'X',
            'scope' => 'write',
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
        $store = new KeyStore();
        // Should not throw.
        $store->revoke('never-registered');
        self::assertSame([], $store->list());
    }

    public function test_register_persists_across_instances(): void
    {
        $a = new KeyStore();
        $a->register('shared', ['label' => 'L', 'scope' => 'read', 'created_at' => 1, 'expires_at' => null, 'revoked_at' => null]);

        $b = new KeyStore();
        $found = $b->find('shared');

        self::assertNotNull($found);
        self::assertSame('L', $found['label']);
    }

    public function test_register_handles_legacy_unversioned_envelope(): void
    {
        // Plant a legacy (pre-R2.11) flat envelope and confirm that read still
        // works AND that the next write upgrades to versioned shape.
        $GLOBALS['ytb_test_options'][KeyStore::OPTION] = [
            'legacy-kid' => ['label' => 'Old', 'scope' => 'read', 'created_at' => 1, 'expires_at' => null, 'revoked_at' => null],
        ];

        $store = new KeyStore();
        self::assertNotNull($store->find('legacy-kid'));

        // Trigger a write — envelope should upgrade.
        $store->register('new-kid', ['label' => 'New', 'scope' => 'read', 'created_at' => 1, 'expires_at' => null, 'revoked_at' => null]);

        $envelope = $GLOBALS['ytb_test_options'][KeyStore::OPTION];
        self::assertArrayHasKey('version', $envelope);
        self::assertArrayHasKey('kids', $envelope);
        self::assertSame(1, $envelope['version']);
    }

    public function test_register_bumps_version_on_each_write(): void
    {
        $store = new KeyStore();

        $store->register('kid-1', ['label' => 'a', 'scope' => 'read', 'created_at' => 1, 'expires_at' => null, 'revoked_at' => null]);
        $v1 = $GLOBALS['ytb_test_options'][KeyStore::OPTION]['version'];

        $store->register('kid-2', ['label' => 'b', 'scope' => 'read', 'created_at' => 2, 'expires_at' => null, 'revoked_at' => null]);
        $v2 = $GLOBALS['ytb_test_options'][KeyStore::OPTION]['version'];

        self::assertSame($v1 + 1, $v2);
    }

    public function test_revoke_bumps_version(): void
    {
        $store = new KeyStore();
        $store->register('kid-rev', ['label' => 'r', 'scope' => 'write', 'created_at' => 1, 'expires_at' => null, 'revoked_at' => null]);
        $before = $GLOBALS['ytb_test_options'][KeyStore::OPTION]['version'];

        $store->revoke('kid-rev');
        $after = $GLOBALS['ytb_test_options'][KeyStore::OPTION]['version'];

        self::assertSame($before + 1, $after);
    }

    public function test_envelope_carries_versioned_shape_after_write(): void
    {
        $store = new KeyStore();
        $store->register('kid', ['label' => 'x', 'scope' => 'read', 'created_at' => 1, 'expires_at' => null, 'revoked_at' => null]);

        $stored = $GLOBALS['ytb_test_options'][KeyStore::OPTION];
        self::assertIsArray($stored);
        self::assertArrayHasKey('version', $stored);
        self::assertArrayHasKey('kids', $stored);
        self::assertGreaterThanOrEqual(1, $stored['version']);
    }
}
