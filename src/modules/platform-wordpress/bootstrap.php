<?php
/**
 * platform-wordpress module bootstrap.
 *
 * Registers WP-Admin integration: the Tools → "YOOtheme Builder MCP"
 * settings-page where operators generate and revoke Bearer-keys.
 *
 * Services are resolved through the plugin's own {@see Container}
 * helper rather than YOOtheme's DI-Container — see Container.php for
 * the rationale.
 *
 * SettingsPage::register() only calls add_action() for admin_menu and
 * admin_post_*. Those hooks fire only in admin context anyway. We
 * hook into `admin_menu` directly (instead of init priority 1) so the
 * settings-page registers in the same tick the WP admin menu is built,
 * matching SettingsPage::register()'s own callback wiring.
 *
 * @package WootsUp\BuilderMcp\Platform\WordPress
 */

declare(strict_types=1);

use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Auth\KeyStore;
use WootsUp\BuilderMcp\Auth\SigningSecret;
use WootsUp\BuilderMcp\Platform\WordPress\SettingsPage;
use WootsUp\BuilderMcp\Util\Container;

\add_action('init', static function (): void {
    Container::get(
        SettingsPage::class,
        static fn (): SettingsPage => new SettingsPage(
            Container::get(
                KeyService::class,
                static fn (): KeyService => new KeyService(SigningSecret::ensure()),
            ),
            Container::get(
                KeyStore::class,
                static fn (): KeyStore => new KeyStore(),
            ),
        ),
    )->register();
}, 1);

return [];
