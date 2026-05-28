<?php
/**
 * rest-bridge module bootstrap.
 *
 * Registers REST controllers in namespace `yt-builder-mcp/v1` and
 * wires their `register_routes()` handlers onto the WordPress
 * `rest_api_init` action.
 *
 * Wave 1 ships only HealthController (unauthenticated GET /health).
 * Wave 2 will add pages/elements/sources/inspection controllers via the
 * same pattern.
 *
 * Services are resolved through the plugin's own {@see Container}
 * helper rather than YOOtheme's DI-Container — see Container.php for
 * the rationale (YT4 ParameterResolver cannot auto-resolve untyped
 * `$container` factory parameters).
 *
 * @package WootsUp\BuilderMcp\Rest
 */

declare(strict_types=1);

use WootsUp\BuilderMcp\Auth\BearerVerifier;
use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Auth\KeyStore;
use WootsUp\BuilderMcp\Auth\SigningSecret;
use WootsUp\BuilderMcp\Rest\HealthController;
use WootsUp\BuilderMcp\Rest\PickupController;
use WootsUp\BuilderMcp\Rest\RateLimitHeadersFilter;
use WootsUp\BuilderMcp\Rest\WwwAuthenticateFilter;
use WootsUp\BuilderMcp\Util\Container;

\add_action('rest_api_init', static function (): void {
    // Health is intentionally unauthenticated; verifier is optional but
    // resolved here so other modules share the same singleton instance.
    $verifier = Container::get(
        BearerVerifier::class,
        static fn (): BearerVerifier => new BearerVerifier(
            Container::get(
                KeyService::class,
                static fn (): KeyService => new KeyService(SigningSecret::ensure()),
            ),
            Container::get(
                KeyStore::class,
                static fn (): KeyStore => new KeyStore(),
            ),
        ),
    );

    $controller = Container::get(
        HealthController::class,
        static fn (): HealthController => new HealthController($verifier),
    );

    $controller->register_routes();

    // Wave C — Setup-Wizard pickup endpoint. Public by design; the nonce IS
    // the credential (one-shot, IP-bound, 300 s TTL, 10/min/IP rate-limit).
    $pickup = Container::get(
        PickupController::class,
        static fn (): PickupController => new PickupController(),
    );
    $pickup->register_routes();
});

// R2.12 — RFC-6750 WWW-Authenticate header injection on 401/403.
WwwAuthenticateFilter::install();

// Wave-1 Fix C-4 — HTTP/1.1 §7.1.3 Retry-After header injection on 429.
RateLimitHeadersFilter::install();

return [];
