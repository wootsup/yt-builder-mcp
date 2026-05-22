<?php
/**
 * YOOtheme Builder MCP — WordPress uninstall script.
 *
 * Executed by WordPress when the plugin is deleted via the Plugins screen
 * (NOT on deactivate). Removes every artifact this plugin can write so
 * the next clean install starts from a blank slate.
 *
 * Idempotent by design: rerunning has no extra effect. Multisite-aware:
 * iterates over every site via `get_sites()` + `switch_to_blog()` so an
 * orphaned signing-secret on site #42 cannot survive a network-wide
 * uninstall.
 *
 * Pattern ported from the api-mapper plugin; adapted to the
 * `ytb_mcp_*` option / transient namespace.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp
 */

declare(strict_types=1);

// Hard guard: WordPress sets this constant before requiring this file.
// Any other entry point (direct hit, malicious include) bails out.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Per-site cleanup. Called once for single-site installs, and once per
 * blog inside the multisite loop.
 */
$ytbMcpCleanupSite = static function (): void {
    /** @var \wpdb $wpdb */
    global $wpdb;

    // -------------------------------------------------------------------
    // 1. Plugin-owned options (autoload=false everywhere, but be paranoid).
    // -------------------------------------------------------------------
    $options = [
        'ytb_mcp_schema_version',
        'ytb_mcp_signing_secret',
        'ytb_mcp_keys',
    ];
    foreach ($options as $option) {
        \delete_option($option);
    }

    if (!isset($wpdb) || !is_object($wpdb)) {
        return;
    }

    // -------------------------------------------------------------------
    // 2. Per-template state-locks (`ytb_mcp_lock_tpl_<md5>`).
    //    There can be hundreds across a busy site; LIKE-DELETE is faster
    //    than iterating individual delete_option() calls.
    // -------------------------------------------------------------------
    $wpdb->query(
        $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe.
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('ytb_mcp_lock_tpl_') . '%',
        ),
    );

    // -------------------------------------------------------------------
    // 3. Transients written by the plugin:
    //    - ytb_mcp_revealed_token_<kid>  (one-shot reveal, 60s TTL)
    //    - ytb_mcp_rate_<kid>             (rate-limiter buckets)
    //    Each transient has a matching `_transient_timeout_*` row.
    // -------------------------------------------------------------------
    $wpdb->query(
        $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe.
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_' . $wpdb->esc_like('ytb_mcp_') . '%',
            '_transient_timeout_' . $wpdb->esc_like('ytb_mcp_') . '%',
        ),
    );

    // -------------------------------------------------------------------
    // 4. Site-wide transients (multisite). On single-site this is the
    //    same row-shape as above, but `set_site_transient` writes into
    //    sitemeta on multisite — keep the cleanup symmetric.
    // -------------------------------------------------------------------
    $wpdb->query(
        $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe.
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_site_transient_' . $wpdb->esc_like('ytb_mcp_') . '%',
            '_site_transient_timeout_' . $wpdb->esc_like('ytb_mcp_') . '%',
        ),
    );
};

// ---------------------------------------------------------------------------
// Multisite-aware dispatch.
// ---------------------------------------------------------------------------
if (function_exists('is_multisite') && \is_multisite()) {
    $sites = \get_sites(['number' => 0, 'fields' => 'ids']);
    foreach ($sites as $siteId) {
        \switch_to_blog((int) $siteId);
        try {
            $ytbMcpCleanupSite();
        } finally {
            \restore_current_blog();
        }
    }
} else {
    $ytbMcpCleanupSite();
}

// Object-cache eviction last — any prior delete_option call would have
// invalidated its mirror, but a final flush guards against custom
// drop-ins that key things differently.
if (function_exists('wp_cache_flush')) {
    \wp_cache_flush();
}
