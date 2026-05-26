<?php
/**
 * MultiItemsController — REST endpoints for the YT-Pro Multi-Items
 * binding pattern (inspect + clean-implode).
 *
 * Cookbook §3.2 Routes 22-23 + §3.4.23-§3.4.24. Two routes:
 *
 *   GET  /v1/pages/:templateId/elements/:path/multi-items/inspect
 *      → MultiItemsInspector report (cookbook §3.4.23)
 *
 *   POST /v1/pages/:templateId/elements/:path/multi-items/clean-implode
 *      → Strip `props.source.props.*.implode` directives
 *        (cookbook §3.4.24). REQUIRES If-Match.
 *
 * Mirrors the WP-side {@see \WootsUp\BuilderMcp\SourceBinding\MultiItemsController}
 * byte-for-byte:
 *   - inspect returns `{template_id, report, etag}`
 *   - clean_implode returns `{template_id, element_path, cleaned_count,
 *     removed_directives, new_etag}`
 *   - idempotent no-op short-circuit when nothing to clean
 *   - TOCTOU close via re-verify ETag before persistence
 *   - cross-template-write defense via shared assert helper
 *
 * @package    WootsUp\Component\Ytbmcp\Api\Controller
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Component\Ytbmcp\Api\Controller;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\AbstractApiController;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaEtagMiddleware;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaJsonResponse;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutReader;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutWriter;
use WootsUp\BuilderMcp\SourceBinding\ImplodeDirectiveCleaner;
use WootsUp\BuilderMcp\SourceBinding\MultiItemsInspector;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class MultiItemsController extends AbstractApiController
{
    /**
     * GET /v1/pages/:templateId/elements/:path/multi-items/inspect.
     *
     * Cookbook §3.2 Route 22 + §3.4.23. Pure read — returns the
     * inspector report (binding-level, container/item siblings,
     * implode-directive presence, etc.) so MCP clients can plan a
     * Multi-Items rewrite before mutating anything.
     */
    public function inspect(): void
    {
        $this->dispatch('read', function (array $claims): void {
            unset($claims);
            $templateId = $this->templateIdParam();
            if ($templateId === '') {
                $this->emitBadRequest('templateId is required.');
                return;
            }
            $pointer = $this->pointerFromPath($templateId);

            if (($err = $this->assertPointerWithinTemplate($templateId, $pointer, 'multi_items')) !== null) {
                $this->emitLockError($err);
                return;
            }

            $reader = new JoomlaLayoutReader();
            $ops    = new ElementOps($reader);
            $multi  = new MultiItemsInspector($ops);

            $report = $multi->inspect($templateId, $pointer);
            if ($report === null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.multi_items.not_found',
                    \sprintf('Element at "%s" not found.', $pointer),
                    404,
                );
                return;
            }

            JoomlaJsonResponse::send($this->app(), [
                'template_id' => $templateId,
                'report'      => $report,
                'etag'        => $reader->etag(),
            ], 200);
        });
    }

    /**
     * POST /v1/pages/:templateId/elements/:path/multi-items/clean-implode.
     *
     * Cookbook §3.2 Route 23 + §3.4.24. REQUIRES If-Match. Strips every
     * `implode` directive on the addressed element's `props.source.props.*`
     * — typically a clean-up step when migrating a binding from container
     * to item level (the implode directive becomes meaningless and
     * produces surprising comma-joined output on the rendered page).
     *
     * Idempotent: when there is nothing to clean, returns 200 with
     * `cleaned_count: 0`, `removed_directives: []` and the unchanged
     * `new_etag` — caller can re-run safely.
     */
    public function cleanImplode(): void
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
                true, // POST mutate REQUIRES If-Match per cookbook §3.4.24.
            );
            if ($lockError !== null) {
                $this->emitLockError($lockError);
                return;
            }

            if (($err = $this->assertPointerWithinTemplate($templateId, $pointer, 'multi_items')) !== null) {
                $this->emitLockError($err);
                return;
            }

            // Wave-1.7 audit-F F-SEC-1: capture baseline ETag BEFORE any
            // state read so the compare-and-swap below is anchored on the
            // pre-read snapshot (mirrors ElementsController::mutate).
            $etagAtStart = $reader->etag();

            $state = $reader->read();
            if (!isset($state['templates'][$templateId]) || !\is_array($state['templates'][$templateId])) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.multi_items.not_found',
                    \sprintf('Template "%s" not found.', $templateId),
                    404,
                );
                return;
            }

            $node = $ops->get($pointer);
            if ($node === null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.multi_items.not_found',
                    \sprintf('Element at "%s" not found.', $pointer),
                    404,
                );
                return;
            }

            $result = ImplodeDirectiveCleaner::clean($node);
            if ($result['cleaned_count'] === 0) {
                JoomlaJsonResponse::send($this->app(), [
                    'template_id'        => $templateId,
                    'element_path'       => $pointer,
                    'cleaned_count'      => 0,
                    'removed_directives' => [],
                    'new_etag'           => $reader->etag(),
                ], 200);
                return;
            }

            JsonPointer::set($state, $pointer, $result['node']);

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

            JoomlaJsonResponse::send($this->app(), [
                'template_id'        => $templateId,
                'element_path'       => $pointer,
                'cleaned_count'      => $result['cleaned_count'],
                'removed_directives' => $result['removed_directives'],
                'new_etag'           => $reader->etag(),
            ], 200);
        });
    }

    // -----------------------------------------------------------------
    // Shared infra (identical to ElementsController / SourcesController).
    // -----------------------------------------------------------------

    private function templateIdParam(): string
    {
        // Wave-7 deploy-fix: pathParam() reads $input->post too (POST route
        // clean-implode injects :templateId into the POST bag).
        return $this->pathParam('templateId', '');
    }

    private function pointerFromPath(string $templateId): string
    {
        // A1-P3 (R8): shared POST-bag `path`/raw fallback — see
        // AbstractApiController::pathParamRaw() (centralised; was duplicated
        // verbatim with ElementsController::pointerFromPath()).
        $raw = $this->pathParamRaw('path');
        if ($raw === '') {
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
        string $errorPrefix = 'multi_items',
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
            'yootheme_builder_mcp.multi_items.invalid_request',
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
