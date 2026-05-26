<?php
/**
 * PIN-TEST: every `SecurityLogger::log(...)` call MUST use a class-constant
 * as its first argument — NEVER a literal string.
 *
 * Cookbook §2.10.12 invariant. Round-6 A4 polish (defense-in-depth).
 *
 * Why the rule:
 *
 * SecurityLogger is the single forensic-grep sink for security events.
 * When a caller passes a literal string like `SecurityLogger::log('foo', …)`:
 *
 *   - A typo at the call-site (`'l2_acl_deined'`) silently lands in
 *     production with NO compile-time warning.
 *   - `grep EVENT_FOO src/` won't catch the call-site for refactor
 *     impact-analysis — invisible to namespace tooling.
 *   - A future renamed constant value (e.g. `'acl_denied'` →
 *     `'auth_denied'` to align with cross-platform vocabulary) silently
 *     diverges from the literal copies scattered through controllers.
 *
 * Pin scope (Joomla side — cookbook §2.10.12 #4):
 *
 *   - `src/modules/platform-joomla/`               (L1 + cache + lock + auth)
 *   - `src/packaging/joomla/extensions/com_ytbmcp/` (controllers + bootstrap)
 *   - `src/packaging/joomla/extensions/plg_system_ytbmcp/`
 *   - `src/packaging/joomla/extensions/plg_webservices_ytbmcp/`
 *
 * Forbidden pattern (regex-detected):
 *   /SecurityLogger::log\s*\(\s*['"][^'"]+['"]/
 *
 * Allowed:
 *   SecurityLogger::log(SecurityLogger::EVENT_CACHE_FLUSH_FAILED, [...])
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SecurityLoggerEventConstantPinTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../../../../..';

    /**
     * Strip PHP comments so rationale snippets referencing the forbidden
     * literal-string pattern (e.g. cookbook quotes in docblocks) don't
     * trip the executable-code scan below.
     */
    private static function stripComments(string $src): string
    {
        $stripped = '';
        if (!\defined('T_OPEN_TAG')) {
            return $src;
        }
        foreach (\token_get_all($src) as $token) {
            if (\is_array($token)) {
                [$id, $text] = $token;
                if ($id === \T_COMMENT || $id === \T_DOC_COMMENT) {
                    continue;
                }
                $stripped .= $text;
            } else {
                $stripped .= $token;
            }
        }
        return $stripped;
    }

    /** @return list<string> */
    private static function scanDirs(): array
    {
        return [
            self::REPO_ROOT . '/src/modules/platform-joomla',
            self::REPO_ROOT . '/src/packaging/joomla',
        ];
    }

    /**
     * Walk every `.php` file under the L1+L2 Joomla code areas and fail
     * loudly if ANY call to `SecurityLogger::log()` passes a literal
     * string as its first argument.
     */
    public function test_no_literal_string_first_arg_to_security_logger_log(): void
    {
        /** @var list<array{file: string, line: int, snippet: string}> $hits */
        $hits = [];

        foreach (self::scanDirs() as $dir) {
            if (!\is_dir($dir)) {
                continue;
            }
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = (string) $file->getRealPath();
                $raw  = (string) \file_get_contents($path);
                if (!\str_contains($raw, 'SecurityLogger::log')) {
                    continue;
                }
                $src   = self::stripComments($raw);
                $lines = \preg_split('/\R/', $src) ?: [];
                foreach ($lines as $i => $line) {
                    // Forbidden: SecurityLogger::log('literal', …) or "literal"
                    if (\preg_match('/SecurityLogger::log\s*\(\s*[\'"][^\'"]+[\'"]/', $line) === 1) {
                        $hits[] = [
                            'file'    => $path,
                            'line'    => $i + 1,
                            'snippet' => \trim($line),
                        ];
                    }
                }
            }
        }

        self::assertSame(
            [],
            $hits,
            'SecurityLogger::log MUST receive a class-constant as its first arg (cookbook §2.10.12). '
            . 'Literal-string call-sites detected: '
            . \json_encode($hits, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Sanity: the scan MUST actually find at least one constant-using
     * call (otherwise the regex above could silently match nothing
     * because we're walking the wrong directories and the test would
     * pass for the wrong reason).
     */
    public function test_scan_finds_actual_security_logger_calls(): void
    {
        $found = false;
        foreach (self::scanDirs() as $dir) {
            if (!\is_dir($dir)) {
                continue;
            }
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) \file_get_contents((string) $file->getRealPath());
                if (\str_contains($src, 'SecurityLogger::log(SecurityLogger::EVENT_')) {
                    $found = true;
                    break 2;
                }
            }
        }
        self::assertTrue(
            $found,
            'Scan must locate at least one SecurityLogger::log(SecurityLogger::EVENT_*, …) call — '
            . 'otherwise the literal-string scan above is testing nothing.'
        );
    }
}
