<?php
/**
 * builder-inspection module bootstrap.
 *
 * Wave 2 Task 2.4 — element-type discovery (read-only). Wave 3 fills the
 * stub `schema()` with real introspection.
 *
 * Services are resolved through the plugin's own {@see Container}
 * helper rather than YOOtheme's DI-Container — see Container.php for
 * the rationale.
 *
 * @package WootsUp\BuilderMcp\Inspection
 */

declare(strict_types=1);

use WootsUp\BuilderMcp\Auth\BearerVerifier;
use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Auth\KeyStore;
use WootsUp\BuilderMcp\Auth\SigningSecret;
use WootsUp\BuilderMcp\Inspection\InspectionController;
use WootsUp\BuilderMcp\Inspection\Inspector;
use WootsUp\BuilderMcp\Util\Container;
use WootsUp\BuilderMcp\Yootheme\YoothemeAdapter;

\add_action('rest_api_init', static function (): void {
    $inspector = Container::get(
        Inspector::class,
        static fn (): Inspector => new Inspector(
            Container::get(
                YoothemeAdapter::class,
                static fn (): YoothemeAdapter => new YoothemeAdapter(),
            ),
        ),
    );

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
        InspectionController::class,
        static fn (): InspectionController => new InspectionController(
            $inspector,
            $verifier,
        ),
    );

    $controller->register_routes();
});

return [];
