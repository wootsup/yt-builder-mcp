<?php
/**
 * PIN-TEST: pkg_ytbmcp postflight — auto-enable both bundled plugins
 * (`plg_system_ytbmcp` + `plg_webservices_ytbmcp`) so customers do not
 * have to dig through System -> Manage -> Plugins after install.
 *
 * Without this contract the webservices plugin stays disabled by default
 * and EVERY REST route 404s; the system plugin stays disabled and the
 * autoloader / bootstrap never runs. Both must flip to enabled=1 on
 * install, update AND discover_install (idempotent).
 *
 * Captured contract:
 *  - postflight('install')           runs 2x UPDATE on #__extensions
 *  - postflight('update')            runs 2x UPDATE on #__extensions
 *  - postflight('discover_install')  runs 2x UPDATE on #__extensions
 *  - each UPDATE binds folder + element + sets enabled = 1
 *  - one row targets folder=system,        element=ytbmcp
 *  - one row targets folder=webservices,   element=ytbmcp
 *  - postflight() returns true on every type (install must never abort)
 *  - a DB throw on auto-enable does NOT abort the install
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;

final class PackageInstallerAutoEnablePinTest extends TestCase
{
    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();

        // Load the package installer script (lives outside src/, no autoload).
        $scriptPath = \dirname(__DIR__, 6) . '/src/packaging/joomla/pkg_ytbmcp/script.php';
        if (\is_file($scriptPath) && !\class_exists(\Pkg_YtbmcpInstallerScript::class, false)) {
            require_once $scriptPath;
        }
    }

    protected function tearDown(): void
    {
        \MockJoomlaFactory::reset();
        \MockJoomlaDatabase::$throwException = false;
    }

    private function runPostflight(string $type): bool
    {
        $script  = new \Pkg_YtbmcpInstallerScript();
        $adapter = new \Joomla\CMS\Installer\InstallerAdapter();

        // Reset query-recorder so we only capture queries from this call.
        \MockJoomlaDatabase::$executedQueries = [];

        // The postflight echoes a cosmetic panel; capture + discard so the
        // test runner does not flag risky output (failOnRisky=true).
        \ob_start();
        try {
            $result = $script->postflight($type, $adapter);
        } finally {
            \ob_end_clean();
        }

        return $result;
    }

    /**
     * Filter the captured queries down to the auto-enable UPDATE rows on
     * #__extensions. Returns one row per UPDATE, keyed by the bound
     * (folder, element) tuple to the row's bound values + set clauses.
     *
     * @return array<string, array{folder:string, element:string, type:string, sets:array<int,string>}>
     */
    private function capturedAutoEnableUpdates(): array
    {
        $rows = [];
        foreach (\MockJoomlaDatabase::$executedQueries as $q) {
            if (!$q instanceof \MockJoomlaQuery) {
                continue;
            }
            if ($q->type !== 'update') {
                continue;
            }
            // Only the auto-enable updates target #__extensions.
            if (\strpos($q->update, '#__extensions') === false) {
                continue;
            }
            $folder  = (string) ($q->binds[':folder']  ?? '');
            $element = (string) ($q->binds[':element'] ?? '');
            $type    = (string) ($q->binds[':type']    ?? '');
            $rows[$folder . '/' . $element] = [
                'folder'  => $folder,
                'element' => $element,
                'type'    => $type,
                'sets'    => $q->sets,
            ];
        }
        return $rows;
    }

    /** Sanity check the script class loaded. */
    private function skipIfScriptUnavailable(): void
    {
        if (!\class_exists(\Pkg_YtbmcpInstallerScript::class, false)) {
            self::markTestSkipped('Pkg_YtbmcpInstallerScript not loadable (packaging path absent).');
        }
    }

    public function test_install_enables_system_plugin(): void
    {
        $this->skipIfScriptUnavailable();

        $ok = $this->runPostflight('install');
        self::assertTrue($ok, 'postflight() must return true on install');

        $rows = $this->capturedAutoEnableUpdates();

        self::assertArrayHasKey(
            'system/ytbmcp',
            $rows,
            'system plugin auto-enable UPDATE must be issued on install'
        );
        self::assertSame('system',  $rows['system/ytbmcp']['folder']);
        self::assertSame('ytbmcp',  $rows['system/ytbmcp']['element']);
        self::assertSame('plugin',  $rows['system/ytbmcp']['type'],
            'UPDATE must scope to type=plugin (not template/module)');

        $hasEnabled = false;
        foreach ($rows['system/ytbmcp']['sets'] as $clause) {
            if (\preg_match('/enabled.*=\s*1/', $clause) === 1) {
                $hasEnabled = true;
                break;
            }
        }
        self::assertTrue(
            $hasEnabled,
            'system plugin UPDATE must set enabled = 1 (captured: '
            . \implode(' | ', $rows['system/ytbmcp']['sets']) . ')'
        );
    }

    public function test_install_enables_webservices_plugin(): void
    {
        $this->skipIfScriptUnavailable();

        $ok = $this->runPostflight('install');
        self::assertTrue($ok);

        $rows = $this->capturedAutoEnableUpdates();

        self::assertArrayHasKey(
            'webservices/ytbmcp',
            $rows,
            'webservices plugin auto-enable UPDATE must be issued on install '
            . '(without it every REST route 404s)'
        );
        self::assertSame('webservices', $rows['webservices/ytbmcp']['folder']);
        self::assertSame('ytbmcp',      $rows['webservices/ytbmcp']['element']);
        self::assertSame('plugin',      $rows['webservices/ytbmcp']['type']);

        $hasEnabled = false;
        foreach ($rows['webservices/ytbmcp']['sets'] as $clause) {
            if (\preg_match('/enabled.*=\s*1/', $clause) === 1) {
                $hasEnabled = true;
                break;
            }
        }
        self::assertTrue(
            $hasEnabled,
            'webservices plugin UPDATE must set enabled = 1 (captured: '
            . \implode(' | ', $rows['webservices/ytbmcp']['sets']) . ')'
        );
    }

    public function test_update_path_also_reenables_both_plugins(): void
    {
        $this->skipIfScriptUnavailable();

        $ok = $this->runPostflight('update');
        self::assertTrue($ok, 'postflight() must return true on update');

        $rows = $this->capturedAutoEnableUpdates();

        self::assertArrayHasKey(
            'system/ytbmcp',
            $rows,
            'update path must re-issue the system-plugin enable UPDATE '
            . '(idempotent — operator may have disabled it manually)'
        );
        self::assertArrayHasKey(
            'webservices/ytbmcp',
            $rows,
            'update path must re-issue the webservices-plugin enable UPDATE'
        );
    }

    public function test_discover_install_also_enables_both_plugins(): void
    {
        $this->skipIfScriptUnavailable();

        $ok = $this->runPostflight('discover_install');
        self::assertTrue($ok, 'postflight() must return true on discover_install');

        $rows = $this->capturedAutoEnableUpdates();

        self::assertArrayHasKey('system/ytbmcp', $rows,
            'discover_install path must enable system plugin');
        self::assertArrayHasKey('webservices/ytbmcp', $rows,
            'discover_install path must enable webservices plugin');
    }

    public function test_db_failure_does_not_abort_install(): void
    {
        $this->skipIfScriptUnavailable();

        // Every DB call throws — auto-enable cannot succeed.
        \MockJoomlaDatabase::$throwException = true;

        $ok = $this->runPostflight('install');

        // Contract: a failed auto-enable degrades to a Joomla warning
        // message but MUST NOT abort the install. The plugins can still be
        // enabled by hand under System -> Manage -> Plugins.
        self::assertTrue(
            $ok,
            'postflight() must return true even when auto-enable DB write fails '
            . '(the install must not abort on a best-effort post-step)'
        );

        \MockJoomlaDatabase::$throwException = false;
    }
}
