<?php
/**
 * builder-source-binding module bootstrap.
 *
 * Wave 2 Task 2.5 (read-only) — source registry + element binding inspection.
 * Wave 3 Task 3.6 — PUT/DELETE /binding endpoints mutate `props.source`
 * through LayoutWriter + CacheFlusher.
 *
 * Services are resolved through the plugin's own {@see Container}
 * helper rather than YOOtheme's DI-Container — see Container.php for
 * the rationale.
 *
 * @package WootsUp\BuilderMcp\SourceBinding
 */

declare(strict_types=1);

use WootsUp\BuilderMcp\Auth\BearerVerifier;
use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Auth\KeyStore;
use WootsUp\BuilderMcp\Auth\SigningSecret;
use WootsUp\BuilderMcp\Cache\CacheFlusher;
use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\SourceBinding\SourceRegistry;
use WootsUp\BuilderMcp\SourceBinding\SourcesController;
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

    $registry = Container::get(
        SourceRegistry::class,
        static fn (): SourceRegistry => new SourceRegistry(
            Container::get(
                YoothemeAdapter::class,
                static fn (): YoothemeAdapter => new YoothemeAdapter(),
            ),
        ),
    );

    $ops = Container::get(
        ElementOps::class,
        static fn (): ElementOps => new ElementOps($reader),
    );

    $controller = Container::get(
        SourcesController::class,
        static fn (): SourcesController => new SourcesController(
            $registry,
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
