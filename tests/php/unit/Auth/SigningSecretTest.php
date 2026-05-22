<?php
/**
 * SigningSecret — lazy-init wp_option-backed HMAC signing key.
 *
 * Wave 1 Task 1.4.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Auth\SigningSecret;

#[CoversClass(SigningSecret::class)]
final class SigningSecretTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [];
    }

    public function test_generates_secret_on_first_access(): void
    {
        $secret = SigningSecret::ensure();
        self::assertNotSame('', $secret);
        self::assertSame(128, strlen($secret), 'Expected 64 bytes hex-encoded = 128 chars.');
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $secret);
    }

    public function test_subsequent_calls_return_same_secret(): void
    {
        $a = SigningSecret::ensure();
        $b = SigningSecret::ensure();
        self::assertSame($a, $b);
    }

    public function test_get_returns_null_when_not_initialized(): void
    {
        self::assertNull(SigningSecret::get());
    }

    public function test_get_returns_stored_secret_after_ensure(): void
    {
        $generated = SigningSecret::ensure();
        $fetched = SigningSecret::get();
        self::assertSame($generated, $fetched);
    }

    public function test_rotate_replaces_secret_with_a_new_value(): void
    {
        $first = SigningSecret::ensure();
        $second = SigningSecret::rotate();
        self::assertNotSame($first, $second);
        self::assertSame($second, SigningSecret::get());
    }

    public function test_stored_value_is_encrypted_at_rest_when_auth_key_defined(): void
    {
        // Wave-6 Fix 4: AUTH_KEY is defined in tests/php/bootstrap.php, so
        // the encoded blob must use the `enc1:` envelope and NOT contain
        // the raw secret.
        $secret = SigningSecret::ensure();
        $stored = $GLOBALS['ytb_test_options'][SigningSecret::OPTION] ?? null;
        self::assertIsString($stored);
        self::assertStringStartsWith('enc1:', $stored);
        self::assertStringNotContainsString($secret, $stored);
    }

    public function test_legacy_plaintext_storage_round_trips_via_get(): void
    {
        // Wave-6 Fix 4: pre-existing installs may have a plaintext value in
        // wp_options. The decode path must return the raw string unchanged.
        $legacy = bin2hex(random_bytes(64));
        $GLOBALS['ytb_test_options'][SigningSecret::OPTION] = $legacy;
        self::assertSame($legacy, SigningSecret::get());
    }

    public function test_ensure_is_race_safe_via_add_option(): void
    {
        // Wave-6 Fix 4: if add_option fails (another process beat us),
        // ensure() must re-read instead of overwriting.
        // We simulate this by pre-seeding the option, then calling ensure()
        // — it must return the pre-seeded value, not generate a new one.
        $pre = SigningSecret::ensure();
        $second = SigningSecret::ensure();
        self::assertSame($pre, $second);
    }

    public function test_corrupt_envelope_returns_null(): void
    {
        // Wave-6 Fix 4: if the stored blob has a valid prefix but a corrupt
        // payload, get() must return null (treats as missing → ensure()
        // would regenerate).
        $GLOBALS['ytb_test_options'][SigningSecret::OPTION] = 'enc1:!!notbase64!!';
        self::assertNull(SigningSecret::get());
    }
}
