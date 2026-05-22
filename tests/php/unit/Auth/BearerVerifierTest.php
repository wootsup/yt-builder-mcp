<?php
/**
 * BearerVerifier — composes KeyService + KeyStore to validate a full
 * "Authorization: Bearer ..." header end-to-end.
 *
 * Wave 1 Task 1.3.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Auth\BearerVerifier;
use WootsUp\BuilderMcp\Auth\ExpiredTokenException;
use WootsUp\BuilderMcp\Auth\InvalidTokenException;
use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Auth\KeyStore;
use WootsUp\BuilderMcp\Auth\RevokedTokenException;

#[CoversClass(BearerVerifier::class)]
final class BearerVerifierTest extends TestCase
{
    private const SECRET_HEX = 'c0ffee00deadbeefc0ffee00deadbeefc0ffee00deadbeefc0ffee00deadbeef';

    private KeyService $service;
    private KeyStore $store;
    private BearerVerifier $verifier;

    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [];
        $this->service = new KeyService(self::SECRET_HEX);
        $this->store = new KeyStore();
        $this->verifier = new BearerVerifier($this->service, $this->store);
    }

    public function test_verifies_well_formed_bearer_header(): void
    {
        $this->registerKid('kid-1', 'write');
        $token = $this->service->generate('kid-1', ['scope' => 'write', 'exp' => time() + 3600]);

        $claims = $this->verifier->verify('Bearer ' . $token);

        self::assertSame('kid-1', $claims['kid']);
        self::assertSame('write', $claims['scope']);
    }

    public function test_rejects_missing_bearer_prefix(): void
    {
        $this->registerKid('kid-1', 'write');
        $token = $this->service->generate('kid-1', ['scope' => 'write', 'exp' => time() + 3600]);

        $this->expectException(InvalidTokenException::class);
        $this->verifier->verify($token); // No "Bearer " prefix.
    }

    public function test_rejects_empty_authorization_header(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->verifier->verify('');
    }

    public function test_rejects_kid_unknown_to_keystore(): void
    {
        // Token signs cleanly, but kid not in store.
        $token = $this->service->generate('ghost-kid', ['scope' => 'write', 'exp' => time() + 3600]);

        $this->expectException(InvalidTokenException::class);
        $this->verifier->verify('Bearer ' . $token);
    }

    public function test_rejects_revoked_kid(): void
    {
        $this->registerKid('kid-rev', 'write');
        $this->store->revoke('kid-rev');

        $token = $this->service->generate('kid-rev', ['scope' => 'write', 'exp' => time() + 3600]);

        $this->expectException(RevokedTokenException::class);
        $this->verifier->verify('Bearer ' . $token);
    }

    public function test_rejects_expired_token(): void
    {
        $this->registerKid('kid-1', 'write');
        $token = $this->service->generate('kid-1', ['scope' => 'write', 'exp' => time() - 60]);

        $this->expectException(ExpiredTokenException::class);
        $this->verifier->verify('Bearer ' . $token);
    }

    public function test_rejects_token_with_tampered_signature(): void
    {
        $this->registerKid('kid-1', 'write');
        $token = $this->service->generate('kid-1', ['scope' => 'write', 'exp' => time() + 3600]);
        $tampered = substr($token, 0, -5) . 'AAAAA';

        $this->expectException(InvalidTokenException::class);
        $this->verifier->verify('Bearer ' . $tampered);
    }

    public function test_accepts_lowercase_bearer_keyword(): void
    {
        // RFC 7235 says the scheme name is case-insensitive.
        $this->registerKid('kid-1', 'write');
        $token = $this->service->generate('kid-1', ['scope' => 'write', 'exp' => time() + 3600]);

        $claims = $this->verifier->verify('bearer ' . $token);
        self::assertSame('kid-1', $claims['kid']);
    }

    public function test_rejects_keystore_side_expired_kid(): void
    {
        // Wave-6 Fix 3: keystore `expires_at` is authoritative even when the
        // token's `exp` claim is still in the future (operator may have
        // shortened the expiry post-issuance).
        $this->store->register('kid-x', [
            'label' => 'expired-by-store',
            'scope' => 'write',
            'created_at' => time() - 7200,
            'expires_at' => time() - 60,
            'revoked_at' => null,
        ]);
        $token = $this->service->generate('kid-x', ['scope' => 'write', 'exp' => time() + 3600]);

        $this->expectException(ExpiredTokenException::class);
        $this->verifier->verify('Bearer ' . $token);
    }

    public function test_rejects_header_exceeding_max_length(): void
    {
        // Wave-6 Fix 12: cap header length.
        $oversized = 'Bearer ' . str_repeat('A', 9000);
        $this->expectException(InvalidTokenException::class);
        $this->verifier->verify($oversized);
    }

    private function registerKid(string $kid, string $scope): void
    {
        $this->store->register($kid, [
            'label' => 'test',
            'scope' => $scope,
            'created_at' => time(),
            'expires_at' => null,
            'revoked_at' => null,
        ]);
    }
}
