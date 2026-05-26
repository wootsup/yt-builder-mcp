<?php
/**
 * F-008 pin: every L2 article persist() MUST bump BOTH the per-article
 * counter AND the global (L1) state-revision counter.
 *
 * Background: `yootheme_builder_get_etag` on Joomla reads the L1 counter
 * exclusively. Before F-008 the L2 writer only advanced the per-article
 * counter, so the top-level ETag stayed frozen while real article-write
 * traffic flowed through L2 — agents lost change-signalling. The fix
 * adds a second `bump()` against the cross-platform
 * {@see StateRevisionInterface} inside `persist()`, mirroring WP-side
 * `\WootsUp\BuilderMcp\State\LayoutWriter::persist`.
 *
 * Strategy: build the writer normally then swap its `storage` property
 * (via reflection) with an in-memory fake that round-trips article
 * tree writes — so persist()'s verify-read matches the canonical
 * encoding of the just-written tree. The REAL JoomlaOptionStore +
 * REAL JoomlaArticleStateRevision write through to the mock-DB tables,
 * giving us a live per-article counter we can read between persist
 * calls. The global L1 counter is a recording fake that captures the
 * per-article counter's value at the exact moment global bump() fires
 * — proving ordering (per-article bumps FIRST).
 *
 * JoomlaArticleLayoutStorage is `final` and the writer's storage field
 * is `readonly`, so reflection is required to install the fake. Using a
 * minimal inline fake (no Joomla DB-driver chatter) keeps the test
 * focused on revision-bump semantics rather than storage internals
 * (those are covered by JoomlaArticleLayoutStorageTest).
 *
 * Cookbook §4.13.5 cross-reference (L2 article-storage), §4.6 (F-07
 * monotonic revision ABA-defense).
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\L2
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\L2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\L2\JoomlaArticleLayoutStorage;
use WootsUp\BuilderMcp\Platform\Joomla\L2\JoomlaArticleLayoutWriter;
use WootsUp\BuilderMcp\Platform\Joomla\L2\JoomlaArticleStateRevision;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;
use WootsUp\BuilderMcp\State\StateLockInterface;
use WootsUp\BuilderMcp\State\StateRevisionInterface;

#[CoversClass(JoomlaArticleLayoutWriter::class)]
final class JoomlaArticleLayoutWriterRevisionBumpTest extends TestCase
{
    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        \ytb_test_install_mock_db();
        \MockJoomlaDatabase::$useLoadResultOverride = false;
        \MockJoomlaDatabase::$throwException = false;
        \MockJoomlaDatabase::$executeResult = true;
    }

    protected function tearDown(): void
    {
        \MockJoomlaFactory::reset();
    }

    public function test_write_article_bumps_both_per_article_and_global_revision(): void
    {
        $fakeStorage = $this->fakeStorageState();
        $optionStore = new JoomlaOptionStore();
        $perArticle  = new JoomlaArticleStateRevision($optionStore, 42);
        $global      = $this->recordingGlobalRevision($perArticle);
        $writer      = $this->buildWriter($fakeStorage, $optionStore, $global);

        $writer->writeArticle(42, ['templates' => ['x' => ['name' => 'X']]]);

        self::assertSame(1, $fakeStorage->writeCalls, 'Fake storage write was invoked once.');
        self::assertSame(
            1,
            $global->bumpCount,
            'F-008 — global L1 revision must bump exactly once per L2 persist().'
        );
        self::assertSame(
            1,
            $perArticle->current(),
            'Per-article counter must bump exactly once per persist().'
        );
        self::assertSame(
            [1],
            $global->observedPerArticleValues,
            'F-008 — per-article bump must precede the global bump '
                . '(global saw per-article=1, not 0).'
        );
    }

    public function test_two_persist_calls_advance_both_counters_monotonically(): void
    {
        $fakeStorage = $this->fakeStorageState();
        $optionStore = new JoomlaOptionStore();
        $perArticle  = new JoomlaArticleStateRevision($optionStore, 5);
        $global      = $this->recordingGlobalRevision($perArticle);
        $writer      = $this->buildWriter($fakeStorage, $optionStore, $global);

        $tree = ['templates' => ['x' => ['name' => 'X']]];
        $writer->writeArticle(5, $tree);
        $writer->writeArticle(5, $tree);

        self::assertSame(2, $fakeStorage->writeCalls);
        self::assertSame(2, $global->bumpCount, 'Two persist calls → global bumped twice.');
        self::assertSame(2, $perArticle->current(), 'Two persist calls → per-article counter at 2.');
        self::assertSame(
            [1, 2],
            $global->observedPerArticleValues,
            'Global must observe per-article ascending — proves ordering on each call.'
        );
    }

    public function test_invalid_id_short_circuits_before_any_bump(): void
    {
        // Sanity: invalid id throws BEFORE persist() — neither counter
        // should advance. Confirms the new bump call sits inside persist()
        // and not, e.g., at the top of writeArticle().
        $fakeStorage = $this->fakeStorageState();
        $optionStore = new JoomlaOptionStore();
        $perArticle  = new JoomlaArticleStateRevision($optionStore, 1);
        $global      = $this->recordingGlobalRevision($perArticle);
        $writer      = $this->buildWriter($fakeStorage, $optionStore, $global);

        try {
            $writer->writeArticle(0, ['k' => 'v']);
            self::fail('Expected InvalidArgumentException for id <= 0.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        self::assertSame(0, $global->bumpCount, 'No global bump on invalid-id rejection.');
        self::assertSame(0, $fakeStorage->writeCalls);
    }

    /**
     * Construct the writer with our in-memory fake storage + the real
     * OptionStore + the recording global revision. The writer's
     * constructor accepts a nullable JoomlaArticleLayoutStorage; we pass
     * a subclass instance with overridden read/write methods that bypass
     * the DB driver entirely.
     */
    private function buildWriter(
        JoomlaArticleLayoutStorage $fakeStorage,
        JoomlaOptionStore $optionStore,
        StateRevisionInterface $global,
    ): JoomlaArticleLayoutWriter {
        return new JoomlaArticleLayoutWriter(
            $fakeStorage,
            $this->noopLock(),
            $optionStore,
            $global,
        );
    }

    /**
     * In-memory subclass of JoomlaArticleLayoutStorage that satisfies the
     * readArticle/writeArticle pair persist() invokes. Mimics the real
     * storage contract: a write stores the array; the very next read
     * returns the same array (so the post-write verify-read encoding
     * round-trip succeeds). Other methods inherit the base impl but are
     * never called by the writer.
     *
     * The base class was de-finalised (single-keyword change, zero
     * functional impact) to allow this test substitution without
     * touching the writer's readonly property contract via reflection.
     */
    private function fakeStorageState(): JoomlaArticleLayoutStorage
    {
        return new class extends JoomlaArticleLayoutStorage {
            /** @var array<int, array<string, mixed>> */
            public array $store      = [];
            public int   $writeCalls = 0;
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
                $this->writeCalls++;
                return true;
            }
        };
    }

    /**
     * Build a no-op StateLock that just invokes the callback. We pin lock
     * semantics elsewhere; here we want to isolate revision-bump behaviour
     * from any storage-lock acquisition complexity.
     */
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

    /**
     * Recording fake L1 StateRevision that, on every bump, also captures
     * the current per-article counter value. Tests then assert the
     * observed per-article value is >= 1 (proving per-article bumped
     * BEFORE global) rather than 0 (which would mean global bumped first).
     */
    private function recordingGlobalRevision(JoomlaArticleStateRevision $perArticle): StateRevisionInterface
    {
        return new class ($perArticle) implements StateRevisionInterface {
            public int $bumpCount = 0;
            public int $value     = 0;
            /** @var array<int, int> */
            public array $observedPerArticleValues = [];
            public function __construct(private readonly JoomlaArticleStateRevision $perArticle)
            {
            }
            public function current(): int
            {
                return $this->value;
            }
            public function bump(): int
            {
                $this->observedPerArticleValues[] = $this->perArticle->current();
                $this->bumpCount++;
                $this->value++;
                return $this->value;
            }
        };
    }
}
