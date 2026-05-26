<?php
/**
 * A3-L1 / A5-F1 — encryption-key-dir wipe: opt-in gate + path-escape guard.
 *
 * Fix-Stream A (61e246c8f) added
 * {@see \PlgSystemYtbmcpInstallerScript::wipeEncryptionKeyDirectory()}: on an
 * OPT-IN full uninstall (`delete_data_on_uninstall = 1`) it removes the Tier-3
 * fallback dir `media/ytb_mcp_secure/`. It MUST NOT run on opt-out, and its
 * realpath-under-media-root guard must refuse to delete anything that resolves
 * OUTSIDE the media root (the symlink/`..`-escape attack surface).
 *
 * BEHAVIOURAL where instantiable: the installer-script class is no-namespace
 * plain PHP (script.php, not in the PSR-4 map) but extends the stubbed
 * {@see \Joomla\CMS\Installer\InstallerScript}, so we `require_once` it and
 * drive `wipeEncryptionKeyDirectory()` + `isDeleteDataOptedIn()` via reflection
 * against real temp dirs under JPATH_ROOT.
 *
 * PIN for the one un-instantiable invariant: that the wipe is only WIRED into
 * the opt-in branch of uninstall() (a behavioural call to uninstall() would
 * need a real DB driver for dropOwnedTables(), so the wiring itself is pinned
 * on source while the gate + guard are behavioural).
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;

final class EncryptionKeyDirWipePinTest extends TestCase
{
    private function scriptPath(): string
    {
        return \dirname(__DIR__, 6)
            . '/src/packaging/joomla/extensions/plg_system_ytbmcp/script.php';
    }

    private function mediaRoot(): string
    {
        return \rtrim(JPATH_ROOT, '/') . '/media';
    }

    private function keyDir(): string
    {
        return $this->mediaRoot() . '/ytb_mcp_secure';
    }

    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        \Joomla\CMS\Plugin\PluginHelper::setMockPlugin(null);

        // Load the installer-script class (no-namespace, plain PHP). Guarded so
        // re-running the suite (or a sibling test that already loaded it) does
        // not redeclare the class.
        if (!\class_exists('PlgSystemYtbmcpInstallerScript', false)) {
            require_once $this->scriptPath();
        }

        // isDeleteDataOptedIn() decodes a JSON params string via
        // Joomla\Registry\Registry, which is not vendored in the test env.
        // Provide the same minimal stub the sibling uninstall pin-tests use
        // (guarded so it is declared once per process).
        if (!\class_exists(\Joomla\Registry\Registry::class)) {
            eval('namespace Joomla\\Registry; class Registry {
                private array $data = [];
                public function __construct(string $json) { $d = json_decode($json, true); if (is_array($d)) $this->data = $d; }
                public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
            }');
        }

        $this->rrmdir($this->keyDir());
    }

    protected function tearDown(): void
    {
        // The key dir may be a symlink (path-escape test) — remove the link
        // first so rrmdir never follows it into the victim dir.
        if (\is_link($this->keyDir())) {
            @\unlink($this->keyDir());
        }
        $this->rrmdir($this->keyDir());
        $this->rrmdir(\rtrim(JPATH_ROOT, '/') . '/outside-victim');
        \Joomla\CMS\Plugin\PluginHelper::setMockPlugin(null);
        \MockJoomlaFactory::reset();
    }

    private function rrmdir(string $dir): void
    {
        if (\is_link($dir)) {
            @\unlink($dir);
            return;
        }
        if (!\is_dir($dir)) {
            @\unlink($dir);
            return;
        }
        foreach (\scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $this->rrmdir($dir . '/' . $e);
        }
        @\rmdir($dir);
    }

    private function newScript(): object
    {
        return new \PlgSystemYtbmcpInstallerScript();
    }

    private function callWipe(object $script): void
    {
        (new \ReflectionMethod($script, 'wipeEncryptionKeyDirectory'))->invoke($script);
    }

    /**
     * A5-F1: the wipe removes the key file + hardening artefacts + the dir
     * itself when they live under the media root.
     */
    public function test_wipe_removes_key_dir_under_media_root(): void
    {
        $dir = $this->keyDir();
        @\mkdir($dir, 0o700, true);
        foreach (['.encryption_key', '.htaccess', 'web.config', 'index.html'] as $f) {
            \file_put_contents($dir . '/' . $f, 'x');
        }
        self::assertDirectoryExists($dir);

        $this->callWipe($this->newScript());

        self::assertDirectoryDoesNotExist($dir, 'the opt-in wipe must remove the whole key dir.');
    }

    /**
     * A5-F1: the wipe is a safe no-op when the key dir is already absent
     * (a prior uninstall, or a Tier-2/Tier-1 deployment that never generated
     * a Tier-3 fallback) — must not throw.
     */
    public function test_wipe_is_noop_when_key_dir_absent(): void
    {
        $this->rrmdir($this->keyDir());
        self::assertDirectoryDoesNotExist($this->keyDir());

        $this->callWipe($this->newScript()); // must not throw

        self::assertDirectoryDoesNotExist($this->keyDir());
    }

    /**
     * A3-L1 (path-escape guard): if the key-dir path resolves OUTSIDE the
     * media root (here via a symlink — the realistic escape vector, mirroring
     * the `..`-traversal guard the prune uses), the wipe must REFUSE to delete
     * the symlink's target. The realpath-under-media-root check is the load-
     * bearing defence.
     */
    public function test_wipe_refuses_to_delete_target_outside_media_root(): void
    {
        if (!\function_exists('symlink')) {
            self::markTestSkipped('symlink() unavailable on this platform.');
        }

        // A victim directory OUTSIDE the media root (sibling of media under
        // JPATH_ROOT) that MUST survive — it is unambiguously outside media/.
        $victim = \rtrim(JPATH_ROOT, '/') . '/outside-victim';
        @\mkdir($victim, 0o700, true);
        \file_put_contents($victim . '/precious.txt', 'do-not-delete');

        // media/ytb_mcp_secure → symlink to the outside victim.
        @\mkdir($this->mediaRoot(), 0o700, true);
        $linkOk = @\symlink($victim, $this->keyDir());
        if ($linkOk === false) {
            self::markTestSkipped('Could not create symlink in the test sandbox.');
        }

        $this->callWipe($this->newScript());

        // The realpath guard resolves the symlink to $victim (outside media)
        // and bails. The victim's contents MUST be untouched.
        self::assertFileExists(
            $victim . '/precious.txt',
            'wipe must refuse to delete a target that resolves outside the media root.'
        );

        // Cleanup the symlink + victim.
        @\unlink($this->keyDir());
        $this->rrmdir($victim);
    }

    // --- opt-in gate (isDeleteDataOptedIn) --------------------------------

    /**
     * A5-F1 gate: opt-out (the default) — `isDeleteDataOptedIn()` is false
     * when the param is '0', so the destructive branch (drop tables + wipe key
     * dir) never runs. Customer data + signing key survive.
     */
    public function test_opt_out_gate_is_false_by_default(): void
    {
        \Joomla\CMS\Plugin\PluginHelper::setMockPlugin(
            (object) ['params' => '{"delete_data_on_uninstall":"0"}']
        );

        $script = $this->newScript();
        $opted = (new \ReflectionMethod($script, 'isDeleteDataOptedIn'))->invoke($script);

        self::assertFalse($opted, 'opt-out (0) must NOT trigger the destructive wipe branch.');
    }

    /**
     * A5-F1 gate: opt-in — `isDeleteDataOptedIn()` is true when the param is
     * '1'. This is the ONLY shape that reaches wipeEncryptionKeyDirectory().
     */
    public function test_opt_in_gate_is_true_when_param_set(): void
    {
        \Joomla\CMS\Plugin\PluginHelper::setMockPlugin(
            (object) ['params' => '{"delete_data_on_uninstall":"1"}']
        );

        $script = $this->newScript();
        $opted = (new \ReflectionMethod($script, 'isDeleteDataOptedIn'))->invoke($script);

        self::assertTrue($opted, 'opt-in (1) must enable the destructive wipe branch.');
    }

    /**
     * A5-F1 gate: a missing/unknown plugin row defaults to opt-out (safe
     * default — never destroy data on an ambiguous read).
     */
    public function test_unknown_plugin_defaults_to_opt_out(): void
    {
        \Joomla\CMS\Plugin\PluginHelper::setMockPlugin(null);

        $script = $this->newScript();
        $opted = (new \ReflectionMethod($script, 'isDeleteDataOptedIn'))->invoke($script);

        self::assertFalse($opted, 'an unknown plugin row must default to opt-out (data-safe).');
    }

    /**
     * PIN: the wipe is only WIRED into the opt-in branch of uninstall() — a
     * behavioural uninstall() call would need a real DB for dropOwnedTables(),
     * so the wiring itself is pinned on source. We assert wipeEncryptionKey
     * Directory() is called inside the `if ($this->isDeleteDataOptedIn())`
     * block, AFTER dropOwnedTables().
     */
    public function test_pin_wipe_is_wired_only_into_opt_in_uninstall_branch(): void
    {
        $src = (string) \file_get_contents($this->scriptPath());

        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$this->isDeleteDataOptedIn\(\)\s*\)\s*\{\s*'
            . '\$this->dropOwnedTables\(\);.*?'
            . '\$this->wipeEncryptionKeyDirectory\(\);/s',
            $src,
            'wipeEncryptionKeyDirectory() must be wired ONLY inside the opt-in uninstall branch, after dropOwnedTables().'
        );
    }
}
