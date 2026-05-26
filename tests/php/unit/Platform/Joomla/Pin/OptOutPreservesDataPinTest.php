<?php
/**
 * PIN-TEST: plg_system_ytbmcp uninstall — OPT-OUT preserves customer data.
 *
 * Round-3 audit A5 NEW-P1 (RELEASE-BLOCKER regression closure). Cookbook
 * §1.3 + §4.6 + §5.14.4. The defining contract this test pins:
 *
 *   - default `delete_data_on_uninstall=0` (OPT-OUT): all three
 *     `#__ytb_mcp_*` tables and their rows STILL EXIST after the
 *     installer-script's `uninstall()` returns. A future reinstall
 *     can rehydrate signing keys + API client registrations.
 *
 *   - explicit `delete_data_on_uninstall=1` (OPT-IN): the three tables
 *     are dropped programmatically.
 *
 * Why this test exists: F3 (Wave-4 fix-round) removed the
 * file_put_contents SQL-file mutation but kept the destructive manifest
 * SQL in place. The audit-trail promised "data preserved" but the
 * manifest's `<uninstall><sql>` still ran `DROP TABLE IF EXISTS`. The
 * Round-3 fix:
 *   (a) makes `sql/uninstall.*.sql` empty (no destructive SQL via manifest)
 *   (b) issues DROP statements ONLY on OPT-IN via dropOwnedTables()
 * This pin guards against a future "well, the manifest could drop them
 * for us" regression that would silently lose customer data again.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;

final class OptOutPreservesDataPinTest extends TestCase
{
    /** Tables that MUST survive an OPT-OUT uninstall. */
    private const OWNED_TABLES = [
        '#__ytb_mcp_options',
        '#__ytb_mcp_transients',
        '#__ytb_mcp_lock',
    ];

    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();

        // Load the installer script (lives outside src/, no autoload).
        $scriptPath = \dirname(__DIR__, 6)
            . '/src/packaging/joomla/extensions/plg_system_ytbmcp/script.php';
        if (\is_file($scriptPath) && !\class_exists(\PlgSystemYtbmcpInstallerScript::class, false)) {
            require_once $scriptPath;
        }

        // Seed each owned table with a sentinel row so we can prove
        // post-uninstall the row is still there.
        foreach (self::OWNED_TABLES as $table) {
            \MockJoomlaDatabase::$tables[$table] = [
                'sentinel_key' => 'sentinel_value_must_survive_opt_out_uninstall',
            ];
        }
    }

    protected function tearDown(): void
    {
        \MockJoomlaFactory::reset();
        \Joomla\CMS\Plugin\PluginHelper::setMockPlugin(null);
    }

    /**
     * @cookbook §1.3 + §4.6 + Audit-A5 NEW-P1 — OPT-OUT preserves all 3 tables
     */
    public function test_opt_out_uninstall_preserves_all_owned_tables_and_rows(): void
    {
        if (!\class_exists(\PlgSystemYtbmcpInstallerScript::class, false)) {
            self::markTestSkipped('Installer script class not loadable (packaging path absent).');
        }

        // Default plugin state: no plugin row → isDeleteDataOptedIn() returns false.
        \Joomla\CMS\Plugin\PluginHelper::setMockPlugin(null);

        $script = new \PlgSystemYtbmcpInstallerScript();
        $parent = $this->buildParentMock();

        $result = $script->uninstall($parent);
        self::assertTrue($result, 'Uninstall hook must always return true.');

        // Audit-A5 NEW-P1 contract: every owned table + sentinel row MUST
        // still exist in the mock DB after the OPT-OUT uninstall path
        // returns. The installer script MUST NOT issue ANY DROP TABLE
        // statement when the gate is OFF.
        foreach (self::OWNED_TABLES as $table) {
            self::assertArrayHasKey(
                $table,
                \MockJoomlaDatabase::$tables,
                "OPT-OUT uninstall MUST preserve table {$table}."
            );
            self::assertArrayHasKey(
                'sentinel_key',
                \MockJoomlaDatabase::$tables[$table],
                "OPT-OUT uninstall MUST preserve sentinel row in {$table}."
            );
            self::assertSame(
                'sentinel_value_must_survive_opt_out_uninstall',
                \MockJoomlaDatabase::$tables[$table]['sentinel_key'],
                "Sentinel row value in {$table} MUST be unchanged after OPT-OUT uninstall."
            );
        }

        // And no DROP TABLE statement was issued.
        $sawDrop = false;
        foreach (\MockJoomlaDatabase::$executedQueries as $q) {
            $sql = \is_string($q) ? $q : '';
            if (\stripos($sql, 'DROP TABLE') !== false) {
                $sawDrop = true;
                break;
            }
        }
        self::assertFalse($sawDrop, 'OPT-OUT uninstall MUST NOT issue any DROP TABLE statement.');
    }

    /**
     * @cookbook §1.3 + §4.6 + Audit-A5 NEW-P1 — OPT-IN drops all 3 tables
     */
    public function test_opt_in_uninstall_drops_all_owned_tables(): void
    {
        if (!\class_exists(\PlgSystemYtbmcpInstallerScript::class, false)) {
            self::markTestSkipped('Installer script class not loadable.');
        }

        $plugin = new \stdClass();
        $plugin->params = '{"delete_data_on_uninstall":"1"}';
        \Joomla\CMS\Plugin\PluginHelper::setMockPlugin($plugin);

        // Need Joomla\Registry\Registry to exist for the param decoder.
        if (!\class_exists(\Joomla\Registry\Registry::class)) {
            eval('namespace Joomla\\Registry; class Registry {
                private array $data = [];
                public function __construct(string $json) { $d = json_decode($json, true); if (is_array($d)) $this->data = $d; }
                public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
            }');
        }

        $script = new \PlgSystemYtbmcpInstallerScript();
        $parent = $this->buildParentMock();

        $result = $script->uninstall($parent);
        self::assertTrue($result, 'Uninstall hook must always return true.');

        // OPT-IN: dropOwnedTables() must have issued a DROP for each table.
        $droppedTables = [];
        foreach (\MockJoomlaDatabase::$executedQueries as $q) {
            $sql = \is_string($q) ? $q : '';
            if (\stripos($sql, 'DROP TABLE IF EXISTS') === false) {
                continue;
            }
            foreach (self::OWNED_TABLES as $table) {
                if (\str_contains($sql, $table)) {
                    $droppedTables[$table] = true;
                }
            }
        }

        foreach (self::OWNED_TABLES as $table) {
            self::assertArrayHasKey(
                $table,
                $droppedTables,
                "OPT-IN uninstall MUST issue DROP TABLE IF EXISTS for {$table}."
            );
        }
    }

    /**
     * @cookbook §1.3 — canonical sql/uninstall.*.sql files contain NO DROP
     */
    public function test_canonical_uninstall_sql_files_are_empty_of_destructive_statements(): void
    {
        $sqlDir = \dirname(__DIR__, 6)
            . '/src/modules/platform-joomla/src/Storage/sql';
        foreach (['uninstall.mysql.sql', 'uninstall.postgresql.sql'] as $name) {
            $path = $sqlDir . '/' . $name;
            self::assertFileExists($path, "Canonical {$name} must exist.");
            $contents = (string) \file_get_contents($path);
            self::assertStringNotContainsString(
                'DROP TABLE',
                \strtoupper($contents),
                "Canonical {$name} MUST NOT contain any DROP TABLE statement (Audit-A5 NEW-P1)."
            );
        }
    }

    /**
     * @cookbook §1.3 — packaging sql/uninstall.*.sql mirrors canonical
     */
    public function test_packaging_uninstall_sql_files_match_canonical(): void
    {
        $srcDir = \dirname(__DIR__, 6) . '/src/modules/platform-joomla/src/Storage/sql';
        $dstDir = \dirname(__DIR__, 6) . '/src/packaging/joomla/extensions/plg_system_ytbmcp/sql';
        foreach (['uninstall.mysql.sql', 'uninstall.postgresql.sql'] as $name) {
            $src = $srcDir . '/' . $name;
            $dst = $dstDir . '/' . $name;
            self::assertFileExists($src);
            self::assertFileExists($dst);
            self::assertSame(
                \hash_file('sha256', $src),
                \hash_file('sha256', $dst),
                "Packaging {$name} MUST byte-match the canonical source. "
                . "Re-run scripts/build-joomla-package.php after editing the source."
            );
        }
    }

    private function buildParentMock(): object
    {
        return new class {
            public function getParent(): object
            {
                return new class {
                    public function getPath(string $name): string
                    {
                        return \sys_get_temp_dir();
                    }
                };
            }
        };
    }
}
