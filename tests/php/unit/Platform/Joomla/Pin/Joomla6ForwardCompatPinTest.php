<?php
/**
 * PIN-TEST: Joomla 6 forward-compatibility — banned API surface.
 *
 * Cookbook §S2 lists the APIs the Joomla 6 release deprecates / removes:
 *   - `Joomla\CMS\Table\Table::getInstance()` — deleted in J6 (use direct
 *     model instantiation via DI instead).
 *   - `$db->getQuery(true)` — removed (`createQuery()` is the replacement).
 *
 * This test greps the platform-joomla source for any literal occurrence
 * of those banned strings and FAILS LOUDLY if any new code regresses.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Joomla6ForwardCompatPinTest extends TestCase
{
    /**
     * @cookbook S2 J6-removed APIs must not appear in platform-joomla source
     */
    #[DataProvider('bannedApiProvider')]
    public function test_no_j6_removed_api_in_source(string $needle, string $reason): void
    {
        $offenders = [];
        foreach ($this->iterateSourceFiles() as $absPath) {
            $contents = (string) \file_get_contents($absPath);
            if (\str_contains($contents, $needle)) {
                $offenders[] = $absPath;
            }
        }

        self::assertSame(
            [],
            $offenders,
            \sprintf(
                "Joomla 6 forward-compat: \"%s\" must not appear in platform-joomla source.\nReason: %s\nOffending files:\n  - %s",
                $needle,
                $reason,
                \implode("\n  - ", $offenders)
            )
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function bannedApiProvider(): iterable
    {
        yield 'Table::getInstance()' => [
            'Table::getInstance(',
            'Joomla 6 removed the Table::getInstance() factory — instantiate the Table subclass directly via DI.',
        ];
        yield 'getQuery(true)' => [
            'getQuery(true)',
            'Joomla 6 removed DatabaseDriver::getQuery() — use $db->createQuery() instead.',
        ];
    }

    /**
     * Round-4 audit A3 P2: pin-tests now scan BOTH the platform-joomla
     * module source AND the com_ytbmcp packaging-path so newly-added
     * Wave 4 controllers + L2 helpers are covered by the same forward-compat
     * grep. __DIR__ here = tests/php/unit/Platform/Joomla/Pin → 6 levels up
     * = yt-builder-mcp/.
     *
     * @return iterable<string>
     */
    private function iterateSourceFiles(): iterable
    {
        $roots = [
            \dirname(__DIR__, 6) . '/src/modules/platform-joomla',
            \dirname(__DIR__, 6) . '/src/packaging/joomla/extensions/com_ytbmcp',
        ];
        foreach ($roots as $root) {
            if (!\is_dir($root)) {
                continue;
            }
            $rii = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            /** @var \SplFileInfo $info */
            foreach ($rii as $info) {
                if ($info->isFile() && $info->getExtension() === 'php') {
                    yield $info->getPathname();
                }
            }
        }
    }
}
