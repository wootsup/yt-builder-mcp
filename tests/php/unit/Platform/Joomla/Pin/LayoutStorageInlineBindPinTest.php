<?php
/**
 * REGRESSION PIN-TEST (R8-A3 F-2): L1 read pipeline — bound values must be
 * pre-declared addressable variables, NOT inline-assignment expressions.
 *
 * Wave-7 production bug: `JoomlaLayoutStorage` bound the WHERE filters as
 * `->bind(':element', $element = self::YT_ELEMENT, ParameterType::STRING)`.
 * Joomla `DatabaseQuery::bind()` takes `$value` BY REFERENCE; PHP cannot
 * pass the RESULT OF AN ASSIGNMENT EXPRESSION by reference, so this raised
 * "Argument #2 ($value) could not be passed by reference", was swallowed by
 * the `catch (\Throwable)`, and `readState()` always returned [] +
 * `resolveExtensionId()` always returned null → the ENTIRE L1 read pipeline
 * saw an empty Builder state (pages list empty, every template "not found"),
 * and every L1 write returned false ("storage_write_returned_false").
 *
 * The fix pre-declares `$element` / `$folder` as variables before binding.
 *
 * RED-WITHOUT-FIX: the test stub's `MockJoomlaQuery::bind()` now takes
 * `$value` BY REFERENCE (mirroring real Joomla), so the pre-fix
 * inline-assignment pattern raises the same by-reference `Error` in the
 * suite → swallowed → readState() returns [] / resolveExtensionId() returns
 * null → the assertions below FAIL. Against the fixed (pre-declared-variable)
 * code the row resolves correctly.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaLayoutStorage;

#[CoversClass(JoomlaLayoutStorage::class)]
final class LayoutStorageInlineBindPinTest extends TestCase
{
    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();
        JoomlaLayoutStorage::resetForTests();
    }

    protected function tearDown(): void
    {
        JoomlaLayoutStorage::resetForTests();
    }

    /**
     * readState() must decode the YT custom_data blob from the resolved
     * `#__extensions` row. If the WHERE bind fataled, readState() would
     * silently return [] and this would fail.
     */
    public function test_read_state_resolves_yt_row_and_decodes_blob(): void
    {
        $blob = \json_encode([
            'library'   => [],
            'templates' => ['tpl-home' => ['name' => 'Home']],
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        // Simulate the #__extensions.custom_data SELECT returning the blob.
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride    = $blob;

        $storage = new JoomlaLayoutStorage();
        $state   = $storage->readState();

        self::assertIsArray($state);
        self::assertArrayHasKey('templates', $state, 'L1 read must decode the templates map.');
        self::assertArrayHasKey('tpl-home', $state['templates']);
        self::assertSame('Home', $state['templates']['tpl-home']['name']);

        // The WHERE filters MUST have been bound (proves bind() ran without
        // the by-reference fatal). Inspect the last executed query object.
        $query = \MockJoomlaDatabase::$executedQueries[\array_key_last(\MockJoomlaDatabase::$executedQueries)] ?? null;
        self::assertInstanceOf(\MockJoomlaQuery::class, $query);
        self::assertSame(JoomlaLayoutStorage::YT_ELEMENT, $query->binds[':element'] ?? null);
        self::assertSame(JoomlaLayoutStorage::YT_FOLDER, $query->binds[':folder'] ?? null);
    }

    /**
     * resolveExtensionId() must read the YT system-plugin extension_id and
     * cast it to int. A by-reference bind fatal would make it return null.
     */
    public function test_resolve_extension_id_returns_the_yt_row_id(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride    = '4242';

        $storage = new JoomlaLayoutStorage();
        $id      = $storage->resolveExtensionId();

        self::assertSame(4242, $id, 'extension_id must resolve from the YT #__extensions row.');

        // Bound WHERE filters confirm the query targeted the right row.
        $query = \MockJoomlaDatabase::$executedQueries[\array_key_last(\MockJoomlaDatabase::$executedQueries)] ?? null;
        self::assertInstanceOf(\MockJoomlaQuery::class, $query);
        self::assertSame('yootheme', $query->binds[':element'] ?? null);
        self::assertSame('system', $query->binds[':folder'] ?? null);
    }

    /**
     * writeState() resolves the extension_id then binds :data/:id. End-to-end
     * proof the write path survives the bind (it would return false if either
     * the resolve or the write bind fataled).
     */
    public function test_write_state_resolves_id_and_returns_true(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride    = '7';

        $storage = new JoomlaLayoutStorage();
        $ok      = $storage->writeState(['templates' => ['t1' => ['x' => 1]]]);

        self::assertTrue($ok, 'writeState() must succeed once extension_id resolves and :data/:id bind.');

        $query = \MockJoomlaDatabase::$executedQueries[\array_key_last(\MockJoomlaDatabase::$executedQueries)] ?? null;
        self::assertInstanceOf(\MockJoomlaQuery::class, $query);
        self::assertSame(7, $query->binds[':id'] ?? null, 'extension_id must be bound as :id.');
        self::assertIsString($query->binds[':data'] ?? null, 'encoded state must be bound as :data.');
    }
}
