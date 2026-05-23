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
use WootsUp\BuilderMcp\Elements\ItemContainerMap;
use WootsUp\BuilderMcp\Rest\EtagMiddleware;
use WootsUp\BuilderMcp\Rest\PointerControllerTrait;
use WootsUp\BuilderMcp\Rest\RestController;
use WootsUp\BuilderMcp\SourceBinding\BindingSerializer;
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

        // A4-fix: exclude multi-items suffixes so MultiItemsController routes
        // win (live-bug 2026-05-22 Maria-Story E2E).
        $pathPattern = '(?P<element_path>(?:(?!/(?:binding|multi-items/inspect|multi-items/clean-implode)$).)+)';

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
        // T7 (Audit-v3 B.9): each source row gains a `kind` alias of the
        // GraphQL `type` field. The MCP TS table mapper reads `kind` for
        // its KIND column; surfacing the alias server-side keeps that
        // column populated without a client-side rename. `type` stays for
        // backwards-compatibility with any caller pinned to the old key.
        $grouped = $this->registry->listAll();
        foreach ($grouped as $origin => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $i => $row) {
                if (is_array($row) && !isset($row['kind']) && isset($row['type'])) {
                    $grouped[$origin][$i]['kind'] = $row['type'];
                }
            }
        }

        // 1.0.1 Wave-1.8 P1 F-COLD-23: cold-agent S3 had to scan 210
        // sources to find the 1 native `posts` entry. Accept `?group=`
        // (origin filter — e.g. `wordpress`) and `?kind=` (graphql
        // type filter — e.g. `PostsQuery`) so callers can scope the
        // listing. Filters are additive — without them, the full
        // grouped listing is returned unchanged.
        $groupFilter = $request->get_param('group');
        if (is_string($groupFilter) && $groupFilter !== '') {
            $grouped = array_intersect_key($grouped, [$groupFilter => true]);
        }
        $kindFilter = $request->get_param('kind');
        if (is_string($kindFilter) && $kindFilter !== '') {
            $filteredKind = [];
            foreach ($grouped as $origin => $rows) {
                if (!is_array($rows)) {
                    continue;
                }
                $kept = [];
                foreach ($rows as $row) {
                    if (
                        is_array($row)
                        && isset($row['kind'])
                        && is_string($row['kind'])
                        && $row['kind'] === $kindFilter
                    ) {
                        $kept[] = $row;
                    }
                }
                if (count($kept) > 0) {
                    $filteredKind[$origin] = $kept;
                }
            }
            $grouped = $filteredKind;
        }

        return new \WP_REST_Response([
            'sources' => $grouped,
        ], 200);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_binding(\WP_REST_Request $request)
    {
        $templateId = (string) $request['template_id'];
        // 1.0.1 — Use the shared trait so rel_path (`/children/...`) and
        // fully-qualified pointers both work, matching the other element
        // endpoints.
        $pointer = self::pointerFromRequest($request, '/binding', $templateId);

        // 1.0.1 Wave-1.6 Audit-D-Gap: read endpoint still must reject
        // crafted cross-template / double-prefix pointers so an attacker
        // can't enumerate foreign-template bindings via this read path.
        $assertErr = $this->assertPointerWithinTemplate($templateId, $pointer, 'source_binding');
        if ($assertErr !== null) {
            return $assertErr;
        }

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

        // F-01: surface the canonical binding fields at the top level so
        // the MCP TS `handleElementGetBinding` reader sees `source_name`
        // and `field_mappings` directly. We keep `binding` as a nested
        // back-compat alias.
        //
        // D1 / T1 (F-01-Rest, 2026-05-22): also surface the full structured
        // record from BindingSerializer — `query_field`, `query_arguments`,
        // `directives` — so MCP-clients can introspect the GraphQL field
        // selector without re-parsing the raw source blob.
        $binding = self::extractBinding($node);
        $serialized = BindingSerializer::serialize($node);
        $hasBinding = $serialized !== null;
        $response = [
            'template_id' => $templateId,
            'path' => $pointer,
            'element_path' => $pointer,
            'source_name' => $binding['source_name'],
            'field_mappings' => $binding['field_mappings'],
            'has_binding' => $hasBinding,
            'binding' => $binding,
            'etag' => $this->reader->etag(),
        ];
        if ($serialized !== null) {
            if (isset($serialized['query_field'])) {
                $response['query_field'] = $serialized['query_field'];
            }
            if (isset($serialized['query_arguments'])) {
                $response['query_arguments'] = $serialized['query_arguments'];
            }
            if (isset($serialized['directives'])) {
                $response['directives'] = $serialized['directives'];
            }
            // Structured field_mappings (list-of-objects) — superset of the
            // back-compat dict shape under `field_mappings`. Field-level
            // filters survive here even when the dict-projection drops them.
            $response['field_mappings_structured'] = $serialized['field_mappings'];
            $response['raw_source'] = $serialized['raw_source'];
        }
        return new \WP_REST_Response($response, 200);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function put_binding(\WP_REST_Request $request)
    {
        $templateId = (string) $request['template_id'];
        $pointer = self::pointerFromRequest($request, '/binding', $templateId);

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

        /** @var mixed $fieldMappingsRaw */
        $fieldMappingsRaw = $params['field_mappings'] ?? null;
        $fieldMappings = null;
        if ($fieldMappingsRaw !== null) {
            if (!is_array($fieldMappingsRaw)) {
                return new \WP_Error(
                    'yootheme_builder_mcp.source_binding.invalid_body',
                    '`field_mappings` must be an object of {prop_name: source_field_name} strings.',
                    ['status' => 400],
                );
            }
            $normalized = [];
            foreach ($fieldMappingsRaw as $propName => $sourceField) {
                if (!is_string($propName) || $propName === '') {
                    return new \WP_Error(
                        'yootheme_builder_mcp.source_binding.invalid_body',
                        '`field_mappings` keys must be non-empty strings.',
                        ['status' => 400],
                    );
                }
                if (!is_string($sourceField)) {
                    return new \WP_Error(
                        'yootheme_builder_mcp.source_binding.invalid_body',
                        sprintf('`field_mappings["%s"]` must be a string source-field name.', $propName),
                        ['status' => 400],
                    );
                }
                $normalized[$propName] = $sourceField;
            }
            $fieldMappings = $normalized;
        }

        // bindingLevel — steers the Multi-Items binding pattern.
        $bindingLevel = 'auto';
        if (array_key_exists('bindingLevel', $params)) {
            /** @var mixed $rawLevel */
            $rawLevel = $params['bindingLevel'];
            if (!is_string($rawLevel) || !in_array($rawLevel, ['auto', 'container', 'item'], true)) {
                return new \WP_Error(
                    'yootheme_builder_mcp.source_binding.invalid_body',
                    '`bindingLevel` must be one of "auto" | "container" | "item".',
                    ['status' => 400],
                );
            }
            $bindingLevel = $rawLevel;
        }

        // 1.0.1 Wave-1.8 F-COLD-20: cold-agent S3 sent
        // `raw_source.query.arguments:{limit:5}` and got 200 OK back
        // even though the field was silently dropped. Detect unknown
        // top-level keys here + flag any `raw_source` content the
        // controller can't honour, so callers see what was ignored.
        $ignored = self::detectIgnoredBindingFields($params);

        return $this->mutateBinding($templateId, $pointer, $sourceName, $fieldMappings, $bindingLevel, $ignored);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function delete_binding(\WP_REST_Request $request)
    {
        $templateId = (string) $request['template_id'];
        $pointer = self::pointerFromRequest($request, '/binding', $templateId);

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
     * Writes a YOOtheme-canonical structured `source` object:
     * ```
     * source: {
     *   query: { name: "<source_name>" },
     *   props: { <prop_name>: { name: "<source_field>", filters: {} }, ... }
     * }
     * ```
     *
     * F-13 fix: previously stored `props.source` as a plain string which
     * YT's frontend rejected. Structured shape mirrors the canonical
     * layout (see YOOtheme native bindings + spike-source-bridge).
     *
     * @param array<string, string>|null $fieldMappings prop_name → source_field_name
     * @param list<string>                $ignoredFields field-keys the controller could not honour (surfaced in response)
     * @return \WP_REST_Response|\WP_Error
     */
    private function mutateBinding(
        string $templateId,
        string $pointer,
        ?string $sourceName,
        ?array $fieldMappings = null,
        string $bindingLevel = 'auto',
        array $ignoredFields = [],
    ) {
        // Wave-6 Fix 6: assert the pointer lives within the addressed template.
        // Wave-1.6 Audit-D-Gap: switched to the shared trait method so the
        // double-prefix detection added in Wave 1.5 also covers source
        // bindings (was elements-only before).
        $assertErr = $this->assertPointerWithinTemplate($templateId, $pointer, 'source_binding');
        if ($assertErr !== null) {
            return $assertErr;
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

        // D2 — Multi-Items pattern resolution. Only kicks in when we
        // are setting a source (not on unbind).
        $resolvedLevel = null;
        $resolvedWarning = null;
        if ($sourceName !== null) {
            $resolution = self::resolveBindingLevel(
                $bindingLevel,
                $node,
                $pointer,
                $state,
            );
            if ($resolution instanceof \WP_Error) {
                return $resolution;
            }
            // The resolver may have moved the pointer to a *_item child.
            $pointer = $resolution['pointer'];
            // Refresh the node so the post-mutation read sees the right one.
            $node = $this->elements->get($pointer);
            if ($node === null) {
                return new \WP_Error(
                    'yootheme_builder_mcp.source_binding.not_found',
                    sprintf('Element at "%s" not found.', $pointer),
                    ['status' => 404],
                );
            }
            $resolvedLevel = $resolution['level'];
            $resolvedWarning = $resolution['warning'] ?? null;
        }

        // Wave-6 Fix 5 (TOCTOU close): capture etag at start.
        $etagAtStart = $this->reader->etag();

        // Mutate props.source on the node in $state via JsonPointer.
        $sourcePtr = $pointer . '/props/source';
        if ($sourceName === null) {
            // Unbind: remove the prop if present (no-op if missing).
            JsonPointer::remove($state, $sourcePtr);
        } else {
            $sourceValue = self::buildSourceValue($sourceName, $fieldMappings);
            JsonPointer::set($state, $sourcePtr, $sourceValue);
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

        // Re-read the node to surface the canonical de-structured shape
        // back to the caller (so MCP-clients can pin a single response
        // contract for both PUT and GET).
        $updatedNode = $this->elements->get($pointer);
        $binding = $updatedNode !== null
            ? self::extractBinding($updatedNode)
            : ($sourceName === null
                ? ['source_name' => null, 'field_mappings' => []]
                : self::buildBindingResponse($sourceName, $fieldMappings));

        $response = [
            'template_id' => $templateId,
            'element_path' => $pointer,
            'binding' => $binding,
            'etag' => $this->reader->etag(),
        ];
        if ($resolvedLevel !== null) {
            $response['binding_level'] = $resolvedLevel;
        }
        if ($resolvedWarning !== null) {
            $response['warning'] = $resolvedWarning;
        }
        // 1.0.1 Wave-1.8 F-COLD-20: surface dropped/unsupported fields
        // so the caller can see at the wire level what was silently
        // ignored. Only emitted when non-empty, keeping the slim
        // common-case response unchanged.
        if (count($ignoredFields) > 0) {
            $response['dropped_fields'] = $ignoredFields;
            $response['dropped_fields_hint'] = 'These body fields were ignored because the controller currently only honours `source_name`, `field_mappings`, and `bindingLevel`. Persist `query.arguments` etc. via element_update_settings on `props.source`.';
        }
        return new \WP_REST_Response($response, 200);
    }

    /**
     * 1.0.1 Wave-1.8 F-COLD-20: walk the inbound body and collect the
     * field-paths the binding endpoint currently ignores. Cold agent
     * S3 sent `raw_source.query.arguments:{limit:5}` and got 200 back —
     * the field was silently dropped. Detect it explicitly so the
     * caller learns it must persist limits via `element_update_settings`
     * on `props.source`, not via the binding endpoint.
     *
     * @param array<mixed> $params raw request body
     * @return list<string> dotted field-paths the controller ignores
     */
    private static function detectIgnoredBindingFields(array $params): array
    {
        // Route-bound segments are URL captures, not body fields — WP
        // REST routes split them out before the controller sees the
        // body, but the test harness funnels everything through one
        // params array. Always silently accept them.
        $recognized = [
            'source_name', 'field_mappings', 'bindingLevel',
            'template_id', 'element_path',
        ];
        $ignored = [];
        // Top-level unknown keys → ignored verbatim.
        foreach (array_keys($params) as $key) {
            if (!is_string($key)) {
                continue;
            }
            if (in_array($key, $recognized, true)) {
                continue;
            }
            if ($key === 'raw_source') {
                // Inspect raw_source sub-paths the controller can't honour.
                $raw = $params[$key];
                if (!is_array($raw)) {
                    $ignored[] = 'raw_source';
                    continue;
                }
                if (isset($raw['query']) && is_array($raw['query'])) {
                    if (isset($raw['query']['arguments'])) {
                        $ignored[] = 'raw_source.query.arguments';
                    }
                }
                continue;
            }
            $ignored[] = $key;
        }
        return $ignored;
    }

    /**
     * Resolve the requested bindingLevel against the target node + state.
     *
     * Returns either:
     *   - WP_Error  — bindingLevel='item' on a container with no *_item child
     *   - array{pointer: string, level: 'item'|'container', warning?: string}
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $state
     * @return array{pointer: string, level: string, warning?: string}|\WP_Error
     */
    private static function resolveBindingLevel(
        string $requested,
        array $node,
        string $pointer,
        array $state,
    ) {
        $type = isset($node['type']) && is_string($node['type']) ? $node['type'] : '';
        $isContainer = ItemContainerMap::isContainer($type);
        $isItem = ItemContainerMap::isItem($type);

        // Targets that are neither container nor item — no Multi-Items
        // resolution applies; pass through.
        if (!$isContainer && !$isItem) {
            return ['pointer' => $pointer, 'level' => 'container'];
        }

        // Normalise 'auto'.
        if ($requested === 'auto') {
            $requested = $isItem ? 'item' : 'container';
        }

        if ($requested === 'item') {
            if ($isItem) {
                return ['pointer' => $pointer, 'level' => 'item'];
            }
            // Container target — resolve to first *_item child.
            $itemType = ItemContainerMap::itemOf($type);
            $childPointer = self::firstChildOfType($node, $pointer, (string) $itemType);
            if ($childPointer === null) {
                return new \WP_Error(
                    'yootheme_builder_mcp.source_binding.no_item_child',
                    sprintf(
                        'Container "%s" has no "%s" child element. Add one via element_add before binding at item level, or pass bindingLevel="container" to bind on the container itself.',
                        $type,
                        (string) $itemType,
                    ),
                    [
                        'status' => 400,
                        'container_type' => $type,
                        'item_type' => $itemType,
                        'hint' => sprintf(
                            'Use element_add to insert a "%s" under this container, then re-call bind_source.',
                            (string) $itemType,
                        ),
                    ],
                );
            }
            return ['pointer' => $childPointer, 'level' => 'item'];
        }

        // requested === 'container'. Emit the structural-warning when
        // the target has a matching *_item pair — caller asked for the
        // legacy pattern explicitly.
        if ($isContainer) {
            $itemType = ItemContainerMap::itemOf($type);
            return [
                'pointer' => $pointer,
                'level' => 'container',
                'warning' => sprintf(
                    'Binding lives on the container. YT-Pro SourceTransform::repeatSource clones the container N times instead of repeating items inside it. Move the binding to a "%s" child element for the canonical Multi-Items pattern.',
                    (string) $itemType,
                ),
            ];
        }

        // Item target with explicit bindingLevel='container' — odd but
        // legal; pass through without a warning (the user explicitly
        // asked for it on a non-container).
        return ['pointer' => $pointer, 'level' => 'container'];
    }

    /**
     * Find the first direct child of $node whose `type` matches
     * $itemType and return its JSON-Pointer; null if none.
     *
     * @param array<string, mixed> $node
     */
    private static function firstChildOfType(array $node, string $parentPointer, string $itemType): ?string
    {
        if (!isset($node['children']) || !is_array($node['children'])) {
            return null;
        }
        foreach ($node['children'] as $index => $child) {
            if (!is_array($child)) {
                continue;
            }
            if (isset($child['type']) && is_string($child['type']) && $child['type'] === $itemType) {
                return $parentPointer . '/children/' . (string) $index;
            }
        }
        return null;
    }

    /**
     * Sentinel string used in field_mappings to request a YT-Pro
     * "Node - Item (Source/Items)" INHERIT binding on the child item.
     *
     * Plain sentinel `__node_item__` → field name defaults to the prop
     * name itself.
     * `__node_item__:<field>` → use `<field>` as the source-field name
     * picked from the parent iteration source.
     */
    public const NODE_ITEM_SENTINEL = '__node_item__';

    /**
     * Build the YT-canonical `source` value written to `props.source`.
     *
     * Shape:
     *   `{query: {name: <source_name>}, props?: {<prop>: {name: <field>, filters: {}}}}`
     *
     * The optional `props` is only included when `$fieldMappings` is a
     * non-empty array — YT's renderer treats absence-of-`props` as
     * "let the element render with no field-bound props" (still iterates
     * the source).
     *
     * D5 — `__node_item__` sentinel emits the YT-Pro INHERIT shape:
     *   `{name: '${builder.source}', field?: <field>, filters: {}, inherit: true}`
     *
     * The `${builder.source}` token resolves at runtime to the parent
     * iteration source (see themes/yootheme/packages/builder/elements/
     * grid_item/element.json:190 for YT's own pattern).
     *
     * @param array<string, string>|null $fieldMappings
     * @return array<string, mixed>
     */
    private static function buildSourceValue(string $sourceName, ?array $fieldMappings): array
    {
        $value = [
            'query' => ['name' => $sourceName],
        ];
        if ($fieldMappings !== null && $fieldMappings !== []) {
            $propsOut = [];
            foreach ($fieldMappings as $propName => $sourceField) {
                $propsOut[$propName] = self::buildPropMapping((string) $propName, $sourceField);
            }
            $value['props'] = $propsOut;
        }
        return $value;
    }

    /**
     * Project a single field-mapping value into its on-disk shape.
     * Honours the `__node_item__` sentinel for YT-Pro INHERIT bindings.
     *
     * @return array<string, mixed>
     */
    private static function buildPropMapping(string $propName, string $sourceField): array
    {
        if ($sourceField === self::NODE_ITEM_SENTINEL) {
            // Plain sentinel: inherit from parent iteration source, use
            // the prop name itself as the field name.
            return [
                'name' => '${builder.source}',
                'filters' => new \stdClass(),
                'inherit' => true,
            ];
        }
        if (str_starts_with($sourceField, self::NODE_ITEM_SENTINEL . ':')) {
            $field = substr($sourceField, strlen(self::NODE_ITEM_SENTINEL) + 1);
            return [
                'name' => '${builder.source}',
                'field' => $field,
                'filters' => new \stdClass(),
                'inherit' => true,
            ];
        }
        // Plain field reference — normal binding.
        return [
            'name' => $sourceField,
            'filters' => new \stdClass(),
        ];
    }

    /**
     * Build the binding-response shape from an in-memory mutation when
     * the node-read after write fails (defense — the writer ran, so the
     * canonical shape is known).
     *
     * @param array<string, string>|null $fieldMappings
     * @return array{source_name: string, field_mappings: array<string, string>}
     */
    private static function buildBindingResponse(string $sourceName, ?array $fieldMappings): array
    {
        return [
            'source_name' => $sourceName,
            'field_mappings' => $fieldMappings ?? [],
        ];
    }

    // pointerFromRequest() now lives in PointerControllerTrait. The
    // SourcesController call-sites pass suffix='/binding' to strip that
    // action-suffix.

    /**
     * Pull the source-binding off a node and project it back to the
     * MCP-canonical `{source_name, field_mappings}` shape.
     *
     * D1 / T1 (F-01-Rest, 2026-05-22): delegates to BindingSerializer for
     * the structured parse, then projects field_mappings (which the
     * serializer emits as a list of {element_prop, source_field, filters?})
     * to the dict-of-strings shape that this REST envelope has historically
     * exposed (round-trip-safe with `PUT /binding`).
     *
     * D5 round-trip: the `__node_item__` sentinel is re-applied here so
     * MCP-clients see the SAME value they wrote.
     *
     * @param array<string, mixed> $node
     * @return array{source_name: string|null, field_mappings: array<string, string>}
     */
    private static function extractBinding(array $node): array
    {
        $serialized = BindingSerializer::serialize($node);
        if ($serialized === null) {
            return ['source_name' => null, 'field_mappings' => []];
        }

        // Dict-projection of field_mappings — preserves the historical
        // `{prop_name => source_field_name}` envelope this endpoint exposes.
        $rawSource = $serialized['raw_source'];
        $fieldMappings = [];
        if (is_array($rawSource) && isset($rawSource['props']) && is_array($rawSource['props'])) {
            foreach ($rawSource['props'] as $propName => $propValue) {
                if (!is_string($propName) || $propName === '') {
                    continue;
                }
                if (!is_array($propValue) || !isset($propValue['name']) || !is_string($propValue['name'])) {
                    continue;
                }
                $isInherit = isset($propValue['inherit']) && $propValue['inherit'] === true;
                if ($isInherit) {
                    if (isset($propValue['field']) && is_string($propValue['field']) && $propValue['field'] !== '') {
                        $fieldMappings[$propName] = self::NODE_ITEM_SENTINEL . ':' . $propValue['field'];
                    } else {
                        $fieldMappings[$propName] = self::NODE_ITEM_SENTINEL;
                    }
                } else {
                    $fieldMappings[$propName] = $propValue['name'];
                }
            }
        }

        return [
            'source_name' => $serialized['source_name'],
            'field_mappings' => $fieldMappings,
        ];
    }
}
