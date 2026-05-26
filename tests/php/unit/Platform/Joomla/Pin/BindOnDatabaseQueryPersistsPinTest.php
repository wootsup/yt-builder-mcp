<?php
/**
 * REGRESSION PIN-TEST (R8-A3 F-1): bind-on-DatabaseQuery, NOT on the driver.
 *
 * The headline Wave-7 production bug: storage code bound parameters on the
 * RESULT of `$db->setQuery($rawSql)` — i.e. on the DatabaseDriver. Real
 * Joomla `MysqliDriver` has NO `bind()` (binding lives on `DatabaseQuery`),
 * so `$db->setQuery($sql)->bind(...)` raised "Call to undefined method
 * MysqliDriver::bind()", was swallowed by the store's `catch (\Throwable)`,
 * and EVERY option / transient / lock write silently failed → no
 * signing_secret, no keys → all Bearer auth dead, with no error surfaced.
 *
 * The fix builds the raw SQL on a query object first
 * (`$db->createQuery()->setQuery($sql)->bind(...)` then
 * `$db->setQuery($query)->execute()`).
 *
 * RED-WITHOUT-FIX: the test stub's DatabaseDriver mock NO LONGER exposes a
 * driver-level `bind()` (JoomlaCmsStubs.php — deliberately absent), so the
 * pre-fix `$db->setQuery($sql)->bind()` pattern raises an `Error` exactly as
 * production did → swallowed → `add()`/`set()` returns false / no row
 * persists → the round-trip assertions below FAIL. Against the fixed code the
 * value persists and round-trips.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaStateLock;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaTransientStore;

#[CoversClass(JoomlaOptionStore::class)]
#[CoversClass(JoomlaTransientStore::class)]
#[CoversClass(JoomlaStateLock::class)]
final class BindOnDatabaseQueryPersistsPinTest extends TestCase
{
    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();
    }

    /**
     * OptionStore::add() — atomic create-if-absent. The bound :value MUST
     * reach the table; a driver-bind fatal would swallow the write → false.
     */
    public function test_option_store_add_persists_and_round_trips(): void
    {
        $store = new JoomlaOptionStore();

        $created = $store->add('signing_secret', 'enc1:abc123');
        self::assertTrue($created, 'add() must report a row was created (affected=1).');

        // Round-trip: the value the bind(:value) carried must be readable.
        self::assertSame('enc1:abc123', $store->get('signing_secret'));

        // The write actually hit the in-memory backing table.
        self::assertArrayHasKey(
            'signing_secret',
            \MockJoomlaDatabase::$tables[JoomlaOptionStore::TABLE] ?? [],
            'The INSERT must have persisted into the options table.'
        );

        // Second add() on the same key is a no-op (INSERT IGNORE → 0 rows).
        self::assertFalse($store->add('signing_secret', 'enc1:zzz'), 'Duplicate key add() must return false.');
    }

    /**
     * OptionStore::set() — upsert. Bound :value must round-trip after update.
     */
    public function test_option_store_set_persists_and_round_trips(): void
    {
        $store = new JoomlaOptionStore();

        self::assertTrue($store->set('published_state_etag', 'sha256:deadbeef'));
        self::assertSame('sha256:deadbeef', $store->get('published_state_etag'));

        // Overwrite via set() → new bound value wins.
        self::assertTrue($store->set('published_state_etag', 'sha256:cafef00d'));
        self::assertSame('sha256:cafef00d', $store->get('published_state_etag'));
    }

    /**
     * TransientStore::set()/get() — bound :payload must persist + read back
     * within TTL.
     */
    public function test_transient_store_set_persists_and_round_trips(): void
    {
        $store = new JoomlaTransientStore();

        self::assertTrue($store->set('pickup_token', 'pk_live_123', 300));
        self::assertSame('pk_live_123', $store->get('pickup_token'));

        self::assertArrayHasKey(
            'pickup_token',
            \MockJoomlaDatabase::$tables[JoomlaTransientStore::TABLE] ?? [],
            'The transient INSERT must have persisted into the transients table.'
        );
    }

    /**
     * StateLock::acquireForTemplate() — the lock-row INSERT IGNORE binds
     * :key/:value/:at on the query. A driver-bind fatal would make the
     * acquire silently fail (catch → false) so the lock never recorded.
     */
    public function test_state_lock_acquire_persists_lock_row(): void
    {
        $lock = new JoomlaStateLock();

        self::assertTrue($lock->acquireForTemplate('tpl-persist', 50));

        $key = JoomlaStateLock::lockKey('tpl-persist');
        self::assertArrayHasKey(
            $key,
            \MockJoomlaDatabase::$tables[JoomlaStateLock::TABLE] ?? [],
            'The INSERT IGNORE must have persisted the lock row (bound :key).'
        );
    }
}
