<?php
/**
 * ElementsController — REST endpoints under `yt-builder-mcp/v1/pages/
 * :templateId/elements`.
 *
 * Cookbook §3.2 Routes 11-17 + §3.4.10-§3.4.16. Seven routes:
 *
 *   GET    /pages/:templateId/elements                      → list (cookbook §3.4.10)
 *   GET    /pages/:templateId/elements/:path                → get  (cookbook §3.4.11)
 *   POST   /pages/:templateId/elements                      → add  (cookbook §3.4.12)
 *   PUT    /pages/:templateId/elements/:path/settings       → update_settings (cookbook §3.4.13)
 *   DELETE /pages/:templateId/elements/:path                → delete (cookbook §3.4.14)
 *   POST   /pages/:templateId/elements/:path/move           → move (cookbook §3.4.15)
 *   POST   /pages/:templateId/elements/:path/clone          → clone (cookbook §3.4.16)
 *
 * Each write endpoint runs the canonical six-step flow inherited
 * from the WP-side: (1) read state → (2) enforce If-Match
 * (cookbook §3.1.6) → (3) mutate via ElementOps → (4) re-verify ETag
 * (TOCTOU close, Wave-6 Fix 5) → (5) persist via JoomlaLayoutWriter →
 * (6) flush caches and return the new ETag.
 *
 * Cross-template-write defense (cookbook §2.6.4): every mutating call
 * runs the pointer through {@see JsonPointer::isWithinPrefix} against
 * `/templates/<id>` before touching state — a crafted pointer that
 * tries to escape the addressed template gets a structured 400.
 *
 * Cookbook §3.7.1 cross-platform parity: response byte-shape matches
 * WP-side ElementsController exactly (`element_path`, `element_type`,
 * `props`, `children`, `has_binding`, `child_count`, `label`,
 * `merge_mode`, `replaced_top_level_props`, `_authoritative_source`).
 *
 * @package    WootsUp\Component\Ytbmcp\Api\Controller
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Component\Ytbmcp\Api\Controller;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\Inspection\Inspector;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\AbstractApiController;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaEtagMiddleware;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaJsonResponse;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutReader;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutWriter;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class ElementsController extends AbstractApiController
{
    /**
     * Cookbook §3.4.10 — `?include=` allow-list. Unknown tokens get a
     * structured 400 mirroring the WP-side defense.
     */
    private const ELEMENT_LIST_INCLUDE_ALLOWED = ['props'];

    /**
     * GET /v1/pages/:templateId/elements — flat list with optional
     * pagination + sub-tree scoping. Cookbook §3.2 Route 11 + §3.4.10.
     */
    public function list(): void
    {
        $this->dispatch('read', function (array $claims): void {
            unset($claims);
            $templateId = $this->templateIdParam();
            if ($templateId === '') {
                $this->emitBadRequest('templateId is required.');
                return;
            }

            $reader = new JoomlaLayoutReader();
            $ops    = new ElementOps($reader);

            $options = [];
            $rootPath = $this->queryString('root_path');
            if ($rootPath !== '') {
                $options['root_path'] = $rootPath;
            }
            $depth = $this->queryString('depth');
            if ($depth !== '' && \is_numeric($depth)) {
                $options['depth'] = (int) $depth;
            }
            $limit = $this->queryString('limit');
            if ($limit !== '' && \is_numeric($limit)) {
                $options['limit'] = (int) $limit;
            }
            $cursor = $this->queryString('cursor');
            if ($cursor !== '') {
                $options['cursor'] = $cursor;
            }
            $include = $this->queryString('include');
            if ($include !== '') {
                $tokens = \array_values(\array_filter(
                    \array_map(
                        static fn (string $s): string => \trim($s),
                        \explode(',', $include),
                    ),
                    static fn (string $s): bool => $s !== '',
                ));
                $unknown = \array_values(\array_diff($tokens, self::ELEMENT_LIST_INCLUDE_ALLOWED));
                if (\count($unknown) > 0) {
                    JoomlaJsonResponse::error(
                        $this->app(),
                        'yootheme_builder_mcp.elements.invalid_query',
                        \sprintf(
                            '`include` tokens not recognized: %s. Accepted: %s.',
                            \implode(', ', $unknown),
                            \implode(', ', self::ELEMENT_LIST_INCLUDE_ALLOWED),
                        ),
                        400,
                        [
                            'hint' => 'Pass a comma-separated list of allow-listed tokens, e.g. `include=props`.',
                        ],
                    );
                    return;
                }
                $options['include'] = $tokens;
            }

            $list = $ops->listOnTemplate($templateId, $options);
            if ($list === null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.elements.not_found',
                    \sprintf('Template "%s" not found.', $templateId),
                    404,
                );
                return;
            }

            if (isset($list['items']) && \is_array($list['items'])) {
                JoomlaJsonResponse::send($this->app(), [
                    'template_id' => $templateId,
                    'items'       => $list['items'],
                    'next_cursor' => $list['next_cursor'] ?? null,
                    'total'       => $list['total'] ?? \count($list['items']),
                    'etag'        => $reader->etag(),
                ], 200);
                return;
            }

            JoomlaJsonResponse::send($this->app(), [
                'template_id' => $templateId,
                'elements'    => $list,
                'total'       => \count($list),
                'etag'        => $reader->etag(),
            ], 200);
        });
    }

    /**
     * GET /v1/pages/:templateId/elements/:path — element + ETag.
     * Cookbook §3.2 Route 12 + §3.4.11.
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

            // Cookbook §2.6.4 — also run the cross-template defense on
            // read paths so a crafted pointer can't enumerate foreign-
            // template nodes.
            if ($pointer !== '' && ($err = $this->assertPointerWithinTemplate($templateId, $pointer)) !== null) {
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
                    'yootheme_builder_mcp.elements.invalid_pointer',
                    $e->getMessage(),
                    400,
                );
                return;
            }

            if ($node === null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.elements.not_found',
                    \sprintf('Element at "%s" not found.', $pointer),
                    404,
                    ['hint' => self::pathHintFor($pointer, $templateId)],
                );
                return;
            }

            $view = ElementOps::flattenNode($node, $pointer);
            $payload = [
                'template_id'  => $templateId,
                'path'         => $pointer,
                'element_path' => $pointer,
                'element_type' => $view['element_type'],
                'type'         => $view['type'],
                'props'        => $view['props'],
                'children'     => $view['children'],
                'has_binding'  => $view['has_binding'],
                'child_count'  => $view['child_count'],
                'label'        => $view['label'] ?? null,
                'element'      => $node,
                'etag'         => $reader->etag(),
            ];
            if ($view['has_binding']) {
                $payload['_authoritative_source'] = 'props.source';
            }
            JoomlaJsonResponse::send($this->app(), $payload, 200);
        });
    }

    /**
     * POST /v1/pages/:templateId/elements — add new element.
     * Cookbook §3.2 Route 13 + §3.4.12. Validates `element_type` against
     * the live Inspector catalogue (F-11) before mutating state.
     */
    public function add(): void
    {
        $this->dispatch('write', function (array $claims): void {
            unset($claims);
            $templateId = $this->templateIdParam();
            if ($templateId === '') {
                $this->emitBadRequest('templateId is required.');
                return;
            }

            $reader = new JoomlaLayoutReader();
            $writer = new JoomlaLayoutWriter();
            $ops    = new ElementOps($reader);

            $current   = $reader->etag();
            $lockError = JoomlaEtagMiddleware::enforce(
                JoomlaEtagMiddleware::readIfMatchHeader(),
                $current,
                false,
            );
            if ($lockError !== null) {
                $this->emitLockError($lockError);
                return;
            }

            $params      = $this->requestBody();
            $parentPath  = \is_string($params['parent_path'] ?? null) ? $params['parent_path'] : '';
            $parentPath  = self::normalizeElementPath($parentPath, $templateId);
            $elementType = \is_string($params['element_type'] ?? null) ? $params['element_type'] : '';
            if ($elementType === '') {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.elements.invalid_body',
                    '`element_type` is required.',
                    400,
                );
                return;
            }

            // F-11 (Maria-Audit 2026-05-22): validate `element_type`
            // against the registry before persisting an unrenderable node.
            $inspector = new Inspector();
            if (!$inspector->isKnownType($elementType)) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.elements.invalid_type',
                    \sprintf(
                        'Unknown element-type "%s". Use yootheme_builder_element_types_list to discover valid values.',
                        $elementType,
                    ),
                    400,
                    [
                        'element_type' => $elementType,
                        'hint'         => 'Use yootheme_builder_element_types_list to discover valid values.',
                    ],
                );
                return;
            }

            $props    = \is_array($params['props'] ?? null) ? $params['props'] : [];
            $children = \is_array($params['children'] ?? null) ? $params['children'] : [];

            if ($parentPath !== '' && ($err = $this->assertPointerWithinTemplate($templateId, $parentPath)) !== null) {
                $this->emitLockError($err);
                return;
            }

            $this->mutate(
                $templateId,
                $current,
                function (array &$state) use ($ops, $templateId, $parentPath, $elementType, $props, $children): array {
                    /** @var array<string, mixed> $props */
                    /** @var list<array<string, mixed>> $children */
                    $newPath = $ops->add($state, $templateId, $parentPath, $elementType, $props, $children);
                    return ['element_path' => $newPath];
                },
                $writer,
                $reader,
            );
        });
    }

    /**
     * PUT /v1/pages/:templateId/elements/:path/settings — replace / merge
     * props on an element. Cookbook §3.2 Route 14 + §3.4.13. REQUIRES
     * `If-Match` (cookbook §3.1.6 — PUT enforces optimistic-lock).
     *
     * Supports `merge: true` for deep-merge semantics (T5 / F-12) so
     * callers can extend a sub-key without clobbering siblings. When
     * full-replace runs, dropped top-level keys are surfaced under
     * `replaced_top_level_props` (F-COLD-21 cold-agent hint).
     */
    public function updateSettings(): void
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
                true, // PUT requires If-Match per cookbook §3.1.6.
            );
            if ($lockError !== null) {
                $this->emitLockError($lockError);
                return;
            }

            $params = $this->requestBody();
            $props  = \is_array($params['props'] ?? null) ? $params['props'] : null;
            if ($props === null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.elements.invalid_body',
                    '`props` (object) is required.',
                    400,
                );
                return;
            }

            if (($err = $this->assertPointerWithinTemplate($templateId, $pointer)) !== null) {
                $this->emitLockError($err);
                return;
            }

            $merge = !empty($params['merge']);

            $this->mutate(
                $templateId,
                $current,
                function (array &$state) use ($ops, $templateId, $pointer, $props, $merge): array {
                    $currentNode  = JsonPointer::get($state, $pointer);
                    $currentProps = (\is_array($currentNode) && isset($currentNode['props']) && \is_array($currentNode['props']))
                        ? $currentNode['props']
                        : [];
                    /** @var array<string, mixed> $currentProps */
                    /** @var array<string, mixed> $props */
                    if ($merge) {
                        $merged = ElementOps::mergeProps($currentProps, $props);
                        $ops->updateSettings($state, $templateId, $pointer, $merged);
                        return [
                            'element_path' => $pointer,
                            'merge_mode'   => 'merge',
                        ];
                    }
                    $dropped = [];
                    foreach (\array_keys($currentProps) as $key) {
                        if (!\array_key_exists($key, $props)) {
                            $dropped[] = (string) $key;
                        }
                    }
                    $ops->updateSettings($state, $templateId, $pointer, $props);
                    $extra = [
                        'element_path' => $pointer,
                        'merge_mode'   => 'replace',
                    ];
                    if (\count($dropped) > 0) {
                        $extra['replaced_top_level_props'] = $dropped;
                    }
                    return $extra;
                },
                $writer,
                $reader,
            );
        });
    }

    /**
     * DELETE /v1/pages/:templateId/elements/:path — delete element.
     * Cookbook §3.2 Route 15 + §3.4.14. REQUIRES `If-Match`.
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
                true, // DELETE requires If-Match per cookbook §3.1.6.
            );
            if ($lockError !== null) {
                $this->emitLockError($lockError);
                return;
            }

            if (($err = $this->assertPointerWithinTemplate($templateId, $pointer)) !== null) {
                $this->emitLockError($err);
                return;
            }

            $this->mutate(
                $templateId,
                $current,
                function (array &$state) use ($ops, $templateId, $pointer): array {
                    $ops->delete($state, $templateId, $pointer);
                    return ['element_path' => $pointer];
                },
                $writer,
                $reader,
            );
        });
    }

    /**
     * POST /v1/pages/:templateId/elements/:path/move — move element.
     * Cookbook §3.2 Route 16 + §3.4.15. Optional If-Match.
     */
    public function move(): void
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
                false,
            );
            if ($lockError !== null) {
                $this->emitLockError($lockError);
                return;
            }

            $params       = $this->requestBody();
            $toParentPath = \is_string($params['to_parent_path'] ?? null) ? $params['to_parent_path'] : '';
            $toParentPath = self::normalizeElementPath($toParentPath, $templateId);
            $toIndex      = \is_int($params['to_index'] ?? null) ? (int) $params['to_index'] : null;
            if ($toIndex === null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.elements.invalid_body',
                    '`to_index` (int) is required.',
                    400,
                );
                return;
            }

            if (($err = $this->assertPointerWithinTemplate($templateId, $pointer)) !== null) {
                $this->emitLockError($err);
                return;
            }
            if ($toParentPath !== '' && ($err = $this->assertPointerWithinTemplate($templateId, $toParentPath)) !== null) {
                $this->emitLockError($err);
                return;
            }

            $this->mutate(
                $templateId,
                $current,
                function (array &$state) use ($ops, $templateId, $pointer, $toParentPath, $toIndex): array {
                    $newPath = $ops->move($state, $templateId, $pointer, $toParentPath, $toIndex);
                    return ['element_path' => $newPath];
                },
                $writer,
                $reader,
            );
        });
    }

    /**
     * POST /v1/pages/:templateId/elements/:path/clone — clone element.
     * Cookbook §3.2 Route 17 + §3.4.16. Optional If-Match.
     */
    public function clone(): void
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
                false,
            );
            if ($lockError !== null) {
                $this->emitLockError($lockError);
                return;
            }

            if (($err = $this->assertPointerWithinTemplate($templateId, $pointer)) !== null) {
                $this->emitLockError($err);
                return;
            }

            $this->mutate(
                $templateId,
                $current,
                function (array &$state) use ($ops, $templateId, $pointer): array {
                    $newPath = $ops->clone($state, $templateId, $pointer);
                    return ['element_path' => $newPath];
                },
                $writer,
                $reader,
            );
        });
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    /**
     * Common mutate-then-persist flow used by every write endpoint.
     *
     * Cookbook §4.3.3 + Wave-6 Fix 5 TOCTOU close: read state, run
     * mutator, re-verify the ETag at the moment of persistence so a
     * concurrent writer that landed between our initial read and now
     * gets caught with 412 instead of being silently clobbered.
     *
     * @param callable(array<string, mixed>&): array<string, mixed> $mutator
     */
    private function mutate(
        string $templateId,
        string $etagAtStart,
        callable $mutator,
        JoomlaLayoutWriter $writer,
        JoomlaLayoutReader $reader,
    ): void {
        $state = $reader->read();
        if (!isset($state['templates'][$templateId]) || !\is_array($state['templates'][$templateId])) {
            JoomlaJsonResponse::error(
                $this->app(),
                'yootheme_builder_mcp.elements.not_found',
                \sprintf('Template "%s" not found.', $templateId),
                404,
            );
            return;
        }

        try {
            $extra = $mutator($state);
        } catch (\InvalidArgumentException $e) {
            JoomlaJsonResponse::error(
                $this->app(),
                'yootheme_builder_mcp.elements.invalid_argument',
                $e->getMessage(),
                400,
            );
            return;
        }

        $etagNow = $reader->etag();
        if (!\hash_equals($etagAtStart, $etagNow)) {
            JoomlaJsonResponse::error(
                $this->app(),
                'yootheme_builder_mcp.precondition_failed',
                'State changed during write (TOCTOU). Re-read and retry.',
                412,
                [
                    'expected_etag' => $etagAtStart,
                    'current_etag'  => $etagNow,
                    'hint'          => 'Re-read the element via yootheme_builder_element_get and retry with the fresh ETag in If-Match.',
                ],
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

        $payload = \array_merge(
            [
                'template_id' => $templateId,
                'etag'        => $reader->etag(),
            ],
            $extra,
        );
        JoomlaJsonResponse::send($this->app(), $payload, 200);
    }

    /**
     * Read the route-captured `:templateId` from Input.
     */
    private function templateIdParam(): string
    {
        // Wave-7 deploy-fix: delegate to pathParam() (reads $input->post too)
        // — POST routes (add/move/clone) inject :templateId into $input->post.
        return $this->pathParam('templateId', '');
    }

    /**
     * Read the route-captured `:path` and normalise it into a fully-
     * qualified JSON-Pointer. Accepts the `rel_path` form
     * (`children/...`) and lifts to `/templates/<id>/layout/...` —
     * mirrors cookbook §3.4.11 normalisation behaviour.
     *
     * The `path` capture uses the `raw` filter so slashes inside the
     * pointer survive Joomla's Input filtering chain. Wave-7 deploy-fix:
     * also read the POST input bag — POST routes (move/clone) inject the
     * :path route var into $input->post, not $input.
     */
    private function pointerFromPath(string $templateId): string
    {
        // A1-P3 (R8): the POST-bag `path`/raw fallback is centralised into
        // AbstractApiController::pathParamRaw() (one source of truth, mirrors
        // the existing pathParam() helper). The `raw` filter preserves pointer
        // slashes; POST routes (move/clone) inject :path into $input->post.
        $raw = $this->pathParamRaw('path');
        if ($raw === '') {
            return '';
        }
        return self::normalizeElementPath($raw, $templateId);
    }

    /**
     * Port of {@see \WootsUp\BuilderMcp\Rest\PointerControllerTrait::normalizeElementPath}.
     * Pure-PHP — copy-paste from the trait so the controller compiles
     * without dragging the WP_REST_Request type-dependency in.
     */
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
     * Cookbook §2.6.4 — port of
     * {@see \WootsUp\BuilderMcp\Rest\PointerControllerTrait::assertPointerWithinTemplate}.
     *
     * @return array{status: int, code: string, message: string, data: array<string, mixed>}|null
     */
    private function assertPointerWithinTemplate(
        string $templateId,
        string $pointer,
        string $errorPrefix = 'elements',
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

    /**
     * Port of WP-side `ElementsController::pathHintFor` (cookbook
     * §3.4.11 cold-agent 404 hints — F-COLD-9 / F-COLD-18).
     */
    private static function pathHintFor(string $pointer, string $templateId): string
    {
        if (\str_contains($pointer, '%2F') || \str_contains($pointer, '%2f')) {
            return 'element_path uses LITERAL slashes — do NOT percent-encode `/` as `%2F`.';
        }
        $expectedPrefix = '/templates/' . $templateId . '/';
        if ($pointer !== '' && !\str_starts_with($pointer, $expectedPrefix)) {
            return \sprintf(
                'element_path must start with `%s` (or use the rel_path form like `/children/0`). ' .
                'Discover paths via yootheme_builder_element_list or `layout_root_pointer` from page_get_layout.',
                $expectedPrefix,
            );
        }
        return 'Verify the path via yootheme_builder_element_list — paths are case-sensitive.';
    }

    private function emitBadRequest(string $message): void
    {
        JoomlaJsonResponse::error(
            $this->app(),
            'yootheme_builder_mcp.elements.invalid_request',
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
     * `flush()` method, silently no-op'ing on every L1 write; now calls
     * `flushL1()` to match the {@see \WootsUp\BuilderMcp\Platform\Joomla\Cache\JoomlaCacheFlusher}
     * contract.
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
            // EVENT_CACHE_FLUSH_FAILED; this guard handles unexpected
            // ctor / class-resolution errors.
        }
    }
}
