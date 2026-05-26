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
 * register the bootstrap on `init` priority 1 so SettingsPage::register()
 * runs early enough to hook `admin_menu` before WP builds the admin
 * menu — same effective ordering as a direct `admin_menu` hook, with
 * the bootstrap-stage flexibility init priority 1 gives us for the
 * other admin services wired in this file.
 *
 * @package WootsUp\BuilderMcp\Platform\WordPress
 */

declare(strict_types=1);

use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Auth\KeyStore;
use WootsUp\BuilderMcp\Auth\SigningSecret;
use WootsUp\BuilderMcp\Platform\WordPress\PluginUpdater;
use WootsUp\BuilderMcp\Platform\WordPress\SettingsPage;
use WootsUp\BuilderMcp\Util\Container;

// Joomla-port guard: this bootstrap is WordPress-only — no-op when the
// host is Joomla (or any non-WP environment). Detection via
// `function_exists('add_action')` is reliable because the WP `add_action`
// global is only present after WP core boots; Joomla does not define it.
if (!\function_exists('add_action') || !\defined('ABSPATH')) {
    return [];
}

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

// W9-T10: self-hosted auto-updater (update-parity with the Joomla feed).
// Only wired in admin / cron context — the update transient is never built on
// front-end requests, so registering the filters there would be dead weight.
// `is_admin()` covers wp-admin + AJAX; `wp_doing_cron()` covers the WP-Cron
// auto-update sweep that actually triggers background updates.
if ((\function_exists('is_admin') && \is_admin())
    || (\function_exists('wp_doing_cron') && \wp_doing_cron())
) {
    Container::get(
        PluginUpdater::class,
        static fn (): PluginUpdater => new PluginUpdater(
            \plugin_basename(YTB_MCP_FILE),
            \dirname(\plugin_basename(YTB_MCP_FILE)),
            YTB_MCP_VERSION,
        ),
    )->register();
}

return [];
