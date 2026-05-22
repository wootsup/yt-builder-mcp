<?php
/**
 * builder-pages module bootstrap.
 *
 * Wires PageQuery (pure-PHP) + PagesController (REST surface) and hooks
 * `register_routes()` onto `rest_api_init`.
 *
 * Wave 2 Task 2.2 — read endpoints.
 * Wave 3 Task 3.7 — save/publish endpoints (write surface).
 *
 * Services are resolved through the plugin's own {@see Container}
 * helper rather than YOOtheme's DI-Container — see Container.php for
 * the rationale.
 *
 * @package WootsUp\BuilderMcp\Pages
 */

declare(strict_types=1);

use WootsUp\BuilderMcp\Auth\BearerVerifier;
use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Auth\KeyStore;
use WootsUp\BuilderMcp\Auth\SigningSecret;
use WootsUp\BuilderMcp\Cache\CacheFlusher;
use WootsUp\BuilderMcp\Pages\PageQuery;
use WootsUp\BuilderMcp\Pages\PagesController;
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

    $pageQuery = Container::get(
        PageQuery::class,
        static fn (): PageQuery => new PageQuery($reader),
    );

    $controller = Container::get(
        PagesController::class,
        static fn (): PagesController => new PagesController(
            $pageQuery,
            $reader,
            $writer,
            $cacheFlusher,
            $verifier,
        ),
    );

    $controller->register_routes();
});

return [];
