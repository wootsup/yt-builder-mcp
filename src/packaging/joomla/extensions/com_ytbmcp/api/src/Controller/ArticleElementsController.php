<?php
/**
 * com_ytbmcp Web Services API controller — L2 per-element write
 * surface for article-scoped Builder state.
 *
 * Cookbook §4.13.5 (L2 article-storage cross-reference). Companion to
 * {@see ArticlesController}; split into a separate controller class so
 * the route taskHandler shape `articleElements.<method>` registered in
 * {@see \WootsUp\Plugin\System\Ytbmcp\Extension\Ytbmcp::onBeforeApiRoute}
 * resolves cleanly under Joomla's MVCFactory class-resolution
 * convention (`<task-controller>` → ucfirst() + 'Controller').
 *
 * Endpoints:
 *
 *   GET    /v1/articles/<articleId>/elements/<path>   → articleElements.get
 *   PUT    /v1/articles/<articleId>/elements/<path>   → articleElements.update
 *   DELETE /v1/articles/<articleId>/elements/<path>   → articleElements.delete
 *
 * Write methods (PUT, DELETE) REQUIRE an `If-Match` header carrying
 * the article's current ETag — see {@see ArticlesController} for the
 * matching `GET /page-layout` endpoint that returns the ETag.
 *
 * SECURITY — L2 writes use the Bearer write-scope as the SOLE authority
 * (cookbook §2.2.4 + ADR-001 session-strip). The per-article Joomla ACL
 * gate was removed in Round-6 along with its sibling in
 * {@see ArticlesController}. See `docs/adr/2026-05-24-l2-bearer-as-authority.md`.
 *
 * @package    WootsUp\Component\Ytbmcp\Api\Controller
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Component\Ytbmcp\Api\Controller;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Platform\Joomla\Cache\JoomlaCacheFlusher;
use WootsUp\BuilderMcp\Platform\Joomla\L2\JoomlaArticleLayoutReader;
use WootsUp\BuilderMcp\Platform\Joomla\L2\JoomlaArticleLayoutStorage;
use WootsUp\BuilderMcp\Platform\Joomla\L2\JoomlaArticleLayoutWriter;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\AbstractApiController;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaEtagMiddleware;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaJsonResponse;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class ArticleElementsController extends AbstractApiController
{
    /**
     * GET /v1/articles/<articleId>/elements/<path> — read the value at
     * the RFC-6901 pointer within the per-article state.
     */
    public function get(): void
    {
        $this->dispatch('read', function (array $claims): void {
            $articleId = $this->resolveArticleId();
            if ($articleId === null) {
                return;
            }
            // ACL: Bearer read-scope is authoritative (cookbook §2.2.4 + ADR-001 — session-strip prevents cookie-bypass).
            $pointer = $this->resolvePointer();
            if ($pointer === null) {
                return;
            }

            $storage = new JoomlaArticleLayoutStorage();
            if (!$storage->articleExists($articleId)) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.articles.not_found',
                    \sprintf('Article #%d not found.', $articleId),
                    404
                );
                return;
            }
            $reader = new JoomlaArticleLayoutReader($storage, $articleId);
            try {
                $value = $reader->readByPointer($pointer);
            } catch (\InvalidArgumentException $e) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.articles.invalid_pointer',
                    $e->getMessage(),
                    400
                );
                return;
            }

            JoomlaJsonResponse::send($this->app(), [
                'article_id' => $articleId,
                'pointer'    => $pointer,
                'value'      => $value,
                'etag'       => $reader->etag(),
            ], 200);
        });
    }

    /**
     * PUT /v1/articles/<articleId>/elements/<path> — set the value at
     * the RFC-6901 pointer. `If-Match` REQUIRED. Body: `{value: …}`.
     */
    public function update(): void
    {
        $this->dispatch('write', function (array $claims): void {
            $articleId = $this->resolveArticleId();
            if ($articleId === null) {
                return;
            }
            // ACL: Bearer write-scope is authoritative (cookbook §2.2.4 + ADR-001 — session-strip prevents cookie-bypass).
            $pointer = $this->resolvePointer();
            if ($pointer === null) {
                return;
            }

            $storage = new JoomlaArticleLayoutStorage();
            if (!$storage->articleExists($articleId)) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.articles.not_found',
                    \sprintf('Article #%d not found.', $articleId),
                    404
                );
                return;
            }

            $reader  = new JoomlaArticleLayoutReader($storage, $articleId);
            // Round-4 audit F-A1-008: unified ETag enforcement via
            // JoomlaEtagMiddleware (RFC-7232 quote-strip + canonical Joomla
            // Input::server chain). PUT requires If-Match (cookbook §3.1.6 Wave-6 Fix 21).
            $ifMatch   = JoomlaEtagMiddleware::readIfMatchHeader();
            $lockError = JoomlaEtagMiddleware::enforce($ifMatch, $reader->etag(), true);
            if ($lockError !== null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    $lockError['code'],
                    $lockError['message'],
                    $lockError['status'],
                    $lockError['data']
                );
                return;
            }

            $body  = $this->requestBody();
            if (!\array_key_exists('value', $body)) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.articles.invalid_body',
                    'Request body must carry a `value` field.',
                    400
                );
                return;
            }
            $value = $body['value'];

            try {
                (new JoomlaArticleLayoutWriter($storage))->writeByPointer($articleId, $pointer, $value);
            } catch (\InvalidArgumentException $e) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.articles.invalid_pointer',
                    $e->getMessage(),
                    400
                );
                return;
            } catch (\RuntimeException $e) {
                SecurityLogger::log(SecurityLogger::EVENT_WRITE_FAILED, [
                    'platform'   => 'joomla',
                    'scope'      => 'l2_article',
                    'article_id' => $articleId,
                    'pointer'    => $pointer,
                    'reason'     => $e->getMessage(),
                ]);
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.write_failed',
                    'Failed to persist element update.',
                    500
                );
                return;
            }

            // Round-4 F-A1-006 + Round-6 A4 polish: invalidate the per-article
            // L2 cache scope. Best-effort; outer try/catch defense-in-depth
            // (cookbook §2.10.15 cache-flush invariant — write success must
            // NEVER depend on cache-flush success).
            try {
                (new JoomlaCacheFlusher())->flushL2($articleId);
            } catch (\Throwable $e) {
                SecurityLogger::log(SecurityLogger::EVENT_CACHE_FLUSH_FAILED, [
                    'platform'   => 'joomla',
                    'scope'      => 'l2_article',
                    'article_id' => $articleId,
                    'pointer'    => $pointer,
                    'reason'     => $e->getMessage(),
                ]);
            }

            $fresh = new JoomlaArticleLayoutReader($storage, $articleId);
            JoomlaJsonResponse::send($this->app(), [
                'article_id' => $articleId,
                'pointer'    => $pointer,
                'etag'       => $fresh->etag(),
                'saved'      => true,
            ], 200);
        });
    }

    /**
     * DELETE /v1/articles/<articleId>/elements/<path> — remove the
     * value at the RFC-6901 pointer. `If-Match` REQUIRED. Silent
     * no-op (still 200) when the pointer doesn't resolve.
     */
    public function delete(): void
    {
        $this->dispatch('write', function (array $claims): void {
            $articleId = $this->resolveArticleId();
            if ($articleId === null) {
                return;
            }
            // ACL: Bearer write-scope is authoritative (cookbook §2.2.4 + ADR-001 — session-strip prevents cookie-bypass).
            $pointer = $this->resolvePointer();
            if ($pointer === null) {
                return;
            }

            $storage = new JoomlaArticleLayoutStorage();
            if (!$storage->articleExists($articleId)) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.articles.not_found',
                    \sprintf('Article #%d not found.', $articleId),
                    404
                );
                return;
            }

            $reader  = new JoomlaArticleLayoutReader($storage, $articleId);
            // Round-4 audit F-A1-008: unified ETag enforcement via
            // JoomlaEtagMiddleware. DELETE requires If-Match (cookbook §3.1.6).
            $ifMatch   = JoomlaEtagMiddleware::readIfMatchHeader();
            $lockError = JoomlaEtagMiddleware::enforce($ifMatch, $reader->etag(), true);
            if ($lockError !== null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    $lockError['code'],
                    $lockError['message'],
                    $lockError['status'],
                    $lockError['data']
                );
                return;
            }

            try {
                (new JoomlaArticleLayoutWriter($storage))->delete($articleId, $pointer);
            } catch (\InvalidArgumentException $e) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.articles.invalid_pointer',
                    $e->getMessage(),
                    400
                );
                return;
            } catch (\RuntimeException $e) {
                SecurityLogger::log(SecurityLogger::EVENT_WRITE_FAILED, [
                    'platform'   => 'joomla',
                    'scope'      => 'l2_article',
                    'article_id' => $articleId,
                    'pointer'    => $pointer,
                    'reason'     => $e->getMessage(),
                ]);
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.write_failed',
                    'Failed to persist element delete.',
                    500
                );
                return;
            }

            // Round-4 F-A1-006 + Round-6 A4 polish: invalidate the per-article
            // L2 cache scope. Best-effort; outer try/catch defense-in-depth
            // (cookbook §2.10.15 cache-flush invariant).
            try {
                (new JoomlaCacheFlusher())->flushL2($articleId);
            } catch (\Throwable $e) {
                SecurityLogger::log(SecurityLogger::EVENT_CACHE_FLUSH_FAILED, [
                    'platform'   => 'joomla',
                    'scope'      => 'l2_article',
                    'article_id' => $articleId,
                    'pointer'    => $pointer,
                    'reason'     => $e->getMessage(),
                ]);
            }

            $fresh = new JoomlaArticleLayoutReader($storage, $articleId);
            JoomlaJsonResponse::send($this->app(), [
                'article_id' => $articleId,
                'pointer'    => $pointer,
                'etag'       => $fresh->etag(),
                'deleted'    => true,
            ], 200);
        });
    }

    /**
     * Resolve and validate the `articleId` path-segment. Emits a 400
     * and returns null on miss/invalid.
     */
    private function resolveArticleId(): ?int
    {
        $raw = $this->pathParam('articleId');
        if ($raw === '' || !\ctype_digit($raw)) {
            JoomlaJsonResponse::error(
                $this->app(),
                'yootheme_builder_mcp.articles.invalid_id',
                'articleId must be a positive integer.',
                400
            );
            return null;
        }
        $id = (int) $raw;
        if ($id <= 0) {
            JoomlaJsonResponse::error(
                $this->app(),
                'yootheme_builder_mcp.articles.invalid_id',
                'articleId must be a positive integer.',
                400
            );
            return null;
        }
        return $id;
    }

    /**
     * Resolve and validate the `path` path-segment as an RFC-6901
     * pointer. Joomla routing passes the matched `(.+)` capture as
     * the literal pointer string (callers send `/a/b/c` style).
     */
    private function resolvePointer(): ?string
    {
        $raw = $this->pathParam('path');
        if ($raw === '') {
            JoomlaJsonResponse::error(
                $this->app(),
                'yootheme_builder_mcp.articles.invalid_pointer',
                'Element path is required.',
                400
            );
            return null;
        }
        // The route capture strips the leading `/`; restore it so the
        // pointer satisfies RFC-6901 shape requirements.
        return $raw[0] === '/' ? $raw : '/' . $raw;
    }

    // Round-6 A2 N-A2-001 (architectural P1): private assertArticleAcl()
    // helper removed (same rationale as ArticlesController). Bearer scope
    // is the sole authority for L2; session-strip (ADR-001) makes
    // Factory::getUser()-based ACL structurally always-deny.
    // See docs/adr/2026-05-24-l2-bearer-as-authority.md.

    // Round-4 audit F-A1-008 / A2-P2-N3: bespoke ifMatch() helper deleted.
    // L1+L2 controllers now share JoomlaEtagMiddleware::readIfMatchHeader()
    // — single source of truth for the Joomla Input::server header read,
    // RFC-7232 quote-strip, and case-sensitivity handling.
}
