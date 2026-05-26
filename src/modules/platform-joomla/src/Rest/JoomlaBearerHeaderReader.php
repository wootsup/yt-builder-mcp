<?php
/**
 * Reads the `Authorization: Bearer …` header from a Joomla request.
 *
 * Defends against the well-known mod_rewrite / mod_fcgid quirk where
 * the canonical `Authorization` header is stripped before reaching PHP
 * (a recurring Joomla-shared-hosting pain-point — Sentry-#XX captured
 * 2024-Q4). We probe four equivalent server-var keys in priority order
 * + Apache's `apache_request_headers()` fallback.
 *
 * Maps to Cookbook §S2 risk A2 + the Joomla-gotcha pin-suite item
 * "Authorization header stripped by mod_rewrite".
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Rest
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Rest;

defined('_JEXEC') or die;

final class JoomlaBearerHeaderReader
{
    /**
     * @return string The raw header value (e.g. `Bearer ytb_live_…`) or
     *                an empty string when absent. Caller MUST NOT trust
     *                non-empty as proof of authentication — pass through
     *                BearerVerifier::verify() to validate.
     */
    public static function read(): string
    {
        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION']          ?? null,
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['HTTP_X_AUTHORIZATION']        ?? null, // some reverse-proxies rename
        ];
        foreach ($candidates as $value) {
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        // apache_request_headers() is the canonical workaround when
        // mod_rewrite hides Authorization from $_SERVER. It returns
        // case-preserving keys, so we lowercase-scan.
        if (\function_exists('apache_request_headers')) {
            $headers = \apache_request_headers() ?: [];
            foreach ($headers as $name => $value) {
                if (\is_string($value) && \strcasecmp($name, 'Authorization') === 0) {
                    return $value;
                }
            }
        }

        return '';
    }
}
