<?php
/**
 * PIN TESTS (Wave-9 T7 — gap #23): upgrade self-heal sentinel + stale-media prune.
 *
 * Two complementary surfaces:
 *
 *   1. {@see JoomlaUpgradeSentinel} — the request-time recovery path for a
 *      MANUAL file-swap that never ran the installer postflight. Pins:
 *        - version-change detection (reconcile fires once, then no-ops);
 *        - stale-media prune is list-driven + fail-safe;
 *        - reconcile persists the on-disk version.
 *
 *   2. The package installer ({@see \Pkg_YtbmcpInstallerScript}) — the
 *      hook-driven Update path. Static pin: its STALE_MEDIA prune list mirrors
 *      the sentinel's (SSoT discipline), and it only prunes on `update`.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaUpgradeSentinel;

final class UpgradeSelfHealPinTest extends TestCase
{
    private string $mediaRoot;

    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();

        $this->mediaRoot = \sys_get_temp_dir() . '/ytb-mcp-media-' . \uniqid('', true);
        \mkdir($this->mediaRoot . '/com_ytbmcp/css', 0o755, true);
    }

    protected function tearDown(): void
    {
        // Best-effort recursive cleanup of the scratch media root.
        $this->rrmdir($this->mediaRoot);
        \MockJoomlaFactory::reset();
    }

    private function rrmdir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }
        @\rmdir($dir);
    }

    private function seedStaleCss(): string
    {
        $path = $this->mediaRoot . '/com_ytbmcp/css/admin.css';
        \file_put_contents($path, '.ytb{color:#2fd1cd}');
        return $path;
    }

    // ── sentinel: version-change detection ────────────────────────────────

    public function test_reconcile_detects_version_change_and_prunes_stale_media(): void
    {
        $css = $this->seedStaleCss();
        self::assertFileExists($css, 'precondition: stale CSS present');

        // Stored version is the OLD one — a code-swap to 1.0.1 must be detected.
        (new JoomlaOptionStore())->set(JoomlaUpgradeSentinel::VERSION_OPTION_KEY, '1.0.0', false);

        $sentinel = new JoomlaUpgradeSentinel();
        $changed  = $sentinel->reconcile('1.0.1', $this->mediaRoot);

        self::assertTrue($changed, 'reconcile must detect the version change.');
        self::assertFileDoesNotExist($css, 'stale admin.css must be pruned on a detected upgrade.');
        self::assertSame(
            '1.0.1',
            (string) (new JoomlaOptionStore())->get(JoomlaUpgradeSentinel::VERSION_OPTION_KEY, ''),
            'reconcile must persist the new on-disk version.'
        );
    }

    public function test_reconcile_is_noop_when_version_matches(): void
    {
        $css = $this->seedStaleCss();
        (new JoomlaOptionStore())->set(JoomlaUpgradeSentinel::VERSION_OPTION_KEY, '1.0.1', false);

        $changed = (new JoomlaUpgradeSentinel())->reconcile('1.0.1', $this->mediaRoot);

        self::assertFalse($changed, 'reconcile must no-op when stored version already matches.');
        self::assertFileExists($css, 'no prune when nothing changed (the file is not ours to touch here).');
    }

    public function test_reconcile_fires_on_fresh_store_then_noops(): void
    {
        // No stored version yet (fresh/orphaned install) → first reconcile fires.
        $first  = (new JoomlaUpgradeSentinel())->reconcile('1.0.1', $this->mediaRoot);
        self::assertTrue($first, 'first reconcile (no stored version) must fire.');

        // Second reconcile in the same "request" is a one-shot no-op.
        $second = (new JoomlaUpgradeSentinel())->reconcile('1.0.1', $this->mediaRoot);
        self::assertFalse($second, 'reconcile must be idempotent once the version is persisted.');
    }

    // ── sentinel: prune is list-driven + fail-safe ────────────────────────

    public function test_prune_removes_only_listed_files_and_returns_them(): void
    {
        $css = $this->seedStaleCss();
        // A sibling file NOT on the list must survive.
        $keep = $this->mediaRoot . '/com_ytbmcp/css/keep.css';
        \file_put_contents($keep, '/* keep me */');

        $removed = (new JoomlaUpgradeSentinel())->pruneStaleMedia($this->mediaRoot);

        self::assertContains('com_ytbmcp/css/admin.css', $removed, 'admin.css must be reported as removed.');
        self::assertFileDoesNotExist($css);
        self::assertFileExists($keep, 'unlisted media must NOT be pruned.');
    }

    public function test_prune_is_fail_safe_when_file_absent(): void
    {
        // No admin.css seeded — prune must not throw and must report nothing.
        $removed = (new JoomlaUpgradeSentinel())->pruneStaleMedia($this->mediaRoot);
        self::assertSame([], $removed, 'absent files are a silent no-op.');
    }

    public function test_prune_rejects_empty_media_root(): void
    {
        $removed = (new JoomlaUpgradeSentinel())->pruneStaleMedia('');
        self::assertSame([], $removed, 'an empty media root must be a safe no-op.');
    }

    public function test_stale_media_list_targets_the_w11_removed_admin_css(): void
    {
        self::assertContains(
            'com_ytbmcp/css/admin.css',
            JoomlaUpgradeSentinel::STALE_MEDIA,
            'the W11-removed component stylesheet must be on the prune list.'
        );
    }

    // ── package script: hook-driven prune mirror ──────────────────────────

    public function test_package_script_prune_list_mirrors_the_sentinel(): void
    {
        $scriptPath = \dirname(__DIR__, 6) . '/src/packaging/joomla/pkg_ytbmcp/script.php';
        self::assertFileExists($scriptPath);
        $src = (string) \file_get_contents($scriptPath);

        // SSoT discipline: every sentinel entry must appear in the package
        // script's STALE_MEDIA constant (the installer cannot rely on the
        // PSR-4 autoloader, so it carries an inline copy).
        self::assertStringContainsString('STALE_MEDIA', $src, 'package script must declare a STALE_MEDIA constant.');
        foreach (JoomlaUpgradeSentinel::STALE_MEDIA as $rel) {
            self::assertStringContainsString(
                "'" . $rel . "'",
                $src,
                "package script STALE_MEDIA must mirror the sentinel entry: {$rel}"
            );
        }

        // The prune must be gated to the Update path (a fresh install ships
        // the correct media set; only upgrades leave stale files behind).
        self::assertMatchesRegularExpression(
            "/if\s*\(\s*\\\$type\s*===\s*'update'\s*\)\s*\{\s*\\n[^}]*pruneStaleMedia\(\)/s",
            $src,
            'pruneStaleMedia() must be called only on the update path.'
        );
    }
}
