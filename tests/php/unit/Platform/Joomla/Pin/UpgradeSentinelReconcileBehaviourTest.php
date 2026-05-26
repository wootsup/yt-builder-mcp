<?php
/**
 * A3-L1 / A3-L2 / A3-L3 / A1-SSoT — JoomlaUpgradeSentinel reconcile behaviour
 * + stale-media prune guard + cross-source parity. (Fix-Stream B, 2026-05-25.)
 *
 * These complement the existing {@see UpgradeSelfHealPinTest} (which pins the
 * happy-path version-change + prune) with the previously-uncovered EDGE +
 * STEP-ORDERING + SSoT invariants the A3 audit flagged:
 *
 *   - A3-L2: reconcile('', …) returns early — no prune, no persist, no
 *            schema-seed. An empty on-disk version must be a hard no-op.
 *   - A3-L3: on a real version change, STEP 1 (JoomlaSchemaVersion::ensure())
 *            actually FIRES — observable via the schema_version option landing
 *            in the in-memory store (the prior pin only asserted prune+persist,
 *            leaving the reseed step uncovered).
 *   - A3-L1: pruneStaleMedia never deletes a file OUTSIDE the media root. The
 *            inline guard (`str_contains($rel, '..')` + `ltrim` join) is what
 *            keeps a (defensive) malformed list entry from escaping the root;
 *            tested behaviourally (decoy survives) + pinned on source (the
 *            class is `final` + the list is the static SSoT, so a `..` entry
 *            cannot be injected behaviourally — the guard branch is pinned).
 *   - A1-SSoT: JoomlaUpgradeSentinel::STALE_MEDIA === ytbmcp_shared_stale_media()
 *              — the BC-alias-const can never silently diverge from the shared
 *              source the prune actually iterates.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaSchemaVersion;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaUpgradeSentinel;

final class UpgradeSentinelReconcileBehaviourTest extends TestCase
{
    private string $mediaRoot = '';

    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();

        // The shared SSoT functions are loaded transitively by the sentinel,
        // but require explicitly so the A1-SSoT parity test stands alone.
        require_once \dirname(__DIR__, 6)
            . '/src/modules/platform-joomla/src/Shared/ytbmcp-joomla-detect.php';

        $this->mediaRoot = \sys_get_temp_dir() . '/ytb-mcp-media-' . \uniqid('', true);
        \mkdir($this->mediaRoot . '/com_ytbmcp/css', 0o755, true);
    }

    protected function tearDown(): void
    {
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

    // ── A3-L2: empty current version → hard no-op ─────────────────────────

    public function test_reconcile_returns_early_when_current_version_empty(): void
    {
        $css = $this->seedStaleCss();
        $store = new JoomlaOptionStore();

        $changed = (new JoomlaUpgradeSentinel($store))->reconcile('', $this->mediaRoot);

        self::assertFalse($changed, 'reconcile("") must return false (early return).');
        self::assertFileExists($css, 'an empty current version must NOT prune stale media.');
        self::assertSame(
            '',
            (string) $store->get(JoomlaUpgradeSentinel::VERSION_OPTION_KEY, ''),
            'an empty current version must NOT persist a plugin_version row.'
        );
        self::assertSame(
            0,
            JoomlaSchemaVersion::get($store),
            'an empty current version must NOT seed the schema_version (no reconcile work).'
        );
    }

    // ── A3-L3: step 1 (schema ensure) actually fires on a version change ──

    public function test_reconcile_fires_schema_version_ensure_on_version_change(): void
    {
        $store = new JoomlaOptionStore();
        // Old stored version → a code-swap to 1.0.1 is a detected change.
        $store->set(JoomlaUpgradeSentinel::VERSION_OPTION_KEY, '1.0.0', false);

        // Precondition: schema_version not yet seeded.
        self::assertSame(0, JoomlaSchemaVersion::get($store), 'precondition: schema_version absent.');

        $changed = (new JoomlaUpgradeSentinel($store))->reconcile('1.0.1', $this->mediaRoot);

        self::assertTrue($changed, 'reconcile must detect the version change.');
        // STEP 1 observable side-effect: JoomlaSchemaVersion::ensure() ran and
        // stamped the current schema version into the store.
        self::assertSame(
            JoomlaSchemaVersion::CURRENT_VERSION,
            JoomlaSchemaVersion::get($store),
            'reconcile STEP 1 must fire JoomlaSchemaVersion::ensure() (schema_version stamped).'
        );
        // …and STEP 3 still persisted the new version (regression guard).
        self::assertSame(
            '1.0.1',
            (string) $store->get(JoomlaUpgradeSentinel::VERSION_OPTION_KEY, ''),
            'reconcile STEP 3 must still persist the new on-disk version.'
        );
    }

    public function test_reconcile_seeds_schema_version_on_fresh_orphaned_install(): void
    {
        $store = new JoomlaOptionStore();
        // No stored version (orphaned install) → first reconcile fires + seeds.
        self::assertSame(0, JoomlaSchemaVersion::get($store));

        $changed = (new JoomlaUpgradeSentinel($store))->reconcile('1.0.1', $this->mediaRoot);

        self::assertTrue($changed);
        self::assertSame(
            JoomlaSchemaVersion::CURRENT_VERSION,
            JoomlaSchemaVersion::get($store),
            'an orphaned install (no stored version) must also reseed schema_version.'
        );
    }

    // ── A3-L1: prune never escapes the media root ─────────────────────────

    public function test_prune_never_deletes_a_file_outside_the_media_root(): void
    {
        $this->seedStaleCss();

        // A decoy file OUTSIDE the media root that must survive no matter what
        // the prune does. If a malformed/escaping entry ever slipped past the
        // guard, an unlink could reach a sibling tree — this proves it cannot.
        $outsideDir = \dirname($this->mediaRoot) . '/ytb-mcp-decoy-' . \uniqid('', true);
        \mkdir($outsideDir, 0o755, true);
        $decoy = $outsideDir . '/com_ytbmcp/css/admin.css'; // same relative shape as the listed entry
        \mkdir(\dirname($decoy), 0o755, true);
        \file_put_contents($decoy, '/* outside the media root */');

        $removed = (new JoomlaUpgradeSentinel())->pruneStaleMedia($this->mediaRoot);

        // Only the in-root admin.css is reported removed; the identically-named
        // decoy outside the root is untouched.
        self::assertContains('com_ytbmcp/css/admin.css', $removed);
        self::assertFileExists($decoy, 'prune must never reach a file outside the supplied media root.');

        $this->rrmdir($outsideDir);
    }

    /**
     * A3-L1 PIN: the inline traversal/empty guard exists in pruneStaleMedia.
     * Because the class is `final` and STALE_MEDIA is the static shared SSoT,
     * a `..` entry cannot be injected behaviourally — so the guard BRANCH is
     * pinned on source (the behavioural test above proves its EFFECT: nothing
     * escapes the root). A future refactor that drops the guard fails here.
     */
    public function test_pin_prune_has_traversal_and_empty_guard(): void
    {
        $sentinelSrc = (string) \file_get_contents(
            \dirname(__DIR__, 6)
            . '/src/modules/platform-joomla/src/Storage/JoomlaUpgradeSentinel.php'
        );
        self::assertMatchesRegularExpression(
            "/\\\\str_contains\\(\\s*\\\$rel\\s*,\\s*'\\.\\.'\\s*\\)/",
            $sentinelSrc,
            'pruneStaleMedia() must reject entries containing ".." (path-traversal guard).'
        );
        self::assertStringContainsString(
            "\\trim(\$rel) === ''",
            $sentinelSrc,
            'pruneStaleMedia() must reject empty/whitespace entries.'
        );
    }

    // ── A1-SSoT: BC-alias const must not diverge from the shared source ───

    public function test_stale_media_const_equals_shared_ssot_function(): void
    {
        self::assertSame(
            \ytbmcp_shared_stale_media(),
            JoomlaUpgradeSentinel::STALE_MEDIA,
            'the BC-alias STALE_MEDIA const must stay in lockstep with the shared SSoT '
            . '(the prune iterates ytbmcp_shared_stale_media(), so a divergence would '
            . 'silently change behaviour relative to the documented const).'
        );
    }
}
