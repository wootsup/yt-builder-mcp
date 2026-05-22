<?php
/**
 * PagesController — REST endpoints under `yt-builder-mcp/v1` for
 * read-only template (= "page") inspection.
 *
 * Wave 2 Task 2.2. Routes:
 *
 *   GET /pages                              → list of templates with meta + etag
 *   GET /pages/{template_id}/layout         → full template tree
 *   GET /pages/{template_id}/schema         → flat list of nodes with paths
 *   GET /etag                               → top-level state ETag
 *
 * Every endpoint requires a valid Bearer-token via {@see RestController::bearer_permission}.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Pages
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Pages;

use WootsUp\BuilderMcp\Cache\CacheFlusher;
use WootsUp\BuilderMcp\Rest\EtagMiddleware;
use WootsUp\BuilderMcp\Rest\RestController;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;

final class PagesController extends RestController
{
    public function __construct(
        private readonly PageQuery $query,
        private readonly LayoutReader $reader,
        private readonly LayoutWriter $writer,
        private readonly CacheFlusher $cacheFlusher,
        \WootsUp\BuilderMcp\Auth\BearerVerifier $verifier,
    ) {
        parent::__construct($verifier);
    }

    public function register_routes(): void
    {
        $read = $this->bearer_permission_for('read');
        $write = $this->bearer_permission_for('write');

        \register_rest_route(self::NAMESPACE, '/pages', [
            'methods' => 'GET',
            'callback' => [$this, 'list_pages'],
            'permission_callback' => $read,
        ]);

        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/layout', [
            'methods' => 'GET',
            'callback' => [$this, 'get_layout'],
            'permission_callback' => $read,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
            ],
        ]);

        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/schema', [
            'methods' => 'GET',
            'callback' => [$this, 'get_schema'],
            'permission_callback' => $read,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
            ],
        ]);

        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/summary', [
            'methods' => 'GET',
            'callback' => [$this, 'get_summary'],
            'permission_callback' => $read,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
            ],
        ]);

        \register_rest_route(self::NAMESPACE, '/etag', [
            'methods' => 'GET',
            'callback' => [$this, 'get_etag'],
            'permission_callback' => $read,
        ]);

        // Wave 3: explicit save / publish actions.
        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/save', [
            'methods' => 'POST',
            'callback' => [$this, 'save_page'],
            'permission_callback' => $write,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
            ],
        ]);

        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/publish', [
            'methods' => 'POST',
            'callback' => [$this, 'publish_page'],
            'permission_callback' => $write,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public function list_pages(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'pages' => $this->query->list(),
            'etag' => $this->query->etag(),
        ], 200);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_layout(\WP_REST_Request $request)
    {
        $id = (string) $request['template_id'];
        $tpl = $this->query->layout($id);
        if ($tpl === null) {
            return new \WP_Error(
                'yootheme_builder_mcp.pages.not_found',
                sprintf('Template "%s" not found.', $id),
                ['status' => 404],
            );
        }
        return new \WP_REST_Response([
            'template_id' => $id,
            'layout' => $tpl,
            'etag' => $this->query->etag(),
        ], 200);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_schema(\WP_REST_Request $request)
    {
        $id = (string) $request['template_id'];
        $schema = $this->query->schema($id);
        if ($schema === null) {
            return new \WP_Error(
                'yootheme_builder_mcp.pages.not_found',
                sprintf('Template "%s" not found.', $id),
                ['status' => 404],
            );
        }
        // F-01/F-02: emit `nodes` (canonical wire name read by the MCP TS
        // mapper) AND `schema` (legacy alias for backwards-compat with
        // older builds). `total` is the recursive count from the same
        // walker used by pages_list.elements_count and element_list.total
        // so the three totals are guaranteed to agree.
        return new \WP_REST_Response([
            'template_id' => $id,
            'nodes' => $schema,
            'schema' => $schema,
            'total' => count($schema),
            'etag' => $this->query->etag(),
        ], 200);
    }

    /**
     * T9 (Audit-v3 B.5): GET /pages/{id}/summary — token-efficient
     * template overview (counts, depth, named landmarks) computed
     * server-side. Lets an agent grasp a 96-node template in one cheap
     * call instead of pulling the full element_list dump.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_summary(\WP_REST_Request $request)
    {
        $id = (string) $request['template_id'];
        $summary = $this->query->summary($id);
        if ($summary === null) {
            return new \WP_Error(
                'yootheme_builder_mcp.pages.not_found',
                sprintf('Template "%s" not found.', $id),
                ['status' => 404],
            );
        }
        return new \WP_REST_Response($summary, 200);
    }

    public function get_etag(\WP_REST_Request $request): \WP_REST_Response
    {
        unset($request); // unused, signature required by WP REST API.
        // F-10 (Maria-Audit 2026-05-22): callers (clients, MCP tools, CI
        // gates) want to know WHEN the ETag was computed — so they can
        // tell a stale-cached document from a fresh server probe. ISO-8601
        // (RFC-3339) with the `c` format preserves the timezone-offset
        // explicitly — UTC ends in `+00:00`, locale-defaults retain their
        // offset rather than collapsing to the server's local TZ.
        return new \WP_REST_Response([
            'etag' => $this->query->etag(),
            'generated_at' => \gmdate('c'),
        ], 200);
    }

    /**
     * Re-run save-transforms on a template and persist. This is the
     * explicit "save" trigger — useful when an MCP client wants the
     * Builder's normalisation pass to run without otherwise changing the
     * tree (e.g. after a series of writeByPointer calls).
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function save_page(\WP_REST_Request $request)
    {
        $templateId = (string) $request['template_id'];

        $current = $this->reader->etag();
        $lockError = EtagMiddleware::enforce($request, $current);
        if ($lockError !== null) {
            return $lockError;
        }

        $tpl = $this->reader->readTemplate($templateId);
        if ($tpl === null) {
            return new \WP_Error(
                'yootheme_builder_mcp.pages.not_found',
                sprintf('Template "%s" not found.', $templateId),
                ['status' => 404],
            );
        }

        try {
            $this->writer->writeTemplate($templateId, $tpl);
        } catch (\RuntimeException $e) {
            return new \WP_Error(
                'yootheme_builder_mcp.write_failed',
                $e->getMessage(),
                ['status' => 500],
            );
        }
        $this->cacheFlusher->flush();

        return new \WP_REST_Response([
            'template_id' => $templateId,
            'saved' => true,
            'etag' => $this->reader->etag(),
        ], 200);
    }

    /**
     * Publish a template.
     *
     * F-15 fix (Maria-Audit 2026-05-22): Wave-3 shipped a thin alias to
     * save_page() with a `published: true` marker. The audit observed
     * that this didn't reflect the real semantics — YOOtheme Pro
     * templates do not have a draft/publish lifecycle in the WordPress
     * post-status sense; they are "live" the instant they are saved.
     *
     * The structural fix has three parts:
     *
     *  1. Run save_page() to commit the state (this also bumps the
     *     monotonic revision — F-07 — so the post-publish ETag is
     *     guaranteed to differ from any prior state).
     *  2. Flush every cache layer that might still hold a pre-publish
     *     render (CacheFlusher already covers YT cache + scoped WP
     *     object-cache eviction).
     *  3. Persist the post-publish ETag in `wp_option('ytb_mcp_published_state_etag')`
     *     so subsequent callers can diff the published snapshot against
     *     the current draft. This is the closest analogue to a
     *     "published version" YT's data model supports.
     *
     * The response carries a `note` documenting (3) so MCP clients and
     * the future Joomla port have a stable explanation surface.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function publish_page(\WP_REST_Request $request)
    {
        $resp = $this->save_page($request);
        if ($resp instanceof \WP_Error) {
            return $resp;
        }
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        if (!is_array($data)) {
            return $resp;
        }

        // (2) Belt-and-braces cache flush. save_page() already calls
        // $this->cacheFlusher->flush() inside its successful path; we
        // re-invoke explicitly here so the publish-action is documented
        // as an idempotent cache-eviction trigger (the second flush is
        // cheap — wp_cache_delete on missing keys is a no-op).
        $this->cacheFlusher->flush();

        // (3) Snapshot the current ETag as the "published" state. We
        // pull a fresh ETag from the reader (which reflects the
        // just-bumped revision from save_page() → LayoutWriter::persist).
        $publishedEtag = $this->reader->etag();
        \update_option(self::PUBLISHED_STATE_ETAG_OPTION, $publishedEtag, false);

        $data['published'] = true;
        $data['published_state_etag'] = $publishedEtag;
        $data['note'] = 'YOOtheme templates publish on save; this is a cache-flush + state-snapshot operation.';
        $resp->data = $data;
        return $resp;
    }

    /**
     * wp_option key for the F-15 "last-published" ETag snapshot. Stored
     * with autoload=false — every publish_page() call writes it, but
     * only callers explicitly probing draft-vs-published need to read it.
     */
    public const PUBLISHED_STATE_ETAG_OPTION = 'ytb_mcp_published_state_etag';
}
