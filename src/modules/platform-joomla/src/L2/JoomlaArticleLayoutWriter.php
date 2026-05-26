<?php
/**
 * Per-article writer over `#__content.fulltext` — L2 twin of
 * {@see \WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutWriter}.
 *
 * Cookbook §4.13.5 (L2 article-storage cross-reference) + §4.3 fidelity:
 *   - Each write wrapped in per-article StateLock via
 *     {@see JoomlaStateLock::withTemplateLock} with the per-article
 *     lock-key shape `article_<id>` (cookbook §4.5.4)
 *   - YT save-transforms run via YtBootstrapper-protected
 *     `\YOOtheme\app('builder')->withParams(context:'save')->load($json)`
 *     (cookbook §4.1.7 + S2 spike — bypasses YT's `onAfterRespond`
 *     deferred-write so REST returns "saved" synchronously)
 *   - Verify-read after persist (cookbook §4.3.6c)
 *   - Revision-bump only on success (no ETag-lie on persist failure)
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\L2
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\L2;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaStateLock;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaStateRevision;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;
use WootsUp\BuilderMcp\Platform\Joomla\Util\YtBootstrapper;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\State\StateLockInterface;
use WootsUp\BuilderMcp\State\StateRevisionInterface;
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class JoomlaArticleLayoutWriter
{
    private readonly StateLockInterface $stateLock;
    private readonly JoomlaArticleLayoutStorage $storage;
    private readonly JoomlaOptionStore $optionStore;
    private readonly StateRevisionInterface $globalRevision;

    public function __construct(
        ?JoomlaArticleLayoutStorage $storage = null,
        ?StateLockInterface $stateLock = null,
        ?JoomlaOptionStore $optionStore = null,
        ?StateRevisionInterface $globalRevision = null,
    ) {
        $this->storage     = $storage     ?? new JoomlaArticleLayoutStorage();
        $this->stateLock   = $stateLock   ?? new JoomlaStateLock();
        $this->optionStore = $optionStore ?? new JoomlaOptionStore();
        // F-008 fix: the global L1 revision counter MUST also advance on
        // every L2 persisted mutation, mirroring WP's LayoutWriter::persist
        // semantics where any committed mutation surfaces as a new
        // top-level ETag. Re-use the same JoomlaOptionStore so the L1
        // counter and the L2 per-article counters live in the same backing
        // table. Honour the StateRevisionInterface contract per Joomla
        // cookbook §S2 — tests can substitute a recording fake.
        $this->globalRevision = $globalRevision ?? new JoomlaStateRevision($this->optionStore);
    }

    /**
     * Full-article-tree replace under per-article lock. Cookbook §4.13.5
     * — lock-key is composed as `article_<id>` so two concurrent saves
     * against the SAME article serialise, but writes against DIFFERENT
     * articles do not contend (per-article isolation).
     *
     * @param array<string, mixed> $tree
     */
    public function writeArticle(int $id, array $tree): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('articleId must be a positive integer.');
        }
        $this->stateLock->withTemplateLock(self::lockKey($id), function () use ($id, $tree): void {
            $transformed = $this->runSaveTransforms($tree);
            $this->persist($id, $transformed);
        });
    }

    /**
     * RFC-6901 set under per-article lock. The pointer is resolved
     * against the article's full tree (NOT the global state) — L2 paths
     * are article-scoped by definition.
     *
     * @param mixed $value
     */
    public function writeByPointer(int $id, string $pointer, mixed $value): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('articleId must be a positive integer.');
        }
        $this->stateLock->withTemplateLock(self::lockKey($id), function () use ($id, $pointer, $value): void {
            $state = $this->storage->readArticle($id);
            JsonPointer::set($state, $pointer, $value);
            $this->persist($id, $state);
        });
    }

    /** RFC-6901 remove under per-article lock. Silent no-op on miss. */
    public function delete(int $id, string $pointer): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('articleId must be a positive integer.');
        }
        $this->stateLock->withTemplateLock(self::lockKey($id), function () use ($id, $pointer): void {
            $state   = $this->storage->readArticle($id);
            $removed = JsonPointer::remove($state, $pointer);
            if ($removed) {
                $this->persist($id, $state);
            }
        });
    }

    /**
     * Lazy YT-bootstrap + invoke `\YOOtheme\app('builder')->withParams(['context'=>'save'])->load($json)`.
     *
     * Mirrors {@see JoomlaLayoutWriter::runSaveTransforms}: YT-absent
     * environments (REST cold-start before YT loads, unit-test harness,
     * Joomla CLI) fall through to the cookbook §4.10.3 fail-safe path
     * — `$tree` returned unchanged. The controller's YT-503 translation
     * applies for bootstrap failures from other code paths.
     *
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    public function runSaveTransforms(array $tree): array
    {
        try {
            YtBootstrapper::ensure();
            $json = \json_encode($tree, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            /** @psalm-suppress UndefinedFunction Resolved by YtBootstrapper::ensure() */
            $builder = \YOOtheme\app('builder');
            if (\is_object($builder) && \method_exists($builder, 'withParams')) {
                $loaded = $builder->withParams(['context' => 'save'])->load($json);
                if (\is_array($loaded)) {
                    return $loaded;
                }
            }
            return $tree;
        } catch (\Throwable $e) {
            // Round-4 audit F-A1-007 / A2-P2-N2: literal-string promoted to
            // SecurityLogger constant for forensic grep-ability + drift-detection.
            SecurityLogger::log(SecurityLogger::EVENT_SAVE_TRANSFORM_FALLBACK, [
                'platform'   => 'joomla',
                'scope'      => 'l2_article',
                'article_id' => 0, // populated by caller-context where useful
                'reason'     => $e->getMessage(),
            ]);
            return $tree;
        }
    }

    /**
     * Single call-site that hits storage. Verify-read after write per
     * cookbook §4.3.6c. On mismatch logs EVENT_WRITE_FAILED + throws
     * (controller maps to 500 `yootheme_builder_mcp.write_failed`).
     *
     * Revision-bump on a per-article counter ONLY on success — no
     * ETag-lie on persist failure (cookbook §4.3.6d).
     *
     * @param array<string, mixed> $state
     */
    private function persist(int $id, array $state): void
    {
        $ok = $this->storage->writeArticle($id, $state);
        if (!$ok) {
            SecurityLogger::log(SecurityLogger::EVENT_WRITE_FAILED, [
                'platform'   => 'joomla',
                'scope'      => 'l2_article',
                'article_id' => $id,
                'reason'     => 'storage_write_returned_false',
            ]);
            throw new \RuntimeException(\sprintf(
                'Failed to persist L2 Builder state to #__content.fulltext for article %d.',
                $id
            ));
        }

        // Verify-read: byte-for-byte compare encoded expected vs actual.
        $verify = $this->storage->readArticle($id);
        $exp    = \json_encode($state,  \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $act    = \json_encode($verify, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($exp !== $act) {
            SecurityLogger::log(SecurityLogger::EVENT_WRITE_FAILED, [
                'platform'   => 'joomla',
                'scope'      => 'l2_article',
                'article_id' => $id,
                'reason'     => 'verify_read_mismatch',
                'exp_len'    => $exp === false ? 0 : \strlen($exp),
                'act_len'    => $act === false ? 0 : \strlen($act),
            ]);
            throw new \RuntimeException(\sprintf(
                'Verify-read mismatch after persisting L2 Builder state for article %d.',
                $id
            ));
        }

        // Bump the per-article revision counter inside the critical
        // section (the per-article StateLock already wraps us).
        $revision = new JoomlaArticleStateRevision($this->optionStore, $id);
        $revision->bump();

        // F-008 fix (2026-05-26): ALSO bump the global L1 state-revision
        // counter so the top-level ETag (`yootheme_builder_get_etag` →
        // EtagController) advances on every L2 article write. Without this
        // an agent watching the global ETag for change-signalling would
        // see WP advance on every write but Joomla stay frozen at the L1
        // revision while real article-write traffic flows through L2.
        // Mirrors {@see \WootsUp\BuilderMcp\State\LayoutWriter::persist}:
        // any committed mutation produces a new global ETag regardless of
        // scope. Order: per-article first (the more granular signal), then
        // global — both happen inside the per-article StateLock so no
        // partial-bump can be observed between the two writes from another
        // request reading the same article.
        $this->globalRevision->bump();
    }

    /**
     * Per-article StateLock key (cookbook §4.13.5). The lock-key shape
     * `article_<id>` keeps article writes isolated from each other AND
     * from L1 template writes (which use `tpl_<hash>` keys via
     * {@see JoomlaStateLock::lockKey}).
     */
    public static function lockKey(int $id): string
    {
        return 'article_' . $id;
    }
}
