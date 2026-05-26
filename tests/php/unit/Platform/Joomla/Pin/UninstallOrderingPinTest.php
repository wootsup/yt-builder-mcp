<?php
/**
 * PIN-TEST: plg_system_ytbmcp uninstall — data-protection gate.
 *
 * Cookbook §5.14.4 + Audit-A5 P1-2 (Wave 4 fix-round F3) — by default,
 * customer data MUST survive a plugin deactivate / uninstall cycle.
 *
 * Contract (post-A5-P1-2): the installer script's `uninstall()` hook
 *   - When `delete_data_on_uninstall=false` (default): the on-disk
 *     `sql/uninstall.*.sql` files are NOT mutated (admin-auditable);
 *     instead a table-snapshot is captured for re-install rehydration.
 *   - When `delete_data_on_uninstall=true`: the owned tables are
 *     dropped programmatically; the manifest SQL is then a no-op.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;

final class UninstallOrderingPinTest extends TestCase
{
    private string $tempSource = '';

    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();

        // Stage a fake `sql/` directory containing the uninstall files.
        $this->tempSource = \sys_get_temp_dir() . '/ytb-mcp-uninstall-pin-' . \bin2hex(\random_bytes(4));
        @\mkdir($this->tempSource . '/sql', 0700, true);
        $this->originalMysql = "-- destructive DROP TABLE statements\nDROP TABLE IF EXISTS `#__ytb_mcp_options`;\n";
        $this->originalPg    = "-- destructive DROP TABLE statements\nDROP TABLE IF EXISTS \"#__ytb_mcp_options\";\n";
        \file_put_contents($this->tempSource . '/sql/uninstall.mysql.sql', $this->originalMysql);
        \file_put_contents($this->tempSource . '/sql/uninstall.postgresql.sql', $this->originalPg);

        // Load the installer script (lives outside src/, so no autoload).
        $scriptPath = \dirname(__DIR__, 6) . '/src/packaging/joomla/extensions/plg_system_ytbmcp/script.php';
        if (\is_file($scriptPath) && !\class_exists(\PlgSystemYtbmcpInstallerScript::class, false)) {
            require_once $scriptPath;
        }
    }

    protected function tearDown(): void
    {
        \MockJoomlaFactory::reset();
        \Joomla\CMS\Plugin\PluginHelper::setMockPlugin(null);
        $this->rmRecursive($this->tempSource);
    }

    private string $originalMysql = '';
    private string $originalPg    = '';

    /**
     * @cookbook 5.14.4 + Audit-A5 P1-2 — default OPT-OUT leaves SQL files byte-identical
     */
    public function test_default_uninstall_does_not_mutate_sql_files(): void
    {
        if (!\class_exists(\PlgSystemYtbmcpInstallerScript::class, false)) {
            self::markTestSkipped('Installer script class not loadable (packaging path absent).');
        }

        // Default plugin state: no plugin row → isDeleteDataOptedIn() returns false.
        \Joomla\CMS\Plugin\PluginHelper::setMockPlugin(null);

        $script = $this->buildScript();
        $parent = $this->buildParentMock();

        $result = $script->uninstall($parent);
        self::assertTrue($result);

        // Audit-A5 P1-2 contract: on-disk SQL files MUST stay byte-identical
        // so administrators can audit what an opt-in uninstall would do.
        self::assertSame(
            $this->originalMysql,
            (string) \file_get_contents($this->tempSource . '/sql/uninstall.mysql.sql'),
            'OPT-OUT uninstall MUST NOT mutate the on-disk uninstall.mysql.sql file.'
        );
        self::assertSame(
            $this->originalPg,
            (string) \file_get_contents($this->tempSource . '/sql/uninstall.postgresql.sql'),
            'OPT-OUT uninstall MUST NOT mutate the on-disk uninstall.postgresql.sql file.'
        );
    }

    /**
     * @cookbook 5.14.4 + Audit-A5 P1-2 — OPT-IN opt-in drops tables programmatically
     */
    public function test_opt_in_uninstall_drops_tables_programmatically(): void
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

        $script = $this->buildScript();
        $parent = $this->buildParentMock();

        $result = $script->uninstall($parent);
        self::assertTrue($result);

        // OPT-IN path issues DROP TABLE IF EXISTS via $db->setQuery($sql)->execute().
        // Verify at least one DROP TABLE statement was sent to the DB.
        $hasDropTable = false;
        foreach (\MockJoomlaDatabase::$executedQueries as $q) {
            if (\is_string($q) && \stripos($q, 'DROP TABLE IF EXISTS') !== false) {
                $hasDropTable = true;
                break;
            }
        }
        self::assertTrue($hasDropTable, 'OPT-IN uninstall MUST issue DROP TABLE IF EXISTS for each owned table.');
    }

    private function buildScript(): \PlgSystemYtbmcpInstallerScript
    {
        return new \PlgSystemYtbmcpInstallerScript();
    }

    private function buildParentMock(): object
    {
        $src = $this->tempSource;
        return new class ($src) {
            public function __construct(public string $sourcePath) {}
            public function getParent(): object
            {
                $s = $this->sourcePath;
                return new class ($s) {
                    public function __construct(public string $src) {}
                    public function getPath(string $name): string
                    {
                        return $this->src;
                    }
                };
            }
        };
    }

    private function rmRecursive(string $path): void
    {
        if (\is_file($path)) {
            @\unlink($path);
            return;
        }
        if (!\is_dir($path)) {
            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmRecursive($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
