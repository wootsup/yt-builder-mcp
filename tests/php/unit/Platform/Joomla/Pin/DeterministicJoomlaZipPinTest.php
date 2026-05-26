<?php
/**
 * STATIC PIN TEST (Wave-9 T7 — gap #22): the Joomla package builders produce
 * BYTE-STABLE ZIPs.
 *
 * The actual two-build byte-diff proof runs at release-gate time (it needs a
 * composer install + the full packaging pipeline). This pin guards the STATIC
 * wiring so a refactor cannot silently revert the deterministic packer back to
 * the non-deterministic createZip():
 *
 *   1. release.php defines createDeterministicZip() + the composer-timestamp
 *      normaliser + a fixed mtime constant.
 *   2. All four ytbmcp Joomla sub-builds (plg_system / com / plg_webservices /
 *      the outer pkg) call createDeterministicZip(), NOT the legacy createZip().
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;

final class DeterministicJoomlaZipPinTest extends TestCase
{
    private static function releaseSource(): string
    {
        $path = \dirname(__DIR__, 7) . '/scripts/release.php';
        if (!\is_file($path)) {
            self::fail("release.php not found at: $path");
        }
        $src = \file_get_contents($path);
        self::assertIsString($src);

        return $src;
    }

    public function test_release_defines_deterministic_zip_helper(): void
    {
        $src = self::releaseSource();
        self::assertStringContainsString(
            'function createDeterministicZip(',
            $src,
            'release.php must define the byte-stable ZIP helper.'
        );
        self::assertStringContainsString(
            'function normaliseComposerTimestamps(',
            $src,
            'release.php must normalise composer installed.json timestamps.'
        );
        self::assertStringContainsString(
            'DETERMINISTIC_ZIP_MTIME',
            $src,
            'release.php must pin a fixed mtime constant for ZIP entries.'
        );
    }

    public function test_deterministic_helper_sorts_entries_and_freezes_mtime(): void
    {
        $src = self::releaseSource();

        // Isolate the helper body.
        $start = \strpos($src, 'function createDeterministicZip(');
        self::assertNotFalse($start);
        $body = \substr($src, $start, 4000);

        self::assertStringContainsString('ksort(', $body, 'entries must be sorted for a stable central directory.');
        self::assertStringContainsString('touch(', $body, 'staged files must be touched to the fixed epoch (portable mtime).');
        self::assertStringContainsString('DETERMINISTIC_ZIP_MTIME', $body, 'the fixed mtime must be applied.');
    }

    public function test_all_ytbmcp_joomla_builders_use_deterministic_zip(): void
    {
        $src = self::releaseSource();

        // The three sub-extension builds use the shape:
        //   if (!createDeterministicZip($tempDir, $zipPath, '')) {
        //       error("Failed to create <marker>...");
        // Pair the deterministic call with its matching error string —
        // `[^{]*` stops at the opening brace so the match stays local
        // (guards against a half-reverted refactor that swaps one site).
        foreach (
            [
                'plg_system_ytbmcp.zip',
                'com_ytbmcp.zip',
                'plg_webservices_ytbmcp.zip',
            ] as $marker
        ) {
            self::assertMatchesRegularExpression(
                '/createDeterministicZip\([^{]*\{\s*\n\s*error\("Failed to create ' . \preg_quote($marker, '/') . '/s',
                $src,
                "the {$marker} build must use createDeterministicZip()."
            );
        }

        // The OUTER package build derives its filename into $zipFilename
        // ("pkg_ytbmcp_v{$version}.zip"), so its error string is generic.
        // Pin the call site by the preceding filename assignment instead.
        self::assertMatchesRegularExpression(
            '/\$zipFilename\s*=\s*"pkg_ytbmcp_v\{\$version\}\.zip";[^{]*createDeterministicZip\(/s',
            $src,
            'the outer pkg_ytbmcp package build must use createDeterministicZip().'
        );

        // Belt-and-braces: none of the ytbmcp builders may still call the
        // non-deterministic createZip() for their final artifact.
        self::assertStringNotContainsString(
            'createZip($tempDir, $zipPath',
            $src,
            'no ytbmcp Joomla builder may use the legacy non-deterministic createZip() for its artifact.'
        );
    }
}
