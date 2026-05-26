<?php
/**
 * GET /v1/etag — top-level Builder-state ETag probe.
 *
 * Cookbook §3.2 Route 3 + §3.4.3. Cheap (one wp_option / one
 * #__extensions read) probe MCP clients hit before a write, to learn
 * the current state's ETag so they can pass it back as `If-Match` on
 * the subsequent mutation. Read-scope Bearer required.
 *
 * Mirrors the WP-side {@see \WootsUp\BuilderMcp\Pages\PagesController::get_etag}
 * payload byte-shape so the @wootsup/yt-builder-mcp client's response
 * mapper works against both platforms unchanged.
 *
 * @package    WootsUp\Component\Ytbmcp\Api\Controller
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Component\Ytbmcp\Api\Controller;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Platform\Joomla\Rest\AbstractApiController;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaJsonResponse;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutReader;

final class EtagController extends AbstractApiController
{
    /**
     * GET /v1/etag — return current state-wide ETag + RFC-3339 timestamp.
     *
     * Cookbook §3.4.3 / F-10 (Maria-Audit 2026-05-22): clients want
     * `generated_at` so they can tell a stale-cached document from a
     * fresh server probe. ISO-8601 (`gmdate('c')`) preserves the UTC
     * `+00:00` offset explicitly.
     */
    public function get(): void
    {
        $this->dispatch('read', function (array $claims): void {
            unset($claims);
            $reader = new JoomlaLayoutReader();
            JoomlaJsonResponse::send($this->app(), [
                'etag'         => $reader->etag(),
                'generated_at' => \gmdate('c'),
            ], 200);
        });
    }
}
