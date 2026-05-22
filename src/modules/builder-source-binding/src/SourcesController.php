<?php
/**
 * SourcesController — REST endpoints for source discovery + element binding.
 *
 * Wave 2 Task 2.5. Routes:
 *
 *   GET /sources
 *      → group-aware list of registered Builder sources
 *        `{apimapper: [...], wordpress: [...], essentials: [...]}`.
 *
 *   GET /pages/{template_id}/elements/{element_path}/binding
 *      → current source-binding for the addressed element (the `source`
 *        prop on the node, if any). Wave-2 read-only.
 *
 * Bearer-authenticated.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\SourceBinding
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\SourceBinding;

use WootsUp\BuilderMcp\Cache\CacheFlusher;
use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\Rest\EtagMiddleware;
use WootsUp\BuilderMcp\Rest\PointerControllerTrait;
use WootsUp\BuilderMcp\Rest\RestController;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;

final class SourcesController extends RestController
{
    use PointerControllerTrait;

    public function __construct(
        private readonly SourceRegistry $registry,
        private readonly ElementOps $elements,
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

        \register_rest_route(self::NAMESPACE, '/sources', [
            'methods' => 'GET',
            'callback' => [$this, 'list_sources'],
            'permission_callback' => $read,
        ]);

        $pathPattern = '(?P<element_path>(?:(?!/binding$).)+)';

        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/elements/' . $pathPattern . '/binding', [
            'methods' => 'GET',
            'callback' => [$this, 'get_binding'],
            'permission_callback' => $read,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
                'element_path' => ['type' => 'string', 'required' => true],
            ],
        ]);

        // Wave 3: bind / unbind via PUT and DELETE.
        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/elements/' . $pathPattern . '/binding', [
            'methods' => 'PUT',
            'callback' => [$this, 'put_binding'],
            'permission_callback' => $write,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
                'element_path' => ['type' => 'string', 'required' => true],
            ],
        ]);

        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/elements/' . $pathPattern . '/binding', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_binding'],
            'permission_callback' => $write,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
                'element_path' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public function list_sources(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'sources' => $this->registry->listAll(),
        ], 200);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_binding(\WP_REST_Request $request)
    {
        $rawPath = (string) $request['element_path'];
        // Strip trailing `/binding` if the regex captured it (route regex
        // is greedy but the named segment already excludes the suffix —
        // defensive nonetheless).
        if (str_ends_with($rawPath, '/binding')) {
            $rawPath = substr($rawPath, 0, -strlen('/binding'));
        }
        $pointer = $rawPath === '' ? '' : ($rawPath[0] === '/' ? $rawPath : '/' . $rawPath);

        try {
            $node = $this->elements->get($pointer);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error(
                'yootheme_builder_mcp.source_binding.invalid_pointer',
                $e->getMessage(),
                ['status' => 400],
            );
        }

        if ($node === null) {
            return new \WP_Error(
                'yootheme_builder_mcp.source_binding.not_found',
                sprintf('Element at "%s" not found.', $pointer),
                ['status' => 404],
            );
        }

        return new \WP_REST_Response([
            'path' => $pointer,
            'binding' => self::extractBinding($node),
        ], 200);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function put_binding(\WP_REST_Request $request)
    {
        $templateId = (string) $request['template_id'];
        $pointer = self::pointerFromRequest($request, '/binding');

        $current = $this->reader->etag();
        $lockError = EtagMiddleware::enforce($request, $current, requireIfMatch: true);
        if ($lockError !== null) {
            return $lockError;
        }

        $params = $request->get_json_params();
        if (!array_key_exists('source_name', $params)) {
            return new \WP_Error(
                'yootheme_builder_mcp.source_binding.invalid_body',
                '`source_name` is required (use null to unbind).',
                ['status' => 400],
            );
        }
        /** @var mixed $sourceName */
        $sourceName = $params['source_name'];
        if ($sourceName !== null && !is_string($sourceName)) {
            return new \WP_Error(
                'yootheme_builder_mcp.source_binding.invalid_body',
                '`source_name` must be string or null.',
                ['status' => 400],
            );
        }

        return $this->mutateBinding($templateId, $pointer, $sourceName);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function delete_binding(\WP_REST_Request $request)
    {
        $templateId = (string) $request['template_id'];
        $pointer = self::pointerFromRequest($request, '/binding');

        $current = $this->reader->etag();
        $lockError = EtagMiddleware::enforce($request, $current, requireIfMatch: true);
        if ($lockError !== null) {
            return $lockError;
        }

        return $this->mutateBinding($templateId, $pointer, null);
    }

    /**
     * Common bind/unbind flow.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    private function mutateBinding(string $templateId, string $pointer, ?string $sourceName)
    {
        // Wave-6 Fix 6: assert the pointer lives within the addressed template.
        $allowedPrefix = JsonPointer::compile(['templates', $templateId]);
        if (!JsonPointer::isWithinPrefix($pointer, $allowedPrefix)) {
            return new \WP_Error(
                'yootheme_builder_mcp.source_binding.cross_template_write_denied',
                sprintf(
                    'Pointer "%s" is not within template "%s".',
                    $pointer,
                    $templateId,
                ),
                ['status' => 400],
            );
        }

        $state = $this->reader->read();
        if (!isset($state['templates'][$templateId]) || !is_array($state['templates'][$templateId])) {
            return new \WP_Error(
                'yootheme_builder_mcp.source_binding.not_found',
                sprintf('Template "%s" not found.', $templateId),
                ['status' => 404],
            );
        }

        $node = $this->elements->get($pointer);
        if ($node === null) {
            return new \WP_Error(
                'yootheme_builder_mcp.source_binding.not_found',
                sprintf('Element at "%s" not found.', $pointer),
                ['status' => 404],
            );
        }

        // Wave-6 Fix 5 (TOCTOU close): capture etag at start.
        $etagAtStart = $this->reader->etag();

        // Mutate props.source on the node in $state via JsonPointer.
        $sourcePtr = $pointer . '/props/source';
        if ($sourceName === null) {
            // Unbind: remove the prop if present (no-op if missing).
            JsonPointer::remove($state, $sourcePtr);
        } else {
            JsonPointer::set($state, $sourcePtr, $sourceName);
        }

        // Re-verify ETag immediately before persisting.
        $etagNow = $this->reader->etag();
        if (!hash_equals($etagAtStart, $etagNow)) {
            return new \WP_Error(
                'yootheme_builder_mcp.precondition_failed',
                'State changed during write (TOCTOU). Re-read and retry.',
                ['status' => 412, 'expected_etag' => $etagAtStart, 'current_etag' => $etagNow],
            );
        }

        /** @var array<string, mixed> $tplTree */
        $tplTree = $state['templates'][$templateId];
        try {
            $this->writer->writeTemplate($templateId, $tplTree);
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
            'element_path' => $pointer,
            'binding' => [
                'source' => $sourceName,
            ],
            'etag' => $this->reader->etag(),
        ], 200);
    }

    // pointerFromRequest() now lives in PointerControllerTrait. The
    // SourcesController call-sites pass suffix='/binding' to strip that
    // action-suffix.

    /**
     * Pull the source-binding off a node. YOOtheme stores the binding
     * under `props.source` plus `source_config`/`source_args`. Wave-2
     * surfaces them as-is; Wave-3 will canonicalise into a richer shape.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function extractBinding(array $node): array
    {
        $props = isset($node['props']) && is_array($node['props']) ? $node['props'] : [];
        $binding = [];
        foreach (['source', 'source_config', 'source_args', 'source_filter', 'source_orderby', 'source_limit'] as $key) {
            if (array_key_exists($key, $props)) {
                $binding[$key] = $props[$key];
            }
        }
        return $binding;
    }
}
