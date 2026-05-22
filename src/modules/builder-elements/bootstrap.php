<?php
/**
 * builder-elements module bootstrap.
 *
 * Wave 2 Task 2.3 — read-only ElementOps + REST surface.
 * Wave 3 Task 3.4 — write operations (add/move/delete/clone/updateSettings)
 * wired through LayoutReader → ElementOps → LayoutWriter → CacheFlusher.
 *
 * Services are resolved through the plugin's own {@see Container}
 * helper rather than YOOtheme's DI-Container — see Container.php for
 * the rationale.
 *
 * @package WootsUp\BuilderMcp\Elements
 */

declare(strict_types=1);

use WootsUp\BuilderMcp\Auth\BearerVerifier;
use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Auth\KeyStore;
use WootsUp\BuilderMcp\Auth\SigningSecret;
use WootsUp\BuilderMcp\Cache\CacheFlusher;
use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\Elements\ElementsController;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;
use WootsUp\BuilderMcp\State\StateLock;
use WootsUp\BuilderMcp\Util\Container;
use WootsUp\BuilderMcp\Yootheme\YoothemeAdapter;

\add_action('rest_api_init', static function (): void {
    $reader = Container::get(
        LayoutReader::class,
        static fn (): LayoutReader => new LayoutReader(),
    );

    $writer = Container::get(
        LayoutWriter::class,
        static fn (): LayoutWriter => new LayoutWriter(
            $reader,
            Container::get(
                YoothemeAdapter::class,
                static fn (): YoothemeAdapter => new YoothemeAdapter(),
            ),
            Container::get(
                StateLock::class,
                static fn (): StateLock => new StateLock(),
            ),
        ),
    );

    $cacheFlusher = Container::get(
        CacheFlusher::class,
        static fn (): CacheFlusher => new CacheFlusher(
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

    $ops = Container::get(
        ElementOps::class,
        static fn (): ElementOps => new ElementOps($reader),
    );

    $controller = Container::get(
        ElementsController::class,
        static fn (): ElementsController => new ElementsController(
            $ops,
            $reader,
            $writer,
            $cacheFlusher,
            $verifier,
        ),
    );

    $controller->register_routes();
});

return [];
