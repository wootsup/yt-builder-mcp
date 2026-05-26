<?php
/**
 * PIN-TEST: pkg_ytbmcp postflight — rich install-complete panel (W11-T5).
 *
 * The package installer's `postflight()` echoes a branded install-complete
 * panel that mirrors api-mapper's: WootsUp logo header, a YOOtheme Pro / PHP
 * / Joomla compatibility-check grid, getting-started tiles, and dark-mode
 * aware scoped CSS. This pin captures the echoed HTML and asserts the panel
 * markers + the YOOtheme-version-check wiring (driven through the same
 * `#__extensions` manifest_cache surface JoomlaYoothemeProbe reads).
 *
 * It also pins the FAIL-SAFE contract: postflight must return true (the
 * install succeeds) even when the panel/probe surface errors, and it must
 * not depend on the platform-joomla PSR-4 autoloader being registered (the
 * package installer runs before the system plugin bootstraps).
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;

final class PackageInstallCompletePanelPinTest extends TestCase
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
    }

    private function buildAdapter(): \Joomla\CMS\Installer\InstallerAdapter
    {
        return new \Joomla\CMS\Installer\InstallerAdapter();
    }

    /** Capture the HTML the postflight echoes for the given install type. */
    private function runPostflight(string $type): string
    {
        $script  = new \Pkg_YtbmcpInstallerScript();
        $adapter = $this->buildAdapter();

        \ob_start();
        $result = $script->postflight($type, $adapter);
        $html   = (string) \ob_get_clean();

        // Contract: postflight ALWAYS succeeds — the panel is cosmetic.
        self::assertTrue($result, 'postflight() must return true (install must not abort on panel render).');

        return $html;
    }

    public function test_install_panel_contains_core_markers(): void
    {
        if (!\class_exists(\Pkg_YtbmcpInstallerScript::class, false)) {
            self::markTestSkipped('Installer script class not loadable (packaging path absent).');
        }

        // YT Pro present + compatible in #__extensions.
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride    = \json_encode([
            'name' => 'YOOtheme', 'type' => 'template', 'version' => '4.5.33',
        ]);

        $html = $this->runPostflight('install');

        // Scoped wrapper + scoped style + dark-mode block.
        self::assertStringContainsString('ytbmcp-install-complete', $html, 'scoped wrapper class missing');
        self::assertStringContainsString('<style>', $html, 'scoped <style> block missing');
        self::assertStringContainsString('[data-bs-theme="dark"]', $html, 'dark-mode block missing');

        // Branded header.
        self::assertStringContainsString('Installation Complete', $html);
        self::assertStringContainsString('YT Builder MCP for YOOtheme Pro', $html);
        self::assertStringContainsString('unofficial', $html);
        self::assertStringContainsString('aria-label="WootsUp"', $html, 'WootsUp logo SVG missing');

        // Getting-started tiles (all four).
        self::assertStringContainsString('Generate your first key', $html);
        self::assertStringContainsString('option=com_ytbmcp', $html, 'keys-tab dashboard route missing');
        self::assertStringContainsString('npx -y @wootsup/yt-builder-mcp setup', $html, 'setup command missing');
        self::assertStringContainsString('Documentation', $html);
        self::assertStringContainsString('github.com/wootsup/yt-builder-mcp', $html, 'docs URL missing');
        self::assertStringContainsString('wootsup.com', $html);
    }

    public function test_yt_check_reports_compatible_when_version_meets_floor(): void
    {
        if (!\class_exists(\Pkg_YtbmcpInstallerScript::class, false)) {
            self::markTestSkipped('Installer script class not loadable.');
        }

        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride    = \json_encode(['version' => '4.5.33']);

        $html = $this->runPostflight('install');

        self::assertStringContainsString('4.5.33', $html, 'detected YT version must be shown');
        self::assertStringContainsString('compatible', $html, 'compatible state must be reported when >= 4.0');
    }

    public function test_yt_check_warns_when_yootheme_absent(): void
    {
        if (!\class_exists(\Pkg_YtbmcpInstallerScript::class, false)) {
            self::markTestSkipped('Installer script class not loadable.');
        }

        // No yootheme template row → loadResult() null → probe reports absent.
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride    = null;

        $html = $this->runPostflight('install');

        self::assertStringContainsString('YOOtheme Pro not found', $html, 'absent YT must surface a clear notice');
        self::assertStringContainsString('stays inert', $html, 'inert-surface explanation must be shown');

        // A critical-issue admin message must have been enqueued.
        $app = \Joomla\CMS\Factory::getApplication();
        /** @var array<int, array{message: string, type: string}> $messages */
        $messages = $app->messages;
        $matched = false;
        foreach ($messages as $m) {
            if (\stripos($m['message'], 'YOOtheme Pro not found') !== false) {
                $matched = true;
                break;
            }
        }
        self::assertTrue($matched, 'absent YOOtheme Pro must also enqueue a Joomla warning message');
    }

    public function test_postflight_is_fail_safe_when_db_throws(): void
    {
        if (!\class_exists(\Pkg_YtbmcpInstallerScript::class, false)) {
            self::markTestSkipped('Installer script class not loadable.');
        }

        // Every DB query (incl. the YT probe + plugin auto-enable) throws.
        \MockJoomlaDatabase::$throwException = true;

        // Must NOT throw, must return true, must still render a panel (the
        // probe degrades to "absent" rather than fataling).
        $html = $this->runPostflight('install');

        self::assertStringContainsString('ytbmcp-install-complete', $html, 'panel must still render on DB failure');

        \MockJoomlaDatabase::$throwException = false;
    }

    public function test_update_panel_renders_with_update_heading(): void
    {
        if (!\class_exists(\Pkg_YtbmcpInstallerScript::class, false)) {
            self::markTestSkipped('Installer script class not loadable.');
        }

        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride    = \json_encode(['version' => '4.5.33']);

        $html = $this->runPostflight('update');

        self::assertStringContainsString('Update Complete', $html, 'update heading must be shown on update');
        self::assertStringContainsString('ytbmcp-install-complete', $html);
    }
}
