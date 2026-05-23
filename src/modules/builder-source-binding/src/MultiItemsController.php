<?php
/**
 * MultiItemsController — REST endpoints for the YT-Pro Multi-Items
 * binding pattern (inspect + clean).
 *
 * Routes:
 *
 *   GET /pages/{template_id}/elements/{element_path}/multi-items/inspect
 *      → MultiItemsInspector report for the addressed element.
 *
 *   POST /pages/{template_id}/elements/{element_path}/multi-items/clean-implode
 *      → Strip every `props.source.props.*.implode` directive on the
 *        addressed element. Requires ETag.
 *
 * Bearer-authenticated.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\SourceBinding
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\SourceBinding;

use WootsUp\BuilderMcp\Auth\BearerVerifier;
use WootsUp\BuilderMcp\Cache\CacheFlusher;
use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\Rest\EtagMiddleware;
use WootsUp\BuilderMcp\Rest\PointerControllerTrait;
use WootsUp\BuilderMcp\Rest\RestController;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;

final class MultiItemsController extends RestController
{
    use PointerControllerTrait;

    public function __construct(
        private readonly MultiItemsInspector $inspector,
        private readonly ElementOps $elements,
        private readonly LayoutReader $reader,
        private readonly LayoutWriter $writer,
        private readonly CacheFlusher $cacheFlusher,
        BearerVerifier $verifier,
    ) {
        parent::__construct($verifier);
    }

    public function register_routes(): void
    {
        $read = $this->bearer_permission_for('read');
        $write = $this->bearer_permission_for('write');

        // Note: regex captures element_path including slashes; the
        // trailing action suffix (/multi-items/...) is stripped via
        // PointerControllerTrait::pointerFromRequest.
        $inspectPattern = '(?P<element_path>(?:(?!/multi-items/inspect$).)+)';
        $cleanPattern = '(?P<element_path>(?:(?!/multi-items/clean-implode$).)+)';

        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/elements/' . $inspectPattern . '/multi-items/inspect', [
            'methods' => 'GET',
            'callback' => [$this, 'inspect'],
            'permission_callback' => $read,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
                'element_path' => ['type' => 'string', 'required' => true],
            ],
        ]);

        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/elements/' . $cleanPattern . '/multi-items/clean-implode', [
            'methods' => 'POST',
            'callback' => [$this, 'clean_implode'],
            'permission_callback' => $write,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
                'element_path' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function inspect(\WP_REST_Request $request)
    {
        $templateId = (string) $request['template_id'];
        $pointer = self::pointerFromRequest($request, '/multi-items/inspect', $templateId);

        // 1.0.1 Wave-1.6 Audit-D-Gap: read endpoint still must reject
        // crafted cross-template / double-prefix pointers — they would
        // otherwise either silently 404 at the layout walker (confusing
        // for the agent) or leak a foreign-template node's container/item
        // shape via the report.
        $assertErr = $this->assertPointerWithinTemplate($templateId, $pointer, 'multi_items');
        if ($assertErr !== null) {
            return $assertErr;
        }

        $report = $this->inspector->inspect($templateId, $pointer);
        if ($report === null) {
            return new \WP_Error(
                'yootheme_builder_mcp.multi_items.not_found',
                sprintf('Element at "%s" not found.', $pointer),
                ['status' => 404],
            );
        }

        return new \WP_REST_Response([
            'template_id' => $templateId,
            'report' => $report,
            'etag' => $this->reader->etag(),
        ], 200);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function clean_implode(\WP_REST_Request $request)
    {
        $templateId = (string) $request['template_id'];
        $pointer = self::pointerFromRequest($request, '/multi-items/clean-implode', $templateId);

        $current = $this->reader->etag();
        $lockError = EtagMiddleware::enforce($request, $current, requireIfMatch: true);
        if ($lockError !== null) {
            return $lockError;
        }

        // Cross-template guard (mirrors the SourcesController defense).
        // Wave-1.6 Audit-D-Gap: switched to the shared trait method so the
        // double-prefix detection added in Wave 1.5 also covers this
        // endpoint (was elements-only before).
        $assertErr = $this->assertPointerWithinTemplate($templateId, $pointer, 'multi_items');
        if ($assertErr !== null) {
            return $assertErr;
        }

        // 1.0.1 Wave-1.7 audit-F F-SEC-1: capture the baseline etag BEFORE
        // any state read so the compare-and-swap below is anchored at the
        // pre-read snapshot, not a snapshot taken after `$node` had
        // already been loaded. ElementsController::mutate uses the same
        // ordering — mirroring it here keeps the TOCTOU window narrow.
        $etagAtStart = $this->reader->etag();

        $state = $this->reader->read();
        if (!isset($state['templates'][$templateId]) || !is_array($state['templates'][$templateId])) {
            return new \WP_Error(
                'yootheme_builder_mcp.multi_items.not_found',
                sprintf('Template "%s" not found.', $templateId),
                ['status' => 404],
            );
        }

        $node = $this->elements->get($pointer);
        if ($node === null) {
            return new \WP_Error(
                'yootheme_builder_mcp.multi_items.not_found',
                sprintf('Element at "%s" not found.', $pointer),
                ['status' => 404],
            );
        }

        $result = ImplodeDirectiveCleaner::clean($node);
        if ($result['cleaned_count'] === 0) {
            // Idempotent no-op.
            return new \WP_REST_Response([
                'template_id' => $templateId,
                'element_path' => $pointer,
                'cleaned_count' => 0,
                'removed_directives' => [],
                'new_etag' => $this->reader->etag(),
            ], 200);
        }

        // Persist cleaned node back into state.
        JsonPointer::set($state, $pointer, $result['node']);

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
            'cleaned_count' => $result['cleaned_count'],
            'removed_directives' => $result['removed_directives'],
            'new_etag' => $this->reader->etag(),
        ], 200);
    }
}
