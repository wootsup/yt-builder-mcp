<?php
/**
 * BearerVerifier — full "Authorization: Bearer …" header validator.
 *
 * Pipeline:
 *  1. Parse and strip the case-insensitive "Bearer " scheme prefix
 *  2. Delegate signature + `exp` to {@see KeyService::verify}
 *  3. Look up the token's `kid` in the {@see KeyStore}
 *  4. Reject with {@see RevokedTokenException} if `revoked_at` is set
 *  5. Return the verified claims
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Auth
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Auth;

final class BearerVerifier
{
    /**
     * Maximum allowed length of the full Authorization header value (in
     * bytes). Defense-in-depth against memory abuse — legitimate tokens
     * are ~200 bytes; 8 KiB is two orders of magnitude over that.
     */
    private const MAX_HEADER_BYTES = 8192;

    /**
     * @param KeyStoreInterface $keyStore Cross-platform keystore (WP: KeyStore,
     *        Joomla: \WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaKeyStore).
     *        Wave-2 type-widening preserves WP backward-compat — WP KeyStore
     *        already implements the interface so existing tests pass unchanged.
     */
    public function __construct(
        private readonly KeyService $keyService,
        private readonly KeyStoreInterface $keyStore,
    ) {
    }

    /**
     * Verify a full HTTP Authorization header value (e.g. "Bearer ytb_live_...").
     *
     * @return array<string, mixed> The decoded claims from the token.
     *
     * @throws InvalidTokenException If the header is malformed, the signature is wrong,
     *                               the format is wrong, or the kid is unknown.
     * @throws ExpiredTokenException If the token's `exp` claim is in the past,
     *                               or the keystore metadata's `expires_at` is in the past.
     * @throws RevokedTokenException If the kid has been marked revoked.
     */
    public function verify(string $authorizationHeader): array
    {
        if ($authorizationHeader === '') {
            throw new InvalidTokenException('Authorization header is empty.');
        }

        // Wave-6 Fix 12: cap header length before any further processing.
        if (strlen($authorizationHeader) > self::MAX_HEADER_BYTES) {
            throw new InvalidTokenException('Authorization header exceeds maximum size.');
        }

        // RFC 7235: scheme name is case-insensitive. Spec uses single space
        // separator; allow one or more whitespace chars to be lenient.
        if (!preg_match('/^Bearer\s+(\S+)$/i', $authorizationHeader, $m)) {
            throw new InvalidTokenException('Authorization header must use Bearer scheme.');
        }

        $token = $m[1];

        // 1+2: signature, format, exp.
        $claims = $this->keyService->verify($token);

        // 3: kid must be present and known.
        if (!isset($claims['kid']) || !is_string($claims['kid'])) {
            throw new InvalidTokenException('Token missing kid claim.');
        }

        $metadata = $this->keyStore->find($claims['kid']);
        if ($metadata === null) {
            throw new InvalidTokenException('Unknown kid.');
        }

        // 4: revocation check.
        if ($metadata['revoked_at'] !== null) {
            throw new RevokedTokenException('Key has been revoked.');
        }

        // Wave-6 Fix 3: keystore-side expiry — the token's payload `exp`
        // claim is verified by KeyService, but the operator may have
        // shortened the expiry server-side after issuing. Close that gap
        // by treating the keystore `expires_at` as authoritative.
        if (
            array_key_exists('expires_at', $metadata)
            && is_int($metadata['expires_at'])
            && $metadata['expires_at'] < time()
        ) {
            throw new ExpiredTokenException('Key has expired (per keystore metadata).');
        }

        return $claims;
    }
}
