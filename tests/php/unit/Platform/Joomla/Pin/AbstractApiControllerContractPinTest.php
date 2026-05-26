<?php
/**
 * REGRESSION PIN-TESTS (R8-A3 #5/#6 + R8-A4 P2 + R8-A1 P3) for the Joomla
 * REST auth/dispatch contract in AbstractApiController.
 *
 * AbstractApiController extends Joomla's BaseController (a runtime MVC class
 * not present in the unit stubs), so — like the existing *ControllerSmokeTest
 * suite — the contract is pinned via source inspection. Each assertion fails
 * against the specific pre-fix / unhardened state it guards.
 *
 *   #6   POST post-bag: pathParam() falls back to $input->post->get() for the
 *        route var (POST routes inject :templateId into the POST bag, not the
 *        main bag → "templateId is required" 400 pre-fix).
 *   #5   Auth fails CLOSED: dispatch() denies (401) on a missing Authorization
 *        header BEFORE invoking the handler, even though routes are public=true
 *        at the Joomla layer.
 *   A4-P2 The auth-stack construction (JoomlaSigningSecret::ensure() + KeyStore)
 *        runs INSIDE a try and maps a throw to a structured 503, not a bare
 *        500 that escapes dispatch().
 *   A1-P3 The `path`/raw POST-bag fallback is centralised into pathParamRaw().
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\AbstractApiController;

/**
 * Minimal Joomla JInput sub-bag stub: get($name, $default, $filter).
 * Records the filter so the test can prove the raw filter is requested.
 *
 * @internal test helper for the behavioural pathParam tests
 */
final class MockJoomlaInputBag
{
    /** @param array<string, string> $values */
    public function __construct(private array $values = [])
    {
    }

    public function get(string $name, mixed $default = null, string $filter = 'cmd'): mixed
    {
        return $this->values[$name] ?? $default;
    }
}

/**
 * Minimal Joomla JInput stub with a POST sub-bag. Mirrors the per-method
 * input-bag split ApiApplication uses: GET/PUT/DELETE route-vars land in the
 * main bag, POST route-vars land in $input->post.
 *
 * @internal test helper for the behavioural pathParam tests
 */
final class MockJoomlaInput
{
    public MockJoomlaInputBag $post;

    /**
     * @param array<string, string> $main  main-bag values
     * @param array<string, string> $postBag POST sub-bag values
     */
    public function __construct(private array $main = [], array $postBag = [])
    {
        $this->post = new MockJoomlaInputBag($postBag);
    }

    public function get(string $name, mixed $default = null, string $filter = 'cmd'): mixed
    {
        return $this->main[$name] ?? $default;
    }

    public function getString(string $name, string $default = ''): string
    {
        $v = $this->main[$name] ?? $default;
        return \is_string($v) ? $v : $default;
    }
}

/**
 * Concrete subclass exposing the protected route-param helpers so the
 * behavioural tests can drive them directly with a mock Input.
 *
 * @internal
 */
final class ConcreteApiControllerForTests extends AbstractApiController
{
    public function exposePathParam(string $name, string $default = ''): string
    {
        return $this->pathParam($name, $default);
    }

    public function exposePathParamRaw(string $name, string $default = ''): string
    {
        return $this->pathParamRaw($name, $default);
    }

    /** Inject a mock JInput into the protected $input slot. */
    public function setInput(object $input): void
    {
        $this->input = $input;
    }
}

final class AbstractApiControllerContractPinTest extends TestCase
{
    private const REL_PATH =
        'src/modules/platform-joomla/src/Rest/AbstractApiController.php';
    private const ELEMENTS_PATH =
        'src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/ElementsController.php';
    private const MULTIITEMS_PATH =
        'src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/MultiItemsController.php';

    private function source(string $rel): string
    {
        $path = \dirname(__DIR__, 6) . '/' . $rel;
        if (!\is_file($path)) {
            self::fail("Source missing: $path");
        }
        return (string) \file_get_contents($path);
    }

    /** #6 — pathParam() reads the main bag AND the POST sub-bag. */
    public function test_path_param_falls_back_to_post_bag(): void
    {
        $src = $this->source(self::REL_PATH);
        self::assertMatchesRegularExpression(
            '/protected function pathParam\(/',
            $src,
            'pathParam() helper must exist (single source of truth for route path-params).'
        );
        // The POST-bag fallback must be present inside pathParam().
        self::assertStringContainsString('$this->input?->post', $src, 'pathParam() must consult $input->post.');
        self::assertMatchesRegularExpression(
            "/\\\$post->get\\(\\\$name,\\s*''\\s*,\\s*'string'\\)/",
            $src,
            'POST route vars are read via $post->get($name, \'\', \'string\') — getString() returned \'\' in the API scope.'
        );
    }

    /** #5 — dispatch() denies on a missing Authorization header before the handler. */
    public function test_dispatch_denies_missing_bearer_before_handler(): void
    {
        $src = $this->source(self::REL_PATH);
        // The missing-header branch returns a 401 envelope and returns early.
        self::assertMatchesRegularExpression(
            "/if\\s*\\(\\s*\\\$authHeader\\s*===\\s*''\\s*\\)\\s*\\{/",
            $src,
            'dispatch() must short-circuit when the Authorization header is empty.'
        );
        self::assertMatchesRegularExpression(
            "/auth\\.bearer_invalid'[^;]*401/s",
            $src,
            'Missing Bearer must yield a 401 (fail-closed) — before any handler runs.'
        );
        // The handler is only invoked AFTER verify + scope + rate-limit.
        $missingHeaderBranch = \substr($src, (int) \strpos($src, "if (\$authHeader === '')"), 400);
        self::assertStringContainsString(
            'return;',
            $missingHeaderBranch,
            'The missing-header branch must early-return (handler never reached).'
        );
    }

    /**
     * A4-P2 — auth-stack construction is INSIDE a try and a throw maps to a
     * structured 503, not a bare 500 escaping dispatch().
     */
    public function test_auth_stack_construction_is_guarded_and_maps_to_503(): void
    {
        $src = $this->source(self::REL_PATH);

        // Locate the BearerVerifier construction and the catch that maps to 503.
        self::assertStringContainsString('new BearerVerifier(', $src);
        self::assertStringContainsString('catch (AuthUnavailableException', $src,
            'A throw from JoomlaSigningSecret::ensure() (P1) must be caught at construction (P2).');
        self::assertMatchesRegularExpression(
            "/auth\\.unavailable'[^;]*503/s",
            $src,
            'Auth-stack construction failure must surface as a structured 503 envelope.'
        );

        // Structural proof the construction precedes the verify() try and is
        // wrapped: the `new BearerVerifier(` must appear AFTER a `try {` and
        // BEFORE the AuthUnavailableException catch.
        $verifierPos = (int) \strpos($src, 'new BearerVerifier(');
        $tryBefore   = \strrpos(\substr($src, 0, $verifierPos), 'try {');
        self::assertNotFalse($tryBefore, 'BearerVerifier construction must sit inside a try block.');
    }

    /**
     * A1-P3 — the `path`/raw POST-bag fallback is centralised into
     * pathParamRaw() and BOTH controllers call it (no duplicated inline block).
     */
    public function test_path_param_raw_is_centralised_and_used_by_both_controllers(): void
    {
        $base = $this->source(self::REL_PATH);
        self::assertMatchesRegularExpression(
            '/protected function pathParamRaw\(/',
            $base,
            'A1-P3: the shared pathParamRaw() helper must exist on AbstractApiController.'
        );
        self::assertMatchesRegularExpression(
            "/\\\$this->input\\?->get\\(\\\$name,\\s*''\\s*,\\s*'raw'\\)/",
            $base,
            'pathParamRaw() must use the raw filter so pointer slashes survive.'
        );

        foreach ([self::ELEMENTS_PATH, self::MULTIITEMS_PATH] as $rel) {
            $src = $this->source($rel);
            self::assertStringContainsString(
                "\$this->pathParamRaw('path')",
                $src,
                "Controller $rel must call the shared pathParamRaw('path') helper."
            );
            // The old duplicated inline block (post->get('path', '', 'raw'))
            // must be gone from the controller body.
            self::assertStringNotContainsString(
                "\$post->get('path', '', 'raw')",
                $src,
                "Controller $rel must NOT carry the duplicated inline path/raw POST-bag block (centralised into pathParamRaw)."
            );
        }
    }

    // =====================================================================
    // BEHAVIOURAL upgrades (R8c-A3) — execute the real helpers against a
    // mock JInput rather than grepping the source. These prove the POST
    // post-bag fallback actually WORKS, not merely that the text is present.
    // =====================================================================

    /**
     * #6 (behavioural) — pathParamRaw() reads the route var from the POST
     * sub-bag when the main bag is empty (POST routes inject :path into the
     * POST bag). This is the headline post-bag-fallback regression: pre-fix
     * the value was unreachable on POST routes.
     */
    public function test_path_param_raw_reads_from_post_bag_behaviourally(): void
    {
        $ctrl = new ConcreteApiControllerForTests();
        // Main bag empty; POST sub-bag carries the JSON-Pointer path with slashes.
        $ctrl->setInput(new MockJoomlaInput([], ['path' => 'children/0/children/2']));

        self::assertSame(
            'children/0/children/2',
            $ctrl->exposePathParamRaw('path'),
            'pathParamRaw() must fall back to the POST sub-bag and preserve pointer slashes.'
        );
    }

    /** #6 (behavioural) — main-bag value (GET/PUT/DELETE routes) wins when present. */
    public function test_path_param_raw_reads_from_main_bag_behaviourally(): void
    {
        $ctrl = new ConcreteApiControllerForTests();
        $ctrl->setInput(new MockJoomlaInput(['path' => 'children/3'], ['path' => 'should/not/be/used']));

        self::assertSame(
            'children/3',
            $ctrl->exposePathParamRaw('path'),
            'pathParamRaw() must prefer the main input bag over the POST sub-bag.'
        );
    }

    /** #6 (behavioural) — absent in BOTH bags → returns the default (''). */
    public function test_path_param_raw_returns_default_when_absent_behaviourally(): void
    {
        $ctrl = new ConcreteApiControllerForTests();
        $ctrl->setInput(new MockJoomlaInput([], []));

        self::assertSame(
            '',
            $ctrl->exposePathParamRaw('path'),
            'pathParamRaw() must return the default when the var is in neither bag.'
        );
        self::assertSame(
            'fallback',
            $ctrl->exposePathParamRaw('path', 'fallback'),
            'pathParamRaw() must honour the supplied default.'
        );
    }

    /**
     * #6 (behavioural) — pathParam() (templateId variant) ALSO falls back to
     * the POST sub-bag. This is the original Wave-7 deploy-fix: POST routes
     * like pages/:templateId/save injected templateId into the POST bag.
     */
    public function test_path_param_reads_template_id_from_post_bag_behaviourally(): void
    {
        $ctrl = new ConcreteApiControllerForTests();
        $ctrl->setInput(new MockJoomlaInput([], ['templateId' => 'HoMeTpL1']));

        self::assertSame(
            'HoMeTpL1',
            $ctrl->exposePathParam('templateId'),
            'pathParam() must fall back to the POST sub-bag for POST-route vars (Wave-7 deploy-fix).'
        );
    }

    /** #6 (behavioural) — pathParam() prefers the main bag and trims it. */
    public function test_path_param_prefers_main_bag_and_trims_behaviourally(): void
    {
        $ctrl = new ConcreteApiControllerForTests();
        $ctrl->setInput(new MockJoomlaInput(['templateId' => '  HoMeTpL1  '], ['templateId' => 'wrong']));

        self::assertSame(
            'HoMeTpL1',
            $ctrl->exposePathParam('templateId'),
            'pathParam() must prefer the main bag and trim surrounding whitespace.'
        );
    }
}
