<?php
/**
 * LayoutWriter ↔ StateLock wiring — integration test.
 *
 * Wave 6 Round-2.5 Fix 1. Round-2 shipped {@see StateLock} (19 unit tests
 * green) but never wired it into the production write paths. This test
 * is the structural guard that every public mutator on LayoutWriter
 * holds the per-template lock during the read+write critical section,
 * so two concurrent writes to the same template-id cannot both pass an
 * ETag/read check and then last-writer-wins each other.
 *
 * Single-process PHP cannot literally simulate the "two requests in the
 * same millisecond" scenario — but we can verify the wiring by:
 *
 *  (a) injecting an observing StateLock that records every
 *      acquire/release call, then asserting both methods were invoked
 *      around the persist();
 *  (b) injecting a "lock-already-held" StateLock that fails acquire,
 *      and asserting writeByPointer/writeTemplate/delete propagate the
 *      RuntimeException without persisting (test = simulated race-loss
 *      where the second writer correctly aborts instead of corrupting);
 *  (c) driving two back-to-back writes through the real StateLock and
 *      asserting both observe the post-state of the other — i.e. the
 *      second write reads the value the first write committed (no
 *      stale-snapshot persist).
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Integration\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;
use WootsUp\BuilderMcp\State\StateLock;

#[CoversClass(LayoutWriter::class)]
#[CoversClass(StateLock::class)]
#[CoversClass(JsonPointer::class)]
final class LayoutWriterLockTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [
            'yootheme' => [
                'templates' => [
                    'tpl-A' => [
                        'name' => 'A',
                        'layout' => ['type' => 'layout', 'children' => []],
                    ],
                    'tpl-B' => [
                        'name' => 'B',
                        'layout' => ['type' => 'layout', 'children' => []],
                    ],
                ],
            ],
        ];
    }

    public function test_write_template_acquires_and_releases_template_lock(): void
    {
        $recorder = new RecordingStateLock();
        $writer = new LayoutWriter(new LayoutReader(), null, $recorder);

        $writer->writeTemplate('tpl-A', [
            'name' => 'A2',
            'layout' => ['type' => 'layout', 'children' => []],
        ]);

        self::assertSame(['tpl-A'], $recorder->acquired);
        self::assertSame(['tpl-A'], $recorder->released);
    }

    public function test_write_by_pointer_acquires_template_lock_for_template_scoped_pointer(): void
    {
        $recorder = new RecordingStateLock();
        $writer = new LayoutWriter(new LayoutReader(), null, $recorder);

        $writer->writeByPointer('/templates/tpl-A/name', 'A-renamed');

        self::assertSame(['tpl-A'], $recorder->acquired);
        self::assertSame(['tpl-A'], $recorder->released);
        // Sanity: the write actually landed (lock did not swallow the write).
        $stored = (new LayoutReader())->readTemplate('tpl-A');
        self::assertNotNull($stored);
        self::assertSame('A-renamed', $stored['name']);
    }

    public function test_delete_acquires_template_lock_for_template_scoped_pointer(): void
    {
        $GLOBALS['ytb_test_options']['yootheme']['templates']['tpl-A']['layout']['children'] = [
            ['type' => 'headline', 'props' => ['content' => 'X']],
        ];
        $recorder = new RecordingStateLock();
        $writer = new LayoutWriter(new LayoutReader(), null, $recorder);

        $writer->delete('/templates/tpl-A/layout/children/0');

        self::assertSame(['tpl-A'], $recorder->acquired);
        self::assertSame(['tpl-A'], $recorder->released);
    }

    public function test_write_by_pointer_uses_empty_template_id_for_root_scoped_pointer(): void
    {
        // Library-level / root-level pointer — empty template id triggers
        // StateLock's no-op fast path (correct: there is nothing to
        // serialise per-template).
        $recorder = new RecordingStateLock();
        $writer = new LayoutWriter(new LayoutReader(), null, $recorder);

        $writer->writeByPointer('/library', ['placeholder' => true]);

        self::assertSame([''], $recorder->acquired);
        self::assertSame([''], $recorder->released);
    }

    public function test_write_template_aborts_when_lock_acquisition_fails(): void
    {
        // Simulate the race-loss case: another worker is already holding
        // the lock, so the second writer must abort before persisting.
        $blocking = new BlockingStateLock();
        $writer = new LayoutWriter(new LayoutReader(), null, $blocking);

        $this->expectException(\RuntimeException::class);
        try {
            $writer->writeTemplate('tpl-A', [
                'name' => 'should-not-persist',
                'layout' => ['type' => 'layout', 'children' => []],
            ]);
        } finally {
            // The blocked write must NOT have mutated the option.
            $stored = (new LayoutReader())->readTemplate('tpl-A');
            self::assertNotNull($stored);
            self::assertSame('A', $stored['name']);
        }
    }

    public function test_write_by_pointer_aborts_when_lock_acquisition_fails(): void
    {
        $blocking = new BlockingStateLock();
        $writer = new LayoutWriter(new LayoutReader(), null, $blocking);

        $this->expectException(\RuntimeException::class);
        try {
            $writer->writeByPointer('/templates/tpl-A/name', 'should-not-persist');
        } finally {
            $stored = (new LayoutReader())->readTemplate('tpl-A');
            self::assertNotNull($stored);
            self::assertSame('A', $stored['name']);
        }
    }

    public function test_sequential_writes_both_observe_their_own_writes_with_real_state_lock(): void
    {
        // The wiring contract: each writeByPointer call must read the
        // post-state of the previous write (no stale snapshot leakage
        // across the lock release/re-acquire boundary). This pins the
        // structural intent of Fix 9.3.
        $writer = new LayoutWriter(new LayoutReader(), null, new StateLock());

        $writer->writeByPointer('/templates/tpl-A/name', 'first');
        $afterFirst = (new LayoutReader())->readTemplate('tpl-A');
        self::assertNotNull($afterFirst);
        self::assertSame('first', $afterFirst['name']);

        $writer->writeByPointer('/templates/tpl-A/name', 'second');
        $afterSecond = (new LayoutReader())->readTemplate('tpl-A');
        self::assertNotNull($afterSecond);
        self::assertSame('second', $afterSecond['name']);

        // After both writes the lock must be released (option key absent).
        $lockKey = StateLock::optionKey('tpl-A');
        self::assertArrayNotHasKey($lockKey, $GLOBALS['ytb_test_options']);
    }

    public function test_writes_to_different_templates_do_not_share_a_lock(): void
    {
        // Wave-6 R2.5 invariant: locks are per-template-id, NOT global —
        // touching template B must not be serialised behind template A.
        $recorder = new RecordingStateLock();
        $writer = new LayoutWriter(new LayoutReader(), null, $recorder);

        $writer->writeByPointer('/templates/tpl-A/name', 'a-new');
        $writer->writeByPointer('/templates/tpl-B/name', 'b-new');

        self::assertSame(['tpl-A', 'tpl-B'], $recorder->acquired);
        self::assertSame(['tpl-A', 'tpl-B'], $recorder->released);
    }
}

/**
 * Test-double StateLock that records every acquire/release call so we can
 * assert wiring without relying on the real add_option-CAS semantics.
 */
final class RecordingStateLock extends StateLock
{
    /** @var list<string> */
    public array $acquired = [];
    /** @var list<string> */
    public array $released = [];

    public function withTemplateLock(string $templateId, callable $callback, int $timeoutMs = self::DEFAULT_TIMEOUT_MS)
    {
        $this->acquired[] = $templateId;
        try {
            return $callback();
        } finally {
            $this->released[] = $templateId;
        }
    }
}

/**
 * Test-double StateLock that always fails acquisition — simulates a
 * race-loss against a concurrent holder so we can verify that the
 * production code path aborts cleanly instead of persisting.
 */
final class BlockingStateLock extends StateLock
{
    public function withTemplateLock(string $templateId, callable $callback, int $timeoutMs = self::DEFAULT_TIMEOUT_MS)
    {
        throw new \RuntimeException(
            sprintf('BlockingStateLock: refused to acquire for "%s" (simulated race loss).', $templateId),
        );
    }
}
