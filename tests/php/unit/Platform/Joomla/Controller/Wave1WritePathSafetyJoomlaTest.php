<?php
/**
 * Wave-1 write-path safety contract — Joomla mirror BEHAVIOURAL pins.
 *
 * Mirrors the WP-side behavioural integration suite
 * {@see \WootsUp\BuilderMcp\Tests\Integration\Elements\Wave1WritePathSafetyTest}.
 * Replaces the previous source-grep variant: source-grep pins CAN'T detect
 * a refactor that breaks the contract while leaving the literals intact —
 * the `live-green != tested-green` anti-pattern flagged by the R8-A4 audit
 * (2026-05-27).
 *
 * Each test executes the production controller end-to-end (only the
 * dispatch() auth + YT-bootstrap pre-flight is bypassed — those have their
 * own dedicated coverage) and asserts on the EMITTED response envelope.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Controller
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Controller;

use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaKeyStore;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaSigningSecret;
use WootsUp\BuilderMcp\State\StateLockInterface;

// The com_ytbmcp ElementsController is Joomla-autoloaded at request-dispatch
// time in production; in tests we require it here so reflection can
// resolve the FQCN.
if (!\class_exists('\WootsUp\Component\Ytbmcp\Api\Controller\ElementsController', false)) {
    require_once \dirname(__DIR__, 6)
        . '/src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/ElementsController.php';
}

// Stub `\YOOtheme\app` so YtBootstrapper::ensure() short-circuits (the
// production dispatch() requires YT to be loaded). The function only needs
// to EXIST — its return value is irrelevant for write-path controller tests
// because the dispatch() flow only checks `function_exists` to gate the
// 503 "yt_not_bootstrapped" envelope.
if (!\function_exists('\YOOtheme\app')) {
    eval('namespace YOOtheme; function app(?string $id = null) { return null; }');
}

final class Wave1WritePathSafetyJoomlaTest extends TestCase
{
    /** FQCN of the runtime-autoloaded api-controller. */
    private const FQCN = '\WootsUp\Component\Ytbmcp\Api\Controller\ElementsController';

    /**
     * Per-test layout: section (with headline child), image, empty grid.
     * Matches the WP-side suite so behaviour comparisons stay 1:1.
     *
     * @var array<string, mixed>
     */
    private array $state = [];

    /** Bearer token issued for the current test (write-scope). */
    private string $bearerToken = '';

    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();
        \WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaLayoutStorage::resetForTests();
        \MockJoomlaDatabase::$tables = [];
        \MockJoomlaDatabase::$executedQueries = [];
        unset($_SERVER['HTTP_IF_MATCH'], $_SERVER['HTTP_AUTHORIZATION']);

        // Seeded layout: tpl with section(headline), image, grid (empty).
        $this->state = [
            'templates' => [
                'tpl' => [
                    'name' => 'Home',
                    'layout' => [
                        'type' => 'layout',
                        'children' => [
                            [
                                'type' => 'section',
                                'props' => ['style' => 'default'],
                                'children' => [
                                    ['type' => 'headline', 'props' => ['content' => 'Hello']],
                                ],
                            ],
                            ['type' => 'image', 'props' => ['source' => 'cat.jpg']],
                            // For H-11: a multi-item container.
                            ['type' => 'grid', 'props' => [], 'children' => []],
                        ],
                    ],
                ],
            ],
        ];

        // Closure override: serve all loadResult() calls from in-memory state
        // by query shape. Reads pass by reference so the live $this->state
        // mutation (via commitWriteToState() after a mutating call) is visible
        // on subsequent reads — proves persistence end-to-end.
        $stateRef = &$this->state;
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = function ($query) use (&$stateRef): mixed {
            if (!$query instanceof \MockJoomlaQuery) {
                return null;
            }
            $selects = \implode(' ', $query->selects ?? []);
            $binds   = $query->binds ?? [];

            if (\str_contains($selects, 'extension_id')) {
                return 99;
            }
            if (\str_contains($selects, 'custom_data')) {
                // Real-DB equivalent: every SELECT custom_data sees the
                // most-recent committed UPDATE. Find the latest UPDATE
                // #__extensions query with a `:data` bind and return THAT
                // (the writer's verify-read path depends on this) — fall
                // through to the in-memory $stateRef when no write has
                // happened yet.
                $queries = \MockJoomlaDatabase::$executedQueries;
                for ($i = \count($queries) - 1; $i >= 0; $i--) {
                    $q = $queries[$i];
                    if (
                        $q instanceof \MockJoomlaQuery
                        && $q->update !== ''
                        && \str_contains($q->update, 'extensions')
                        && isset($q->binds[':data'])
                    ) {
                        return (string) $q->binds[':data'];
                    }
                }
                return \json_encode($stateRef, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }
            if (isset($binds[':key'])) {
                $key = (string) $binds[':key'];
                $tables = \MockJoomlaDatabase::$tables;
                foreach ($tables as $rows) {
                    if (\array_key_exists($key, $rows)) {
                        return $rows[$key];
                    }
                }
            }
            return null;
        };

        // Set up REAL auth stack via the production JoomlaSigningSecret +
        // JoomlaKeyStore (these write into MockJoomlaDatabase::$tables which
        // our closure reads back). The bearer is then verifiable by the
        // production BearerVerifier built inside AbstractApiController::dispatch().
        $this->bearerToken = $this->mintWriteScopeBearer();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->bearerToken;
    }

    /**
     * Mint a write-scoped bearer token via the REAL production auth stack
     * (JoomlaSigningSecret + KeyService + JoomlaKeyStore). The token's kid
     * is registered in the keystore, so the bearer-verify path in
     * AbstractApiController::dispatch() accepts it.
     */
    private function mintWriteScopeBearer(): string
    {
        $secret = JoomlaSigningSecret::ensure();
        $keyService = new KeyService($secret);
        $keyStore = new JoomlaKeyStore();
        $kid = \bin2hex(\random_bytes(8));

        $token = $keyService->generate($kid, [
            'scope' => 'write',
            'exp'   => \time() + 3600,
        ]);
        $keyStore->register($kid, [
            'label'      => 'wave1-write-path-safety-test',
            'scope'      => 'write',
            'created_at' => \time(),
            'expires_at' => null,
            'revoked_at' => null,
        ]);
        return $token;
    }

    protected function tearDown(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = false;
        \MockJoomlaDatabase::$loadResultOverride = null;
        \MockJoomlaDatabase::$tables = [];
        \MockJoomlaDatabase::$executedQueries = [];
        \WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaLayoutStorage::resetForTests();
        \MockJoomlaFactory::reset();
        unset($_SERVER['HTTP_IF_MATCH'], $_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * Sync in-memory $state with the last UPDATE #__extensions write
     * recorded in MockJoomlaDatabase::$executedQueries — call AFTER any
     * controller mutation so subsequent reads see the new state.
     */
    private function commitWriteToState(): void
    {
        foreach (\array_reverse(\MockJoomlaDatabase::$executedQueries) as $q) {
            if ($q instanceof \MockJoomlaQuery && isset($q->binds[':data'])) {
                $decoded = \json_decode((string) $q->binds[':data'], true);
                if (\is_array($decoded)) {
                    $this->state = $decoded;
                    return;
                }
            }
        }
    }

    /**
     * Invoke a production controller method against an isolated controller
     * instance whose dispatch() is short-circuited via reflection
     * (auth + YT-bootstrap pre-flight has its own coverage).
     *
     * @param array<string, mixed>  $body         Request body (json-decoded shape)
     * @param array<string, string> $pathParams   Route captures (:templateId, :path)
     * @param string|null           $ifMatch      If-Match header value (null = absent)
     * @return array{body: array<string, mixed>, status: int}
     */
    private function runHandler(
        string $method,
        array $body = [],
        array $pathParams = [],
        ?string $ifMatch = null,
    ): array {
        if ($ifMatch === null) {
            unset($_SERVER['HTTP_IF_MATCH']);
        } else {
            $_SERVER['HTTP_IF_MATCH'] = $ifMatch;
        }

        $fqcn = \ltrim(self::FQCN, '\\');
        /** @var object $controller */
        $controller = (new \ReflectionClass($fqcn))->newInstanceWithoutConstructor();

        // Inject the test Input bag into the protected `$this->input` slot
        // so production helpers (pathParam, requestBody, pathParamRaw,
        // queryString) see our test payload.
        $reflectionInput = new \ReflectionProperty($fqcn, 'input');
        $reflectionInput->setValue($controller, new TestJoomlaInput($pathParams, $body));

        // Capture the echoed JSON body (JoomlaJsonResponse::send echoes).
        \ob_start();
        try {
            $controller->{$method}();
        } catch (\Throwable $e) {
            \ob_end_clean();
            throw $e;
        }
        $rawBody = (string) \ob_get_clean();

        $decoded = \json_decode($rawBody, true);
        self::assertIsArray($decoded, "Controller {$method}() must echo JSON. Got: {$rawBody}");

        return [
            'body'   => $decoded,
            'status' => (int) (\MockJoomlaFactory::getApplication()->headers['status'] ?? 0),
        ];
    }

    private function currentEtag(): string
    {
        return (new \WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutReader())->etag();
    }

    // ------------------------------------------------------------------
    // C-2 — POST add requires If-Match (428 when missing).
    // ------------------------------------------------------------------

    public function test_C2_add_returns_428_when_if_match_missing(): void
    {
        $result = $this->runHandler(
            'add',
            body: ['parent_path' => '', 'element_type' => 'divider'],
            pathParams: ['templateId' => 'tpl'],
            ifMatch: null,
        );

        self::assertSame(428, $result['status']);
        self::assertSame('yootheme_builder_mcp.if_match_required', $result['body']['code']);
        self::assertArrayHasKey(
            'current_etag',
            $result['body']['data'],
            '428 payload must carry current_etag so callers can retry without an extra read round-trip.'
        );
    }

    public function test_C2_add_proceeds_with_valid_if_match(): void
    {
        $etag = $this->currentEtag();
        $result = $this->runHandler(
            'add',
            body: ['parent_path' => '', 'element_type' => 'divider'],
            pathParams: ['templateId' => 'tpl'],
            ifMatch: $etag,
        );

        self::assertSame(200, $result['status']);
        self::assertSame('tpl', $result['body']['template_id']);
        self::assertArrayHasKey('element_path', $result['body']);
    }

    public function test_C2_add_accepts_wildcard_if_match(): void
    {
        // RFC-7232 §3.1 wildcard — must keep the escape-hatch alive.
        $result = $this->runHandler(
            'add',
            body: ['parent_path' => '', 'element_type' => 'divider'],
            pathParams: ['templateId' => 'tpl'],
            ifMatch: '*',
        );

        self::assertSame(200, $result['status']);
    }

    // ------------------------------------------------------------------
    // C-3 — DELETE preview/confirm two-call protocol.
    // ------------------------------------------------------------------

    public function test_C3_delete_without_confirm_returns_preview_without_mutating(): void
    {
        $etag = $this->currentEtag();
        $result = $this->runHandler(
            'delete',
            body: [], // no confirm
            pathParams: [
                'templateId' => 'tpl',
                'path' => 'templates/tpl/layout/children/1', // image
            ],
            ifMatch: $etag,
        );

        self::assertSame(200, $result['status']);
        self::assertTrue($result['body']['requires_confirm'] ?? false);
        self::assertArrayHasKey('preview', $result['body']);
        self::assertSame('image', $result['body']['preview']['element_type']);
        self::assertSame('/templates/tpl/layout/children/1', $result['body']['preview']['element_path']);
        self::assertArrayHasKey('child_count', $result['body']['preview']);

        // Critical invariant: preview MUST NOT mutate state.
        self::assertSame(
            'image',
            $this->state['templates']['tpl']['layout']['children'][1]['type'],
            'C-3 preview path must not delete — image must still be at index 1.'
        );
        self::assertCount(3, $this->state['templates']['tpl']['layout']['children']);
    }

    public function test_C3_delete_with_confirm_actually_deletes(): void
    {
        $etag = $this->currentEtag();
        $result = $this->runHandler(
            'delete',
            body: ['confirm' => true],
            pathParams: [
                'templateId' => 'tpl',
                'path' => 'templates/tpl/layout/children/1', // image
            ],
            ifMatch: $etag,
        );

        self::assertSame(200, $result['status']);
        self::assertArrayNotHasKey('requires_confirm', $result['body']);
        self::assertArrayNotHasKey('preview', $result['body']);
        self::assertSame('/templates/tpl/layout/children/1', $result['body']['element_path']);

        // Sync in-memory state with the storage UPDATE the writer emitted.
        $this->commitWriteToState();

        self::assertCount(
            2,
            $this->state['templates']['tpl']['layout']['children'],
            'After confirm:true the image must be gone — section + grid remain.'
        );
        self::assertSame('section', $this->state['templates']['tpl']['layout']['children'][0]['type']);
        self::assertSame('grid', $this->state['templates']['tpl']['layout']['children'][1]['type']);
    }

    // ------------------------------------------------------------------
    // H-10 — move returns the post-move element_path.
    //
    // Cross-platform parity: the WP-side suite pins the same envelope on
    // the same shared ElementOps engine. This Joomla pin proves the
    // Joomla controller's path-extraction + response-merge agree.
    // ------------------------------------------------------------------

    public function test_H10_move_into_different_parent_returns_target_parent_relative_path(): void
    {
        $etag = $this->currentEtag();
        $result = $this->runHandler(
            'move',
            body: [
                'to_parent_path' => '/templates/tpl/layout/children/2', // grid
                'to_index'       => 0,
            ],
            pathParams: [
                'templateId' => 'tpl',
                // Headline inside the section.
                'path' => 'templates/tpl/layout/children/0/children/0',
            ],
            ifMatch: $etag,
        );

        self::assertSame(200, $result['status']);
        self::assertSame(
            '/templates/tpl/layout/children/2/children/0',
            $result['body']['element_path'],
            'move() must emit target_parent_path/children/N, not the pre-move address.'
        );
    }

    public function test_H10_move_same_parent_shift_returns_post_move_path(): void
    {
        // Regression pin for the same-parent shift case (where removal
        // shifts every subsequent index down by 1).
        $etag = $this->currentEtag();
        $result = $this->runHandler(
            'move',
            body: [
                'to_parent_path' => '/templates/tpl/layout',
                'to_index'       => 2,
            ],
            pathParams: [
                'templateId' => 'tpl',
                'path' => 'templates/tpl/layout/children/0', // section
            ],
            ifMatch: $etag,
        );

        self::assertSame(200, $result['status']);
        self::assertSame(
            '/templates/tpl/layout/children/1',
            $result['body']['element_path'],
            'Same-parent move from 0 to 2 lands at adjusted index 1 (removal shifts indices).'
        );
    }

    // ------------------------------------------------------------------
    // H-11 — add validates parent/child container/item compatibility.
    // ------------------------------------------------------------------

    public function test_H11_add_text_inside_grid_returns_400_with_required_item_type(): void
    {
        $etag = $this->currentEtag();
        $result = $this->runHandler(
            'add',
            body: [
                'parent_path'  => '/templates/tpl/layout/children/2', // grid
                'element_type' => 'headline',
            ],
            pathParams: ['templateId' => 'tpl'],
            ifMatch: $etag,
        );

        self::assertSame(400, $result['status']);
        self::assertSame('yootheme_builder_mcp.elements.invalid_parent_child', $result['body']['code']);
        self::assertSame('grid', $result['body']['data']['parent_type']);
        self::assertSame('grid_item', $result['body']['data']['expected_child_type']);
        self::assertSame('headline', $result['body']['data']['actual_child_type']);
        self::assertArrayHasKey('hint', $result['body']['data']);
    }

    public function test_H11_add_grid_item_inside_grid_proceeds(): void
    {
        // Happy path pin: canonical pairing is accepted.
        $etag = $this->currentEtag();
        $result = $this->runHandler(
            'add',
            body: [
                'parent_path'  => '/templates/tpl/layout/children/2', // grid
                'element_type' => 'grid_item',
            ],
            pathParams: ['templateId' => 'tpl'],
            ifMatch: $etag,
        );

        self::assertSame(200, $result['status'], 'Adding grid_item into a grid must succeed.');
    }

    public function test_H11_add_headline_into_section_proceeds(): void
    {
        // Section is NOT in ItemContainerMap — children are unrestricted.
        $etag = $this->currentEtag();
        $result = $this->runHandler(
            'add',
            body: [
                'parent_path'  => '/templates/tpl/layout/children/0', // section
                'element_type' => 'headline',
                'props'        => ['content' => 'Another headline'],
            ],
            pathParams: ['templateId' => 'tpl'],
            ifMatch: $etag,
        );

        self::assertSame(200, $result['status']);
    }

    // ------------------------------------------------------------------
    // H-12 — StateLock acquire-timeout → 409 (not 500).
    //
    // The controller's H-12 mapping logic lives in the private static
    // `isLockTimeoutException()` helper + the `mutate()` catch. Since
    // ElementsController is `final` we cannot subclass to inject a custom
    // StateLockInterface. We pin H-12 in two complementary layers:
    //
    //   (a) BEHAVIOURAL — the writer's exception SHAPE.
    //       Drive JoomlaLayoutWriter::writeTemplate() directly with an
    //       always-fail StateLock; assert the thrown RuntimeException
    //       carries the canonical "Could not acquire lock for template"
    //       prefix that the controller's isLockTimeoutException() detector
    //       matches. If the writer ever stops throwing this exact message
    //       prefix, the controller's H-12 path quietly stops firing — this
    //       behavioural pin catches that drift.
    //
    //   (b) STRUCTURAL — the controller's mapping logic.
    //       The controller source MUST classify a RuntimeException with
    //       this prefix as 409 + `retry_after_ms`. The structural pin
    //       below asserts the canonical literals are present in the
    //       controller source. This is a weaker pin than (a) — but
    //       since the production code is `final`, end-to-end injection
    //       is not feasible without a production change.
    // ------------------------------------------------------------------

    public function test_H12_writer_throws_canonical_lock_timeout_prefix(): void
    {
        // (a) Behavioural pin on the writer side: prove that an always-
        // fail StateLockInterface causes JoomlaLayoutWriter::writeTemplate
        // to throw a RuntimeException whose message matches the prefix
        // ElementsController::isLockTimeoutException() uses for detection.
        $failingLock = new class () implements StateLockInterface {
            public function acquireForTemplate(string $templateId, int $timeoutMs = 5000): bool
            {
                return false;
            }
            public function releaseForTemplate(string $templateId): void
            {
            }
            public function withTemplateLock(string $templateId, callable $callback, int $timeoutMs = 5000): mixed
            {
                throw new \RuntimeException(\sprintf(
                    'Could not acquire lock for template "%s" within %dms.',
                    $templateId,
                    $timeoutMs,
                ));
            }
        };

        $writer = new \WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutWriter(
            new \WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutReader(),
            new \WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaLayoutStorage(),
            $failingLock,
        );

        try {
            $writer->writeTemplate('tpl', $this->state['templates']['tpl']);
            self::fail('writer must throw RuntimeException when lock cannot be acquired.');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith(
                'Could not acquire lock for template',
                $e->getMessage(),
                'The thrown RuntimeException message MUST start with this exact prefix — ' .
                'ElementsController::isLockTimeoutException() uses str_starts_with on it to ' .
                'map lock-contention to 409 instead of 500. Drift here silently breaks H-12.',
            );
        }
    }

    public function test_H12_controller_maps_lock_timeout_to_409_envelope(): void
    {
        // (b) Structural pin on the controller mapping. Asserted on the
        // source because the controller is `final` and inline-constructs
        // its writer — no behavioural injection possible without changing
        // production. The (a) pin above guarantees the message shape the
        // controller relies on; this pin guarantees the controller acts
        // on that shape.
        $src = (string) \file_get_contents(\dirname(__DIR__, 6)
            . '/src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/ElementsController.php');

        // The detector helper.
        self::assertStringContainsString(
            "str_starts_with(\$e->getMessage(), 'Could not acquire lock for template')",
            $src,
            'Controller must detect lock-timeout via the canonical message prefix.',
        );
        // The 409 envelope members.
        self::assertStringContainsString('concurrent_write_in_progress', $src);
        self::assertStringContainsString("'retry_after_ms' => 250", $src);
        self::assertStringContainsString('409', $src);
    }
}

// =========================================================================
// Test-only Input bag — fills the role of `$this->input` so the production
// controller's pathParam() / pathParamRaw() / requestBody() / queryString()
// all read from our test payload without needing a real Joomla request.
// =========================================================================

/**
 * @internal
 */
final class TestJoomlaInput
{
    /** @var array<string, string> Route captures (:templateId, :path) */
    private array $captures;
    /** @var array<string, mixed> Request body */
    private array $body;
    public TestJoomlaInputPostBag $post;

    /**
     * @param array<string, string> $captures
     * @param array<string, mixed>  $body
     */
    public function __construct(array $captures, array $body)
    {
        $this->captures = $captures;
        $this->body = $body;
        $this->post = new TestJoomlaInputPostBag($captures);
    }

    public function getString(string $name, string $default = ''): string
    {
        $v = $this->captures[$name] ?? $default;
        return \is_string($v) ? $v : $default;
    }

    /**
     * Mirrors Joomla\Input\Input::get($name, $default, $filter).
     * The controller uses `'raw'` for the :path capture so pointer slashes
     * survive the filter.
     */
    public function get(string $name, string $default = '', string $filter = 'string'): string
    {
        $v = $this->captures[$name] ?? $default;
        return \is_string($v) ? $v : $default;
    }

    public function getRaw(): string
    {
        return (string) \json_encode($this->body);
    }
}

/**
 * @internal
 */
final class TestJoomlaInputPostBag
{
    /** @var array<string, string> */
    private array $captures;

    /**
     * @param array<string, string> $captures
     */
    public function __construct(array $captures)
    {
        $this->captures = $captures;
    }

    public function get(string $name, string $default = '', string $filter = 'string'): string
    {
        $v = $this->captures[$name] ?? $default;
        return \is_string($v) ? $v : $default;
    }
}
