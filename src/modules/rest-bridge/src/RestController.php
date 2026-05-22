<?php
/**
 * RestController — abstract base for AUTHENTICATED yt-builder-mcp/v1
 * REST controllers.
 *
 * Subclasses implement {@see register_routes} to call
 * `register_rest_route()` for their endpoints. The base class exposes
 * {@see bearer_permission} (default `read` scope) and {@see bearer_permission_for}
 * (caller-specified minimum scope) callbacks that subclasses can hand to
 * `permission_callback`.
 *
 * Wave-6 security hardening:
 *  - The BearerVerifier is now NON-NULLABLE. Constructing this controller
 *    without a verifier is a wiring bug — the controller would otherwise
 *    fail-open. Public/unauth surfaces inherit from {@see PublicRestController}
 *    instead.
 *  - Scope enforcement: `bearer_permission_for($minScope)` returns a closure
 *    that verifies the bearer AND asserts the token's scope is sufficient
 *    against the hierarchy read < write < admin.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Rest
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Rest;

use WootsUp\BuilderMcp\Auth\BearerVerifier;
use WootsUp\BuilderMcp\Auth\ExpiredTokenException;
use WootsUp\BuilderMcp\Auth\InvalidTokenException;
use WootsUp\BuilderMcp\Auth\RevokedTokenException;
use WootsUp\BuilderMcp\Util\SecurityLogger;

abstract class RestController
{
    public const NAMESPACE = 'yt-builder-mcp/v1';

    /**
     * Scope hierarchy: a token with `admin` may access read/write/admin;
     * `write` may access read/write; `read` may access read only.
     *
     * @var array<string, int>
     */
    private const SCOPE_RANK = [
        'read' => 1,
        'write' => 2,
        'admin' => 3,
    ];

    public function __construct(protected readonly BearerVerifier $verifier)
    {
    }

    /**
     * Hook target — subclasses register routes here via `register_rest_route()`.
     */
    abstract public function register_routes(): void;

    /**
     * Default permission callback — requires a valid bearer with `read` scope.
     *
     * Returns true on valid token, otherwise a WP_Error with HTTP 401/403 (so
     * WordPress short-circuits the route handler with the correct status).
     *
     * @return true|\WP_Error
     */
    public function bearer_permission(\WP_REST_Request $request)
    {
        return $this->check_bearer($request, 'read');
    }

    /**
     * Returns a permission callback closure scoped to the given minimum scope.
     * Use this from `register_rest_route()` to require write/admin scopes:
     *
     *   'permission_callback' => $this->bearer_permission_for('write')
     *
     * @return callable(\WP_REST_Request): (true|\WP_Error)
     */
    public function bearer_permission_for(string $minScope): callable
    {
        if (!isset(self::SCOPE_RANK[$minScope])) {
            throw new \InvalidArgumentException(
                sprintf('Unknown scope "%s"; must be read|write|admin.', $minScope),
            );
        }
        return fn (\WP_REST_Request $request) => $this->check_bearer($request, $minScope);
    }

    /**
     * Internal: verify the bearer and enforce scope-hierarchy. Surfaces:
     *  - 401 Unauthorized: missing/invalid/expired/revoked token
     *  - 403 Forbidden:    valid token, insufficient scope
     *  - 503 Service Unavailable: BearerVerifier wiring missing (defense)
     *
     * @return true|\WP_Error
     */
    protected function check_bearer(\WP_REST_Request $request, string $minScope)
    {
        if (!isset(self::SCOPE_RANK[$minScope])) {
            return new \WP_Error(
                'yootheme_builder_mcp.configuration_error',
                sprintf('Unknown required scope "%s".', $minScope),
                ['status' => 500],
            );
        }

        $header = (string) $request->get_header('Authorization');

        try {
            $claims = $this->verifier->verify($header);
        } catch (ExpiredTokenException $e) {
            SecurityLogger::log(SecurityLogger::EVENT_BEARER_FAIL, [
                'reason' => 'expired',
                'route' => (string) $request->get_route(),
            ]);
            return new \WP_Error(
                'yootheme_builder_mcp.auth.bearer_expired',
                $e->getMessage(),
                ['status' => 401],
            );
        } catch (RevokedTokenException $e) {
            SecurityLogger::log(SecurityLogger::EVENT_BEARER_FAIL, [
                'reason' => 'revoked',
                'route' => (string) $request->get_route(),
            ]);
            return new \WP_Error(
                'yootheme_builder_mcp.auth.bearer_revoked',
                $e->getMessage(),
                ['status' => 401],
            );
        } catch (InvalidTokenException $e) {
            SecurityLogger::log(SecurityLogger::EVENT_BEARER_FAIL, [
                'reason' => 'invalid',
                'route' => (string) $request->get_route(),
            ]);
            return new \WP_Error(
                'yootheme_builder_mcp.auth.bearer_invalid',
                $e->getMessage(),
                ['status' => 401],
            );
        }

        $tokenScope = isset($claims['scope']) && is_string($claims['scope']) ? $claims['scope'] : 'read';
        $tokenRank = self::SCOPE_RANK[$tokenScope] ?? 0;
        $requiredRank = self::SCOPE_RANK[$minScope];

        if ($tokenRank < $requiredRank) {
            SecurityLogger::log(SecurityLogger::EVENT_SCOPE_DENY, [
                'token_scope' => $tokenScope,
                'required_scope' => $minScope,
                'route' => (string) $request->get_route(),
                'kid' => isset($claims['kid']) && is_string($claims['kid']) ? $claims['kid'] : null,
            ]);
            return new \WP_Error(
                'yootheme_builder_mcp.auth.insufficient_scope',
                sprintf(
                    'Token scope "%s" is insufficient (requires "%s" or higher).',
                    $tokenScope,
                    $minScope,
                ),
                ['status' => 403, 'required_scope' => $minScope, 'token_scope' => $tokenScope],
            );
        }

        // Wave-6 Fix 15: per-kid rate-limit on write/admin scopes.
        if ($requiredRank >= self::SCOPE_RANK['write']) {
            $kid = isset($claims['kid']) && is_string($claims['kid']) ? $claims['kid'] : '';
            $rateError = RateLimiter::checkWrite($kid);
            if ($rateError !== null) {
                return $rateError;
            }
        }

        return true;
    }
}
