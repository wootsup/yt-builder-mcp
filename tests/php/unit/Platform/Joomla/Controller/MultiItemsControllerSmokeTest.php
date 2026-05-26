<?php
/**
 * MultiItemsController smoke-test — structural & dispatch contracts.
 *
 * Round-4 audit A3 P1. See {@see PagesControllerSmokeTest} for rationale.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Controller
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MultiItemsControllerSmokeTest extends TestCase
{
    private const REL_PATH = 'src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/MultiItemsController.php';

    public function test_controller_file_exists(): void
    {
        self::assertFileExists($this->controllerPath());
    }

    public function test_extends_abstract_api_controller(): void
    {
        self::assertMatchesRegularExpression(
            '/final\s+class\s+MultiItemsController\s+extends\s+AbstractApiController\b/',
            $this->controllerSource(),
            'MultiItemsController must extend AbstractApiController (auth-stack pin).'
        );
    }

    #[DataProvider('methodScopeProvider')]
    public function test_method_dispatches_with_correct_scope(string $method, string $expectedScope): void
    {
        self::assertStringContainsString(
            "\$this->dispatch('$expectedScope'",
            $this->methodBody($method),
            "MultiItemsController::$method() must dispatch with scope '$expectedScope'."
        );
    }

    public function test_write_method_enforces_etag_via_joomla_etag_middleware(): void
    {
        $src = $this->controllerSource();
        self::assertStringContainsString('JoomlaEtagMiddleware::enforce', $src);
        self::assertStringContainsString('JoomlaEtagMiddleware::readIfMatchHeader', $src);
    }

    public function test_cache_flush_helper_calls_flush_l1(): void
    {
        $src = $this->controllerSource();
        self::assertStringContainsString(
            '->flushL1(',
            $src,
            'flushCachesIfAvailable() must call $flusher->flushL1() (R4 F-A1-005 release-blocker fix).'
        );
        $stripped = (string) \preg_replace('#/\*.*?\*/#s', '', $src);
        $stripped = (string) \preg_replace('#//[^\n]*#', '', $stripped);
        self::assertDoesNotMatchRegularExpression(
            '/->\s*flush\s*\(\s*\)/',
            $stripped,
            'MultiItemsController must NOT call the dead ->flush() API.'
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function methodScopeProvider(): iterable
    {
        yield 'inspect'      => ['inspect',      'read'];
        yield 'cleanImplode' => ['cleanImplode', 'write'];
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
            self::fail("Could not locate method body for $method in MultiItemsController.");
        }
        return $m[1];
    }
}
