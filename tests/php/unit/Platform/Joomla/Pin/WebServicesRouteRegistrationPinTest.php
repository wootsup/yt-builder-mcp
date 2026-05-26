<?php
/**
 * BEHAVIOURAL REGRESSION TESTS (R8-A3 #3 / #5 / #6c → R8c-A3 hardening):
 * plg_webservices_ytbmcp route registration + controller-task resolution
 * + public=true semantics.
 *
 * R8c-A3 upgrade: these seams were previously guarded by SOURCE-STRING
 * INSPECTION (regex over the plugin's text), which passes for
 * present-but-broken code. They are now guarded by GENUINE BEHAVIOURAL
 * EXECUTION:
 *
 *   1. The real {@see \WootsUp\Plugin\WebServices\Ytbmcp\Extension\Ytbmcp}
 *      Extension is require_once'd (it is Joomla-runtime-autoloaded, NOT in
 *      the composer PSR-4 map) and INSTANTIATED. We fire onBeforeApiRoute()
 *      with a real Event carrying a capturing ApiRouter stub, then assert on
 *      the captured Route OBJECTS — not the source text. A malformed route
 *      table (wrong count, wrong prefix, missing `public`/`component`) now
 *      FAILS even though the right strings are present somewhere in the file.
 *
 *   2. Controller-task resolution is verified via REFLECTION: each route's
 *      `controller.task` default token is split into a real com_ytbmcp
 *      controller CLASS + dispatched METHOD; we assert the class exists AND
 *      exposes the method. This makes the headline regression
 *      (`binding.* → BindingController`) fail GENUINELY — BindingController
 *      does not exist, so the reflection lookup fails. No string grep can be
 *      tricked.
 *
 *   3. public=true is asserted on the captured Route objects' defaults
 *      (behavioural), not via regex.
 *
 * Pre-fix states each assertion catches:
 *   #3  routes registered from a SYSTEM-group plugin (never fired on
 *       onBeforeApiRoute → every route 404) / wrong v1 prefix → wrong/zero
 *       captured Route patterns.
 *   #6c binding routes pointed at a `binding.*` token resolving to a
 *       non-existent BindingController → reflection class-existence fails.
 *   #5  public!=true → captured defaults assertion fails.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use Joomla\CMS\Router\ApiRouter;
use Joomla\Event\Event;
use Joomla\Router\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WootsUp\Plugin\WebServices\Ytbmcp\Extension\Ytbmcp;

final class WebServicesRouteRegistrationPinTest extends TestCase
{
    private const REPO_REL =
        'src/packaging/joomla/extensions/plg_webservices_ytbmcp/src/Extension/Ytbmcp.php';

    private const CONTROLLER_DIR =
        'src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller';

    private const CONTROLLER_NS = 'WootsUp\\Component\\Ytbmcp\\Api\\Controller\\';

    /** Expected HTTP-method split across the canonical 31-route set. */
    private const EXPECTED_TOTAL  = 31;
    private const EXPECTED_GET     = 17;
    private const EXPECTED_POST    = 8;
    private const EXPECTED_PUT     = 3;
    private const EXPECTED_DELETE  = 3;

    private static function repoRoot(): string
    {
        return \dirname(__DIR__, 6);
    }

    /**
     * The webservices Extension + the com_ytbmcp controllers are
     * Joomla-runtime-autoloaded (NOT in composer PSR-4), so pull them in
     * explicitly. _JEXEC is defined by the joomla-bootstrap; the Router /
     * Event / BaseController stubs are loaded with the CMS stubs.
     */
    public static function setUpBeforeClass(): void
    {
        $plugin = self::repoRoot() . '/' . self::REPO_REL;
        if (!\is_file($plugin)) {
            self::fail("Webservices plugin source missing: $plugin");
        }
        require_once $plugin;

        // Require every controller so reflection-based task resolution can
        // see the real classes. (BindingController deliberately absent.)
        foreach (\glob(self::repoRoot() . '/' . self::CONTROLLER_DIR . '/*Controller.php') ?: [] as $file) {
            require_once $file;
        }
    }

    /**
     * Instantiate the real Extension, fire onBeforeApiRoute with a capturing
     * ApiRouter, and return the captured Route objects.
     *
     * @return array<int, Route>
     */
    private function captureRoutes(): array
    {
        $plugin = new Ytbmcp((object) [], []);
        $router = new ApiRouter();
        $event  = new Event('onBeforeApiRoute', ['subject' => $router]);

        $plugin->onBeforeApiRoute($event);

        self::assertSame(
            1,
            $router->addRoutesCalls,
            'onBeforeApiRoute() must call ApiRouter::addRoutes() exactly once.'
        );

        return $router->captured;
    }

    /** #3 — the listener subscribes to the webservices-group event. */
    public function test_subscribes_to_on_before_api_route(): void
    {
        $events = Ytbmcp::getSubscribedEvents();
        self::assertArrayHasKey(
            'onBeforeApiRoute',
            $events,
            'Route registration MUST happen on onBeforeApiRoute (a webservices-group event). '
            . 'Pre-fix, a system-group listener never fired → every route 404ed.'
        );
        self::assertSame('onBeforeApiRoute', $events['onBeforeApiRoute']);
        self::assertInstanceOf(
            \Joomla\Event\SubscriberInterface::class,
            new Ytbmcp((object) [], []),
            'Plugin must implement SubscriberInterface to register the event.'
        );
    }

    /**
     * #3 — exactly 31 routes are registered, with the expected HTTP-method
     * split (behavioural: counted from the captured Route OBJECTS, so a
     * malformed/short route table fails here, unlike a regex).
     */
    public function test_registers_exactly_thirty_one_routes_with_method_split(): void
    {
        $routes = $this->captureRoutes();
        self::assertCount(
            self::EXPECTED_TOTAL,
            $routes,
            'Expected the canonical 31-route Web Services table.'
        );

        $byMethod = ['GET' => 0, 'POST' => 0, 'PUT' => 0, 'DELETE' => 0];
        foreach ($routes as $route) {
            $methods = $route->getMethods();
            self::assertCount(1, $methods, 'Each route declares exactly one HTTP method.');
            $m = $methods[0];
            self::assertArrayHasKey($m, $byMethod, "Unexpected HTTP method '$m'.");
            $byMethod[$m]++;
        }

        self::assertSame(self::EXPECTED_GET, $byMethod['GET'], 'GET route count mismatch.');
        self::assertSame(self::EXPECTED_POST, $byMethod['POST'], 'POST route count mismatch.');
        self::assertSame(self::EXPECTED_PUT, $byMethod['PUT'], 'PUT route count mismatch.');
        self::assertSame(self::EXPECTED_DELETE, $byMethod['DELETE'], 'DELETE route count mismatch.');
    }

    /**
     * #3 — every captured route's pattern mounts under the canonical
     * v1/yt-builder-mcp/ prefix. A wrong prefix was a deploy-fix root cause.
     */
    public function test_every_route_uses_canonical_v1_prefix(): void
    {
        $routes = $this->captureRoutes();
        self::assertNotEmpty($routes);
        foreach ($routes as $route) {
            self::assertStringStartsWith(
                'v1/yt-builder-mcp/',
                $route->getPattern(),
                "Route '{$route->getPattern()}' must mount under the canonical v1/yt-builder-mcp/ prefix."
            );
        }
    }

    /**
     * #5 — every captured route's defaults carry component=com_ytbmcp and
     * public=true (behavioural: read off the Route object, not regexed).
     */
    public function test_every_route_default_is_public_true_and_com_ytbmcp(): void
    {
        $routes = $this->captureRoutes();
        self::assertNotEmpty($routes);
        foreach ($routes as $route) {
            $defaults = $route->getDefaults();
            self::assertArrayHasKey('component', $defaults, "Route '{$route->getPattern()}' must set a component default.");
            self::assertSame(
                'com_ytbmcp',
                $defaults['component'],
                "Route '{$route->getPattern()}' must dispatch into com_ytbmcp."
            );
            self::assertArrayHasKey('public', $defaults, "Route '{$route->getPattern()}' must set a 'public' default.");
            self::assertTrue(
                $defaults['public'],
                "Route '{$route->getPattern()}' must be public=true at the Joomla layer "
                . '(public=false → ApiApplication throws AuthenticationFailed BEFORE the controller runs). '
                . 'Auth is enforced inside AbstractApiController::dispatch().'
            );
        }
    }

    /**
     * #6c — controller-task resolution (BEHAVIOURAL via reflection). EVERY
     * captured route's `controller.task` token MUST resolve to a real
     * com_ytbmcp controller CLASS exposing the dispatched METHOD. This makes
     * the `binding.* → BindingController` regression fail genuinely:
     * BindingController does not exist, so class-existence fails — no string
     * grep can be tricked into a false green.
     */
    public function test_every_route_task_resolves_to_a_real_controller_method(): void
    {
        $routes = $this->captureRoutes();
        self::assertNotEmpty($routes);

        foreach ($routes as $route) {
            $task = $route->getController();
            self::assertIsString($task, "Route '{$route->getPattern()}' must carry a string controller-task token.");
            self::assertStringContainsString('.', $task, "Controller-task '$task' must be 'prefix.method'.");

            [$prefix, $method] = \explode('.', $task, 2);
            $class = self::CONTROLLER_NS . \ucfirst($prefix) . 'Controller';

            self::assertTrue(
                \class_exists($class),
                "Route '{$route->getPattern()}' task '$task' must resolve to an existing controller class ($class). "
                . 'A non-existent controller (e.g. BindingController) would 404.'
            );

            $ref = new \ReflectionClass($class);
            self::assertTrue(
                $ref->hasMethod($method),
                "Controller $class must expose the dispatched method '$method()' (task '$task')."
            );
            $refMethod = $ref->getMethod($method);
            self::assertTrue(
                $refMethod->isPublic(),
                "Controller method $class::$method() must be public to be dispatchable."
            );
        }
    }

    /**
     * #6c headline mappings, asserted BEHAVIOURALLY: the named route is
     * present in the captured table AND maps to the expected controller-task
     * token AND that token resolves to a real controller method.
     *
     * @param string $httpMethod  HTTP verb
     * @param string $pattern     full canonical route pattern
     * @param string $task        the controller.task token it must map to
     */
    #[DataProvider('routeTaskProvider')]
    public function test_headline_route_maps_to_expected_controller_task(string $httpMethod, string $pattern, string $task): void
    {
        $routes = $this->captureRoutes();
        $match  = null;
        foreach ($routes as $route) {
            if ($route->getPattern() === $pattern && \in_array($httpMethod, $route->getMethods(), true)) {
                $match = $route;
                break;
            }
        }
        self::assertNotNull($match, "Expected a $httpMethod route for '$pattern'.");
        self::assertSame(
            $task,
            $match->getController(),
            "Route $httpMethod '$pattern' must map to controller.task '$task'."
        );

        [$prefix, $method] = \explode('.', $task, 2);
        $class = self::CONTROLLER_NS . \ucfirst($prefix) . 'Controller';
        self::assertTrue(\class_exists($class), "Headline task '$task' must resolve to a real controller ($class).");
        self::assertTrue(\method_exists($class, $method), "Headline task '$task' must resolve to $class::$method().");
    }

    /** @return iterable<string, array{0:string,1:string,2:string}> */
    public static function routeTaskProvider(): iterable
    {
        // #6c headline mappings (method, route pattern, controller.task).
        yield 'identity → health.identity'      => ['GET',    'v1/yt-builder-mcp/identity', 'health.identity'];
        yield 'health → health.get'             => ['GET',    'v1/yt-builder-mcp/health', 'health.get'];
        yield 'sources list → sources.list'     => ['GET',    'v1/yt-builder-mcp/sources', 'sources.list'];
        yield 'binding GET → sources.get'       => ['GET',    'v1/yt-builder-mcp/pages/:templateId/elements/:path/binding', 'sources.get'];
        yield 'binding PUT → sources.put'       => ['PUT',    'v1/yt-builder-mcp/pages/:templateId/elements/:path/binding', 'sources.put'];
        yield 'binding DELETE → sources.delete' => ['DELETE', 'v1/yt-builder-mcp/pages/:templateId/elements/:path/binding', 'sources.delete'];
    }

    /**
     * #6c — guard against re-introducing a binding.* controller token.
     * BEHAVIOURAL: no captured route may carry a `binding.*` task, AND a
     * BindingController must genuinely not exist (so any such token would
     * 404). This is asserted against the live captured table + reflection,
     * not a source grep.
     */
    public function test_no_binding_controller_task_and_binding_controller_absent(): void
    {
        $routes = $this->captureRoutes();
        foreach ($routes as $route) {
            $task = (string) $route->getController();
            self::assertFalse(
                \str_starts_with($task, 'binding.'),
                "Route '{$route->getPattern()}' must NOT target a binding.* controller task. "
                . 'Binding routes resolve to SourcesController via the sources.* token.'
            );
        }
        self::assertFalse(
            \class_exists(self::CONTROLLER_NS . 'BindingController'),
            'BindingController must not exist — binding routes resolve to SourcesController. '
            . 'A binding.* task would 404 against this missing controller.'
        );
    }

    /**
     * F-103 regression — every route rule must be GROUP-FREE.
     *
     * Background: Joomla's `\Joomla\Router\Route::buildRegexAndVarList`
     * (libraries/vendor/joomla/router/src/Route.php) wraps each rule in
     * `'(' . $rule . ')'` itself. If WE wrap our rules too (e.g.
     * `'path' => '(.+)'`), the compiled regex becomes `((.+))` —
     * double-capture. PCRE numbers groups left-to-right; the second route
     * variable's captured value gets bound to the FIRST variable's INNER
     * group, so `:path` was silently bound to the `:templateId` value.
     *
     * Live symptom (2026-05-26 audit): every `/pages/:templateId/elements/:path/...`
     * request returned `Pointer "/I99YS8Ii" is not within template "I99YS8Ii"`
     * because `pathParamRaw('path')` resolved to the templateId string.
     *
     * Pre-fix state: pin-fails because the offending rules contain `(.+)`
     * (or any other group). Post-fix: every rule string has zero capturing
     * groups (the leading `(` immediately followed by `?` for non-capturing
     * `(?:...)` is allowed; but bare `(...)` is not).
     */
    public function test_every_route_rule_is_capture_group_free_f103(): void
    {
        $routes = $this->captureRoutes();
        self::assertNotEmpty($routes);

        foreach ($routes as $route) {
            $rules = $route->getRules();
            foreach ($rules as $varName => $ruleRegex) {
                self::assertIsString($ruleRegex, "Route '{$route->getPattern()}' rule for ':$varName' must be a string.");
                /** @var string $ruleRegex */
                // Detect any UNESCAPED `(` that does NOT start a non-capturing
                // group `(?...)`. Walk char-by-char so escapes are honoured —
                // a literal `\(` is fine, only the BARE `(` that opens a
                // capture group is forbidden.
                $hasCaptureGroup = false;
                $len = strlen($ruleRegex);
                for ($i = 0; $i < $len; $i++) {
                    $ch = $ruleRegex[$i];
                    if ($ch === '\\') {
                        // Skip the escaped char.
                        $i++;
                        continue;
                    }
                    if ($ch === '(') {
                        // `(?...)` is non-capturing / lookahead etc. — fine.
                        if (($ruleRegex[$i + 1] ?? '') === '?') {
                            continue;
                        }
                        $hasCaptureGroup = true;
                        break;
                    }
                }

                self::assertFalse(
                    $hasCaptureGroup,
                    "F-103 regression — route rule for ':$varName' in '{$route->getPattern()}' "
                    . "must NOT contain a bare `(...)` capture group. Got: '$ruleRegex'. "
                    . 'Joomla\Router\Route already wraps each rule in `(...)`. '
                    . 'A double-wrap shifts PCRE group indices and binds the wrong vars '
                    . '(see test docblock + commit message).'
                );
            }
        }
    }
}
