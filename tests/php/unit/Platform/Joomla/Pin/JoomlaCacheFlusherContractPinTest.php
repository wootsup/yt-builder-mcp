<?php
/**
 * PIN-TEST: JoomlaCacheFlusher API contract — L1 controllers MUST call
 * `->flushL1()`, NEVER `->flush()`.
 *
 * Round-4 audit F-A1-005 (RELEASE-BLOCKER). Pre-R4 every Wave-4 L1
 * controller (`PagesController`, `ElementsController`, `SourcesController`,
 * `MultiItemsController`) called the non-existent `->flush()` inside a
 * `method_exists($flusher, 'flush')` guard. `method_exists` returned
 * false silently and the cache was never invalidated on L1 writes —
 * stale Builder state would persist until YT's render-layer TTL caught
 * up (or forever, on infinite-TTL site backends).
 *
 * This pin guards three contracts simultaneously:
 *
 *   1. {@see \WootsUp\BuilderMcp\Platform\Joomla\Cache\JoomlaCacheFlusher}
 *      MUST expose public `flushL1(): void` and `flushL2(int): void`.
 *   2. Each of the four L1 controllers MUST call `->flushL1()` literally.
 *   3. NO Joomla controller MUST call `->flush()` as a literal (forbids
 *      regression to the dead API contract).
 *
 * Forensic pattern: a future innocent-looking refactor renaming
 * `flushL1` → `invalidateL1` or removing the helper-method indirection
 * would silently re-introduce the silent no-op. This pin fails loudly
 * at PHPUnit-collection time so the regression cannot ship.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WootsUp\BuilderMcp\Platform\Joomla\Cache\JoomlaCacheFlusher;

final class JoomlaCacheFlusherContractPinTest extends TestCase
{
    /**
     * @cookbook 4.13.3 JoomlaCacheFlusher exposes flushL1 + flushL2 public
     */
    public function test_joomla_cache_flusher_exposes_flush_l1_and_flush_l2_public(): void
    {
        self::assertTrue(
            \class_exists(JoomlaCacheFlusher::class),
            'JoomlaCacheFlusher class must exist under WootsUp\\BuilderMcp\\Platform\\Joomla\\Cache.'
        );

        $rc = new ReflectionClass(JoomlaCacheFlusher::class);

        self::assertTrue(
            $rc->hasMethod('flushL1'),
            'JoomlaCacheFlusher::flushL1() must exist — controllers depend on it (R3 F-A1-005).'
        );
        self::assertTrue(
            $rc->getMethod('flushL1')->isPublic(),
            'JoomlaCacheFlusher::flushL1() must be public so controllers can call it.'
        );

        self::assertTrue(
            $rc->hasMethod('flushL2'),
            'JoomlaCacheFlusher::flushL2(int $articleId) must exist — L2 controllers depend on it (R4 F-A1-006).'
        );
        self::assertTrue(
            $rc->getMethod('flushL2')->isPublic(),
            'JoomlaCacheFlusher::flushL2() must be public so L2 controllers can call it.'
        );

        // No public `flush()` — this is the dead API the original
        // controllers guarded on. Asserting absence makes any future
        // "let's add a generic flush() alias" PR fail this pin.
        self::assertFalse(
            $rc->hasMethod('flush'),
            'JoomlaCacheFlusher must NOT expose a generic ->flush() — the L1/L2 split is intentional (R3 F-A1-005 regression class).'
        );
    }

    /**
     * Pin: each of the four L1 controllers calls `->flushL1()` literally.
     *
     * @cookbook 4.13.3 L1 controllers call ->flushL1() after every write
     */
    #[DataProvider('l1ControllerProvider')]
    public function test_l1_controller_calls_flush_l1(string $relPath): void
    {
        $abs = $this->ytbMcpRoot() . '/' . $relPath;
        self::assertFileExists($abs, "Wave-4 L1 controller missing: $relPath");
        $src = (string) \file_get_contents($abs);

        // Strip docblocks + line comments so the assertions test
        // executable code only.
        $stripped = (string) \preg_replace('#/\*.*?\*/#s', '', $src);
        $stripped = (string) \preg_replace('#//[^\n]*#', '', $stripped);

        self::assertStringContainsString(
            '->flushL1(',
            $stripped,
            "L1 controller $relPath must call \$flusher->flushL1() — pre-R4 it called the non-existent ->flush() (F-A1-005)."
        );
    }

    /**
     * Pin: no Joomla controller calls `->flush()` literally — that's the
     * dead API contract the silent no-op regression depended on.
     *
     * @cookbook 4.13.3 ->flush() literal forbidden on Joomla cache flusher
     */
    public function test_no_joomla_controller_calls_dead_flush_literal(): void
    {
        $offenders = [];
        $root      = $this->ytbMcpRoot() . '/src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller';
        if (!\is_dir($root)) {
            self::markTestSkipped('com_ytbmcp Controller directory missing — Wave 4 not landed?');
        }
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $info */
        foreach ($rii as $info) {
            if (!$info->isFile() || $info->getExtension() !== 'php') {
                continue;
            }
            $src      = (string) \file_get_contents($info->getPathname());
            // Strip docblocks + line comments — narrative text may
            // legitimately reference `->flush()` as the dead API.
            $stripped = (string) \preg_replace('#/\*.*?\*/#s', '', $src);
            $stripped = (string) \preg_replace('#//[^\n]*#', '', $stripped);
            // The forbidden literal: ->flush() with NO further suffix
            // (flushL1 / flushL2 / flushSomething is fine).
            if (\preg_match('/->\s*flush\s*\(\s*\)/', $stripped)) {
                $offenders[] = $info->getPathname();
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Joomla controllers must NOT call the dead ->flush() API. Use flushL1() or flushL2(int) instead (R3 F-A1-005).\nOffending files:\n  - "
            . \implode("\n  - ", $offenders)
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function l1ControllerProvider(): iterable
    {
        $base = 'src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller';
        yield 'PagesController'      => [$base . '/PagesController.php'];
        yield 'ElementsController'   => [$base . '/ElementsController.php'];
        yield 'SourcesController'    => [$base . '/SourcesController.php'];
        yield 'MultiItemsController' => [$base . '/MultiItemsController.php'];
    }

    private function ytbMcpRoot(): string
    {
        // __DIR__ here = tests/php/unit/Platform/Joomla/Pin → 6 levels up.
        return \dirname(__DIR__, 6);
    }
}
