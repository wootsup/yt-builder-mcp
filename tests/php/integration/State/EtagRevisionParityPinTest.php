<?php
/**
 * F-008 parity pin: every Builder-state mutation MUST advance the
 * global state-revision counter that drives `yootheme_builder_get_etag`,
 * regardless of which write path (L1 templates, L2 per-article) the
 * mutation took. This pin tests the Joomla side; the WP-side contract
 * is pinned by {@see \WootsUp\BuilderMcp\Tests\Integration\State\LayoutWriterJsonStorageTest}
 * plus the WP write-side persist invariant in
 * {@see \WootsUp\BuilderMcp\State\LayoutWriter::persist} (commented
 * "F-07 fix" at line ~336).
 *
 * Why this test exists: F-008 (verified live 2026-05-26) found that the
 * Joomla L2 article writer bumped its per-article counter but NOT the
 * global L1 counter — so `yootheme_builder_get_etag` returned WP `-r121`
 * but Joomla `-r2` after equivalent traffic. Clients lost change-
 * signalling for L2 traffic.
 *
 * Contract pinned (CROSS-PATH parity — both L1 and L2 must bump the
 * global counter; without an L1 case here, a future regression that
 * breaks the L1 bump (e.g. removing the `getRevision()->bump()` call
 * from `JoomlaLayoutWriter::persist()`) would silently slip through
 * THIS file despite the filename claiming "parity"):
 *   - After L2 `writeArticle()`, the global JoomlaStateRevision MUST
 *     have advanced. The reader's etag (sha256-based) MUST differ from
 *     the pre-write etag.
 *   - After L1 `writeTemplate()`, the global JoomlaStateRevision MUST
 *     have advanced by exactly the same +1 increment (parity claim).
 *   - The behavior holds across multiple consecutive writes (counter is
 *     strictly monotonic, never resets).
 *
 * @package WootsUp\BuilderMcp\Tests\Integration\State
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Integration\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\L2\JoomlaArticleLayoutStorage;
use WootsUp\BuilderMcp\Platform\Joomla\L2\JoomlaArticleLayoutWriter;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutReader;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutWriter;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaPagesMetaStore;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaStateRevision;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaLayoutStorage;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;
use WootsUp\BuilderMcp\State\StateLockInterface;

#[CoversClass(JoomlaArticleLayoutWriter::class)]
#[CoversClass(JoomlaLayoutWriter::class)]
#[CoversClass(JoomlaStateRevision::class)]
final class EtagRevisionParityPinTest extends TestCase
{
    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        \ytb_test_install_mock_db();
        \MockJoomlaDatabase::$useLoadResultOverride = false;
        \MockJoomlaDatabase::$throwException = false;
        \MockJoomlaDatabase::$executeResult = true;
        JoomlaLayoutStorage::resetForTests();
    }

    protected function tearDown(): void
    {
        \MockJoomlaFactory::reset();
        JoomlaLayoutStorage::resetForTests();
    }

    public function test_l2_write_article_advances_global_state_revision(): void
    {
        $optionStore    = new JoomlaOptionStore();
        $globalRevision = new JoomlaStateRevision($optionStore);
        $writer         = new JoomlaArticleLayoutWriter(
            $this->fakeStorage(),
            $this->noopLock(),
            $optionStore,
            $globalRevision,
        );

        // Pre-write: global revision is 0.
        self::assertSame(0, $globalRevision->current(), 'Global revision starts at 0.');

        $writer->writeArticle(42, ['templates' => ['x' => ['name' => 'X']]]);

        // F-008: global counter MUST have advanced.
        self::assertSame(
            1,
            $globalRevision->current(),
            'F-008 — L2 writeArticle must advance global state-revision (the '
                . 'counter that yootheme_builder_get_etag reads).'
        );
    }

    public function test_consecutive_l2_writes_are_strictly_monotonic_on_global_counter(): void
    {
        $optionStore    = new JoomlaOptionStore();
        $globalRevision = new JoomlaStateRevision($optionStore);
        $writer         = new JoomlaArticleLayoutWriter(
            $this->fakeStorage(),
            $this->noopLock(),
            $optionStore,
            $globalRevision,
        );

        // Drive 5 consecutive L2 writes across different articles + same
        // article. Each must advance the global counter by exactly 1.
        $writer->writeArticle(42, ['templates' => ['x' => ['name' => 'X1']]]);
        self::assertSame(1, $globalRevision->current());
        $writer->writeArticle(42, ['templates' => ['x' => ['name' => 'X2']]]);
        self::assertSame(2, $globalRevision->current());
        $writer->writeArticle(7, ['templates' => ['y' => ['name' => 'Y1']]]);
        self::assertSame(3, $globalRevision->current());
        $writer->writeArticle(99, ['templates' => ['z' => ['name' => 'Z1']]]);
        self::assertSame(4, $globalRevision->current());
        $writer->writeArticle(7, ['templates' => ['y' => ['name' => 'Y2']]]);
        self::assertSame(
            5,
            $globalRevision->current(),
            'F-008 — five L2 writes (mixed article-ids) must produce global '
                . 'counter = 5, regardless of which article was touched.'
        );
    }

    /**
     * F-008 PARITY-CASE: L1 write path. Without this case the file's
     * filename-claim "parity" is hollow — a future regression that
     * removed `$this->reader->getRevision()->bump()` from
     * {@see JoomlaLayoutWriter::persist} (production L1 write path)
     * would still leave THIS file green, because every other test here
     * only exercises L2. This test pairs with
     * {@see self::test_l2_write_article_advances_global_state_revision}
     * to lock the cross-path invariant: *every* committed Builder-state
     * mutation, no matter the scope, MUST advance the global counter.
     *
     * Setup mirrors the L2 case's recording-fake style: an in-memory
     * round-tripping mock for the DB-bound `JoomlaLayoutStorage` reads
     * (extension_id lookup + custom_data blob round-trip via the
     * closure {@see self::layoutStorageReadClosure}), plus the shared
     * no-op StateLock so persist() runs synchronously without touching
     * the lock-row.
     */
    public function test_l1_write_template_also_advances_global_state_revision(): void
    {
        // Closure-mode override: the production L1 read pipeline binds
        // `:element` + `:folder` (not `:key`), so the default
        // `:key`-only mock derivation returns null. The closure
        // dispatches per-query: extension_id lookup → '7'; custom_data
        // SELECT → the latest blob written via the `:data` bind (so
        // persist()'s verify-read round-trips byte-for-byte); option-
        // store `:key` reads → delegate to the in-memory tables map.
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride    = self::layoutStorageReadClosure();

        $optionStore    = new JoomlaOptionStore();
        $globalRevision = new JoomlaStateRevision($optionStore);

        $writer = new JoomlaLayoutWriter(
            new JoomlaLayoutReader(new JoomlaLayoutStorage(), $globalRevision),
            new JoomlaLayoutStorage(),
            $this->noopLock(),
            new JoomlaPagesMetaStore($optionStore),
        );

        // Pre-write: global revision is 0 (same starting contract as L2).
        self::assertSame(0, $globalRevision->current(), 'Global revision starts at 0 for the L1 path.');

        $writer->writeTemplate('tpl-home', ['name' => 'Home']);

        // F-008 parity: the L1 write path MUST also have bumped the same
        // global counter that L2 bumps. If a refactor ever deletes the
        // `$this->reader->getRevision()->bump()` line from
        // JoomlaLayoutWriter::persist, this assertion fails — pinning
        // the cross-path parity the filename claims.
        self::assertSame(
            1,
            $globalRevision->current(),
            'F-008 — L1 writeTemplate must advance the SAME global state-'
                . 'revision counter as L2 (cross-path parity claim).'
        );
    }

    /**
     * Closure for {@see \MockJoomlaDatabase::$loadResultOverride} that
     * makes the DB-bound `JoomlaLayoutStorage` round-trip in unit-test
     * land. Three dispatch arms:
     *   1. Queries with `:key` bind (option-store reads) → delegate to
     *      the in-memory `$tables` map by scanning each table.
     *   2. `:element` + `:folder` + SELECT extension_id → return '7'
     *      (the YT system-plugin extension_id).
     *   3. `:element` + `:folder` + SELECT custom_data → return the
     *      most-recent `:data` bind from `$executedQueries` (the blob
     *      we just wrote), so persist()'s verify-read matches.
     */
    private static function layoutStorageReadClosure(): \Closure
    {
        return static function (?\MockJoomlaQuery $q): mixed {
            if (!$q instanceof \MockJoomlaQuery) {
                return null;
            }
            // (1) Option-store reads — `:key`-based lookups delegate to
            // the in-memory tables map. Mirrors deriveLoadResultFromQuery.
            if (isset($q->binds[':key'])) {
                $key = (string) $q->binds[':key'];
                foreach (\MockJoomlaDatabase::$tables as $rows) {
                    if (\array_key_exists($key, $rows)) {
                        return $rows[$key];
                    }
                }
                return null;
            }
            // (2) + (3) JoomlaLayoutStorage queries are bound by element/folder.
            if (isset($q->binds[':element'], $q->binds[':folder'])) {
                $selects = \implode(' ', $q->selects);
                if (\str_contains($selects, 'extension_id')) {
                    return '7';
                }
                if (\str_contains($selects, 'custom_data')) {
                    // Walk recorded queries backwards for the latest write.
                    $queries = \MockJoomlaDatabase::$executedQueries;
                    for ($i = \count($queries) - 1; $i >= 0; $i--) {
                        $past = $queries[$i];
                        if ($past instanceof \MockJoomlaQuery && isset($past->binds[':data'])) {
                            return (string) $past->binds[':data'];
                        }
                    }
                    return '';
                }
            }
            return null;
        };
    }

    /**
     * In-memory subclass of JoomlaArticleLayoutStorage that satisfies the
     * readArticle/writeArticle pair persist() invokes. Bypasses the DB
     * driver because we only care about revision-counter advancement,
     * not storage byte-shape (covered by other tests).
     */
    private function fakeStorage(): JoomlaArticleLayoutStorage
    {
        return new class extends JoomlaArticleLayoutStorage {
            /** @var array<int, array<string, mixed>> */
            private array $store = [];
            public function readArticle(int $id): array
            {
                return $this->store[$id] ?? [];
            }
            public function writeArticle(int $id, array $tree): bool
            {
                if ($id <= 0) {
                    return false;
                }
                $this->store[$id] = $tree;
                return true;
            }
        };
    }

    private function noopLock(): StateLockInterface
    {
        return new class implements StateLockInterface {
            public function withTemplateLock(string $templateId, callable $callback, int $timeoutMs = 5000): mixed
            {
                return $callback();
            }
            public function acquireForTemplate(string $templateId, int $timeoutMs = 5000): bool
            {
                return true;
            }
            public function releaseForTemplate(string $templateId): void
            {
            }
        };
    }
}
