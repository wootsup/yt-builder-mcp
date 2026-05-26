<?php
/**
 * SourcesController — REST endpoints for source discovery + element
 * binding.
 *
 * Cookbook §3.2 Routes 18-21 + §3.4.17-§3.4.20. Four routes:
 *
 *   GET    /v1/sources                                       → list_sources    (cookbook §3.4.17)
 *   GET    /v1/pages/:templateId/elements/:path/binding      → get_binding     (cookbook §3.4.18)
 *   PUT    /v1/pages/:templateId/elements/:path/binding      → put_binding     (cookbook §3.4.19)
 *   DELETE /v1/pages/:templateId/elements/:path/binding      → delete_binding  (cookbook §3.4.20)
 *
 * Mirrors the WP-side SourcesController byte-shape:
 *  - structured `sources` grouped by origin (apimapper/wordpress/essentials)
 *  - structured `binding` envelope on get/put (with `field_mappings_structured`,
 *    `raw_source`, `query_field`, `query_arguments`, `directives` superset)
 *  - YT-canonical `source` object written under `props.source` on put
 *    (cookbook §4.7 — `{query:{name:..}, props:{<el>:{name:..,filters:{}}}}`)
 *  - Multi-Items bindingLevel resolution (`auto|container|item`) with
 *    the `${builder.source}` INHERIT sentinel for `__node_item__` field
 *    mappings (cookbook §4.8.4 / D5)
 *  - PUT + DELETE require If-Match (cookbook §3.1.6)
 *
 * @package    WootsUp\Component\Ytbmcp\Api\Controller
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Component\Ytbmcp\Api\Controller;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\Elements\ItemContainerMap;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\AbstractApiController;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaEtagMiddleware;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaJsonResponse;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutReader;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutWriter;
use WootsUp\BuilderMcp\SourceBinding\BindingSerializer;
use WootsUp\BuilderMcp\SourceBinding\SourceRegistry;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class SourcesController extends AbstractApiController
{
    /**
     * Sentinel for the YT-Pro Multi-Items INHERIT binding. Mirrors
     * {@see \WootsUp\BuilderMcp\SourceBinding\SourcesController::NODE_ITEM_SENTINEL}.
     */
    public const NODE_ITEM_SENTINEL = '__node_item__';

    /**
     * GET /v1/sources — registered Builder sources grouped by origin.
     *
     * Cookbook §3.2 Route 18 + §3.4.17. Each row carries the `kind`
     * alias of `type` (T7 / Audit-v3 B.9 — MCP-TS column name). Accepts
     * `?group=` and `?kind=` filters (F-COLD-23) to scope the listing.
     */
    public function list(): void
    {
        $this->dispatch('read', function (array $claims): void {
            unset($claims);

            $registry = new SourceRegistry();
            $grouped  = $registry->listAll();
            foreach ($grouped as $origin => $rows) {
                if (!\is_array($rows)) {
                    continue;
                }
                foreach ($rows as $i => $row) {
                    if (\is_array($row) && !isset($row['kind']) && isset($row['type'])) {
                        $grouped[$origin][$i]['kind'] = $row['type'];
                    }
                }
            }

            $groupFilter = $this->queryString('group');
            if ($groupFilter !== '') {
                $grouped = \array_intersect_key($grouped, [$groupFilter => true]);
            }
            $kindFilter = $this->queryString('kind');
            if ($kindFilter !== '') {
                $filteredKind = [];
                foreach ($grouped as $origin => $rows) {
                    if (!\is_array($rows)) {
                        continue;
                    }
                    $kept = [];
                    foreach ($rows as $row) {
                        if (
                            \is_array($row)
                            && isset($row['kind'])
                            && \is_string($row['kind'])
                            && $row['kind'] === $kindFilter
                        ) {
                            $kept[] = $row;
                        }
                    }
                    if (\count($kept) > 0) {
                        $filteredKind[$origin] = $kept;
                    }
                }
                $grouped = $filteredKind;
            }

            JoomlaJsonResponse::send($this->app(), ['sources' => $grouped], 200);
        });
    }

    /**
     * GET /v1/pages/:templateId/elements/:path/binding — read binding.
     * Cookbook §3.2 Route 19 + §3.4.18.
     */
    public function get(): void
    {
        $this->dispatch('read', function (array $claims): void {
            unset($claims);
            $templateId = $this->templateIdParam();
            if ($templateId === '') {
                $this->emitBadRequest('templateId is required.');
                return;
            }
            $pointer = $this->pointerFromPath($templateId);

            // Read paths also enforce cross-template defense — a crafted
            // pointer would otherwise enumerate foreign-template bindings.
            if (($err = $this->assertPointerWithinTemplate($templateId, $pointer, 'source_binding')) !== null) {
                $this->emitLockError($err);
                return;
            }

            $reader = new JoomlaLayoutReader();
            $ops    = new ElementOps($reader);

            try {
                $node = $ops->get($pointer);
            } catch (\InvalidArgumentException $e) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.source_binding.invalid_pointer',
                    $e->getMessage(),
                    400,
                );
                return;
            }

            if ($node === null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.source_binding.not_found',
                    \sprintf('Element at "%s" not found.', $pointer),
                    404,
                );
                return;
            }

            $binding    = self::extractBinding($node);
            $serialized = BindingSerializer::serialize($node);
            $hasBinding = $serialized !== null;
            $response = [
                'template_id'    => $templateId,
                'path'           => $pointer,
                'element_path'   => $pointer,
                'source_name'    => $binding['source_name'],
                'field_mappings' => $binding['field_mappings'],
                'has_binding'    => $hasBinding,
                'binding'        => $binding,
                'etag'           => $reader->etag(),
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
                $response['field_mappings_structured'] = $serialized['field_mappings'];
                $response['raw_source']                = $serialized['raw_source'];
            }
            JoomlaJsonResponse::send($this->app(), $response, 200);
        });
    }

    /**
     * PUT /v1/pages/:templateId/elements/:path/binding — write / replace
     * binding. Cookbook §3.2 Route 20 + §3.4.19. REQUIRES If-Match.
     */
    public function put(): void
    {
        $this->dispatch('write', function (array $claims): void {
            unset($claims);
            $templateId = $this->templateIdParam();
            if ($templateId === '') {
                $this->emitBadRequest('templateId is required.');
                return;
            }
            $pointer = $this->pointerFromPath($templateId);

            $reader = new JoomlaLayoutReader();
            $writer = new JoomlaLayoutWriter();
            $ops    = new ElementOps($reader);

            $current   = $reader->etag();
            $lockError = JoomlaEtagMiddleware::enforce(
                JoomlaEtagMiddleware::readIfMatchHeader(),
                $current,
                true, // PUT requires If-Match.
            );
            if ($lockError !== null) {
                $this->emitLockError($lockError);
                return;
            }

            $params = $this->requestBody();
            if (!\array_key_exists('source_name', $params)) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.source_binding.invalid_body',
                    '`source_name` is required (use null to unbind).',
                    400,
                );
                return;
            }
            /** @var mixed $sourceName */
            $sourceName = $params['source_name'];
            if ($sourceName !== null && !\is_string($sourceName)) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.source_binding.invalid_body',
                    '`source_name` must be string or null.',
                    400,
                );
                return;
            }

            /** @var mixed $fieldMappingsRaw */
            $fieldMappingsRaw = $params['field_mappings'] ?? null;
            $fieldMappings = null;
            if ($fieldMappingsRaw !== null) {
                if (!\is_array($fieldMappingsRaw)) {
                    JoomlaJsonResponse::error(
                        $this->app(),
                        'yootheme_builder_mcp.source_binding.invalid_body',
                        '`field_mappings` must be an object of {prop_name: source_field_name} strings.',
                        400,
                    );
                    return;
                }
                $normalized = [];
                foreach ($fieldMappingsRaw as $propName => $sourceField) {
                    if (!\is_string($propName) || $propName === '') {
                        JoomlaJsonResponse::error(
                            $this->app(),
                            'yootheme_builder_mcp.source_binding.invalid_body',
                            '`field_mappings` keys must be non-empty strings.',
                            400,
                        );
                        return;
                    }
                    if (!\is_string($sourceField)) {
                        JoomlaJsonResponse::error(
                            $this->app(),
                            'yootheme_builder_mcp.source_binding.invalid_body',
                            \sprintf('`field_mappings["%s"]` must be a string source-field name.', $propName),
                            400,
                        );
                        return;
                    }
                    $normalized[$propName] = $sourceField;
                }
                $fieldMappings = $normalized;
            }

            $bindingLevel = 'auto';
            if (\array_key_exists('bindingLevel', $params)) {
                $rawLevel = $params['bindingLevel'];
                if (!\is_string($rawLevel) || !\in_array($rawLevel, ['auto', 'container', 'item'], true)) {
                    JoomlaJsonResponse::error(
                        $this->app(),
                        'yootheme_builder_mcp.source_binding.invalid_body',
                        '`bindingLevel` must be one of "auto" | "container" | "item".',
                        400,
                    );
                    return;
                }
                $bindingLevel = $rawLevel;
            }

            $ignored = self::detectIgnoredBindingFields($params);

            $this->mutateBinding(
                $reader,
                $writer,
                $ops,
                $templateId,
                $pointer,
                $sourceName,
                $fieldMappings,
                $bindingLevel,
                $ignored,
            );
        });
    }

    /**
     * DELETE /v1/pages/:templateId/elements/:path/binding — unbind.
     * Cookbook §3.2 Route 21 + §3.4.20. REQUIRES If-Match.
     */
    public function delete(): void
    {
        $this->dispatch('write', function (array $claims): void {
            unset($claims);
            $templateId = $this->templateIdParam();
            if ($templateId === '') {
                $this->emitBadRequest('templateId is required.');
                return;
            }
            $pointer = $this->pointerFromPath($templateId);

            $reader = new JoomlaLayoutReader();
            $writer = new JoomlaLayoutWriter();
            $ops    = new ElementOps($reader);

            $current   = $reader->etag();
            $lockError = JoomlaEtagMiddleware::enforce(
                JoomlaEtagMiddleware::readIfMatchHeader(),
                $current,
                true, // DELETE requires If-Match.
            );
            if ($lockError !== null) {
                $this->emitLockError($lockError);
                return;
            }

            $this->mutateBinding(
                $reader,
                $writer,
                $ops,
                $templateId,
                $pointer,
                null,
                null,
                'auto',
                [],
            );
        });
    }

    // -----------------------------------------------------------------
    // Internal helpers (mirror WP-side SourcesController::mutateBinding)
    // -----------------------------------------------------------------

    /**
     * Common bind/unbind flow.
     *
     * @param array<string, string>|null $fieldMappings prop_name → source_field_name
     * @param list<string>                $ignoredFields top-level body keys ignored by the controller
     */
    private function mutateBinding(
        JoomlaLayoutReader $reader,
        JoomlaLayoutWriter $writer,
        ElementOps $ops,
        string $templateId,
        string $pointer,
        ?string $sourceName,
        ?array $fieldMappings,
        string $bindingLevel,
        array $ignoredFields,
    ): void {
        $assertErr = $this->assertPointerWithinTemplate($templateId, $pointer, 'source_binding');
        if ($assertErr !== null) {
            $this->emitLockError($assertErr);
            return;
        }

        $state = $reader->read();
        if (!isset($state['templates'][$templateId]) || !\is_array($state['templates'][$templateId])) {
            JoomlaJsonResponse::error(
                $this->app(),
                'yootheme_builder_mcp.source_binding.not_found',
                \sprintf('Template "%s" not found.', $templateId),
                404,
            );
            return;
        }

        $node = $ops->get($pointer);
        if ($node === null) {
            JoomlaJsonResponse::error(
                $this->app(),
                'yootheme_builder_mcp.source_binding.not_found',
                \sprintf('Element at "%s" not found.', $pointer),
                404,
            );
            return;
        }

        // D2 — Multi-Items pattern resolution. Only kicks in when we are
        // setting a source (not on unbind).
        $resolvedLevel   = null;
        $resolvedWarning = null;
        if ($sourceName !== null) {
            $resolution = self::resolveBindingLevel($bindingLevel, $node, $pointer);
            if (\is_array($resolution) && isset($resolution['error'])) {
                $this->emitLockError($resolution['error']);
                return;
            }
            // The resolver may have moved the pointer to a *_item child.
            $pointer = $resolution['pointer'];
            $node = $ops->get($pointer);
            if ($node === null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.source_binding.not_found',
                    \sprintf('Element at "%s" not found.', $pointer),
                    404,
                );
                return;
            }
            $resolvedLevel   = $resolution['level'];
            $resolvedWarning = $resolution['warning'] ?? null;
        }

        $etagAtStart = $reader->etag();

        $sourcePtr = $pointer . '/props/source';
        if ($sourceName === null) {
            JsonPointer::remove($state, $sourcePtr);
        } else {
            $sourceValue = self::buildSourceValue($sourceName, $fieldMappings);
            JsonPointer::set($state, $sourcePtr, $sourceValue);
        }

        $etagNow = $reader->etag();
        if (!\hash_equals($etagAtStart, $etagNow)) {
            JoomlaJsonResponse::error(
                $this->app(),
                'yootheme_builder_mcp.precondition_failed',
                'State changed during write (TOCTOU). Re-read and retry.',
                412,
                ['expected_etag' => $etagAtStart, 'current_etag' => $etagNow],
            );
            return;
        }

        /** @var array<string, mixed> $tplTree */
        $tplTree = $state['templates'][$templateId];
        try {
            $writer->writeTemplate($templateId, $tplTree);
        } catch (\RuntimeException $e) {
            JoomlaJsonResponse::error(
                $this->app(),
                'yootheme_builder_mcp.write_failed',
                $e->getMessage(),
                500,
            );
            return;
        }
        self::flushCachesIfAvailable();

        $updatedNode = $ops->get($pointer);
        $binding = $updatedNode !== null
            ? self::extractBinding($updatedNode)
            : ($sourceName === null
                ? ['source_name' => null, 'field_mappings' => []]
                : self::buildBindingResponse($sourceName, $fieldMappings));

        $response = [
            'template_id'  => $templateId,
            'element_path' => $pointer,
            'binding'      => $binding,
            'etag'         => $reader->etag(),
        ];
        if ($resolvedLevel !== null) {
            $response['binding_level'] = $resolvedLevel;
        }
        if ($resolvedWarning !== null) {
            $response['warning'] = $resolvedWarning;
        }
        if (\count($ignoredFields) > 0) {
            $response['dropped_fields']      = $ignoredFields;
            $response['dropped_fields_hint'] = 'These body fields were ignored because the controller currently only honours `source_name`, `field_mappings`, and `bindingLevel`. Persist `query.arguments` etc. via element_update_settings on `props.source`.';
        }
        JoomlaJsonResponse::send($this->app(), $response, 200);
    }

    /**
     * Detect inbound body keys the controller cannot honour. Mirrors
     * WP-side `SourcesController::detectIgnoredBindingFields` (F-COLD-20).
     *
     * @param array<mixed> $params raw request body
     * @return list<string>
     */
    private static function detectIgnoredBindingFields(array $params): array
    {
        $recognized = [
            'source_name', 'field_mappings', 'bindingLevel',
            'template_id', 'element_path', 'templateId', 'path',
        ];
        $ignored = [];
        foreach (\array_keys($params) as $key) {
            if (!\is_string($key)) {
                continue;
            }
            if (\in_array($key, $recognized, true)) {
                continue;
            }
            if ($key === 'raw_source') {
                $raw = $params[$key];
                if (!\is_array($raw)) {
                    $ignored[] = 'raw_source';
                    continue;
                }
                if (isset($raw['query']) && \is_array($raw['query']) && isset($raw['query']['arguments'])) {
                    $ignored[] = 'raw_source.query.arguments';
                }
                continue;
            }
            $ignored[] = $key;
        }
        return $ignored;
    }

    /**
     * Resolve bindingLevel ∈ {auto, container, item} against the node.
     * Mirrors WP-side `SourcesController::resolveBindingLevel`.
     *
     * @param array<string, mixed> $node
     * @return array{pointer: string, level: string, warning?: string, error?: array{status: int, code: string, message: string, data: array<string, mixed>}}
     */
    private static function resolveBindingLevel(string $requested, array $node, string $pointer): array
    {
        $type        = \is_string($node['type'] ?? null) ? $node['type'] : '';
        $isContainer = ItemContainerMap::isContainer($type);
        $isItem      = ItemContainerMap::isItem($type);

        if (!$isContainer && !$isItem) {
            return ['pointer' => $pointer, 'level' => 'container'];
        }

        if ($requested === 'auto') {
            $requested = $isItem ? 'item' : 'container';
        }

        if ($requested === 'item') {
            if ($isItem) {
                return ['pointer' => $pointer, 'level' => 'item'];
            }
            $itemType     = ItemContainerMap::itemOf($type);
            $childPointer = self::firstChildOfType($node, $pointer, (string) $itemType);
            if ($childPointer === null) {
                return [
                    'pointer' => $pointer,
                    'level'   => 'item',
                    'error'   => [
                        'status'  => 400,
                        'code'    => 'yootheme_builder_mcp.source_binding.no_item_child',
                        'message' => \sprintf(
                            'Container "%s" has no "%s" child element. Add one via element_add before binding at item level, or pass bindingLevel="container" to bind on the container itself.',
                            $type,
                            (string) $itemType,
                        ),
                        'data'    => [
                            'container_type' => $type,
                            'item_type'      => $itemType,
                            'hint'           => \sprintf(
                                'Use element_add to insert a "%s" under this container, then re-call bind_source.',
                                (string) $itemType,
                            ),
                        ],
                    ],
                ];
            }
            return ['pointer' => $childPointer, 'level' => 'item'];
        }

        if ($isContainer) {
            $itemType = ItemContainerMap::itemOf($type);
            return [
                'pointer' => $pointer,
                'level'   => 'container',
                'warning' => \sprintf(
                    'Binding lives on the container. YT-Pro SourceTransform::repeatSource clones the container N times instead of repeating items inside it. Move the binding to a "%s" child element for the canonical Multi-Items pattern.',
                    (string) $itemType,
                ),
            ];
        }

        return ['pointer' => $pointer, 'level' => 'container'];
    }

    /**
     * Find the first direct child of $node whose `type` matches $itemType.
     *
     * @param array<string, mixed> $node
     */
    private static function firstChildOfType(array $node, string $parentPointer, string $itemType): ?string
    {
        if (!isset($node['children']) || !\is_array($node['children'])) {
            return null;
        }
        foreach ($node['children'] as $index => $child) {
            if (!\is_array($child)) {
                continue;
            }
            if (isset($child['type']) && \is_string($child['type']) && $child['type'] === $itemType) {
                return $parentPointer . '/children/' . (string) $index;
            }
        }
        return null;
    }

    /**
     * Build the YT-canonical `source` value written to `props.source`.
     * Cookbook §4.7 + D5 (INHERIT sentinel).
     *
     * @param array<string, string>|null $fieldMappings
     * @return array<string, mixed>
     */
    private static function buildSourceValue(string $sourceName, ?array $fieldMappings): array
    {
        $value = ['query' => ['name' => $sourceName]];
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
     * Map a field-mapping value to its on-disk shape. Honours the
     * `__node_item__` sentinel for YT-Pro INHERIT bindings (D5).
     *
     * @return array<string, mixed>
     */
    private static function buildPropMapping(string $propName, string $sourceField): array
    {
        if ($sourceField === self::NODE_ITEM_SENTINEL) {
            return [
                'name'    => '${builder.source}',
                'filters' => new \stdClass(),
                'inherit' => true,
            ];
        }
        if (\str_starts_with($sourceField, self::NODE_ITEM_SENTINEL . ':')) {
            $field = \substr($sourceField, \strlen(self::NODE_ITEM_SENTINEL) + 1);
            return [
                'name'    => '${builder.source}',
                'field'   => $field,
                'filters' => new \stdClass(),
                'inherit' => true,
            ];
        }
        return [
            'name'    => $sourceField,
            'filters' => new \stdClass(),
        ];
    }

    /**
     * Fallback binding-response shape used when the post-write node-read
     * fails (defense — the writer ran so the canonical shape is known).
     *
     * @param array<string, string>|null $fieldMappings
     * @return array{source_name: string, field_mappings: array<string, string>}
     */
    private static function buildBindingResponse(string $sourceName, ?array $fieldMappings): array
    {
        return [
            'source_name'    => $sourceName,
            'field_mappings' => $fieldMappings ?? [],
        ];
    }

    /**
     * Project the on-disk `props.source` blob back to the canonical
     * `{source_name, field_mappings}` envelope. Mirrors WP-side
     * `SourcesController::extractBinding` (D1 / T1).
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

        $rawSource     = $serialized['raw_source'];
        $fieldMappings = [];
        if (\is_array($rawSource) && isset($rawSource['props']) && \is_array($rawSource['props'])) {
            foreach ($rawSource['props'] as $propName => $propValue) {
                if (!\is_string($propName) || $propName === '') {
                    continue;
                }
                if (!\is_array($propValue) || !isset($propValue['name']) || !\is_string($propValue['name'])) {
                    continue;
                }
                $isInherit = isset($propValue['inherit']) && $propValue['inherit'] === true;
                if ($isInherit) {
                    if (isset($propValue['field']) && \is_string($propValue['field']) && $propValue['field'] !== '') {
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
            'source_name'    => $serialized['source_name'],
            'field_mappings' => $fieldMappings,
        ];
    }

    // -----------------------------------------------------------------
    // Shared infra (path-param / pointer / errors / cache-flush)
    // Same patterns as ElementsController; kept inline so each controller
    // file is self-contained (cookbook §3.4 controller-as-thin-adapter).
    // -----------------------------------------------------------------

    private function templateIdParam(): string
    {
        // Wave-7 deploy-fix: pathParam() reads $input->post too (consistency
        // with the other controllers; binding routes are GET/PUT/DELETE so the
        // var is in $input, but the fallback is harmless and future-proof).
        return $this->pathParam('templateId', '');
    }

    private function pointerFromPath(string $templateId): string
    {
        $raw = $this->input?->get('path', '', 'raw');
        if (!\is_string($raw)) {
            return '';
        }
        return self::normalizeElementPath($raw, $templateId);
    }

    private static function normalizeElementPath(string $rawPath, ?string $templateId = null): string
    {
        if ($rawPath === '') {
            return '';
        }
        if (\str_starts_with($rawPath, '/templates/') || \str_starts_with($rawPath, 'templates/')) {
            return $rawPath[0] === '/' ? $rawPath : '/' . $rawPath;
        }
        $relPath = $rawPath[0] === '/' ? $rawPath : '/' . $rawPath;
        if ($templateId !== null && $templateId !== '' && \str_starts_with($relPath, '/children/')) {
            return '/templates/' . $templateId . '/layout' . $relPath;
        }
        if ($templateId !== null && $templateId !== '' && $relPath === '/') {
            return '/templates/' . $templateId . '/layout';
        }
        return $relPath;
    }

    /**
     * @return array{status: int, code: string, message: string, data: array<string, mixed>}|null
     */
    private function assertPointerWithinTemplate(
        string $templateId,
        string $pointer,
        string $errorPrefix = 'source_binding',
    ): ?array {
        if ($pointer !== '' && $pointer[0] !== '/') {
            $pointer = '/' . $pointer;
        }
        $layoutPrefix = JsonPointer::compile(['templates', $templateId, 'layout']) . '/';
        if ($pointer !== '' && \str_starts_with($pointer, $layoutPrefix)) {
            $tail = \substr($pointer, \strlen($layoutPrefix));
            if (\str_contains($tail, '/templates/') || \str_starts_with($tail, 'templates/')) {
                SecurityLogger::log(SecurityLogger::EVENT_CROSS_TEMPLATE_DENY, [
                    'platform'    => 'joomla',
                    'pointer'     => $pointer,
                    'template_id' => $templateId,
                    'reason'      => 'double_prefix',
                ]);
                return [
                    'status'  => 400,
                    'code'    => 'yootheme_builder_mcp.' . $errorPrefix . '.double_prefix',
                    'message' => \sprintf(
                        'Pointer "%s" contains a duplicated `/templates/<id>/layout` prefix. ' .
                        'Pass EITHER the rel_path form (e.g. `/children/0`) OR the fully-' .
                        'qualified pointer once — never both concatenated.',
                        $pointer,
                    ),
                    'data'    => [],
                ];
            }
        }
        $allowedPrefix = JsonPointer::compile(['templates', $templateId]);
        if (JsonPointer::isWithinPrefix($pointer, $allowedPrefix)) {
            return null;
        }
        SecurityLogger::log(SecurityLogger::EVENT_CROSS_TEMPLATE_DENY, [
            'platform'       => 'joomla',
            'pointer'        => $pointer,
            'template_id'    => $templateId,
            'allowed_prefix' => $allowedPrefix,
        ]);
        return [
            'status'  => 400,
            'code'    => 'yootheme_builder_mcp.' . $errorPrefix . '.cross_template_write_denied',
            'message' => \sprintf(
                'Pointer "%s" is not within template "%s" (allowed prefix "%s").',
                $pointer,
                $templateId,
                $allowedPrefix,
            ),
            'data'    => [],
        ];
    }

    private function emitBadRequest(string $message): void
    {
        JoomlaJsonResponse::error(
            $this->app(),
            'yootheme_builder_mcp.source_binding.invalid_request',
            $message,
            400,
        );
    }

    /**
     * @param array{status: int, code: string, message: string, data: array<string, mixed>} $lockError
     */
    private function emitLockError(array $lockError): void
    {
        JoomlaJsonResponse::error(
            $this->app(),
            $lockError['code'],
            $lockError['message'],
            $lockError['status'],
            $lockError['data'],
        );
    }

    /**
     * Best-effort cache flush after an L1 (Type Template) write.
     *
     * Closes R3 finding F-A1-005 — see {@see PagesController::flushCachesIfAvailable}
     * for the full rationale. Pre-R4 this helper called the non-existent
     * `flush()` method.
     */
    private static function flushCachesIfAvailable(): void
    {
        $cls = '\\WootsUp\\BuilderMcp\\Platform\\Joomla\\Cache\\JoomlaCacheFlusher';
        if (!\class_exists($cls)) {
            return;
        }
        try {
            $flusher = new $cls();
            if (\is_object($flusher) && \method_exists($flusher, 'flushL1')) {
                $flusher->flushL1();
            }
        } catch (\Throwable) {
            // JoomlaCacheFlusher logs its own failure via
            // EVENT_CACHE_FLUSH_FAILED.
        }
    }
}
