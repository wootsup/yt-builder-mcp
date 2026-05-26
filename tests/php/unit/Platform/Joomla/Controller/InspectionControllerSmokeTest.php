<?php
/**
 * InspectionController smoke-test — structural & dispatch contracts.
 *
 * Round-4 audit A3 P1. See {@see PagesControllerSmokeTest} for rationale.
 * InspectionController is read-only (listTypes + getSchema) so no
 * ETag / cache-flush surfaces apply; the dispatch-scope + auth-stack
 * pins are the only contract surfaces.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Controller
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InspectionControllerSmokeTest extends TestCase
{
    private const REL_PATH = 'src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/InspectionController.php';

    public function test_controller_file_exists(): void
    {
        self::assertFileExists($this->controllerPath());
    }

    public function test_extends_abstract_api_controller(): void
    {
        self::assertMatchesRegularExpression(
            '/final\s+class\s+InspectionController\s+extends\s+AbstractApiController\b/',
            $this->controllerSource(),
            'InspectionController must extend AbstractApiController (auth-stack pin).'
        );
    }

    #[DataProvider('methodScopeProvider')]
    public function test_method_dispatches_with_correct_scope(string $method, string $expectedScope): void
    {
        self::assertStringContainsString(
            "\$this->dispatch('$expectedScope'",
            $this->methodBody($method),
            "InspectionController::$method() must dispatch with scope '$expectedScope'."
        );
    }

    public function test_no_write_surface_so_no_etag_middleware_required(): void
    {
        // InspectionController is read-only; we explicitly assert it
        // does NOT use JoomlaEtagMiddleware (verifying the negative
        // surfaces a regression where a write-surface accidentally
        // lands here without proper optimistic-lock).
        $src = $this->controllerSource();
        self::assertStringNotContainsString(
            "\$this->dispatch('write'",
            $src,
            'InspectionController is read-only by design — no write-dispatch site allowed.'
        );
        self::assertStringNotContainsString(
            "\$this->dispatch('admin'",
            $src,
            'InspectionController is read-only by design — no admin-dispatch site allowed.'
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function methodScopeProvider(): iterable
    {
        yield 'listTypes' => ['listTypes', 'read'];
        yield 'getSchema' => ['getSchema', 'read'];
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
            self::fail("Could not locate method body for $method in InspectionController.");
        }
        return $m[1];
    }
}
