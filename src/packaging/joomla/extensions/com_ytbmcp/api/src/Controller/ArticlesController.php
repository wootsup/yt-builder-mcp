<?php
/**
 * com_ytbmcp Web Services API controller — L2 article-scoped Builder
 * state surface (Joomla-extra-scope per
 * [[feedback-parity-is-floor-not-ceiling]]).
 *
 * Cookbook §4.13.5 (L2 article-storage cross-reference). Joomla's
 * `#__content.fulltext` column natively carries per-article YT-Builder
 * state; WP follow-up planned via `wp_postmeta._yootheme_page`.
 *
 * Endpoints (registered in {@see \WootsUp\Plugin\System\Ytbmcp\Extension\Ytbmcp::onBeforeApiRoute}):
 *
 *   GET  /v1/articles                                 → articles.list
 *   GET  /v1/articles/<articleId>/page-layout         → articles.getLayout
 *   POST /v1/articles/<articleId>/page-layout/save    → articles.saveLayout
 *
 * Per-element routes are dispatched to {@see ArticleElementsController}:
 *   GET    /v1/articles/<articleId>/elements/<path>   → articleElements.get
 *   PUT    /v1/articles/<articleId>/elements/<path>   → articleElements.update
 *   DELETE /v1/articles/<articleId>/elements/<path>   → articleElements.delete
 *
 * SECURITY — L2 writes use the Bearer write-scope as the SOLE authority
 * (cookbook §2.2.4 Bearer-Deny-Invariant + ADR-001 session-strip). The
 * per-article Joomla `core.edit` ACL gate was removed in Round-6 because
 * {@see \WootsUp\Plugin\System\Ytbmcp\Extension\Ytbmcp::onStripApiSession}
 * deliberately drops Joomla user identity on every yt-builder-mcp API
 * request — `Factory::getUser()` always returns Guest, so
 * `authorise('core.edit', …)` was always false. Re-introducing identity
 * binding would re-open the cookie-bypass surface that session-strip
 * was designed to close. See `docs/adr/2026-05-24-l2-bearer-as-authority.md`.
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
use WootsUp\BuilderMcp\Platform\Joomla\Pages\JoomlaPublicRootResolver;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\AbstractApiController;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaEtagMiddleware;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaJsonResponse;
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class ArticlesController extends AbstractApiController
{
    /**
     * GET /v1/articles — paginated list of `#__content` articles that
     * MAY carry an L2 Builder layout. Supports `?catid=N`, `?state=...`,
     * `?limit=N`, `?offset=N`. Each row carries an `is_public_homepage`
     * flag set when the article is the front-page binding.
     */
    public function list(): void
    {
        $this->dispatch('read', function (array $claims): void {
            $catIdRaw  = $this->queryString('catid', '');
            $catId     = $catIdRaw === '' ? null : (int) $catIdRaw;
            $stateRaw  = $this->queryString('state', '');
            $state     = $stateRaw === '' ? null : $stateRaw;
            $limit     = (int) ($this->queryString('limit', '20') ?: '20');
            $offset    = (int) ($this->queryString('offset', '0') ?: '0');

            $storage = new JoomlaArticleLayoutStorage();
            $rows    = $storage->listArticles($catId, $state, $limit, $offset);

            // Front-page binding hint (best-effort — returns null
            // outside of Joomla request context).
            $frontHint = null;
            try {
                $frontHint = (new JoomlaPublicRootResolver())->resolveSiteFront();
            } catch (\Throwable) {
                $frontHint = null;
            }

            $items = [];
            foreach ($rows as $row) {
                $items[] = [
                    'id'                 => $row['id'],
                    'title'              => $row['title'],
                    'alias'              => $row['alias'],
                    'catid'              => $row['catid'],
                    'state'              => $row['state'],
                    'modified'           => $row['modified'],
                    // is_public_homepage is true when the front-page
                    // binding resolves to an article-list view that
                    // would include this row. We expose the hint only
                    // when the resolver matched an article-style root.
                    'is_public_homepage' => $frontHint === 'archive-post',
                ];
            }

            JoomlaJsonResponse::send($this->app(), [
                'articles' => $items,
                'count'    => \count($items),
                'limit'    => $limit,
                'offset'   => $offset,
            ], 200);
        });
    }

    /**
     * GET /v1/articles/<articleId>/page-layout — return the full
     * per-article Builder state + L2 ETag for use with `If-Match` on
     * subsequent PUT/DELETE element calls.
     */
    public function getLayout(): void
    {
        $this->dispatch('read', function (array $claims): void {
            $articleId = $this->resolveArticleId();
            if ($articleId === null) {
                return; // resolveArticleId already emitted the 400 response
            }
            // ACL: Bearer write-scope is authoritative (cookbook §2.2.4 + ADR-001 — session-strip prevents cookie-bypass).
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
            JoomlaJsonResponse::send($this->app(), [
                'article_id' => $articleId,
                'layout'     => $reader->read(),
                'etag'       => $reader->etag(),
            ], 200);
        });
    }

    /**
     * POST /v1/articles/<articleId>/page-layout/save — replace the full
     * per-article tree with the JSON body's `layout` field (or, when
     * absent, re-run save-transforms over the current state — useful
     * after a YT save-transform extension is installed).
     *
     * Optimistic-lock via `If-Match: <etag>` — when supplied and the
     * stored ETag doesn't match, returns 412 with the current ETag in
     * the body so the client can resync.
     */
    public function saveLayout(): void
    {
        $this->dispatch('write', function (array $claims): void {
            $articleId = $this->resolveArticleId();
            if ($articleId === null) {
                return;
            }
            // ACL: Bearer write-scope is authoritative (cookbook §2.2.4 + ADR-001 — session-strip prevents cookie-bypass).

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

            // Optimistic-lock — If-Match enforced when supplied. Round-4
            // audit F-A1-008: unified header reader via JoomlaEtagMiddleware
            // (RFC-7232 quote-strip + canonical Joomla Input::server chain).
            // POST save is opt-in lock (requireIfMatch=false) — cookbook §3.1.6.
            $ifMatch   = JoomlaEtagMiddleware::readIfMatchHeader();
            $lockError = JoomlaEtagMiddleware::enforce($ifMatch, $reader->etag(), false);
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

            $body   = $this->requestBody();
            $layout = $body['layout'] ?? null;
            $tree   = \is_array($layout) ? $layout : $reader->read();

            try {
                (new JoomlaArticleLayoutWriter($storage))->writeArticle($articleId, $tree);
            } catch (\RuntimeException $e) {
                SecurityLogger::log(SecurityLogger::EVENT_WRITE_FAILED, [
                    'platform'   => 'joomla',
                    'scope'      => 'l2_article',
                    'article_id' => $articleId,
                    'reason'     => $e->getMessage(),
                ]);
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.write_failed',
                    'Failed to persist article Builder state.',
                    500
                );
                return;
            }

            // Round-4 audit F-A1-006 + Round-6 A4 polish: invalidate the
            // per-article L2 cache scope (com_content + page-cache groups).
            // Best-effort — JoomlaCacheFlusher catches + logs internally; the
            // outer try/catch is defense-in-depth (cookbook §2.10.15 cache-flush
            // invariant — write success must NEVER depend on cache-flush success).
            try {
                (new JoomlaCacheFlusher())->flushL2($articleId);
            } catch (\Throwable $e) {
                SecurityLogger::log(SecurityLogger::EVENT_CACHE_FLUSH_FAILED, [
                    'platform'   => 'joomla',
                    'scope'      => 'l2_article',
                    'article_id' => $articleId,
                    'reason'     => $e->getMessage(),
                ]);
            }

            // Refresh ETag against the post-persist state.
            $fresh = new JoomlaArticleLayoutReader($storage, $articleId);
            JoomlaJsonResponse::send($this->app(), [
                'article_id' => $articleId,
                'etag'       => $fresh->etag(),
                'saved'      => true,
            ], 200);
        });
    }

    /**
     * Resolve and validate the `articleId` path-segment. Emits a 400
     * response and returns null on miss/invalid (caller checks for
     * null and exits early — the response is already flushed).
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

    // Round-6 A2 N-A2-001 (architectural P1): private assertArticleAcl() helper
    // removed. The pre-Round-6 implementation tried to layer Factory::getUser()
    // ACL on top of Bearer scope, but session-strip (ADR-001) deliberately
    // drops Joomla user identity for every yt-builder-mcp API request, so
    // authorise('core.edit', ...) was always false → every L2 write returned
    // a structurally always-deny 403 even with a valid admin-scope Bearer.
    // Bearer write-scope is now the sole authority for L2 (cookbook §2.2.4
    // Bearer-Deny-Invariant). See docs/adr/2026-05-24-l2-bearer-as-authority.md.

    // Round-4 audit F-A1-008 / A2-P2-N3: bespoke ifMatch() helper deleted.
    // L1+L2 controllers now share JoomlaEtagMiddleware::readIfMatchHeader()
    // — single source of truth for the Joomla Input::server header read,
    // RFC-7232 quote-strip, and case-sensitivity handling.
}
