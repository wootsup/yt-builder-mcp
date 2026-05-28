<?php
/**
 * Plugin Name: YT Builder MCP for YOOtheme Pro (unofficial)
 * Plugin URI: https://github.com/wootsup/yt-builder-mcp
 * Description: Drive your page builder programmatically from Claude, Cursor, Codex, Gemini and 6 other MCP-capable AI assistants. Built for YOOtheme Pro® 4.0+. Independent third-party project, not affiliated with YOOtheme GmbH. Free, GPL-2.0-or-later.
 * Version: 1.1.7
 * Author: WootsUp
 * Author URI: https://wootsup.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.2
 * Requires at least: 6.0
 * Tested up to: 6.7
 * Update URI: https://updates.wootsup.com/yt-builder-mcp/
 * Text Domain: yt-builder-mcp
 * Domain Path: /languages
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp
 * @author  WootsUp
 * @copyright getimo productions
 */

defined('ABSPATH') || exit;

if (defined('YTB_MCP_VERSION')) {
    return; // Already loaded.
}

define('YTB_MCP_VERSION', '1.1.7');
define('YTB_MCP_FILE', __FILE__);
define('YTB_MCP_DIR', __DIR__);
define('YTB_MCP_MIN_YT_VERSION', '4.0');
define('YTB_MCP_MIN_PHP_VERSION', '8.2');
define('YTB_MCP_MIN_WP_VERSION', '6.0');

/**
 * Activation: runtime PHP / WP version gates + stamp the schema version
 * so future migrations know which on-disk layout they're upgrading from.
 *
 * If PHP or WP is below the minimum, we deactivate ourselves and bail
 * with a branded `wp_die()` rather than detonating later. Pattern ported
 * from the api-mapper plugin.
 */
register_activation_hook(__FILE__, static function (): void {
    if (version_compare(PHP_VERSION, YTB_MCP_MIN_PHP_VERSION, '<')) {
        \deactivate_plugins(\plugin_basename(__FILE__));
        \wp_die(
            \wp_kses_post(
                sprintf(
                    '<h1>YT Builder MCP for YOOtheme Pro (unofficial)</h1>' .
                    '<p>This plugin requires <strong>PHP %s</strong> or higher.</p>' .
                    '<p>Your current PHP version is <strong>%s</strong>.</p>' .
                    '<p>Please upgrade your PHP version or contact your hosting provider.</p>' .
                    '<p><a href="%s">&laquo; Back to Plugins</a></p>',
                    \esc_html(YTB_MCP_MIN_PHP_VERSION),
                    \esc_html(PHP_VERSION),
                    \esc_url(\admin_url('plugins.php')),
                ),
            ),
            \esc_html__('YT Builder MCP for YOOtheme Pro (unofficial) — PHP version too low', 'yt-builder-mcp'),
            ['back_link' => true],
        );
    }

    if (version_compare(\get_bloginfo('version'), YTB_MCP_MIN_WP_VERSION, '<')) {
        \deactivate_plugins(\plugin_basename(__FILE__));
        \wp_die(
            \wp_kses_post(
                sprintf(
                    '<h1>YT Builder MCP for YOOtheme Pro (unofficial)</h1>' .
                    '<p>This plugin requires <strong>WordPress %s</strong> or higher.</p>' .
                    '<p>Your current WordPress version is <strong>%s</strong>.</p>' .
                    '<p>Please upgrade WordPress before activating this plugin.</p>' .
                    '<p><a href="%s">&laquo; Back to Plugins</a></p>',
                    \esc_html(YTB_MCP_MIN_WP_VERSION),
                    \esc_html((string) \get_bloginfo('version')),
                    \esc_url(\admin_url('plugins.php')),
                ),
            ),
            \esc_html__('YT Builder MCP for YOOtheme Pro (unofficial) — WordPress version too low', 'yt-builder-mcp'),
            ['back_link' => true],
        );
    }

    require_once __DIR__ . '/modules/core-storage/src/SchemaVersion.php';
    \WootsUp\BuilderMcp\Storage\SchemaVersion::ensure();
});

/**
 * Deactivation: belt-and-suspenders cleanup of per-template state locks
 * and revealed-token transients. The lock rows self-heal via their TTL
 * (60s by default), but reclaiming them eagerly on plugin deactivation
 * keeps wp_options clean for operators who toggle the plugin off
 * intentionally.
 *
 * NOTE: This does NOT delete signing-secret, keystore, or schema-version
 * — those belong to uninstall.php (full deletion path).
 */
register_deactivation_hook(__FILE__, static function (): void {
    /** @var \wpdb $wpdb */
    global $wpdb;
    if (!isset($wpdb) || !is_object($wpdb)) {
        return;
    }

    // Per-template lock rows (ytb_mcp_lock_tpl_<md5>).
    $wpdb->query(
        $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe.
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('ytb_mcp_lock_tpl_') . '%',
        ),
    );

    // One-shot revealed-token transients + their timeout siblings.
    $wpdb->query(
        $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe.
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_' . $wpdb->esc_like('ytb_mcp_') . '%',
            '_transient_timeout_' . $wpdb->esc_like('ytb_mcp_') . '%',
        ),
    );

    if (function_exists('wp_cache_flush')) {
        \wp_cache_flush();
    }
});

/**
 * Guard: YOOtheme Pro must be available.
 *
 * Hooked on `after_setup_theme` at **priority 20** because YOOtheme
 * registers its `YOOtheme\Application` class during theme bootstrap at the
 * default priority 10 — running at priority 10 races YOOtheme's own
 * registration and `class_exists` returns false intermittently in REST
 * contexts (observed 2026-05-22: REST routes silently failed to register).
 * Priority 20 guarantees YT is loaded by the time our callback runs. This
 * exactly matches the api-mapper plugin pattern (`api-mapper.php:228`).
 *
 * If YOOtheme is missing, we register an admin notice and bail out without
 * fatal errors.
 */
add_action('after_setup_theme', static function (): void {
    if (class_exists('YOOtheme\\Application', false)) {
        // YOOtheme is present — best-effort runtime version note via the
        // theme metadata (wp_get_theme reliably exposes Version; the
        // `YOOtheme\Application::VERSION` constant is NOT public-API in
        // v4.x — empirically not defined on dev.wootsup.com). We do not
        // hard-gate on it; YT Pro v3.x and older simply fail at the
        // `app()->load()` call below with a graceful PHP notice. The
        // YTB_MCP_MIN_YT_VERSION constant remains as docs-only signal.
        require_once __DIR__ . '/bootstrap.php';
        return;
    }

    // YOOtheme missing entirely — register a branded fallback admin page
    // with actionable guidance (mirrors api-mapper UX, prevents the
    // "plugin installed but invisible" confusion).
    add_action('admin_menu', static function (): void {
        \add_submenu_page(
            'tools.php',
            \esc_html__('YT Builder MCP for YOOtheme Pro (unofficial)', 'yt-builder-mcp'),
            \esc_html__('YT Builder MCP', 'yt-builder-mcp'),
            'manage_options',
            'ytb-mcp-settings',
            static function (): void {
                ?>
                <div class="wrap">
                    <h1><?php echo \esc_html__('YT Builder MCP for YOOtheme Pro (unofficial)', 'yt-builder-mcp'); ?></h1>
                    <div class="notice notice-error">
                        <p><strong><?php echo \esc_html__('YOOtheme Pro is required', 'yt-builder-mcp'); ?></strong></p>
                        <p><?php
                            printf(
                                /* translators: %s: minimum YOOtheme Pro version */
                                \esc_html__('YT Builder MCP needs %s or later as your active theme to drive its page builder.', 'yt-builder-mcp'),
                                '<strong>YOOtheme Pro ' . \esc_html(YTB_MCP_MIN_YT_VERSION) . '</strong>',
                            );
                        ?></p>
                        <p><a class="button button-primary" href="https://yootheme.com/" target="_blank" rel="noopener noreferrer"><?php echo \esc_html__('Get YOOtheme Pro', 'yt-builder-mcp'); ?></a></p>
                    </div>
                </div>
                <?php
            },
        );
    });

    add_action('admin_notices', static function (): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>YT Builder MCP</strong> requires the ';
        echo '<a href="https://yootheme.com/" target="_blank" rel="noopener">YOOtheme Pro</a> ';
        echo 'theme (version ' . esc_html(YTB_MCP_MIN_YT_VERSION) . ' or later). ';
        echo 'The plugin remains inactive until YOOtheme Pro is installed and active.';
        echo '</p></div>';
    });
}, 20);
