<?php
/**
 * ElementsController — REST endpoints for element inspection + mutation.
 *
 * Wave 2 Task 2.3 added the read paths. Wave 3 Task 3.4 adds the write
 * surface:
 *
 *   POST   /pages/{template_id}/elements
 *      → add a new element. Body: {parent_path, element_type, props, children}.
 *
 *   PUT    /pages/{template_id}/elements/{element_path}/settings
 *      → replace props on an element. Body: {props}. Requires If-Match.
 *
 *   DELETE /pages/{template_id}/elements/{element_path}
 *      → delete an element.
 *
 *   POST   /pages/{template_id}/elements/{element_path}/move
 *      → move an element. Body: {to_parent_path, to_index}. Requires If-Match.
 *
 *   POST   /pages/{template_id}/elements/{element_path}/clone
 *      → clone an element as a sibling.
 *
 * Every write-endpoint follows the same six-step flow:
 *   1) read current state via LayoutReader
 *   2) enforce optimistic-lock (If-Match) via EtagMiddleware
 *   3) mutate via ElementOps
 *   4) run save-transforms + persist via LayoutWriter::writeTemplate
 *   5) flush caches via CacheFlusher
 *   6) return new ETag in the response payload
 *
 * Bearer-authenticated.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Elements
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Elements;

use WootsUp\BuilderMcp\Cache\CacheFlusher;
use WootsUp\BuilderMcp\Inspection\Inspector;
use WootsUp\BuilderMcp\Rest\EtagMiddleware;
use WootsUp\BuilderMcp\Rest\PointerControllerTrait;
use WootsUp\BuilderMcp\Rest\RestController;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;

final class ElementsController extends RestController
{
    use PointerControllerTrait;

    /**
     * 1.0.1 Wave-1.8 audit-pass v2: explicit allow-list for the
     * `?include=` query param on element_list. Unknown tokens get
     * 400 with a hint listing this set.
     */
    private const ELEMENT_LIST_INCLUDE_ALLOWED = ['props'];

    private ?Inspector $inspector;

    public function __construct(
        private readonly ElementOps $ops,
        private readonly LayoutReader $reader,
        private readonly LayoutWriter $writer,
        private readonly CacheFlusher $cacheFlusher,
        \WootsUp\BuilderMcp\Auth\BearerVerifier $verifier,
        ?Inspector $inspector = null,
    ) {
        parent::__construct($verifier);
        $this->inspector = $inspector;
    }

    public function register_routes(): void
    {
        $read = $this->bearer_permission_for('read');
        $write = $this->bearer_permission_for('write');

        // -------- read endpoints (unchanged) -------------------------------
        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/elements', [
            'methods' => 'GET',
            'callback' => [$this, 'list_elements'],
            'permission_callback' => $read,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
            ],
        ]);

        // Wave-6 Fix 19: element_path is captured by a regex that excludes
        // the action-suffixes (`/move`, `/clone`, `/settings`, `/binding`).
        // This removes the suffix-stripping fragility in pointerFromRequest
        // and lets multiple routes share the same path-shape.
        // Negative-lookahead exclusions ensure action-routes win over the
        // generic element-get catch-all. Without `multi-items/...`, the
        // generic element-route swallows `/multi-items/inspect` requests
        // from MultiItemsController (live-bug 2026-05-22 Maria-Story E2E).
        $pathPattern = '(?P<element_path>(?:(?!/(?:move|clone|settings|binding|multi-items/inspect|multi-items/clean-implode)$).)+)';

        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/elements/' . $pathPattern, [
            'methods' => 'GET',
            'callback' => [$this, 'get_element'],
            'permission_callback' => $read,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
                'element_path' => ['type' => 'string', 'required' => true],
            ],
        ]);

        // -------- write endpoints (Wave 3) --------------------------------
        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/elements', [
            'methods' => 'POST',
            'callback' => [$this, 'add_element'],
            'permission_callback' => $write,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
            ],
        ]);

        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/elements/' . $pathPattern . '/settings', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_settings'],
            'permission_callback' => $write,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
                'element_path' => ['type' => 'string', 'required' => true],
            ],
        ]);

        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/elements/' . $pathPattern, [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_element'],
            'permission_callback' => $write,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
                'element_path' => ['type' => 'string', 'required' => true],
            ],
        ]);

        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/elements/' . $pathPattern . '/move', [
            'methods' => 'POST',
            'callback' => [$this, 'move_element'],
            'permission_callback' => $write,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
                'element_path' => ['type' => 'string', 'required' => true],
            ],
        ]);

        \register_rest_route(self::NAMESPACE, '/pages/(?P<template_id>[A-Za-z0-9_-]+)/elements/' . $pathPattern . '/clone', [
            'methods' => 'POST',
            'callback' => [$this, 'clone_element'],
            'permission_callback' => $write,
            'args' => [
                'template_id' => ['type' => 'string', 'required' => true],
                'element_path' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    /**
     * N-01 (Audit-v3): element_list accepts transport-safe query params —
     * `root_path` (subtree scope), `depth` (recursion cap), `limit` +
     * `cursor` (pagination). With `limit`, the response carries the
     * pagination envelope `{items, next_cursor, total}`; without it the
     * flat `{elements, total}` shape is preserved (backward-compatible).
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function list_elements(\WP_REST_Request $request)
    {
        $id = (string) $request['template_id'];

        // Collect the optional N-01 scoping/pagination params from the
        // query string. Each is forwarded only when actually supplied so
        // ElementOps::listOnTemplate keeps its backward-compatible
        // flat-list shape for plain `?` calls.
        $options = [];
        $rootPath = $request->get_param('root_path');
        if (is_string($rootPath) && $rootPath !== '') {
            $options['root_path'] = $rootPath;
        }
        $depth = $request->get_param('depth');
        if ($depth !== null && is_numeric($depth)) {
            $options['depth'] = (int) $depth;
        }
        $limit = $request->get_param('limit');
        if ($limit !== null && is_numeric($limit)) {
            $options['limit'] = (int) $limit;
        }
        $cursor = $request->get_param('cursor');
        if (is_string($cursor) && $cursor !== '') {
            $options['cursor'] = $cursor;
        }
        // 1.0.1 Wave-1.8 F-COLD-12: cold-agent S6 (a11y audit) had to
        // fall back to a full /layout dump because element_list only
        // surfaces `props_summary` (key names). Accept `?include=props`
        // to forward the full props map per row — caller-opt-in so the
        // default response stays slim.
        //
        // Wave-1.8 audit-pass v2 (A5 follow-up): explicit allow-list +
        // validation. Unknown include tokens get a structured 400 with
        // a hint listing the accepted set, mirroring the F-COLD-9/18
        // 404-hint pattern. Future include tokens (e.g. `etag`,
        // `binding`) just add to ELEMENT_LIST_INCLUDE_ALLOWED below.
        $include = $request->get_param('include');
        if (is_string($include) && $include !== '') {
            $tokens = array_values(array_filter(
                array_map(
                    static fn(string $s): string => trim($s),
                    explode(',', $include),
                ),
                static fn(string $s): bool => $s !== '',
            ));
            $unknown = array_values(array_diff($tokens, self::ELEMENT_LIST_INCLUDE_ALLOWED));
            if (count($unknown) > 0) {
                return new \WP_Error(
                    'yootheme_builder_mcp.elements.invalid_query',
                    sprintf(
                        '`include` tokens not recognized: %s. Accepted: %s.',
                        implode(', ', $unknown),
                        implode(', ', self::ELEMENT_LIST_INCLUDE_ALLOWED),
                    ),
                    [
                        'status' => 400,
                        'hint' => 'Pass a comma-separated list of allow-listed tokens, e.g. `include=props`.',
                    ],
                );
            }
            $options['include'] = $tokens;
        }

        $list = $this->ops->listOnTemplate($id, $options);
        if ($list === null) {
            return new \WP_Error(
                'yootheme_builder_mcp.elements.not_found',
                sprintf('Template "%s" not found.', $id),
                ['status' => 404],
            );
        }

        // Pagination envelope: ElementOps returns `{items, next_cursor,
        // total}` when `limit` was supplied. Pass it through verbatim
        // plus the ETag so clients can pin the snapshot the cursor is
        // valid against.
        if (isset($list['items']) && is_array($list['items'])) {
            return new \WP_REST_Response([
                'template_id' => $id,
                'items' => $list['items'],
                'next_cursor' => $list['next_cursor'] ?? null,
                'total' => $list['total'] ?? count($list['items']),
                'etag' => $this->reader->etag(),
            ], 200);
        }

        // F-02: flat-list shape — `total` is the recursive count from the
        // same walker that feeds pages_list.elements_count and
        // page_get_schema.total, so all three agree for any given state.
        return new \WP_REST_Response([
            'template_id' => $id,
            'elements' => $list,
            'total' => count($list),
            'etag' => $this->reader->etag(),
        ], 200);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_element(\WP_REST_Request $request)
    {
        $templateId = (string) $request['template_id'];
        $pointer = self::pointerFromRequest($request, '', $templateId);

        // Wave-1.5 (Audit B6a): surface double-prefix as a structured 400
        // before the layout walker silently 404s. The same assertion runs
        // on every write route; mirror it on read for consistent shape.
        if ($pointer !== '') {
            $assertErr = $this->assertPointerWithinTemplate($templateId, $pointer);
            if ($assertErr !== null) {
                return $assertErr;
            }
        }

        try {
            $node = $this->ops->get($pointer);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error(
                'yootheme_builder_mcp.elements.invalid_pointer',
                $e->getMessage(),
                ['status' => 400],
            );
        }

        if ($node === null) {
            // 1.0.1 Wave-1.8 F-COLD-9 / F-COLD-18: cold agents that
            // either (a) percent-encoded the path or (b) supplied the
            // layout-relative form without the `templates/<id>/` prefix
            // historically got a 404 echoing back the broken pointer
            // with no actionable hint. Add a `hint` field that diagnoses
            // the two most common shapes — mirrors the
            // `expected_etag` hint pattern on 412.
            return new \WP_Error(
                'yootheme_builder_mcp.elements.not_found',
                sprintf('Element at "%s" not found.', $pointer),
                [
                    'status' => 404,
                    'hint' => self::pathHintFor($pointer, $templateId),
                ],
            );
        }
        // F-01: surface the canonical normalized shape at the top level
        // ({element_type, props, children, has_binding, child_count}) — the
        // MCP TS handler reads these keys directly. We keep `element` as
        // a legacy alias carrying the raw node for back-compat with older
        // MCP-clients that may still walk the nested object.
        $view = ElementOps::flattenNode($node, $pointer);
        // 1.0.1 Wave-1.8 F-COLD-19: cold-agent S3 saw two `source` keys
        // with diverging values — `props.source` (the binding the
        // controller writes / renderer reads, authoritative) vs a stale
        // YT-storage-side `element.source`/`element.source_extended`
        // denormalized snapshot left behind by an earlier binding write.
        // Until we can safely strip the legacy keys (defer to 2.0.0 —
        // breaking), surface an additive pointer so cold agents know
        // which field carries truth and can ignore the legacy artifact.
        //
        // Wave-1.8 audit-pass v2 (A5 follow-up): only emit the pointer
        // when there's actually a binding to disambiguate. On bindings-
        // free elements the legacy `element.source` / `source_extended`
        // keys are absent too, so there's nothing to clarify — keeping
        // the pointer-key absent halves the noise for the common case.
        $payload = [
            'template_id' => $templateId,
            'path' => $pointer,
            'element_path' => $pointer,
            'element_type' => $view['element_type'],
            'type' => $view['type'],
            'props' => $view['props'],
            'children' => $view['children'],
            'has_binding' => $view['has_binding'],
            'child_count' => $view['child_count'],
            'label' => $view['label'] ?? null,
            'element' => $node,
            'etag' => $this->reader->etag(),
        ];
        if ($view['has_binding']) {
            $payload['_authoritative_source'] = 'props.source';
        }
        return new \WP_REST_Response($payload, 200);
    }

    // -----------------------------------------------------------------------
    // Wave 3 — write endpoints
    // -----------------------------------------------------------------------

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function add_element(\WP_REST_Request $request)
    {
        $templateId = (string) $request['template_id'];

        $current = $this->reader->etag();
        $lockError = EtagMiddleware::enforce($request, $current);
        if ($lockError !== null) {
            return $lockError;
        }

        $params = $request->get_json_params();
        $parentPath = isset($params['parent_path']) && is_string($params['parent_path']) ? $params['parent_path'] : '';
        // 1.0.1 — accept rel_path form (`children/...` / `/children/...`) in
        // addition to fully-qualified `/templates/<id>/layout/...`. The
        // normalizer also handles the missing-leading-slash case the
        // tool-sweep 2026-05-22 fix introduced.
        $parentPath = self::normalizeElementPath($parentPath, $templateId);
        $elementType = isset($params['element_type']) && is_string($params['element_type']) ? $params['element_type'] : '';
        if ($elementType === '') {
            return new \WP_Error(
                'yootheme_builder_mcp.elements.invalid_body',
                '`element_type` is required.',
                ['status' => 400],
            );
        }
        // F-11 (Maria-Audit 2026-05-22): validate `element_type` against the
        // live YT element-registry (or the static fallback catalogue when YT
        // is not loaded). Without this check, the controller happily wrote
        // unknown element-types into the layout tree — YT then refused to
        // render them and the customer saw silent failure.
        $inspector = $this->inspector();
        if (!$inspector->isKnownType($elementType)) {
            return new \WP_Error(
                'yootheme_builder_mcp.elements.invalid_type',
                sprintf(
                    'Unknown element-type "%s". Use yootheme_builder_element_types_list to discover valid values.',
                    $elementType,
                ),
                [
                    'status' => 400,
                    'element_type' => $elementType,
                    'hint' => 'Use yootheme_builder_element_types_list to discover valid values.',
                ],
            );
        }
        $props = isset($params['props']) && is_array($params['props']) ? $params['props'] : [];
        $children = isset($params['children']) && is_array($params['children']) ? $params['children'] : [];

        if ($parentPath !== '') {
            $err = $this->assertPointerWithinTemplate($templateId, $parentPath);
            if ($err !== null) {
                return $err;
            }
        }

        return $this->mutate($templateId, $request, function (array &$state) use ($templateId, $parentPath, $elementType, $props, $children): array {
            /** @var array<string, mixed> $props */
            /** @var list<array<string, mixed>> $children */
            $newPath = $this->ops->add($state, $templateId, $parentPath, $elementType, $props, $children);
            return ['element_path' => $newPath];
        });
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function update_settings(\WP_REST_Request $request)
    {
        $templateId = (string) $request['template_id'];
        $pointer = self::pointerFromRequest($request, '/settings', $templateId);

        $current = $this->reader->etag();
        $lockError = EtagMiddleware::enforce($request, $current, requireIfMatch: true);
        if ($lockError !== null) {
            return $lockError;
        }

        $params = $request->get_json_params();
        $props = isset($params['props']) && is_array($params['props']) ? $params['props'] : null;
        if ($props === null) {
            return new \WP_Error(
                'yootheme_builder_mcp.elements.invalid_body',
                '`props` (object) is required.',
                ['status' => 400],
            );
        }

        /** @var array<string, mixed> $props */
        $err = $this->assertPointerWithinTemplate($templateId, $pointer);
        if ($err !== null) {
            return $err;
        }

        // T5 / F-12 (Maria-Audit 2026-05-22): when `merge:true`, fetch the
        // current props and deep-merge the request body in-place. This
        // closes the read-modify-write race that clients otherwise face
        // when extending a sub-key without clobbering siblings. Default
        // remains "full replace" — back-compat with every existing caller.
        $merge = !empty($params['merge']);

        return $this->mutate($templateId, $request, function (array &$state) use ($templateId, $pointer, $props, $merge): array {
            // 1.0.1 Wave-1.8 F-COLD-21: surface the merge semantics +
            // (when full-replace) the list of top-level keys the caller
            // DIDN'T supply that consequently got dropped. The
            // Multi-Items workflow stumbles here repeatedly because
            // `props.source` (the binding) is invisible to a caller who
            // is iterating on `props.item_element` and supplies only
            // that one key — full-replace silently wipes the source.
            // Surfacing the dropped keys gives the caller a chance to
            // notice + recover (or switch to `merge:true`).
            $currentNode = JsonPointer::get($state, $pointer);
            $currentProps = (is_array($currentNode) && isset($currentNode['props']) && is_array($currentNode['props']))
                ? $currentNode['props']
                : [];
            /** @var array<string, mixed> $currentProps */

            if ($merge) {
                $merged = ElementOps::mergeProps($currentProps, $props);
                $this->ops->updateSettings($state, $templateId, $pointer, $merged);
                return [
                    'element_path' => $pointer,
                    'merge_mode' => 'merge',
                ];
            }

            $dropped = [];
            foreach (array_keys($currentProps) as $key) {
                if (!array_key_exists($key, $props)) {
                    $dropped[] = (string) $key;
                }
            }
            $this->ops->updateSettings($state, $templateId, $pointer, $props);
            $extra = [
                'element_path' => $pointer,
                'merge_mode' => 'replace',
            ];
            if (count($dropped) > 0) {
                $extra['replaced_top_level_props'] = $dropped;
            }
            return $extra;
        });
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function delete_element(\WP_REST_Request $request)
    {
        $templateId = (string) $request['template_id'];
        $pointer = self::pointerFromRequest($request, '', $templateId);

        $current = $this->reader->etag();
        $lockError = EtagMiddleware::enforce($request, $current, requireIfMatch: true);
        if ($lockError !== null) {
            return $lockError;
        }

        $err = $this->assertPointerWithinTemplate($templateId, $pointer);
        if ($err !== null) {
            return $err;
        }

        return $this->mutate($templateId, $request, function (array &$state) use ($templateId, $pointer): array {
            $this->ops->delete($state, $templateId, $pointer);
            return ['element_path' => $pointer];
        });
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function move_element(\WP_REST_Request $request)
    {
        $templateId = (string) $request['template_id'];
        $pointer = self::pointerFromRequest($request, '/move', $templateId);

        $current = $this->reader->etag();
        $lockError = EtagMiddleware::enforce($request, $current);
        if ($lockError !== null) {
            return $lockError;
        }

        $params = $request->get_json_params();
        $toParentPath = isset($params['to_parent_path']) && is_string($params['to_parent_path']) ? $params['to_parent_path'] : '';
        // 1.0.1 — accept rel_path form in addition to fully-qualified pointers.
        $toParentPath = self::normalizeElementPath($toParentPath, $templateId);
        $toIndex = isset($params['to_index']) && is_int($params['to_index']) ? $params['to_index'] : null;
        if ($toIndex === null) {
            return new \WP_Error(
                'yootheme_builder_mcp.elements.invalid_body',
                '`to_index` (int) is required.',
                ['status' => 400],
            );
        }

        $err = $this->assertPointerWithinTemplate($templateId, $pointer);
        if ($err !== null) {
            return $err;
        }
        if ($toParentPath !== '') {
            $err = $this->assertPointerWithinTemplate($templateId, $toParentPath);
            if ($err !== null) {
                return $err;
            }
        }

        return $this->mutate($templateId, $request, function (array &$state) use ($templateId, $pointer, $toParentPath, $toIndex): array {
            $newPath = $this->ops->move($state, $templateId, $pointer, $toParentPath, $toIndex);
            return ['element_path' => $newPath];
        });
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function clone_element(\WP_REST_Request $request)
    {
        $templateId = (string) $request['template_id'];
        $pointer = self::pointerFromRequest($request, '/clone', $templateId);

        $current = $this->reader->etag();
        $lockError = EtagMiddleware::enforce($request, $current);
        if ($lockError !== null) {
            return $lockError;
        }

        $err = $this->assertPointerWithinTemplate($templateId, $pointer);
        if ($err !== null) {
            return $err;
        }

        return $this->mutate($templateId, $request, function (array &$state) use ($templateId, $pointer): array {
            $newPath = $this->ops->clone($state, $templateId, $pointer);
            return ['element_path' => $newPath];
        });
    }

    /**
     * Common mutate-then-persist flow used by every write endpoint.
     *
     * Wave-6 Fix 5 (TOCTOU close): the read↔mutate↔persist sequence used to
     * only enforce ETag at the *start* of the request. A second writer that
     * landed between our initial read and our update_option could overwrite
     * silently. We now re-read state immediately before persistence and
     * recompute the ETag — if it has drifted from the request's If-Match
     * header (or from the ETag captured at the start), the write is aborted
     * with 412 instead of clobbering the concurrent change.
     *
     * @param callable(array<string, mixed>&): array<string, mixed> $mutator
     *        Receives the state by reference, returns extra payload keys.
     * @return \WP_REST_Response|\WP_Error
     */
    private function mutate(string $templateId, \WP_REST_Request $request, callable $mutator)
    {
        $state = $this->reader->read();
        if (!isset($state['templates'][$templateId]) || !is_array($state['templates'][$templateId])) {
            return new \WP_Error(
                'yootheme_builder_mcp.elements.not_found',
                sprintf('Template "%s" not found.', $templateId),
                ['status' => 404],
            );
        }

        $etagAtStart = $this->reader->etag();

        try {
            $extra = $mutator($state);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error(
                'yootheme_builder_mcp.elements.invalid_argument',
                $e->getMessage(),
                ['status' => 400],
            );
        }

        // Re-read immediately before persisting and confirm no concurrent
        // writer landed between our initial read and now (TOCTOU close).
        $etagNow = $this->reader->etag();
        if (!hash_equals($etagAtStart, $etagNow)) {
            return new \WP_Error(
                'yootheme_builder_mcp.precondition_failed',
                'State changed during write (TOCTOU). Re-read and retry.',
                [
                    'status' => 412,
                    'expected_etag' => $etagAtStart,
                    'current_etag' => $etagNow,
                    // F-12 (Maria-Audit 2026-05-22): hint MCP-clients to
                    // re-read via element_get (now that F-01 makes its
                    // response shape canonical) before retrying the write.
                    'hint' => 'Re-read the element via yootheme_builder_element_get and retry with the fresh ETag in If-Match.',
                ],
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

        $payload = array_merge([
            'template_id' => $templateId,
            'etag' => $this->reader->etag(),
        ], $extra);

        return new \WP_REST_Response($payload, 200);
    }

    // pointerFromRequest() and assertPointerWithinTemplate() now live in
    // PointerControllerTrait (Wave-6 R2.8 — single source of truth shared
    // with SourcesController).

    /**
     * Lazy Inspector accessor — keeps the constructor signature back-compat
     * (Inspector is injected by the module bootstrap when wired; tests that
     * build the controller directly without an Inspector get a default
     * static-catalogue Inspector here).
     */
    private function inspector(): Inspector
    {
        return $this->inspector ??= new Inspector();
    }

    /**
     * 1.0.1 Wave-1.8 F-COLD-9 / F-COLD-18: produce an actionable hint
     * for 404 not-found responses on the element-tools. Cold-agent S2
     * stumbled because it percent-encoded slashes (`%2F` literals
     * leak into the pointer); S3 stumbled because it sent the
     * layout-relative form without the `templates/<id>/` prefix.
     * Detect both shapes and emit a targeted one-line hint —
     * everything else gets the generic "verify path" hint.
     */
    private static function pathHintFor(string $pointer, string $templateId): string
    {
        // Percent-encoded slash leaked into the pointer.
        if (str_contains($pointer, '%2F') || str_contains($pointer, '%2f')) {
            return 'element_path uses LITERAL slashes — do NOT percent-encode `/` as `%2F`.';
        }
        // Layout-relative pointer (`/layout/...` or `/children/...`)
        // was passed but the controller couldn't normalize it (e.g.
        // pre-1.0.1 client form): nudge toward the canonical prefix.
        $expectedPrefix = '/templates/' . $templateId . '/';
        if ($pointer !== '' && !str_starts_with($pointer, $expectedPrefix)) {
            return sprintf(
                'element_path must start with `%s` (or use the rel_path form like `/children/0`). ' .
                'Discover paths via yootheme_builder_element_list or `layout_root_pointer` from page_get_layout.',
                $expectedPrefix,
            );
        }
        return 'Verify the path via yootheme_builder_element_list — paths are case-sensitive.';
    }
}
