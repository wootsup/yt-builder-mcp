<?php
/**
 * PagesController — REST endpoints under `yt-builder-mcp/v1/pages`.
 *
 * Cookbook §3.2 Routes 4-10 + §3.4.4-§3.4.9. Six routes (list / layout
 * / schema / summary / save / publish) — read endpoints scope to `read`,
 * write endpoints to `write`, with optimistic-lock enforcement on save
 * + publish via {@see JoomlaEtagMiddleware}.
 *
 * Each method is a thin adapter over the shared-domain
 * {@see \WootsUp\BuilderMcp\Pages\PageQuery} (read side) and
 * {@see JoomlaLayoutWriter::writeTemplate()} (write side) — both pure
 * PHP, no platform-specific behaviour. The Joomla skin is limited to:
 *
 *  - URL path-param extraction via `$this->input->get('templateId')`
 *  - Bearer + scope check via {@see AbstractApiController::dispatch()}
 *  - Response emission via {@see JoomlaJsonResponse::send()}
 *  - ETag pre-condition via {@see JoomlaEtagMiddleware::enforce()}
 *  - Optional cache-flush via {@see JoomlaCacheFlusher} (Wave 6.5 stream;
 *    conditional `class_exists` so this controller compiles before that
 *    stream lands)
 *  - Published-state ETag snapshot via {@see JoomlaOptionStore} (cookbook
 *    F-15 publish-action parity)
 *
 * Cookbook §3.7.1 cross-platform parity: every response body matches the
 * WP-side wire-shape byte-for-byte (`pages` shape, `layout_root_pointer`
 * / `pointer_hint` cold-agent hints, `nodes`+`schema` alias pair on
 * /schema, `published` + `published_state_etag` + `note` triple on
 * /publish).
 *
 * @package    WootsUp\Component\Ytbmcp\Api\Controller
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Component\Ytbmcp\Api\Controller;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Pages\PageQuery;
use WootsUp\BuilderMcp\Platform\Joomla\Pages\JoomlaFrontendUrlResolver;
use WootsUp\BuilderMcp\Platform\Joomla\Pages\JoomlaPublicRootResolver;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\AbstractApiController;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaEtagMiddleware;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaJsonResponse;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutReader;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutWriter;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaPagesMetaStore;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class PagesController extends AbstractApiController
{
    /**
     * Option-key for the F-15 "last-published" ETag snapshot. Stored in
     * `#__ytb_mcp_options` (cookbook §4.5 storage table) — mirrors the
     * WP-side `ytb_mcp_published_state_etag` autoload=false option so
     * cross-platform diff tools can pin the same key.
     */
    public const PUBLISHED_STATE_ETAG_OPTION = 'published_state_etag';

    /**
     * GET /v1/pages — list templates with meta + state ETag.
     *
     * Cookbook §3.2 Route 4 + §3.4.4. Shape:
     * `{pages: [{id, name?, label?, title?, type, elements_count,
     *   modified_at, etag, is_public_homepage?, template_purpose?}, ...],
     *  etag: string}`.
     */
    public function list(): void
    {
        $this->dispatch('read', function (array $claims): void {
            unset($claims);
            $reader = new JoomlaLayoutReader();
            // Cookbook §3.4.4 — supply the Joomla public-root resolver so
            // the `is_public_homepage` cold-agent hint resolves correctly
            // (default menu-item walk, not WP `show_on_front`).
            $query  = new PageQuery($reader, new JoomlaPagesMetaStore(), new JoomlaPublicRootResolver(), new JoomlaFrontendUrlResolver());
            JoomlaJsonResponse::send($this->app(), [
                'pages' => $query->list(),
                'etag'  => $query->etag(),
            ], 200);
        });
    }

    /**
     * GET /v1/pages/:templateId/layout — full template tree.
     *
     * Cookbook §3.2 Route 5 + §3.4.5. Includes the F-COLD-3 cold-agent
     * `layout_root_pointer` + `pointer_hint` fields so cold agents
     * don't double the `/layout` segment when constructing child paths.
     */
    public function getLayout(): void
    {
        $this->dispatch('read', function (array $claims): void {
            unset($claims);
            $templateId = $this->templateIdParam();
            if ($templateId === '') {
                $this->emitBadRequest('templateId is required.');
                return;
            }

            $reader = new JoomlaLayoutReader();
            $query  = new PageQuery($reader, new JoomlaPagesMetaStore(), new JoomlaPublicRootResolver(), new JoomlaFrontendUrlResolver());
            $tpl    = $query->layout($templateId);
            if ($tpl === null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.pages.not_found',
                    \sprintf('Template "%s" not found.', $templateId),
                    404,
                );
                return;
            }

            // F-COLD-3 cold-agent hint — surface the canonical pointer
            // base so naive walkers don't double the `/layout` segment.
            $pointerBase = JsonPointer::compile(['templates', $templateId, 'layout']);
            JoomlaJsonResponse::send($this->app(), [
                'template_id'         => $templateId,
                'layout'              => $tpl,
                'etag'                => $query->etag(),
                'layout_root_pointer' => $pointerBase,
                'pointer_hint'        =>
                    'Element paths in this template share the prefix "' .
                    $pointerBase . '" (e.g. "' . $pointerBase . '/children/0"). ' .
                    'You can also pass the rel_path form ("/children/0") to ' .
                    'element-tools — both are accepted since 1.0.1.',
            ], 200);
        });
    }

    /**
     * GET /v1/pages/:templateId/schema — flat node-list with paths.
     *
     * Cookbook §3.2 Route 6 + §3.4.6. F-01/F-02: emit canonical
     * `nodes` + legacy `schema` alias + `total` so the three counts
     * (pages_list.elements_count, page_get_schema.total,
     * element_list.total) agree for any given state.
     */
    public function getSchema(): void
    {
        $this->dispatch('read', function (array $claims): void {
            unset($claims);
            $templateId = $this->templateIdParam();
            if ($templateId === '') {
                $this->emitBadRequest('templateId is required.');
                return;
            }

            $reader = new JoomlaLayoutReader();
            $query  = new PageQuery($reader, new JoomlaPagesMetaStore(), new JoomlaPublicRootResolver(), new JoomlaFrontendUrlResolver());
            $schema = $query->schema($templateId);
            if ($schema === null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.pages.not_found',
                    \sprintf('Template "%s" not found.', $templateId),
                    404,
                );
                return;
            }

            JoomlaJsonResponse::send($this->app(), [
                'template_id' => $templateId,
                'nodes'       => $schema,
                'schema'      => $schema,
                'total'       => \count($schema),
                'etag'        => $query->etag(),
            ], 200);
        });
    }

    /**
     * GET /v1/pages/:templateId/summary — token-efficient overview.
     *
     * Cookbook §3.2 Route 7 + §3.4.7 + T9 (Audit-v3 B.5). Returns
     * `{template_id, counts_by_type, bound_count, max_depth, total,
     *   named_sections, etag}` computed server-side in a single walk.
     */
    public function getSummary(): void
    {
        $this->dispatch('read', function (array $claims): void {
            unset($claims);
            $templateId = $this->templateIdParam();
            if ($templateId === '') {
                $this->emitBadRequest('templateId is required.');
                return;
            }

            $reader  = new JoomlaLayoutReader();
            $query   = new PageQuery($reader, new JoomlaPagesMetaStore(), new JoomlaPublicRootResolver(), new JoomlaFrontendUrlResolver());
            $summary = $query->summary($templateId);
            if ($summary === null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.pages.not_found',
                    \sprintf('Template "%s" not found.', $templateId),
                    404,
                );
                return;
            }

            JoomlaJsonResponse::send($this->app(), $summary, 200);
        });
    }

    /**
     * POST /v1/pages/:templateId/save — re-run save-transforms + persist.
     *
     * Cookbook §3.2 Route 9 + §3.4.8. Optional If-Match optimistic-lock
     * (not required — POST `save` may legitimately be invoked without
     * a prior read). Flushes caches on success.
     */
    public function save(): void
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

            $current   = $reader->etag();
            $lockError = JoomlaEtagMiddleware::enforce(
                JoomlaEtagMiddleware::readIfMatchHeader(),
                $current,
                false, // POST save is opt-in lock — see cookbook §3.1.6.
            );
            if ($lockError !== null) {
                $this->emitLockError($lockError);
                return;
            }

            $tpl = $reader->readTemplate($templateId);
            if ($tpl === null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.pages.not_found',
                    \sprintf('Template "%s" not found.', $templateId),
                    404,
                );
                return;
            }

            try {
                $writer->writeTemplate($templateId, $tpl);
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
                'template_id' => $templateId,
                'saved'       => true,
                'etag'        => $reader->etag(),
            ], 200);
        });
    }

    /**
     * POST /v1/pages/:templateId/publish — save + cache flush + ETag snap.
     *
     * Cookbook §3.2 Route 10 + §3.4.9 + F-15 (Maria-Audit). YT templates
     * publish on save; the publish endpoint additionally:
     *
     *   (1) re-runs save (commits any pending normalisation pass);
     *   (2) flushes caches (idempotent — already done by save);
     *   (3) snapshots the post-publish ETag in
     *       {@see JoomlaOptionStore} so MCP clients can diff drafts
     *       against the last-published version.
     *
     * Response carries `published: true`, `published_state_etag` and a
     * `note` documenting (3) — byte-shape parity with the WP-side.
     */
    public function publish(): void
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

            // Inline the save flow so we can attach the F-15 fields to
            // the same response without re-reading the writer.
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

            $tpl = $reader->readTemplate($templateId);
            if ($tpl === null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.pages.not_found',
                    \sprintf('Template "%s" not found.', $templateId),
                    404,
                );
                return;
            }

            try {
                $writer->writeTemplate($templateId, $tpl);
            } catch (\RuntimeException $e) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.write_failed',
                    $e->getMessage(),
                    500,
                );
                return;
            }
            // (2) Belt-and-braces cache flush.
            self::flushCachesIfAvailable();

            // (3) Snapshot the post-publish ETag for cookbook F-15 parity.
            // R8-A4 P3: surface the persist boolean. A failed snapshot only
            // degrades the draft-vs-published diff (non-critical metadata) —
            // the template write above (line ~325) already succeeded and is
            // hardened — so we LOG rather than fail the request, keeping
            // consistency with the no-silent-swallow convention.
            $publishedEtag    = $reader->etag();
            $etagSnapshotKept = (new JoomlaOptionStore())->set(self::PUBLISHED_STATE_ETAG_OPTION, $publishedEtag);
            if (!$etagSnapshotKept) {
                SecurityLogger::log(SecurityLogger::EVENT_WRITE_FAILED, [
                    'platform' => 'joomla',
                    'op'       => 'published_state_etag_snapshot',
                    'template' => $templateId,
                    'note'     => 'non-fatal: publish succeeded, only the draft/published diff snapshot was not persisted',
                ]);
            }

            JoomlaJsonResponse::send($this->app(), [
                'template_id'          => $templateId,
                'saved'                => true,
                'etag'                 => $publishedEtag,
                'published'            => true,
                'published_state_etag' => $publishedEtag,
                'note'                 => 'YOOtheme templates publish on save; this is a cache-flush + state-snapshot operation.',
            ], 200);
        });
    }

    /**
     * Joomla Web-Services-API path-capture reader. The route
     * `pages.getLayout` declares `templateId` as a route regex capture
     * (see {@see \WootsUp\Plugin\System\Ytbmcp\Extension\Ytbmcp::onBeforeApiRoute}).
     * The ApiRouter merges captures into the `Input` bag, so a `string`
     * filter on `templateId` is the safe canonical read.
     */
    private function templateIdParam(): string
    {
        // Wave-7 deploy-fix: delegate to pathParam() which also reads the
        // POST input bag — on POST routes (save/publish) Joomla injects the
        // :templateId route var into $input->post, not $input, so a direct
        // $input->getString('templateId') returned '' → "templateId is
        // required" 400 and no template could ever be created.
        return $this->pathParam('templateId', '');
    }

    /**
     * Emit a structured 400 for missing path-params. Centralised so the
     * error-shape stays byte-identical across the six routes.
     */
    private function emitBadRequest(string $message): void
    {
        JoomlaJsonResponse::error(
            $this->app(),
            'yootheme_builder_mcp.pages.invalid_request',
            $message,
            400,
        );
    }

    /**
     * Re-emit a {@see JoomlaEtagMiddleware} error-descriptor as a wire
     * response. Centralised so the six write methods don't repeat the
     * `error()` boilerplate.
     *
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
     * Closes R3 finding F-A1-005 (RELEASE-BLOCKER): pre-R4 this helper
     * guarded on the non-existent `flush()` method — `method_exists`
     * returned false and `flushCachesIfAvailable` silently no-op'd on
     * every L1 write. {@see \WootsUp\BuilderMcp\Platform\Joomla\Cache\JoomlaCacheFlusher}
     * exposes `flushL1()` + `flushL2(int)`, not `flush()`. Method-name
     * now matches the published contract and a pin-test
     * ({@see \WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin\JoomlaCacheFlusherContractPinTest})
     * guards the four L1 controllers against regression.
     *
     * The class-exists branch is intentional defense-in-depth so unit
     * tests that don't bootstrap the cache subsystem stay green.
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
            // Cache flush failures must never bubble — they would
            // surface as customer-facing 5xx for a non-critical post-
            // write step. JoomlaCacheFlusher already logs via
            // SecurityLogger::EVENT_CACHE_FLUSH_FAILED on its own
            // failure path; this guard handles unexpected ctor errors.
        }
    }
}
