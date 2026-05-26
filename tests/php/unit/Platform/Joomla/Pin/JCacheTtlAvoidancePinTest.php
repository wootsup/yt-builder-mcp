<?php
/**
 * PIN-TEST: platform-joomla MUST NOT use JCache for transient storage.
 *
 * Cookbook §5.14.4 — Joomla's `JCache` / `Joomla\CMS\Cache\Cache` has
 * coarse-grained TTL handling (15-minute default lifetime, configurable
 * site-wide but not per-key) and surprising eviction behaviour under
 * memory-cache handlers. yt-builder-mcp uses a dedicated transient table
 * (`#__ytb_mcp_transients`) with per-row TTL via JoomlaTransientStore
 * instead.
 *
 * The test greps platform-joomla source for any JCache / Cache::getInstance
 * usage and fails loud on regression.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JCacheTtlAvoidancePinTest extends TestCase
{
    /**
     * @cookbook 5.14.4 banned cache APIs absent from platform source
     */
    #[DataProvider('bannedCacheApiProvider')]
    public function test_banned_cache_api_absent_from_platform_source(string $needle, string $reason): void
    {
        $offenders = [];
        foreach ($this->iterateSourceFiles() as $absPath) {
            $contents = (string) \file_get_contents($absPath);

            // Strip docblocks / inline comments to avoid flagging docs.
            $stripped = (string) \preg_replace('#/\*.*?\*/#s', '', $contents);
            $stripped = (string) \preg_replace('#//[^\n]*#', '', $stripped);

            if (\str_contains($stripped, $needle)) {
                $offenders[] = $absPath;
            }
        }

        self::assertSame(
            [],
            $offenders,
            \sprintf(
                "Banned cache API \"%s\" found in platform-joomla source.\nReason: %s\nUse #__ytb_mcp_transients + JoomlaTransientStore instead.\nOffending files:\n  - %s",
                $needle,
                $reason,
                \implode("\n  - ", $offenders)
            )
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function bannedCacheApiProvider(): iterable
    {
        yield 'JCache::getInstance' => [
            'JCache::getInstance(',
            'JCache has coarse TTL granularity and surprising eviction. Use a dedicated transient table.',
        ];
        yield 'Cache::getInstance' => [
            'Cache::getInstance(',
            'Joomla\\CMS\\Cache\\Cache has the same TTL/eviction issues as JCache.',
        ];
        yield 'Factory::getCache(' => [
            'Factory::getCache(',
            'Factory::getCache() returns a CacheController — same JCache plumbing.',
        ];
    }

    /**
     * Round-4 audit A3 P2: pin-tests now scan BOTH the platform-joomla
     * module source AND the com_ytbmcp packaging-path so newly-added
     * Wave 4 controllers + L2 helpers are covered by the JCache-ban grep.
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
