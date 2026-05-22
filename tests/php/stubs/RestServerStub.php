<?php
/**
 * RestServerStub — minimal WP_REST_Server replacement for unit tests.
 *
 * Loaded by tests/php/bootstrap.php so HealthControllerTest can assert on
 * the `available_endpoints` payload without spinning up WP-Testbench.
 *
 * Tests populate `$GLOBALS['ytb_test_rest_routes']` with a list<string> of
 * route paths; get_routes() reshapes that into the
 * `[route => routeArgs]` map the real server returns.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Stub;

final class RestServerStub
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function get_routes(): array
    {
        $routes = $GLOBALS['ytb_test_rest_routes'] ?? [];
        if (!is_array($routes)) {
            return [];
        }
        $out = [];
        foreach ($routes as $route) {
            $out[(string) $route] = [];
        }
        return $out;
    }
}
