<?php
/**
 * PIN TEST (A3-M3, 2026-05-25) — sentinel bootstrap wiring in plg_system_ytbmcp.
 *
 * The request-time recovery path ({@see \WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaUpgradeSentinel})
 * is itself behaviourally covered ({@see UpgradeSelfHealPinTest} +
 * {@see UpgradeSentinelReconcileBehaviourTest}). What was UNCOVERED is the
 * CALL-SITE that fires it: `Ytbmcp::onAfterInitialise()`. That method lives in
 * the system-plugin Extension class, which Joomla autoloads at runtime via its
 * plugin/MVC factory (NOT the composer PSR-4 map) and which can only be
 * meaningfully instantiated inside a booted Joomla ApiApplication. So the
 * wiring INVARIANTS are pinned against the source:
 *
 *   1. the sentinel call is GUARDED by `class_exists(...JoomlaUpgradeSentinel)`
 *      (so an install without the class never fatals);
 *   2. `reconcile()` runs inside a `try { … } catch (\Throwable)` (a sentinel
 *      failure must NEVER unwind the request bootstrap);
 *   3. `$mediaRoot` is resolved from `JPATH_ROOT . '/media'` (the correct site
 *      media root the prune paths are relative to);
 *   4. reconcile is called with the on-disk `self::YTBMCP_VERSION` constant;
 *   5. the SESSION-REVIVAL strip runs FIRST in onAfterInitialise (ordering
 *      invariant — the autoloader/seed/sentinel work follows the strip).
 *
 * Clearly labelled PIN (not behavioural) per the A3 disposition: the Extension
 * class is not PSR-4-instantiable in the unit harness.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;

final class SentinelBootstrapWiringPinTest extends TestCase
{
    private function extensionPath(): string
    {
        return \dirname(__DIR__, 6)
            . '/src/packaging/joomla/extensions/plg_system_ytbmcp/src/Extension/Ytbmcp.php';
    }

    private function source(): string
    {
        $path = $this->extensionPath();
        self::assertFileExists($path, "Extension class missing: {$path}");
        return (string) \file_get_contents($path);
    }

    /**
     * Extract the body of onAfterInitialise() so ordering + guard assertions
     * run against the right method (not, e.g., onStripApiSession's own
     * autoloader call).
     */
    private function onAfterInitialiseBody(): string
    {
        $src = $this->source();
        self::assertMatchesRegularExpression(
            '/public function onAfterInitialise\(\): void/',
            $src,
            'Extension must declare onAfterInitialise(): void.'
        );
        // Grab from the method signature to the next "public function" (or EOF).
        if (!\preg_match(
            '/public function onAfterInitialise\(\): void\s*\{(.*?)\n    \}/s',
            $src,
            $m
        )) {
            self::fail('Could not isolate the onAfterInitialise() body.');
        }
        return $m[1];
    }

    /** A3-M3 (1): the sentinel call is class_exists-guarded. */
    public function test_sentinel_call_is_class_exists_guarded(): void
    {
        $body = $this->onAfterInitialiseBody();
        self::assertMatchesRegularExpression(
            '/class_exists\(\s*[\'"]\\\\?WootsUp\\\\BuilderMcp\\\\Platform\\\\Joomla\\\\Storage\\\\JoomlaUpgradeSentinel[\'"]\s*\)/',
            $body,
            'reconcile() must be guarded by class_exists(JoomlaUpgradeSentinel::class) so a class-less install never fatals.'
        );
    }

    /** A3-M3 (2): reconcile() is wrapped in try/catch(\Throwable). */
    public function test_reconcile_runs_inside_try_catch_throwable(): void
    {
        $body = $this->onAfterInitialiseBody();
        self::assertMatchesRegularExpression(
            '/try\s*\{.*?->reconcile\(.*?\).*?\}\s*catch\s*\(\s*\\\\Throwable/s',
            $body,
            'reconcile() must run inside try { … } catch (\\Throwable) so a sentinel failure never unwinds the bootstrap.'
        );
    }

    /** A3-M3 (3): $mediaRoot is resolved from JPATH_ROOT . '/media'. */
    public function test_media_root_is_resolved_from_jpath_root(): void
    {
        $body = $this->onAfterInitialiseBody();
        self::assertMatchesRegularExpression(
            "/\\\$mediaRoot\s*=\s*\\\\?defined\(\s*'JPATH_ROOT'\s*\)\s*\?\s*\(\s*JPATH_ROOT\s*\.\s*'\/media'\s*\)/",
            $body,
            "the sentinel media root must be JPATH_ROOT . '/media' (guarded by defined('JPATH_ROOT'))."
        );
    }

    /** A3-M3 (4): reconcile is called with the on-disk YTBMCP_VERSION constant. */
    public function test_reconcile_is_called_with_ondisk_version_constant(): void
    {
        $body = $this->onAfterInitialiseBody();
        self::assertMatchesRegularExpression(
            '/->reconcile\(\s*self::YTBMCP_VERSION\s*,\s*\$mediaRoot\s*\)/',
            $body,
            'reconcile() must be called with self::YTBMCP_VERSION + the resolved $mediaRoot.'
        );
    }

    /**
     * A3-M3 (5): the session-revival strip runs FIRST in onAfterInitialise,
     * BEFORE the autoloader/seed/sentinel work (ADR-002 ordering invariant —
     * Joomla binds one listener per event so the priority-1-before-10 ordering
     * is enforced inline).
     */
    public function test_session_strip_runs_before_autoloader_and_sentinel(): void
    {
        $body = $this->onAfterInitialiseBody();

        $stripPos     = \strpos($body, '$this->onStripApiSession()');
        $autoloadPos  = \strpos($body, '$this->ensureAutoloader()');
        $reconcilePos = \strpos($body, '->reconcile(');

        self::assertNotFalse($stripPos, 'onAfterInitialise must invoke onStripApiSession() inline.');
        self::assertNotFalse($autoloadPos, 'onAfterInitialise must invoke ensureAutoloader().');
        self::assertNotFalse($reconcilePos, 'onAfterInitialise must invoke the sentinel reconcile().');

        self::assertLessThan(
            $autoloadPos,
            $stripPos,
            'the session-revival strip must run BEFORE the autoloader (ADR-002 ordering).'
        );
        self::assertLessThan(
            $reconcilePos,
            $stripPos,
            'the session-revival strip must run BEFORE the upgrade sentinel.'
        );
    }

    /**
     * A3-M3: onAfterInitialise must also run the unconditional schema reseed
     * BEFORE (or alongside) the sentinel — the sentinel is the version-gated
     * recovery, the unconditional ensure() is the orphan-install safety-net.
     */
    public function test_schema_version_ensure_is_wired_unconditionally(): void
    {
        $body = $this->onAfterInitialiseBody();
        self::assertMatchesRegularExpression(
            '/class_exists\([^)]*JoomlaSchemaVersion[^)]*\)[^;]*;?\s*\n[^}]*JoomlaSchemaVersion::ensure\(\)/s',
            $body,
            'onAfterInitialise must call JoomlaSchemaVersion::ensure() (guarded) as the orphan-install safety-net.'
        );
    }
}
