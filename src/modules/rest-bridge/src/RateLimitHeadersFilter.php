<?php
/**
 * RateLimitHeadersFilter — HTTP/1.1 §7.1.3 `Retry-After` header injection
 * on 429 responses emitted from the `yt-builder-mcp/v1` namespace.
 *
 * Wave-1 Fix C-4. The per-kid write rate-limit ({@see RateLimiter::checkWrite})
 * returns a {@see \WP_Error} from `permission_callback` so WP REST emits
 * status 429. That path however bypasses normal response-header handling,
 * so the `Retry-After` header was silently dropped pre-Wave-1. This filter
 * runs on `rest_post_dispatch` and adds the integer delta-seconds form of
 * `Retry-After` to every 429 response within our namespace.
 *
 * Out-of-namespace 429s (other plugins) are left untouched.
 * Non-429 statuses are no-ops.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Rest
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Rest;

final class RateLimitHeadersFilter
{
    /**
     * Install the filter on `rest_post_dispatch`. Hooked from the
     * rest-bridge module bootstrap.
     */
    public static function install(): void
    {
        if (!\function_exists('add_filter')) {
            return;
        }
        \add_filter('rest_post_dispatch', [self::class, 'inject'], 10, 3);
    }

    /**
     * `rest_post_dispatch` filter signature:
     *   ($response, $server, $request) → $response
     *
     * @param mixed $response The WP_REST_Response (or null on some paths).
     * @param mixed $server   The WP_REST_Server instance (unused).
     * @param mixed $request  The WP_REST_Request that produced $response.
     * @return mixed The (possibly mutated) response — unchanged for
     *               non-object / out-of-namespace / non-429 cases.
     */
    public static function inject($response, $server = null, $request = null): mixed
    {
        if (!\is_object($response) || !\method_exists($response, 'get_status')) {
            return $response;
        }
        if ($response->get_status() !== 429) {
            return $response;
        }
        if (!\is_object($request) || !\method_exists($request, 'get_route')) {
            return $response;
        }

        $route = (string) $request->get_route();
        // Only attach the header within OUR namespace.
        if (!\str_starts_with($route, '/' . PublicRestController::NAMESPACE)) {
            return $response;
        }

        if (\method_exists($response, 'header')) {
            $response->header('Retry-After', (string) RateLimiter::WINDOW_SECONDS);
        }
        return $response;
    }
}
