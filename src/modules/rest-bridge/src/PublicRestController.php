<?php
/**
 * PublicRestController — abstract base for INTENTIONALLY UNAUTHENTICATED
 * REST controllers (currently: only HealthController).
 *
 * Wave-6 split out of {@see RestController} so that the auth-required code
 * path can be fail-closed (constructor takes a non-null BearerVerifier).
 *
 * Subclasses MAY accept a BearerVerifier for tier-2 surfaces — e.g. the
 * Health controller exposes a minimal payload anonymously and a richer
 * payload when a valid bearer is supplied (Wave-6 Fix 11).
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Rest
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Rest;

use WootsUp\BuilderMcp\Auth\BearerVerifier;
use WootsUp\BuilderMcp\Auth\InvalidTokenException;

abstract class PublicRestController
{
    public const NAMESPACE = 'yt-builder-mcp/v1';

    public function __construct(protected readonly ?BearerVerifier $verifier = null)
    {
    }

    abstract public function register_routes(): void;

    /**
     * Returns true if the request carries a valid bearer token (any scope),
     * false otherwise. Used by subclasses to selectively expand the public
     * payload for authenticated callers.
     */
    protected function has_valid_bearer(\WP_REST_Request $request): bool
    {
        if ($this->verifier === null) {
            return false;
        }
        $header = (string) $request->get_header('Authorization');
        if ($header === '') {
            return false;
        }
        try {
            $this->verifier->verify($header);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
