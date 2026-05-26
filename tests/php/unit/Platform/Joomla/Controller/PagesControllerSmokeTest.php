<?php
/**
 * PagesController smoke-test — structural & dispatch contracts.
 *
 * Round-4 audit A3 P1 — Wave 4 controllers (com_ytbmcp/api/...) had
 * zero unit coverage. Joomla autoloads these via MVCFactory at runtime
 * so they are intentionally NOT in composer's PSR-4 map; the smoke
 * suite therefore inspects the source file directly with regex/grep
 * assertions on the contract surfaces:
 *
 *   - Class extends AbstractApiController (Bearer + scope stack)
 *   - Every public REST method calls $this->dispatch('<scope>', ...)
 *   - Write methods call JoomlaEtagMiddleware::enforce
 *   - Cache-flush helper calls flushL1() (F-A1-005 release-blocker pin)
 *
 * Cross-references: cookbook §3.2 routes, §3.7.1 wire-shape parity,
 * R4 F-A1-005 (cache contract) + F-A1-008 (unified ETag).
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Controller
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PagesControllerSmokeTest extends TestCase
{
    private const REL_PATH = 'src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/PagesController.php';

    public function test_controller_file_exists(): void
    {
        self::assertFileExists($this->controllerPath(), 'Wave 4 PagesController must exist on disk.');
    }

    public function test_extends_abstract_api_controller(): void
    {
        $src = $this->controllerSource();
        self::assertMatchesRegularExpression(
            '/final\s+class\s+PagesController\s+extends\s+AbstractApiController\b/',
            $src,
            'PagesController must extend AbstractApiController so the Bearer + scope + rate-limit + ETag stack runs (auth-stack pin).'
        );
    }

    public function test_published_state_etag_option_key_pinned(): void
    {
        $src = $this->controllerSource();
        self::assertMatchesRegularExpression(
            '/const\s+PUBLISHED_STATE_ETAG_OPTION\s*=\s*[\'"]published_state_etag[\'"]/',
            $src,
            'The F-15 publish-action depends on the literal "published_state_etag" option key for cross-platform diff parity.'
        );
    }

    #[DataProvider('methodScopeProvider')]
    public function test_method_dispatches_with_correct_scope(string $method, string $expectedScope): void
    {
        $body = $this->methodBody($method);
        self::assertStringContainsString(
            "\$this->dispatch('$expectedScope'",
            $body,
            "PagesController::$method() must call \$this->dispatch('$expectedScope', ...) — scope is the auth-stack gate."
        );
    }

    public function test_write_methods_enforce_etag_via_joomla_etag_middleware(): void
    {
        $src = $this->controllerSource();
        self::assertStringContainsString(
            'JoomlaEtagMiddleware::enforce',
            $src,
            'Write methods must enforce optimistic-lock via JoomlaEtagMiddleware::enforce().'
        );
        self::assertStringContainsString(
            'JoomlaEtagMiddleware::readIfMatchHeader',
            $src,
            'Write methods must read If-Match via JoomlaEtagMiddleware::readIfMatchHeader() (R4 F-A1-008 unified header).'
        );
    }

    public function test_cache_flush_helper_calls_flush_l1(): void
    {
        $src = $this->controllerSource();
        self::assertStringContainsString(
            '->flushL1(',
            $src,
            'flushCachesIfAvailable() must call $flusher->flushL1() — pre-R4 it called the dead ->flush() API (F-A1-005).'
        );
        // Strip docblocks before searching for the forbidden dead literal.
        $stripped = (string) \preg_replace('#/\*.*?\*/#s', '', $src);
        $stripped = (string) \preg_replace('#//[^\n]*#', '', $stripped);
        self::assertDoesNotMatchRegularExpression(
            '/->\s*flush\s*\(\s*\)/',
            $stripped,
            'PagesController must NOT call the dead ->flush() API — F-A1-005 regression class.'
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function methodScopeProvider(): iterable
    {
        yield 'list'       => ['list',       'read'];
        yield 'getLayout'  => ['getLayout',  'read'];
        yield 'getSchema'  => ['getSchema',  'read'];
        yield 'getSummary' => ['getSummary', 'read'];
        yield 'save'       => ['save',       'write'];
        yield 'publish'    => ['publish',    'write'];
    }

    private function controllerPath(): string
    {
        return \dirname(__DIR__, 6) . '/' . self::REL_PATH;
    }

    private function controllerSource(): string
    {
        $path = $this->controllerPath();
        if (!\is_file($path)) {
            self::fail("Controller source missing: $path");
        }
        return (string) \file_get_contents($path);
    }

    private function methodBody(string $method): string
    {
        $src = $this->controllerSource();
        if (!\preg_match(
            '/public function\s+' . \preg_quote($method, '/') . '\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s',
            $src,
            $m,
        )) {
            self::fail("Could not locate method body for $method in PagesController.");
        }
        return $m[1];
    }
}
