<?php
/**
 * EtagMiddleware — optimistic-lock enforcement via the `If-Match` header.
 *
 * Wave 3 Task 3.2. Every Wave-3 write-endpoint passes the inbound
 * WP_REST_Request and the *current* ETag (from LayoutReader::etag()) here.
 * If the client supplied an `If-Match: <etag>` header and it does NOT
 * match the current state, this returns a WP_Error 412 Precondition
 * Failed so the route handler short-circuits without mutating anything.
 *
 * Missing `If-Match` is treated as "client opted out of optimistic-lock"
 * — we proceed but the response should carry a `Warning` header (caller's
 * responsibility, kept here as documentation).
 *
 * Spike-5 settled the choice of If-Match (not If-None-Match): the client
 * holds the ETag they last *read* and want the server to confirm nothing
 * has changed since. RFC-7232 §3.1.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Rest
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Rest;

final class EtagMiddleware
{
    /**
     * Enforce optimistic-lock by comparing the If-Match header against the
     * current ETag. Returns null when the request is permitted to proceed,
     * or a WP_Error 412 Precondition Failed when the header mismatches.
     *
     * Behavioural contract:
     *  - Missing header        → null (no enforcement, proceed)
     *  - "*"                   → null (RFC-7232 §3.1 wildcard, proceed)
     *  - Exact match           → null (proceed)
     *  - Mismatch              → WP_Error 412
     *
     * The header value may be wrapped in double-quotes per RFC-7232 §2.3
     * (`If-Match: "abc123"`); we strip them defensively.
     */
    public static function enforce(
        \WP_REST_Request $request,
        string $currentEtag,
        bool $requireIfMatch = false,
    ): ?\WP_Error {
        $header = (string) $request->get_header('If-Match');
        if ($header === '') {
            // Wave-6 Fix 21: DELETE / PUT must require If-Match — opt-in via
            // $requireIfMatch=true. POST creates may still proceed without
            // a precondition (the new resource has no prior ETag to lock).
            if ($requireIfMatch) {
                return new \WP_Error(
                    'yootheme_builder_mcp.if_match_required',
                    'If-Match header is required for this method.',
                    ['status' => 428, 'current_etag' => $currentEtag],
                );
            }
            return null;
        }

        $supplied = self::stripQuotes(trim($header));
        if ($supplied === '*') {
            // Wildcard matches any existing resource — proceed.
            return null;
        }

        if (hash_equals($currentEtag, $supplied)) {
            return null;
        }

        return new \WP_Error(
            'yootheme_builder_mcp.precondition_failed',
            sprintf(
                'If-Match header (%s) does not match current resource ETag (%s).',
                $supplied,
                $currentEtag,
            ),
            [
                'status' => 412,
                'expected_etag' => $currentEtag,
                // F-12 (Maria-Audit 2026-05-22): the read-modify-write
                // cycle is functional now that F-01 makes element_get
                // surface the canonical shape — direct callers to
                // re-read the element they want to mutate before retrying.
                'hint' => 'Re-read via yootheme_builder_element_get and retry with the fresh ETag in If-Match.',
            ],
        );
    }

    /**
     * Strip a surrounding pair of double-quotes if present.
     */
    private static function stripQuotes(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            return substr($value, 1, -1);
        }
        return $value;
    }
}
