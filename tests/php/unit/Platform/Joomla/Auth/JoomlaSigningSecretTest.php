<?php
/**
 * JoomlaSigningSecret — DB-backed twin of WP SigningSecret. Uses the
 * 3-tier encryption-key resolver (ADR-001).
 *
 * Cookbook §2.4.3 (enc1: envelope) + §2.4.6 (plaintext fallback when
 * no encryption key is resolvable).
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaSigningSecret;
use WootsUp\BuilderMcp\Platform\Joomla\Encryption\JoomlaEncryptionKeyResolver;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;

#[CoversClass(JoomlaSigningSecret::class)]
final class JoomlaSigningSecretTest extends TestCase
{
    /** @var string Path to the auto-generated tier-3 key file used in tests. */
    private string $tier3KeyPath = '';

    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();
        JoomlaEncryptionKeyResolver::resetForTests();

        // Establish a deterministic tier-1 key for the encrypt-at-rest tests
        // (define-once: subsequent setUp calls are no-ops, matching the
        // way Joomla configuration.php declares the constant exactly once
        // per request).
        if (!\defined('YTB_MCP_ENCRYPTION_KEY')) {
            \define('YTB_MCP_ENCRYPTION_KEY', 'ytb-mcp-test-suite-encryption-key-deterministic-do-not-use');
        }
    }

    protected function tearDown(): void
    {
        JoomlaEncryptionKeyResolver::resetForTests();
    }

    /**
     * @cookbook 2.4.3 enc1: envelope — first-access generation
     */
    public function test_generates_secret_on_first_access(): void
    {
        $secret = JoomlaSigningSecret::ensure();
        self::assertNotSame('', $secret);
        self::assertSame(128, \strlen($secret), 'Expected 64 bytes hex-encoded = 128 chars.');
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $secret);
    }

    public function test_subsequent_calls_return_same_secret(): void
    {
        $a = JoomlaSigningSecret::ensure();
        $b = JoomlaSigningSecret::ensure();
        self::assertSame($a, $b);
    }

    public function test_get_returns_null_when_not_initialized(): void
    {
        self::assertNull(JoomlaSigningSecret::get());
    }

    public function test_get_returns_stored_secret_after_ensure(): void
    {
        $generated = JoomlaSigningSecret::ensure();
        $fetched   = JoomlaSigningSecret::get();
        self::assertSame($generated, $fetched);
    }

    /**
     * @cookbook 2.4.3 rotate() invalidates previous tokens
     */
    public function test_rotate_replaces_secret_with_a_new_value(): void
    {
        $first  = JoomlaSigningSecret::ensure();
        $second = JoomlaSigningSecret::rotate();
        self::assertNotSame($first, $second);
        self::assertSame($second, JoomlaSigningSecret::get());
    }

    /**
     * @cookbook 2.4.3 stored blob carries `enc1:` prefix + does NOT contain raw secret
     */
    public function test_stored_value_is_encrypted_at_rest_when_key_resolves(): void
    {
        $secret = JoomlaSigningSecret::ensure();
        $raw    = \MockJoomlaDatabase::$tables[JoomlaOptionStore::TABLE][JoomlaSigningSecret::OPTION_KEY] ?? null;
        self::assertIsString($raw);
        self::assertStringStartsWith('enc1:', $raw);
        self::assertStringNotContainsString($secret, $raw);
    }

    /**
     * @cookbook 2.4.6 legacy plaintext storage round-trips via get()
     */
    public function test_legacy_plaintext_storage_round_trips_via_get(): void
    {
        $legacy = \bin2hex(\random_bytes(64));
        \MockJoomlaDatabase::$tables[JoomlaOptionStore::TABLE] = [
            JoomlaSigningSecret::OPTION_KEY => $legacy,
        ];
        self::assertSame($legacy, JoomlaSigningSecret::get());
    }

    /**
     * @cookbook 2.4.3 race-safe insert via add() — second ensure() returns the first secret
     */
    public function test_ensure_is_race_safe_via_add(): void
    {
        // First ensure() generates + inserts. Second ensure() sees the
        // row already exists (add() returns false) and re-reads.
        $first  = JoomlaSigningSecret::ensure();
        $second = JoomlaSigningSecret::ensure();
        self::assertSame($first, $second);
    }

    /**
     * @cookbook 2.4.6 corrupt envelope returns null (caller regenerates)
     */
    public function test_corrupt_envelope_returns_null(): void
    {
        \MockJoomlaDatabase::$tables[JoomlaOptionStore::TABLE] = [
            JoomlaSigningSecret::OPTION_KEY => 'enc1:!!notbase64!!',
        ];
        self::assertNull(JoomlaSigningSecret::get());
    }
}
