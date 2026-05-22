<?php
/**
 * KeyService — HMAC-SHA256 token sign/verify unit-tests.
 *
 * Wave 1 Task 1.1.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Auth\ExpiredTokenException;
use WootsUp\BuilderMcp\Auth\InvalidTokenException;
use WootsUp\BuilderMcp\Auth\KeyService;

#[CoversClass(KeyService::class)]
final class KeyServiceTest extends TestCase
{
    private const SECRET_HEX = 'c0ffee00deadbeefc0ffee00deadbeefc0ffee00deadbeefc0ffee00deadbeef';

    public function test_generates_token_with_correct_format(): void
    {
        $service = new KeyService(self::SECRET_HEX);
        $token = $service->generate('kid-1', ['scope' => 'write', 'exp' => time() + 3600]);
        self::assertMatchesRegularExpression(
            '/^ytb_live_[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/',
            $token,
            'Token must be Stripe-style ytb_live_<payload>.<sig>.'
        );
    }

    public function test_verifies_valid_token_and_returns_payload(): void
    {
        $service = new KeyService(self::SECRET_HEX);
        $token = $service->generate('kid-1', ['scope' => 'write', 'exp' => time() + 3600]);
        $payload = $service->verify($token);

        self::assertSame('kid-1', $payload['kid']);
        self::assertSame('write', $payload['scope']);
        self::assertIsInt($payload['exp']);
    }

    public function test_rejects_token_with_tampered_signature(): void
    {
        $service = new KeyService(self::SECRET_HEX);
        $token = $service->generate('kid-1', ['scope' => 'write', 'exp' => time() + 3600]);

        // Flip the last 5 chars of the signature half.
        $tampered = substr($token, 0, -5) . 'AAAAA';

        $this->expectException(InvalidTokenException::class);
        $service->verify($tampered);
    }

    public function test_rejects_expired_token(): void
    {
        $service = new KeyService(self::SECRET_HEX);
        $token = $service->generate('kid-1', ['scope' => 'write', 'exp' => time() - 60]);

        $this->expectException(ExpiredTokenException::class);
        $service->verify($token);
    }

    public function test_rejects_non_integer_exp_claim(): void
    {
        // Wave-6 Fix 13: a payload that smuggles `exp` as a string instead
        // of an integer used to be silently ignored (`is_int` false →
        // expiry-check skipped). Now strict — must reject as InvalidToken.
        $service = new KeyService(self::SECRET_HEX);
        // Manually craft a token with a stringy exp.
        $payload = [
            'kid' => 'kid-1',
            'scope' => 'write',
            'exp' => '9999999999', // string, not int
        ];
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $payloadB64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $payloadB64, self::SECRET_HEX, true);
        $sigB64 = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
        $token = 'ytb_live_' . $payloadB64 . '.' . $sigB64;

        $this->expectException(InvalidTokenException::class);
        $service->verify($token);
    }

    public function test_rejects_token_with_malformed_format(): void
    {
        $service = new KeyService(self::SECRET_HEX);
        $this->expectException(InvalidTokenException::class);
        $service->verify('not-a-ytb-token');
    }

    public function test_rejects_token_signed_with_different_secret(): void
    {
        $a = new KeyService(self::SECRET_HEX);
        $b = new KeyService(str_repeat('f', 64));

        $token = $a->generate('kid-1', ['scope' => 'write', 'exp' => time() + 3600]);

        $this->expectException(InvalidTokenException::class);
        $b->verify($token);
    }

    public function test_generate_rejects_blank_kid(): void
    {
        $service = new KeyService(self::SECRET_HEX);
        $this->expectException(\InvalidArgumentException::class);
        $service->generate('', ['scope' => 'write', 'exp' => time() + 3600]);
    }

    public function test_generate_rejects_blank_secret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new KeyService('');
    }
}
