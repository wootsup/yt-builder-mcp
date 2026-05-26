<?php
/**
 * JoomlaEtagMiddleware — Joomla-side optimistic-lock enforcement.
 *
 * Pure-PHP mirror of {@see \WootsUp\BuilderMcp\Rest\EtagMiddleware} that
 * accepts a raw `If-Match` header string + the current ETag, and returns
 * either `null` (proceed) or an error-descriptor describing the 412 /
 * 428 response that the controller MUST emit.
 *
 * The WP-side middleware returns a {@see \WP_Error} keyed on the
 * `WP_REST_Request::get_header()` accessor — neither type exists under
 * Joomla. This shim keeps the algorithm 1:1 (same RFC-7232 §3.1
 * semantics, same canonical-quote-strip, same `hash_equals` constant-
 * time compare, same 412 / 428 status codes, same `expected_etag` data
 * payload, same `hint` text byte-for-byte parity with the WP wire-shape
 * cookbook §3.1.7 demands) while letting the Joomla controllers feed
 * their own header-reading + response-emission stack.
 *
 * Cookbook §3.1.6 / §4.3.3 + cookbook §3.7.1 cross-platform parity.
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Rest
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Rest;

defined('_JEXEC') or die;

final class JoomlaEtagMiddleware
{
    /**
     * Enforce optimistic-lock by comparing the supplied `If-Match` header
     * value against the current ETag.
     *
     * Behavioural contract (byte-identical with WP-side EtagMiddleware):
     *  - Missing header AND !$requireIfMatch → null   (proceed, no lock)
     *  - Missing header AND  $requireIfMatch → 428    (precondition required)
     *  - "*"                                  → null   (RFC-7232 §3.1 wildcard)
     *  - Exact match                          → null   (proceed)
     *  - Mismatch                             → 412    (precondition failed)
     *
     * @param string $suppliedHeader  Raw `If-Match` value (caller MUST have
     *                                 already read the header — pass "" when
     *                                 absent so the contract stays explicit).
     * @param string $currentEtag     Current ETag from LayoutReader::etag().
     * @param bool   $requireIfMatch  When true, missing header is itself a
     *                                 412/428 error (PUT / DELETE).
     *
     * @return array{status: int, code: string, message: string, data: array<string, mixed>}|null
     *         null when the request may proceed; otherwise an error-descriptor
     *         the caller emits via {@see JoomlaJsonResponse::error}.
     */
    public static function enforce(
        string $suppliedHeader,
        string $currentEtag,
        bool $requireIfMatch = false,
    ): ?array {
        $header = \trim($suppliedHeader);
        if ($header === '') {
            // Cookbook §3.1.6 (Wave-6 Fix 21): DELETE / PUT must require
            // an If-Match precondition — opt-in via $requireIfMatch=true.
            // POST creates may still proceed because the new resource has
            // no prior ETag to lock against.
            if ($requireIfMatch) {
                return [
                    'status'  => 428,
                    'code'    => 'yootheme_builder_mcp.if_match_required',
                    'message' => 'If-Match header is required for this method.',
                    'data'    => ['current_etag' => $currentEtag],
                ];
            }
            return null;
        }

        $supplied = self::stripQuotes($header);
        if ($supplied === '*') {
            // RFC-7232 §3.1 wildcard — matches any existing resource.
            return null;
        }

        if (\hash_equals($currentEtag, $supplied)) {
            return null;
        }

        return [
            'status'  => 412,
            'code'    => 'yootheme_builder_mcp.precondition_failed',
            'message' => \sprintf(
                'If-Match header (%s) does not match current resource ETag (%s).',
                $supplied,
                $currentEtag,
            ),
            'data'    => [
                'expected_etag' => $currentEtag,
                // F-12 (Maria-Audit 2026-05-22): the read-modify-write
                // cycle is functional now that F-01 makes element_get
                // surface the canonical shape — direct callers to re-read
                // before retrying. Same wording as the WP-side hint so
                // MCP-client error-classifiers stay byte-identical.
                'hint'          => 'Re-read via yootheme_builder_element_get and retry with the fresh ETag in If-Match.',
            ],
        ];
    }

    /**
     * Read the `If-Match` header off the active Joomla request without
     * relying on a {@see \WP_REST_Request} stand-in. Falls back to the
     * raw `$_SERVER['HTTP_IF_MATCH']` global when the Joomla Input filter
     * doesn't surface the header (CLI tests, custom dispatchers).
     *
     * Centralised here so every controller reads the header consistently
     * (case-insensitive, trimmed, NUL-byte stripped via Joomla's `string`
     * filter when available).
     */
    public static function readIfMatchHeader(): string
    {
        // 1) Joomla `Input::server` filter when available — strips NUL
        //    bytes + canonicalises whitespace. Required to feed the
        //    `hash_equals` constant-time compare safely.
        try {
            $app = \Joomla\CMS\Factory::getApplication();
            if (\method_exists($app, 'input') || \property_exists($app, 'input')) {
                /** @var \Joomla\Input\Input|null $input */
                $input = $app->input ?? null;
                if (\is_object($input) && \method_exists($input, 'server')) {
                    $value = $input->server->getString('HTTP_IF_MATCH', '');
                    if (\is_string($value) && $value !== '') {
                        return \trim($value);
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through to the $_SERVER read.
        }

        // 2) Bare $_SERVER fallback — for test harnesses and unusual
        //    Joomla dispatch chains that don't populate Input::server.
        if (isset($_SERVER['HTTP_IF_MATCH']) && \is_string($_SERVER['HTTP_IF_MATCH'])) {
            return \trim($_SERVER['HTTP_IF_MATCH']);
        }
        return '';
    }

    /**
     * Strip a surrounding pair of double-quotes if present (RFC-7232 §2.3).
     */
    private static function stripQuotes(string $value): string
    {
        $length = \strlen($value);
        if ($length >= 2 && $value[0] === '"' && $value[$length - 1] === '"') {
            return \substr($value, 1, -1);
        }
        return $value;
    }
}
