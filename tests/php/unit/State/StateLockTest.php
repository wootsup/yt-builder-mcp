<?php
/**
 * StateLock — per-template advisory lock tests.
 *
 * Wave 6 Round-2 R2.10. Closes the residual TOCTOU window on
 * concurrent writes to the same template by serializing the
 * read+write critical-section via add_option-CAS semantics.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\State\StateLock;

#[CoversClass(StateLock::class)]
final class StateLockTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [];
    }

    public function test_acquire_succeeds_when_lock_is_free(): void
    {
        $lock = new StateLock();
        self::assertTrue($lock->acquireForTemplate('tpl-1'));
    }

    public function test_acquire_writes_lock_to_options(): void
    {
        $lock = new StateLock();
        $lock->acquireForTemplate('tpl-1');
        $key = StateLock::optionKey('tpl-1');
        self::assertArrayHasKey($key, $GLOBALS['ytb_test_options']);
    }

    public function test_acquire_returns_false_when_lock_already_held_and_timeout_expires(): void
    {
        // Manually plant a fresh lock so the polling loop never gets it.
        $key = StateLock::optionKey('tpl-1');
        $GLOBALS['ytb_test_options'][$key] = '999:' . microtime(true);

        $lock = new StateLock();
        $start = microtime(true);
        $acquired = $lock->acquireForTemplate('tpl-1', 200); // 200ms timeout
        $elapsed = (microtime(true) - $start) * 1000;

        self::assertFalse($acquired);
        // Sanity: we did wait at least most of the timeout.
        self::assertGreaterThanOrEqual(150, $elapsed);
    }

    public function test_release_clears_the_lock(): void
    {
        $lock = new StateLock();
        $lock->acquireForTemplate('tpl-1');
        $lock->releaseForTemplate('tpl-1');
        $key = StateLock::optionKey('tpl-1');
        self::assertArrayNotHasKey($key, $GLOBALS['ytb_test_options']);
    }

    public function test_release_is_safe_when_no_lock_held(): void
    {
        // Must NOT throw.
        $lock = new StateLock();
        $lock->releaseForTemplate('never-locked');
        self::assertTrue(true);
    }

    public function test_acquire_reclaims_stale_lock(): void
    {
        // Plant a lock that's "old" (timestamp far in the past so age > TTL).
        $key = StateLock::optionKey('tpl-1');
        $GLOBALS['ytb_test_options'][$key] = '999:1';

        $lock = new StateLock();
        // Should succeed within a few polling cycles by reclaiming the stale entry.
        self::assertTrue($lock->acquireForTemplate('tpl-1', 1000));
    }

    public function test_with_template_lock_releases_after_callback(): void
    {
        $lock = new StateLock();
        $result = $lock->withTemplateLock('tpl-1', static fn (): string => 'ok');
        self::assertSame('ok', $result);
        $key = StateLock::optionKey('tpl-1');
        self::assertArrayNotHasKey($key, $GLOBALS['ytb_test_options']);
    }

    public function test_with_template_lock_releases_on_exception(): void
    {
        $lock = new StateLock();
        try {
            $lock->withTemplateLock('tpl-1', static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException) {
            // Expected.
        }
        $key = StateLock::optionKey('tpl-1');
        self::assertArrayNotHasKey($key, $GLOBALS['ytb_test_options']);
    }

    public function test_acquire_noop_for_empty_template_id(): void
    {
        // Root-pointer / library-only writes pass templateId='' and the
        // lock must short-circuit to true (no per-template locking applies).
        $lock = new StateLock();
        self::assertTrue($lock->acquireForTemplate(''));
        self::assertSame([], $GLOBALS['ytb_test_options']);
    }

    public function test_option_key_is_stable_md5_derived(): void
    {
        // Pin the key derivation so a future change is loud — same templateId
        // must always produce the same key (otherwise lock collisions break).
        $expected = 'ytb_mcp_lock_tpl_' . md5('tpl-foo');
        self::assertSame($expected, StateLock::optionKey('tpl-foo'));
    }
}
