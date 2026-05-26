<?php
/**
 * A3 STRUCTURAL (the headline A3 finding, 2026-05-25) — BEHAVIOURAL execution
 * of the pre-auth /health security boundary on the Joomla api-application
 * HealthController.
 *
 * Until now the anonymous-vs-Bearer field split was pinned ONLY by regex on
 * the method body ({@see HealthControllerSmokeTest}). A regex pin cannot catch
 * a runtime regression where the SHAPE of the payload is right in source but
 * the GATE mis-fires (e.g. hasValidBearer() returns true on a bad token, or
 * the augmentation leaks into the anonymous branch at runtime). This test
 * ACTUALLY RUNS the controller's get() path:
 *
 *   - MISSING Bearer  → the echoed JSON body is EXACTLY {plugin_version,status}
 *                       (no host-fingerprint, no yootheme_loaded leak).
 *   - VALID Bearer    → the body is augmented with the Bearer-gated fields
 *                       (cms, cms_version, php_version, site_url,
 *                       yootheme_loaded, storage_type, storage_target,
 *                       schema_version, available_endpoints, docs).
 *   - INVALID Bearer  → falls back to the anonymous minimal payload (a bad
 *                       token must NOT unlock augmentation — the security
 *                       invariant the regex pin can't prove).
 *
 * The api-controller is runtime-autoloaded by Joomla (not PSR-4), so we
 * require_once the file and inject a real BearerVerifier into the controller's
 * memoised static slot via reflection. We drive a real KeyService/JoomlaKeyStore
 * (in-memory DB-backed) so the token verify is the REAL verify path — no stub
 * of the auth contract.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Controller
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Controller;

use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Auth\BearerVerifier;
use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaKeyStore;

final class HealthControllerPayloadBehaviourTest extends TestCase
{
    /** FQCN of the runtime-autoloaded api-controller. */
    private const FQCN = '\WootsUp\Component\Ytbmcp\Api\Controller\HealthController';

    private function controllerPath(): string
    {
        return \dirname(__DIR__, 6)
            . '/src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/HealthController.php';
    }

    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();

        // Require the runtime-autoloaded controller once (guarded — re-running
        // the suite must not redeclare it).
        if (!\class_exists(self::FQCN, false)) {
            require_once $this->controllerPath();
        }

        // Clean any cross-test Authorization header + verifier memo.
        unset(
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
            $_SERVER['HTTP_X_AUTHORIZATION']
        );
        $this->resetVerifier();
    }

    protected function tearDown(): void
    {
        unset(
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
            $_SERVER['HTTP_X_AUTHORIZATION']
        );
        $this->resetVerifier();
        \MockJoomlaFactory::reset();
    }

    /** Wipe the controller's memoised static verifier between cases. */
    private function resetVerifier(): void
    {
        $fqcn = \ltrim(self::FQCN, '\\');
        if (\class_exists($fqcn, false) && \method_exists($fqcn, 'resetVerifierForTests')) {
            $fqcn::resetVerifierForTests();
        }
    }

    /**
     * Build a real verifier + a valid bearer token (in-memory DB-backed
     * KeyService + JoomlaKeyStore — the REAL verify path), and inject the
     * verifier into the controller's memoised static slot so get() uses it
     * (rather than constructing one off JoomlaSigningSecret::ensure()).
     *
     * @return string the valid bearer token (without the "Bearer " prefix)
     */
    private function injectVerifierAndIssueToken(string $scope = 'read'): string
    {
        $secret     = \bin2hex(\random_bytes(32));
        $keyService = new KeyService($secret);
        $keyStore   = new JoomlaKeyStore();
        $kid        = \bin2hex(\random_bytes(8));

        $token = $keyService->generate($kid, [
            'scope' => $scope,
            'exp'   => \time() + 3600,
        ]);
        $keyStore->register($kid, [
            'label'      => 'test',
            'scope'      => $scope,
            'created_at' => \time(),
            'expires_at' => null,
            'revoked_at' => null,
        ]);

        $verifier = new BearerVerifier($keyService, $keyStore);

        $ref = new \ReflectionProperty(\ltrim(self::FQCN, '\\'), 'verifier');
        $ref->setAccessible(true);
        $ref->setValue(null, $verifier);

        return $token;
    }

    /**
     * Run the controller's get() and capture the echoed JSON body
     * (JoomlaJsonResponse::send echoes — that is the api-application contract).
     *
     * @return array<string, mixed> decoded payload
     */
    private function runGetAndDecode(): array
    {
        $fqcn = \ltrim(self::FQCN, '\\');
        /** @var object $controller */
        $controller = new $fqcn();

        \ob_start();
        $controller->get();
        $body = (string) \ob_get_clean();

        $decoded = \json_decode($body, true);
        self::assertIsArray($decoded, 'get() must echo a JSON object body. Got: ' . $body);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    // ── MISSING Bearer → minimal anonymous payload ────────────────────────

    public function test_missing_bearer_yields_exactly_plugin_version_and_status(): void
    {
        // No Authorization header at all.
        $payload = $this->runGetAndDecode();

        self::assertSame(
            ['plugin_version', 'status'],
            \array_keys($payload),
            'anonymous /health body must be EXACTLY {plugin_version, status} at runtime.'
        );
        self::assertSame('ok', $payload['status']);
        // The pre-auth fingerprint-leak invariant — proven at runtime, not by regex.
        self::assertArrayNotHasKey('yootheme_loaded', $payload);
        self::assertArrayNotHasKey('cms_version', $payload);
        self::assertArrayNotHasKey('php_version', $payload);
        self::assertArrayNotHasKey('site_url', $payload);
        // F-Frontend-URL (2026-05-25): home_url is bearer-gated alongside
        // site_url — anonymous callers must NOT see it (host-fingerprint
        // tier parity with site_url).
        self::assertArrayNotHasKey('home_url', $payload);
        self::assertArrayNotHasKey('available_endpoints', $payload);
    }

    // ── VALID Bearer → augmented payload ──────────────────────────────────

    public function test_valid_bearer_augments_payload_with_gated_fields(): void
    {
        $token = $this->injectVerifierAndIssueToken('read');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        $payload = $this->runGetAndDecode();

        // Anonymous fields still present …
        self::assertSame('ok', $payload['status']);
        self::assertArrayHasKey('plugin_version', $payload);

        // … plus the Bearer-gated augmentation, asserted on ACTUAL emitted values.
        self::assertSame('joomla', $payload['cms'] ?? null, 'augmented payload must declare cms=joomla.');
        self::assertArrayHasKey('cms_version', $payload);
        self::assertArrayHasKey('php_version', $payload);
        self::assertArrayHasKey('site_url', $payload);
        // F-Frontend-URL (2026-05-25): home_url parity with WP-side payload.
        // The MCP TS HEALTH_OUTPUT_SCHEMA declares both keys; cross-platform
        // agents asking "what is the front-end URL?" must get the same
        // shape regardless of CMS. On Joomla site_url == home_url because
        // there is no admin/front URL split (Uri::root() returns both).
        self::assertArrayHasKey('home_url', $payload, 'home_url must be surfaced for WP-parity (F-Frontend-URL 2026-05-25).');
        self::assertSame($payload['site_url'], $payload['home_url'], 'site_url + home_url collapse to Uri::root() on Joomla.');
        self::assertArrayHasKey('yootheme_loaded', $payload, 'yootheme_loaded must surface behind a valid Bearer.');
        self::assertIsBool($payload['yootheme_loaded']);
        self::assertSame('joomla_extension_custom_data', $payload['storage_type'] ?? null);
        self::assertSame('yootheme', $payload['storage_target'] ?? null);
        self::assertSame(1, $payload['schema_version'] ?? null);
        self::assertArrayHasKey('available_endpoints', $payload);
        self::assertIsArray($payload['available_endpoints']);
        self::assertSame(
            \count($payload['available_endpoints']),
            $payload['available_endpoints_count'] ?? null,
            'available_endpoints_count must equal the actual endpoint list size.'
        );
        self::assertArrayHasKey('docs', $payload);
    }

    // ── INVALID Bearer → falls back to minimal (the security invariant) ───

    public function test_invalid_bearer_does_not_unlock_augmentation(): void
    {
        // Inject a real verifier (issues a valid token) but present a DIFFERENT,
        // garbage token — the real verify path must reject it, so the
        // augmentation must stay locked.
        $this->injectVerifierAndIssueToken('read');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ytb_not-a-real-token.deadbeef';

        $payload = $this->runGetAndDecode();

        self::assertSame(
            ['plugin_version', 'status'],
            \array_keys($payload),
            'an INVALID Bearer must fall back to the minimal anonymous payload (no augmentation).'
        );
        self::assertArrayNotHasKey('yootheme_loaded', $payload);
    }

    /**
     * The 200 status is emitted via JoomlaJsonResponse::send → app->setHeader
     * ('status', '200'). Assert the controller set it on the (mock) app, so the
     * behavioural path covers the response-status contract too.
     */
    public function test_get_emits_200_status_header(): void
    {
        $this->runGetAndDecode();

        $app = \MockJoomlaFactory::getApplication();
        self::assertSame(
            '200',
            $app->headers['status'] ?? null,
            'get() must emit HTTP 200 via JoomlaJsonResponse::send.'
        );
        self::assertStringContainsString(
            'application/json',
            $app->headers['Content-Type'] ?? '',
            'get() must emit a JSON Content-Type.'
        );
    }
}
