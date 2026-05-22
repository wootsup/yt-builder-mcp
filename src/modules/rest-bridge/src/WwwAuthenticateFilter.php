<?php
/**
 * WwwAuthenticateFilter — RFC-6750 §3 header injection on 401 responses.
 *
 * Wave 6 Round-2 R2.12. Bearer authentication MUST advertise its
 * realm/error/error_description via a `WWW-Authenticate` header on every
 * 401 response (RFC-6750 §3, "The WWW-Authenticate Response Header
 * Field"). MCP clients with auth-aware UX (Claude Desktop, Cursor)
 * inspect this header to render "your bearer is expired" toasts; without
 * it they silently fall through to generic "401 Unauthorized" text.
 *
 * The filter only attaches the header to responses inside the
 * `yt-builder-mcp/v1` namespace (other plugins' 401 responses are
 * none of our concern).
 *
 * Mapping (error_code → RFC-6750 error token):
 *   - bearer_invalid    → invalid_token
 *   - bearer_expired    → invalid_token
 *   - bearer_revoked    → invalid_token
 *   - insufficient_scope → insufficient_scope
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Rest
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Rest;

final class WwwAuthenticateFilter
{
    public const REALM = 'yt-builder-mcp';

    /**
     * Install the filter on `rest_post_dispatch`. Hooked from the
     * rest-bridge module bootstrap.
     */
    public static function install(): void
    {
        if (!function_exists('add_filter')) {
            return;
        }
        \add_filter('rest_post_dispatch', [self::class, 'inject'], 10, 3);
    }

    /**
     * `rest_post_dispatch` filter signature:
     *   ($response, $server, $request) → $response
     *
     * @param mixed $response The WP_REST_Response (or null on some paths).
     * @param mixed $server   The WP_REST_Server instance.
     * @param mixed $request  The WP_REST_Request that produced $response.
     * @return mixed The (possibly mutated) response, returned unchanged
     *               for any non-401/403 / out-of-namespace / non-object case.
     */
    public static function inject($response, $server = null, $request = null): mixed
    {
        if (!is_object($response) || !method_exists($response, 'get_status')) {
            return $response;
        }
        $status = $response->get_status();
        if ($status !== 401 && $status !== 403) {
            return $response;
        }
        if (!is_object($request) || !method_exists($request, 'get_route')) {
            return $response;
        }

        $route = (string) $request->get_route();
        // Only attach the header to OUR namespace's 401/403.
        if (!str_starts_with($route, '/' . PublicRestController::NAMESPACE)) {
            return $response;
        }

        // Derive the RFC-6750 error token from the error-code (when present).
        $error = 'invalid_token';
        $description = 'Authentication required.';
        if (method_exists($response, 'get_data')) {
            $data = $response->get_data();
            if (is_array($data) && isset($data['code']) && is_string($data['code'])) {
                $code = $data['code'];
                if (str_contains($code, 'insufficient_scope')) {
                    $error = 'insufficient_scope';
                } elseif (str_contains($code, 'bearer_expired')) {
                    $error = 'invalid_token';
                    $description = 'The bearer token has expired.';
                } elseif (str_contains($code, 'bearer_revoked')) {
                    $error = 'invalid_token';
                    $description = 'The bearer token has been revoked.';
                } elseif (str_contains($code, 'bearer_invalid')) {
                    $error = 'invalid_token';
                    $description = 'The bearer token is invalid.';
                }
            }
        }

        $header = sprintf(
            'Bearer realm="%s", error="%s", error_description="%s"',
            self::escape(self::REALM),
            self::escape($error),
            self::escape($description),
        );

        if (method_exists($response, 'header')) {
            $response->header('WWW-Authenticate', $header);
        }
        return $response;
    }

    /**
     * RFC-7235 §2.2 — quoted-string MUST NOT contain " or \. Escape both.
     */
    private static function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
