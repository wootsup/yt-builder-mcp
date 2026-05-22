<?php
/**
 * PickupController — claim-flow unit tests.
 *
 * Wave C (2026-05-22): covers the eight contract scenarios from the
 * plan-doc (sprightly-tickling-hollerith.md, "Tests > PHP"):
 *   1. happy-path (200 + token + transient deleted)
 *   2. expired (404)
 *   3. already-consumed — one-shot semantics (2× claim, 1st 200, 2nd 404)
 *   4. ip-mismatch (403, transient NOT deleted)
 *   5. ip-mismatch with ip_bound=false (200, opt-out works)
 *   6. malformed body — missing nonce (400)
 *   7. rate-limit (11th call from same IP → 429)
 *   8. nonce shape validation (too short / too long / bad chars → 404
 *      before transient lookup)
 *
 * All eight scenarios run against the in-process WP-function stubs from
 * tests/php/bootstrap.php; no WP-Testbench needed.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Rest\PickupController;
use WootsUp\BuilderMcp\Rest\PublicRestController;
use WootsUp\BuilderMcp\Storage\PickupChannel;
use WootsUp\BuilderMcp\Util\SecurityLogger;

#[CoversClass(PickupController::class)]
#[CoversClass(PublicRestController::class)]
#[CoversClass(PickupChannel::class)]
final class PickupControllerTest extends TestCase
{
    /** Valid 43-char base64url nonce (random_bytes(32) → base64url). */
    private const VALID_NONCE = 'abcdefghijklmnopqrstuvwxyz0123456789-_ABCDEF';

    private string $previousRemoteAddr = '';
    private bool $hadRemoteAddr = false;

    private string $errorLogFile = '';
    /** @var string|false */
    private $previousErrorLog = false;

    protected function setUp(): void
    {
        // Reset transient store between tests so rate-limit counters and
        // pickup-payloads don't leak across scenarios.
        $GLOBALS['ytb_test_transients'] = [];
        $GLOBALS['ytb_test_rest_routes'] = [];

        // Capture and clear REMOTE_ADDR — each test sets its own.
        if (isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
            $this->hadRemoteAddr = true;
            $this->previousRemoteAddr = $_SERVER['REMOTE_ADDR'];
        }
        unset($_SERVER['REMOTE_ADDR']);

        // Redirect error_log() so SecurityLogger emissions can be inspected.
        $tmp = tempnam(sys_get_temp_dir(), 'ytb_mcp_pickup_log_');
        $this->errorLogFile = $tmp !== false ? $tmp : '';
        $this->previousErrorLog = ini_get('error_log');
        if ($this->errorLogFile !== '') {
            ini_set('error_log', $this->errorLogFile);
        }
    }

    protected function tearDown(): void
    {
        if ($this->hadRemoteAddr) {
            $_SERVER['REMOTE_ADDR'] = $this->previousRemoteAddr;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }
        $this->hadRemoteAddr = false;
        $this->previousRemoteAddr = '';

        if ($this->previousErrorLog !== false) {
            ini_set('error_log', (string) $this->previousErrorLog);
        }
        if ($this->errorLogFile !== '' && is_file($this->errorLogFile)) {
            @unlink($this->errorLogFile);
        }
        $this->errorLogFile = '';
        $this->previousErrorLog = false;
    }

    private function logContents(): string
    {
        return $this->errorLogFile !== '' && is_file($this->errorLogFile)
            ? (string) file_get_contents($this->errorLogFile)
            : '';
    }

    // -----------------------------------------------------------------
    // 1) happy-path
    // -----------------------------------------------------------------

    public function test_claim_happy_path_returns_token_and_deletes_transient(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.4';

        $key = PickupController::PICKUP_TRANSIENT_PREFIX . self::VALID_NONCE;
        \set_transient($key, [
            'token' => 'ytb_v1_pickup_token_HAPPY',
            'site_url' => 'https://example.test',
            'ip' => '203.0.113.4',
            'ip_bound' => true,
        ], PickupController::PICKUP_TTL_SECONDS);

        $controller = new PickupController();
        $request = $this->makeRequest(['nonce' => self::VALID_NONCE]);
        $response = $controller->handle_claim($request);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('ytb_v1_pickup_token_HAPPY', $data['token']);
        self::assertSame('https://example.test', $data['site_url']);
        self::assertArrayHasKey('plugin_version', $data);

        // One-shot delete must run BEFORE return — transient gone after claim.
        self::assertFalse(\get_transient($key));
    }

    // -----------------------------------------------------------------
    // 2) expired
    // -----------------------------------------------------------------

    public function test_claim_returns_404_when_transient_missing_or_expired(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';

        // Deliberately do NOT set a transient — emulates expiry / never-existed.
        $controller = new PickupController();
        $request = $this->makeRequest(['nonce' => self::VALID_NONCE]);
        $response = $controller->handle_claim($request);

        self::assertSame(404, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('not_found', $data['error']);
    }

    // -----------------------------------------------------------------
    // 3) already-consumed — one-shot semantics
    // -----------------------------------------------------------------

    public function test_claim_is_single_use(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.6';

        $key = PickupController::PICKUP_TRANSIENT_PREFIX . self::VALID_NONCE;
        \set_transient($key, [
            'token' => 'ytb_v1_pickup_token_ONESHOT',
            'site_url' => 'https://example.test',
            'ip' => '203.0.113.6',
            'ip_bound' => true,
        ], PickupController::PICKUP_TTL_SECONDS);

        $controller = new PickupController();

        // First claim — 200, returns payload, deletes transient.
        $first = $controller->handle_claim($this->makeRequest(['nonce' => self::VALID_NONCE]));
        self::assertSame(200, $first->get_status());

        // Second claim — same nonce, same IP, but transient is gone → 404.
        $second = $controller->handle_claim($this->makeRequest(['nonce' => self::VALID_NONCE]));
        self::assertSame(404, $second->get_status());
        $data = $second->get_data();
        self::assertIsArray($data);
        self::assertSame('not_found', $data['error']);
    }

    // -----------------------------------------------------------------
    // 4) ip-mismatch (default ip_bound=true) — transient NOT consumed
    // -----------------------------------------------------------------

    public function test_claim_rejects_mismatched_ip_and_preserves_transient(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7'; // attacker IP

        $key = PickupController::PICKUP_TRANSIENT_PREFIX . self::VALID_NONCE;
        \set_transient($key, [
            'token' => 'ytb_v1_pickup_token_IP_BOUND',
            'site_url' => 'https://example.test',
            'ip' => '203.0.113.10', // legitimate wp-admin IP
            'ip_bound' => true,
        ], PickupController::PICKUP_TTL_SECONDS);

        $controller = new PickupController();
        $response = $controller->handle_claim($this->makeRequest(['nonce' => self::VALID_NONCE]));

        self::assertSame(403, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('ip_mismatch', $data['error']);

        // Transient MUST remain so the legitimate user can still pick up.
        $remaining = \get_transient($key);
        self::assertIsArray($remaining);
        self::assertSame('ytb_v1_pickup_token_IP_BOUND', $remaining['token']);
    }

    // -----------------------------------------------------------------
    // 5) ip-mismatch with ip_bound=false → opt-out works
    // -----------------------------------------------------------------

    public function test_claim_allows_mismatched_ip_when_ip_bound_is_false(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.99'; // different IP

        $key = PickupController::PICKUP_TRANSIENT_PREFIX . self::VALID_NONCE;
        \set_transient($key, [
            'token' => 'ytb_v1_pickup_token_OPTOUT',
            'site_url' => 'https://example.test',
            'ip' => '203.0.113.50',
            'ip_bound' => false, // explicit opt-out — "different machine" option
        ], PickupController::PICKUP_TTL_SECONDS);

        $controller = new PickupController();
        $response = $controller->handle_claim($this->makeRequest(['nonce' => self::VALID_NONCE]));

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('ytb_v1_pickup_token_OPTOUT', $data['token']);

        // Still single-use even with ip_bound=false.
        self::assertFalse(\get_transient($key));
    }

    // -----------------------------------------------------------------
    // 6) malformed body — missing nonce
    // -----------------------------------------------------------------

    public function test_claim_rejects_request_with_missing_nonce(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.11';

        $controller = new PickupController();
        // Empty body — no `nonce` key.
        $response = $controller->handle_claim($this->makeRequest([]));

        self::assertSame(400, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('invalid_request', $data['error']);
    }

    public function test_claim_rejects_request_with_non_string_nonce(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.12';

        $controller = new PickupController();
        // nonce present but wrong type — must still 400.
        $response = $controller->handle_claim($this->makeRequest(['nonce' => 12345]));

        self::assertSame(400, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('invalid_request', $data['error']);
    }

    // -----------------------------------------------------------------
    // 7) rate-limit — 11th call from same IP within window → 429
    // -----------------------------------------------------------------

    public function test_claim_rate_limits_after_max_attempts(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.50';

        $controller = new PickupController();

        // First 10 calls — each one fails with 404 (no transient set) but
        // they all PASS the rate-limit check and burn one slot each.
        for ($i = 1; $i <= PickupController::RATE_LIMIT_MAX_ATTEMPTS; $i++) {
            $resp = $controller->handle_claim($this->makeRequest(['nonce' => self::VALID_NONCE]));
            self::assertSame(
                404,
                $resp->get_status(),
                sprintf('Attempt %d expected 404, got %d', $i, $resp->get_status()),
            );
        }

        // 11th call — rate-limit must fire BEFORE any nonce validation.
        $response = $controller->handle_claim($this->makeRequest(['nonce' => self::VALID_NONCE]));
        self::assertSame(429, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('rate_limited', $data['error']);
        self::assertSame(PickupController::RATE_LIMIT_WINDOW_SECONDS, $data['retry_after']);
    }

    // -----------------------------------------------------------------
    // 8) nonce-shape validation — 404 before transient lookup
    // -----------------------------------------------------------------

    public function test_claim_rejects_nonce_that_is_too_short(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.20';

        $controller = new PickupController();
        $response = $controller->handle_claim($this->makeRequest(['nonce' => 'tooShort']));

        self::assertSame(404, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('not_found', $data['error']);
    }

    public function test_claim_rejects_nonce_that_is_too_long(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.21';

        // 65 chars — one over the 64-char max.
        $oversized = str_repeat('A', 65);

        $controller = new PickupController();
        $response = $controller->handle_claim($this->makeRequest(['nonce' => $oversized]));

        self::assertSame(404, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('not_found', $data['error']);
    }

    public function test_claim_rejects_nonce_with_invalid_chars(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.22';

        // 43 chars but contains chars outside [A-Za-z0-9_-].
        $bad = 'abcdefghijklmnopqrstuvwxyz0123456789+/=ABC!';
        self::assertSame(43, strlen($bad));

        $controller = new PickupController();
        $response = $controller->handle_claim($this->makeRequest(['nonce' => $bad]));

        self::assertSame(404, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('not_found', $data['error']);
    }

    public function test_nonce_shape_validation_runs_before_transient_lookup(): void
    {
        // REMOTE_ADDR set so rate-limit passes (post-SEC-IP-1 H2: empty IP
        // returns 429 not 404). No transient set. With a malformed nonce
        // PickupChannel must NEVER call get_transient for the malformed key
        // — keeps the response identical regardless of whether the
        // (malformed) key happens to collide with some legitimate transient.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.250';
        $controller = new PickupController();
        $response = $controller->handle_claim($this->makeRequest(['nonce' => 'short']));

        self::assertSame(404, $response->get_status());
        // Only the rate-limit transient counter may exist; the pickup-payload
        // key for the malformed nonce must NOT have been touched. We check
        // the exact computed key (no fuzzy prefix match — the rate-limit
        // bucket also starts with `ytb_mcp_pickup_`).
        $pickupKey = PickupController::PICKUP_TRANSIENT_PREFIX . 'short';
        self::assertArrayNotHasKey(
            $pickupKey,
            $GLOBALS['ytb_test_transients'],
            'PickupChannel::claim must not touch the transient store for malformed nonces.',
        );
    }

    // -----------------------------------------------------------------
    // H2 — SecurityLogger event emission (SEC-L-1 / SEC-L-2)
    // -----------------------------------------------------------------

    public function test_security_logger_emits_pickup_claimed_on_success(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.40';

        $key = PickupChannel::TRANSIENT_PREFIX . self::VALID_NONCE;
        \set_transient($key, [
            'token' => 'ytb_v1_pickup_token_SLOG_OK',
            'site_url' => 'https://example.test',
            'ip' => '203.0.113.40',
            'ip_bound' => true,
        ], PickupChannel::TTL_SECONDS);

        $controller = new PickupController();
        $response = $controller->handle_claim($this->makeRequest(['nonce' => self::VALID_NONCE]));

        self::assertSame(200, $response->get_status());
        $log = $this->logContents();
        self::assertStringContainsString('pickup_claimed', $log);
        self::assertStringContainsString('"http_status":200', $log);
        // Nonce MUST NEVER appear in the log — only ip_hash + http_status.
        self::assertStringNotContainsString(self::VALID_NONCE, $log);
        self::assertStringNotContainsString('ytb_v1_pickup_token_SLOG_OK', $log);
    }

    public function test_security_logger_emits_pickup_ip_mismatch_on_403(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.99';

        $key = PickupChannel::TRANSIENT_PREFIX . self::VALID_NONCE;
        \set_transient($key, [
            'token' => 'ytb_v1_pickup_token_SLOG_IPMM',
            'site_url' => 'https://example.test',
            'ip' => '203.0.113.41',
            'ip_bound' => true,
        ], PickupChannel::TTL_SECONDS);

        $controller = new PickupController();
        $response = $controller->handle_claim($this->makeRequest(['nonce' => self::VALID_NONCE]));

        self::assertSame(403, $response->get_status());
        $log = $this->logContents();
        self::assertStringContainsString('pickup_ip_mismatch', $log);
        self::assertStringContainsString('"http_status":403', $log);
        self::assertStringNotContainsString(self::VALID_NONCE, $log);
    }

    public function test_security_logger_emits_pickup_rate_limited_on_429(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.60';
        $controller = new PickupController();

        // Burn the budget (10 attempts) without writing anything to the log
        // about the actual error mode — we only want to assert the 11th call
        // emits pickup_rate_limited.
        for ($i = 1; $i <= PickupController::RATE_LIMIT_MAX_ATTEMPTS; $i++) {
            $controller->handle_claim($this->makeRequest(['nonce' => self::VALID_NONCE]));
        }

        // Truncate the captured log so the assertion only sees the 11th call.
        if ($this->errorLogFile !== '' && is_file($this->errorLogFile)) {
            file_put_contents($this->errorLogFile, '');
        }

        $response = $controller->handle_claim($this->makeRequest(['nonce' => self::VALID_NONCE]));
        self::assertSame(429, $response->get_status());

        $log = $this->logContents();
        self::assertStringContainsString('pickup_rate_limited', $log);
        self::assertStringContainsString('"http_status":429', $log);
    }

    public function test_security_logger_emits_pickup_not_found_on_404(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
        $controller = new PickupController();
        $response = $controller->handle_claim($this->makeRequest(['nonce' => self::VALID_NONCE]));

        self::assertSame(404, $response->get_status());
        $log = $this->logContents();
        self::assertStringContainsString('pickup_not_found', $log);
        self::assertStringContainsString('"http_status":404', $log);
    }

    // -----------------------------------------------------------------
    // H2 — SEC-IP-1: empty REMOTE_ADDR → rate_limited (no bypass)
    // -----------------------------------------------------------------

    public function test_empty_remote_addr_returns_rate_limited(): void
    {
        // No REMOTE_ADDR set at all — previously no-op'd the rate-limit and
        // granted unlimited budget. Now must return 429.
        unset($_SERVER['REMOTE_ADDR']);

        $controller = new PickupController();
        $response = $controller->handle_claim($this->makeRequest(['nonce' => self::VALID_NONCE]));

        self::assertSame(429, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame('rate_limited', $data['error']);
        self::assertSame(PickupController::RATE_LIMIT_WINDOW_SECONDS, $data['retry_after']);

        // SecurityLogger should still emit pickup_rate_limited even though
        // the IP is empty — ip_hash falls back to the literal 'empty' tag.
        $log = $this->logContents();
        self::assertStringContainsString('pickup_rate_limited', $log);
        self::assertStringContainsString('"ip_hash":"empty"', $log);
    }

    // -----------------------------------------------------------------
    // H3 — TEST-T-1: constants are stable contract
    // -----------------------------------------------------------------

    public function test_constants_are_stable_contract(): void
    {
        // Pin: rate-limit + nonce constants. A mutator changing these would
        // ship green without this test — security defenses depend on the values.
        self::assertSame(10, PickupController::RATE_LIMIT_MAX_ATTEMPTS);
        self::assertSame(60, PickupController::RATE_LIMIT_WINDOW_SECONDS);
        self::assertSame(300, PickupChannel::TTL_SECONDS);
        self::assertSame(32, PickupChannel::NONCE_MIN_LENGTH);
        self::assertSame(64, PickupChannel::NONCE_MAX_LENGTH);
    }

    // -----------------------------------------------------------------
    // H3 — TEST-T-2: rate-limit window-reset
    // -----------------------------------------------------------------

    public function test_rate_limit_window_resets_after_expiry(): void
    {
        $ip = '198.51.100.42';
        $_SERVER['REMOTE_ADDR'] = $ip;
        $ipHash = substr(hash('sha256', $ip), 0, 16);
        // H4 ARCH-REUSE-2: PickupController now uses RateLimiter::checkGeneric
        // with bucket-key 'pickup_rl_<ipHash>', which RateLimiter prefixes
        // with 'ytb_mcp_rate_'. Update assertion accordingly.
        $rlKey = 'ytb_mcp_rate_pickup_rl_' . $ipHash;

        $controller = new PickupController();

        // Saturate the rate-limit (10 attempts → 11th is blocked).
        for ($i = 0; $i < 10; $i++) {
            $controller->handle_claim(
                $this->makeRequest(['nonce' => 'never-matches-anything-of-correct-length-aaaaa']),
            );
        }
        $response = $controller->handle_claim(
            $this->makeRequest(['nonce' => 'still-never-matches-aaaaaaaaaaaaaaaaaaaaaaaaa']),
        );
        self::assertSame(429, $response->get_status());

        // Simulate window expiry by deleting the rate-limit transient (= what
        // would happen after RATE_LIMIT_WINDOW_SECONDS seconds in production).
        unset($GLOBALS['ytb_test_transients'][$rlKey]);

        // 11th attempt after window expiry should NO LONGER be rate-limited.
        $response = $controller->handle_claim(
            $this->makeRequest(['nonce' => 'still-never-matches-aaaaaaaaaaaaaaaaaaaaaaaaa']),
        );
        self::assertNotSame(429, $response->get_status());
    }

    // -----------------------------------------------------------------
    // H3 — TEST-T-3: register_routes uses the public permission_callback
    // -----------------------------------------------------------------

    public function test_register_routes_uses_public_permission_callback(): void
    {
        $GLOBALS['ytb_test_rest_routes'] = [];
        $GLOBALS['ytb_test_rest_route_args'] = [];

        (new PickupController())->register_routes();

        self::assertContains(
            '/yt-builder-mcp/v1/setup/pickup',
            $GLOBALS['ytb_test_rest_routes'],
        );
        $args = $GLOBALS['ytb_test_rest_route_args']['/yt-builder-mcp/v1/setup/pickup'] ?? null;
        self::assertNotNull($args);
        self::assertSame('POST', $args['methods']);
        // Must be the public-permission sentinel — anything else would gate
        // the nonce-exchange endpoint behind admin auth, breaking the AI-client
        // flow. The controller passes the literal string '__return_true' which
        // WordPress treats as a callable.
        self::assertSame('__return_true', $args['permission_callback']);
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function makeRequest(array $body): \WP_REST_Request
    {
        $req = new \WP_REST_Request('POST', '/yt-builder-mcp/v1/setup/pickup');
        foreach ($body as $key => $value) {
            $req->set_param((string) $key, $value);
        }
        return $req;
    }
}
