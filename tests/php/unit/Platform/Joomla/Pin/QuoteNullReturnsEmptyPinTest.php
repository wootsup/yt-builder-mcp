<?php
/**
 * PIN-TEST: Joomla 6 quote(null) returns '' (not NULL).
 *
 * Cookbook §5.14.4 — `DatabaseDriver::quote(null)` returns the empty
 * string in Joomla 6, NOT the SQL literal `NULL`. Any SQL path that
 * passes a nullable into quote() risks silently writing the literal
 * string `''` instead of the intended `NULL`. The correct pattern is
 * explicit binding with `ParameterType::NULL`.
 *
 * Sentinel scan: every platform-joomla source file MUST NOT call
 * `$db->quote($var)` where $var is documented nullable. The reader
 * scans for the dangerous pattern and surfaces it as a guard.
 *
 * This is currently a SENTINEL-FOR-FUTURE-CODE test: the platform-joomla
 * surface as of Wave 4 uses bound parameters with explicit ParameterType
 * everywhere it touches the DB (verified by inspection — every
 * JoomlaOptionStore / JoomlaStateLock / JoomlaPagesMetaStore SQL site
 * uses `->bind(':k', $v, ParameterType::XYZ)`). The test fails loud if
 * any new code regresses by introducing a raw `->quote(` call.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;

final class QuoteNullReturnsEmptyPinTest extends TestCase
{
    /**
     * @cookbook 5.14.4 J6 quote(null) gotcha — no raw ->quote( calls in platform source
     */
    public function test_no_raw_quote_call_in_platform_joomla_source(): void
    {
        $offenders = [];
        foreach ($this->iterateSourceFiles() as $absPath) {
            $contents = (string) \file_get_contents($absPath);

            // Strip /** ... */ doc-blocks + single-line // comments so
            // that documentation referencing `->quote(` does not flag.
            $stripped = (string) \preg_replace('#/\*.*?\*/#s', '', $contents);
            $stripped = (string) \preg_replace('#//[^\n]*#', '', $stripped);

            if (\preg_match('/->\s*quote\s*\(/', $stripped)) {
                $offenders[] = $absPath;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Joomla 6 quote(null) returns '' (not NULL). Use \$db->bind(':key', \$value, ParameterType::NULL) instead of \$db->quote(\$nullable).\n"
            . "Offending files:\n  - "
            . \implode("\n  - ", $offenders)
        );
    }

    /**
     * Behavioural sentinel — documents the J6 contract via the mock.
     *
     * The MockJoomlaDatabase::quote() in tests/php/joomla-stubs/JoomlaCmsStubs.php
     * intentionally mirrors the J6 behaviour: quote(null) returns "''".
     * Any code that relied on the old J5 behaviour (returning 'NULL')
     * would break on this mock — surfacing the bug at test time.
     */
    public function test_mock_quote_null_returns_empty_string_literal_matching_j6(): void
    {
        $db = new \MockJoomlaDatabase();
        self::assertSame("''", $db->quote(null), 'Joomla 6 quote(null) contract: returns empty-string SQL literal, NOT the keyword NULL.');
    }

    /**
     * Round-4 audit A3 P2: pin-tests now scan BOTH the platform-joomla
     * module source AND the com_ytbmcp packaging-path so newly-added
     * Wave 4 controllers + L2 helpers are covered by the quote(null) grep.
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
