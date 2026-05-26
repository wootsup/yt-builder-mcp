<?php
/**
 * PIN-TEST: JoomlaStateLock — INSERT IGNORE atomicity contract.
 *
 * Cookbook §4.5.1 / §4.5.4 — INSERT IGNORE on a PRIMARY KEY column is
 * the Joomla-portable atomic create-if-absent primitive (analogue to
 * WP add_option). A second acquire on the same key MUST return false;
 * stale locks (TTL exceeded) MUST be reclaimable by parsing the stored
 * `pid:microtime` value.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaStateLock;

#[CoversClass(JoomlaStateLock::class)]
final class JoomlaStateLockAtomicityPinTest extends TestCase
{
    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();
    }

    /**
     * @cookbook 4.5.1 first acquire wins; second on the same key fails
     */
    public function test_insert_ignore_returns_false_on_second_acquire(): void
    {
        $lock = new JoomlaStateLock();
        $first  = $lock->acquireForTemplate('tpl-a', 50);
        self::assertTrue($first);

        // Second acquire (same key, no reclaim happened) → must fail.
        // Use a tiny timeout so the test runs fast.
        $second = $lock->acquireForTemplate('tpl-a', 100);
        self::assertFalse($second, 'INSERT IGNORE must return 0 affected rows on duplicate key.');
    }

    /**
     * @cookbook 4.5.1 release deletes the lock row
     */
    public function test_release_deletes_lock_row_so_reacquire_succeeds(): void
    {
        $lock = new JoomlaStateLock();
        self::assertTrue($lock->acquireForTemplate('tpl-b', 50));

        $lock->releaseForTemplate('tpl-b');

        self::assertTrue($lock->acquireForTemplate('tpl-b', 50));
    }

    /**
     * @cookbook 4.5.1 reclaimIfStale parses pid:microtime and frees the lock
     */
    public function test_reclaim_if_stale_parses_pid_microtime_correctly(): void
    {
        $lock = new JoomlaStateLock();
        $key  = JoomlaStateLock::lockKey('tpl-stale');

        // Plant a stale lock row directly: microtime old enough that
        // (now - then) > LOCK_TTL_SECONDS (5).
        $stalePid   = 99999;
        $staleMicro = \microtime(true) - 60.0;
        \MockJoomlaDatabase::$tables[JoomlaStateLock::TABLE] = [
            $key => \sprintf('%d:%s', $stalePid, $staleMicro),
        ];

        // For the reclaim to occur we have to call acquireForTemplate
        // again — its internal loop invokes reclaimIfStale + tryAcquire.
        // The MockJoomlaDatabase loadAssoc() returns null by default;
        // configure it to surface the stale row so the reclaim branch
        // runs.
        \MockJoomlaDatabase::$useLoadAssocOverride = true;
        \MockJoomlaDatabase::$loadAssocOverride    = [
            'lock_value'  => \sprintf('%d:%s', $stalePid, $staleMicro),
            'acquired_at' => (int) $staleMicro,
        ];

        // First tryAcquire fails (key present), reclaim fires, deletes row,
        // next tryAcquire succeeds.
        $acquired = $lock->acquireForTemplate('tpl-stale', 500);
        self::assertTrue($acquired, 'Stale-lock reclaim must allow re-acquire within the timeout.');
    }

    /**
     * @cookbook 4.5.8 empty templateId is a no-op (root/library writes)
     */
    public function test_empty_template_id_is_noop(): void
    {
        $lock = new JoomlaStateLock();
        self::assertTrue($lock->acquireForTemplate('', 100));
        // Release on empty is also a no-op (no exception).
        $lock->releaseForTemplate('');
        // Re-acquire on empty is again true (no row was ever written).
        self::assertTrue($lock->acquireForTemplate('', 100));
    }

    /**
     * @cookbook 4.5.4 withTemplateLock wraps the callback + always releases
     */
    public function test_with_template_lock_releases_even_on_exception(): void
    {
        $lock = new JoomlaStateLock();
        $threw = false;
        try {
            $lock->withTemplateLock('tpl-c', function (): void {
                throw new \RuntimeException('boom');
            }, 100);
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
            $threw = true;
        }
        self::assertTrue($threw);

        // Lock MUST be released — re-acquire succeeds.
        self::assertTrue($lock->acquireForTemplate('tpl-c', 100));
    }
}
