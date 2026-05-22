<?php
/**
 * TestVerifierFactory — build a real BearerVerifier wired with an
 * in-memory KeyService + KeyStore for tests that need a controller
 * (which now requires a non-null verifier — Wave-6 Fix 1).
 *
 * Tests that don't drive the auth-gate use {@see verifier} to get a
 * functional instance; tests that DO want to exercise the gate use
 * {@see verifierWithKey} to get a valid bearer-token alongside.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests;

use WootsUp\BuilderMcp\Auth\BearerVerifier;
use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Auth\KeyStore;

final class TestVerifierFactory
{
    /**
     * Build a BearerVerifier using a deterministic test signing secret.
     * Useful for tests that only need to instantiate controllers.
     */
    public static function verifier(): BearerVerifier
    {
        $secret = bin2hex(random_bytes(32));
        return new BearerVerifier(new KeyService($secret), new KeyStore());
    }

    /**
     * Build a BearerVerifier + a valid bearer-token registered against it.
     * The token has the requested scope ("read"|"write"|"admin", default "write").
     *
     * @return array{verifier: BearerVerifier, token: string, kid: string}
     */
    public static function verifierWithKey(string $scope = 'write'): array
    {
        $secret = bin2hex(random_bytes(32));
        $keyService = new KeyService($secret);
        $keyStore = new KeyStore();
        $kid = bin2hex(random_bytes(8));
        $token = $keyService->generate($kid, [
            'scope' => $scope,
            'exp' => time() + 3600,
        ]);
        $keyStore->register($kid, [
            'label' => 'test',
            'scope' => $scope,
            'created_at' => time(),
            'expires_at' => null,
            'revoked_at' => null,
        ]);
        return [
            'verifier' => new BearerVerifier($keyService, $keyStore),
            'token' => $token,
            'kid' => $kid,
        ];
    }
}
