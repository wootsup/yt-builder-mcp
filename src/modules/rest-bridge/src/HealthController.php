<?php
/**
 * HealthController — unauthenticated GET /health endpoint.
 *
 * Returns a small JSON document with the plugin version, the detected
 * YOOtheme Pro version (or null if YT is not loaded), the WP version,
 * and the storage backend the plugin will read/write against.
 *
 * Wave-6 disclosure tightening:
 *  - Anonymous payload no longer exposes `php_version` (host-fingerprint).
 *  - Anonymous payload returns only `available_endpoints_count`. Callers
 *    that supply a valid bearer (any scope) receive the full
 *    `available_endpoints` list.
 *
 * The endpoint is intentionally readable without a Bearer-token so the
 * MCP-Setup-Wizard can probe the URL during configuration ("is the plugin
 * installed and reachable?") before the user has even pasted their key.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Rest
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Rest;

use WootsUp\BuilderMcp\Storage\SchemaVersion;
use WootsUp\BuilderMcp\Yootheme\YoothemeAdapter;

final class HealthController extends PublicRestController
{
    private readonly YoothemeAdapter $yootheme;

    public function __construct(?\WootsUp\BuilderMcp\Auth\BearerVerifier $verifier = null, ?YoothemeAdapter $yootheme = null)
    {
        parent::__construct($verifier);
        $this->yootheme = $yootheme ?? new YoothemeAdapter();
    }

    public function register_routes(): void
    {
        \register_rest_route(self::NAMESPACE, '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            // Health is public: anyone who reaches the URL gets a tiny version-document.
            'permission_callback' => '__return_true',
        ]);

        // Wave 6.5: /identity — minimal public probe used by the npm
        // setup-wizard to (a) confirm the plugin is installed at the URL
        // the user pasted, and (b) cross-check the URL against the
        // token's `iss` claim. Intentionally a separate endpoint from
        // /health so it can stay stable across feature waves and so a
        // future Joomla port can ship the same shape on its own URL
        // pattern (matches api-mapper's WP+Joomla parity).
        \register_rest_route(self::NAMESPACE, '/identity', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_identity'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response($this->payload($request), 200);
    }

    /**
     * GET /identity — minimal public probe.
     *
     * Returns only the fields the wizard genuinely needs:
     *  - product       — server-side proof the URL is our plugin (not some other MCP)
     *  - platform      — wordpress | joomla (future)
     *  - siteurl       — canonical site URL (so the wizard can cross-check vs token `iss`)
     *  - plugin_version — for "your plugin needs updating" hints
     *
     * No host-fingerprinting fields (no PHP / WP / YT version, no endpoint list).
     * That richer surface lives behind a Bearer at /health.
     */
    public function handle_identity(\WP_REST_Request $request): \WP_REST_Response
    {
        unset($request); // unused but signature required by WP REST API.

        return new \WP_REST_Response([
            'product'        => 'yt-builder-mcp',
            'platform'       => 'wordpress',
            'siteurl'        => \rtrim((string) \get_site_url(), '/'),
            'plugin_version' => defined('YTB_MCP_VERSION') ? (string) YTB_MCP_VERSION : 'dev',
        ], 200);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(?\WP_REST_Request $request = null): array
    {
        $authenticated = $request !== null ? $this->has_valid_bearer($request) : false;

        // L4-tier-reduction (Wave-6 Round-2 R2.13): the anonymous payload is
        // the minimum a setup-wizard needs to confirm "the plugin is
        // installed at this URL" — plugin_version + a generic status. Every
        // other field (WP version, YT version, yootheme_loaded flag,
        // endpoint count, storage backend, schema version) leaks
        // host-fingerprint to unauthenticated callers and is reserved for
        // bearer-holders.
        if (!$authenticated) {
            return [
                'plugin_version' => defined('YTB_MCP_VERSION') ? (string) YTB_MCP_VERSION : 'dev',
                'status' => 'ok',
            ];
        }

        $endpoints = $this->detect_endpoints();
        return [
            'plugin_version' => defined('YTB_MCP_VERSION') ? (string) YTB_MCP_VERSION : 'dev',
            'status' => 'ok',
            'yootheme_version' => $this->yootheme->getVersion(),
            'wp_version' => $this->detect_wp_version(),
            // Spike-Outcomes (2026-05-21): real storage is wp_option('yootheme'),
            // not wp_posts.post_content. Surfaced here so the MCP-Setup-Wizard
            // can sanity-check that the plugin matches the layout it expects.
            'storage_type' => 'wp_option',
            'storage_target' => 'yootheme',
            'yootheme_loaded' => $this->yootheme->isLoaded(),
            'available_endpoints_count' => count($endpoints),
            'available_endpoints' => $endpoints,
            'php_version' => PHP_VERSION,
            'schema_version' => SchemaVersion::get(),
        ];
    }

    /**
     * Enumerate the registered REST routes filtered to our namespace, so
     * the MCP-Setup-Wizard can present an at-a-glance "what does this
     * plugin offer me" list without having to probe every URL.
     *
     * Sorted alphabetically for deterministic test assertions.
     *
     * @return list<string>
     */
    private function detect_endpoints(): array
    {
        if (!function_exists('rest_get_server')) {
            return [];
        }
        try {
            $server = \rest_get_server();
            if (!is_object($server) || !method_exists($server, 'get_routes')) {
                return [];
            }
            /** @var mixed $routes */
            $routes = $server->get_routes();
            if (!is_array($routes)) {
                return [];
            }
            $prefix = '/' . self::NAMESPACE;
            $out = [];
            foreach (array_keys($routes) as $route) {
                $route = (string) $route;
                if (str_starts_with($route, $prefix)) {
                    $out[] = $route;
                }
            }
            sort($out);
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function detect_wp_version(): ?string
    {
        if (isset($GLOBALS['wp_version']) && is_string($GLOBALS['wp_version'])) {
            return $GLOBALS['wp_version'];
        }
        return null;
    }
}
