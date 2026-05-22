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
        return new \WP_REST_Response([
            'template_id' => $id,
            'schema' => $schema,
            'etag' => $this->query->etag(),
        ], 200);
    }

    public function get_etag(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'etag' => $this->query->etag(),
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
     * Publish a template. Wave-3 stub: alias to save_page. Later waves may
     * bump `post_status` on the underlying WP post or trigger cache-warmers.
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
        if (is_array($data)) {
            $data['published'] = true;
            $resp->data = $data;
        }
        return $resp;
    }
}
