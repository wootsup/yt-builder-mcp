<?php
/**
 * HealthController — payload-only test.
 *
 * Wave 1 Task 1.6. Wave 6 Fix 11: anonymous payload no longer exposes
 * `php_version` or full `available_endpoints` — those tier into the
 * authenticated payload.
 *
 * Wave 6 Round-2 Fix R2.13: anonymous payload further reduced to ONLY
 * `plugin_version` + `status`. Every other field (WP/YT version, storage
 * target, endpoint count, schema_version) requires a valid bearer.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Rest\HealthController;
use WootsUp\BuilderMcp\Rest\PublicRestController;
use WootsUp\BuilderMcp\Rest\RestController;

#[CoversClass(HealthController::class)]
#[CoversClass(PublicRestController::class)]
#[CoversClass(RestController::class)]
final class HealthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_rest_routes'] = [];
    }

    public function test_anonymous_payload_is_minimal(): void
    {
        $controller = new HealthController();
        $payload = $controller->payload();

        // Wave-6 Round-2 R2.13: anonymous payload is ONLY plugin_version +
        // generic status. Everything else (host-fingerprint, schema_version,
        // endpoint listing) requires a valid bearer.
        self::assertArrayHasKey('plugin_version', $payload);
        self::assertArrayHasKey('status', $payload);
        self::assertSame('ok', $payload['status']);

        // Tier-reduction: anonymous callers must NOT see any of these.
        self::assertArrayNotHasKey('yootheme_version', $payload);
        self::assertArrayNotHasKey('wp_version', $payload);
        self::assertArrayNotHasKey('storage_type', $payload);
        self::assertArrayNotHasKey('storage_target', $payload);
        self::assertArrayNotHasKey('yootheme_loaded', $payload);
        self::assertArrayNotHasKey('available_endpoints_count', $payload);
        self::assertArrayNotHasKey('available_endpoints', $payload);
        self::assertArrayNotHasKey('php_version', $payload);
        self::assertArrayNotHasKey('schema_version', $payload);
    }

    public function test_anonymous_payload_has_exactly_two_fields(): void
    {
        // L4-tier-reduction surface check: the anonymous payload's key-set
        // must be exactly {plugin_version, status} — no implicit drift.
        $controller = new HealthController();
        $payload = $controller->payload();
        self::assertSame(['plugin_version', 'status'], array_keys($payload));
    }

    public function test_payload_reports_plugin_version_constant(): void
    {
        $controller = new HealthController();
        $payload = $controller->payload();

        self::assertSame(YTB_MCP_VERSION, $payload['plugin_version']);
    }

    public function test_namespace_constant_matches_design_doc(): void
    {
        // Design-Doc Sektion 7 contractually fixes this; any change here is
        // a breaking change for downstream MCP clients.
        self::assertSame('yt-builder-mcp/v1', RestController::NAMESPACE);
        self::assertSame('yt-builder-mcp/v1', PublicRestController::NAMESPACE);
    }

    /**
     * F-09 fix (Maria-Audit 2026-05-22): the authenticated payload must
     * carry `yooessentials_version`. The value is null in the test
     * environment (no companion plugin loaded) — what we pin here is
     * the presence of the key, so the field is reliably surfaced and
     * MCP clients/wizards can render an "n/a" badge instead of
     * silently omitting the row.
     */
    public function test_authenticated_payload_exposes_essentials_version_key(): void
    {
        $bundle = \WootsUp\BuilderMcp\Tests\TestVerifierFactory::verifierWithKey('read');
        $controller = new HealthController($bundle['verifier']);
        $req = new \WP_REST_Request('GET', '/');
        $req->set_header('Authorization', 'Bearer ' . $bundle['token']);
        $payload = $controller->payload($req);

        self::assertArrayHasKey('yooessentials_version', $payload);
        self::assertArrayHasKey('yootheme_version', $payload);
    }
}
