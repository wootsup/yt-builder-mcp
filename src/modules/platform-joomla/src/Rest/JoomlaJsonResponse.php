<?php
/**
 * Joomla JSON response helper.
 *
 * Builds Joomla\CMS\Response\JsonResponse-compatible envelopes that
 * match the WP-side `WP_REST_Response` byte-shape — same body schema,
 * same status codes, same `WWW-Authenticate` header on 401/403, same
 * `code/message/data` envelope. Cookbook §3.7.1 cross-platform parity
 * column lives or dies on this helper's fidelity.
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Rest
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Rest;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;

final class JoomlaJsonResponse
{
    /**
     * @param array<string, mixed> $payload Body payload (will be JSON-encoded
     *                                       with `JSON_UNESCAPED_SLASHES |
     *                                       JSON_UNESCAPED_UNICODE` for ETag-
     *                                       parity with the WP-side).
     * @param int                  $status  HTTP status code.
     * @param array<string,string> $headers Additional response headers
     *                                       (Content-Type is set by Joomla).
     */
    public static function send(
        CMSApplicationInterface $app,
        array $payload,
        int $status = 200,
        array $headers = []
    ): void {
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store', true);
        foreach ($headers as $name => $value) {
            $app->setHeader($name, $value, true);
        }

        // Joomla's response model doesn't carry a status-code setter on
        // CMSApplicationInterface — it's set via the HTTP response code
        // global. setHeader() with first-arg 'status' is the canonical
        // J5/6 way to do this.
        $app->setHeader('status', (string) $status, true);

        $body = \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $body = $body === false ? '{}' : $body;

        // CRITICAL (Wave 7 deploy-fix): on Joomla's ApiApplication the
        // response body MUST be ECHOED, not set via $app->setBody() nor via
        // $document->setBuffer(). The dispatch flow is:
        //   ApiApplication::dispatch()
        //     → $contents = ComponentHelper::renderComponent($component)
        //         (renderComponent does ob_start() … ob_get_clean() — it
        //          captures whatever the controller ECHOES)
        //     → $document->setBuffer($contents, ['type' => 'component'])
        //         (this OVERWRITES any buffer the controller set itself)
        //   ApiApplication::render() → setBody($document->render())
        // So a setBody()/setBuffer() inside the controller is discarded; only
        // echoed output survives (it becomes $contents → the document buffer
        // → the response body). We therefore echo here. Headers (incl. the
        // 'status' code) are still set on the application normally.
        //
        // R8-A4 P2 — output-buffer hygiene. ApiApplication captures echoed
        // output via ComponentHelper::renderComponent (ob_start … ob_get_clean).
        // A stray PHP notice/warning/deprecation emitted DURING handler
        // execution on a server with display_errors=On lands in that SAME
        // buffer and would prepend non-JSON bytes to our envelope → the
        // response is no longer valid JSON, silently breaking the client's
        // byte-shape error-classifier (and dispatch()'s catch(\Throwable)
        // does NOT trap notices). Discard any such stray output that is
        // sitting in the active buffer before we emit the JSON, so the body
        // is EXACTLY the envelope. We only clean (not close) the buffer:
        // ApiApplication owns the outer ob level and still does its
        // ob_get_clean() on it.
        if (\ob_get_level() > 0 && \ob_get_length() > 0) {
            \ob_clean();
        }
        echo $body;
    }

    /**
     * WP-compatible error envelope. Matches `WP_Error` JSON serialisation
     * exactly so the @wootsup/yt-builder-mcp client's error-classifier
     * works byte-identical against WP and Joomla.
     *
     * @param array<string,mixed> $data Extra data merged into `data:{…}`.
     */
    public static function error(
        CMSApplicationInterface $app,
        string $code,
        string $message,
        int $status,
        array $data = [],
        array $headers = []
    ): void {
        $payload = [
            'code'    => $code,
            'message' => $message,
            'data'    => \array_merge(['status' => $status], $data),
        ];
        self::send($app, $payload, $status, $headers);
    }
}
