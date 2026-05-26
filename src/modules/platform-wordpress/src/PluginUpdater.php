<?php
/**
 * PluginUpdater — self-hosted WordPress auto-updater (W9-T10).
 *
 * Gives the WordPress build update-parity with the Joomla package, which
 * declares an `<updateservers>` feed. Both platforms now auto-update from
 * updates.wootsup.com:
 *
 *   - Joomla:    updates.wootsup.com/yt-builder-mcp/joomla/update.xml
 *   - WordPress: updates.wootsup.com/yt-builder-mcp/wordpress/info.json
 *
 * ## How WordPress routes us here
 *
 * Since WP 5.8, a plugin header `Update URI: https://updates.wootsup.com/...`
 * tells core "do NOT phone home to wordpress.org for this plugin — instead
 * fire the `update_plugins_{hostname}` filter so a self-hosted handler can
 * answer." We register on `update_plugins_updates.wootsup.com`. The classic
 * `pre_set_site_transient_update_plugins` is kept as a defensive fallback for
 * the rare environment where the host-keyed filter doesn't fire (e.g. a
 * security plugin stripping the Update-URI header) — it self-guards on slug so
 * it never collides with wordpress.org-hosted plugins.
 *
 * ## Fail-safe contract
 *
 * Every remote interaction degrades to "no update offered". A network error,
 * a non-JSON body, or a payload missing the version field all return the
 * update transient UNCHANGED. The updater never throws into WP's admin/cron
 * cycle. The remote check is cached in a transient (12h) so a normal admin
 * page-load never hammers the update server.
 *
 * ## Distribution
 *
 * yt-builder-mcp is free / GPL-2.0-or-later and ships its WordPress ZIP as a
 * GitHub release asset (same model as the Joomla feed's `<downloadurl>`). The
 * `package` we inject is therefore the GitHub asset URL straight from the
 * feed's `download_url` — no license key, no api.wootsup.com indirection
 * (that's the paid api-mapper product's model, deliberately NOT mirrored here).
 *
 * ## Testability
 *
 * The remote fetch + cache are injectable callables (see the constructor) so
 * the unit suite exercises the decision logic without the WP HTTP API. In
 * production the defaults bind to `wp_safe_remote_get` + transients.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Platform\WordPress
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\WordPress;

final class PluginUpdater
{
    /** Host the Update-URI header points at; keys the `update_plugins_{host}` filter. */
    public const UPDATE_HOST = 'updates.wootsup.com';

    /** Remote update-info feed (mirror of the Joomla update.xml, JSON shape). */
    private const INFO_URL = 'https://updates.wootsup.com/yt-builder-mcp/wordpress/info.json';

    /** Transient key for the cached remote feed. */
    private const CACHE_KEY = 'ytb_mcp_update_info';

    /** Cache lifetime — 12h, matches api-mapper's UpdateChecker cadence. */
    private const CACHE_TTL = 43200;

    /** Plugin homepage surfaced in the details modal. */
    private const HOMEPAGE = 'https://github.com/wootsup/yt-builder-mcp';

    private string $pluginBasename;
    private string $slug;
    private string $currentVersion;

    /** @var callable(string):?string */
    private $fetcher;

    /** @var callable(string):(array<string,mixed>|null) */
    private $cacheGet;

    /** @var callable(string,array<string,mixed>,int):void */
    private $cacheSet;

    /**
     * In-request memo so repeated filter passes don't re-decode.
     *
     * @var array<string,mixed>|null
     */
    private ?array $infoMemo = null;
    private bool $infoFetched = false;

    /**
     * @param string                                               $pluginBasename plugin_basename() value (folder/file.php)
     * @param string                                               $slug           plugin slug (folder name)
     * @param string                                               $currentVersion installed version
     * @param (callable(string):?string)|null                      $fetcher        remote GET → body|null (defaults to wp_safe_remote_get)
     * @param (callable(string):(array<string,mixed>|null))|null   $cacheGet       cache read (defaults to get_transient)
     * @param (callable(string,array<string,mixed>,int):void)|null $cacheSet       cache write (defaults to set_transient)
     */
    public function __construct(
        string $pluginBasename,
        string $slug,
        string $currentVersion,
        ?callable $fetcher = null,
        ?callable $cacheGet = null,
        ?callable $cacheSet = null
    ) {
        $this->pluginBasename = $pluginBasename;
        $this->slug = $slug;
        $this->currentVersion = $currentVersion;

        $this->fetcher = $fetcher ?? [$this, 'defaultFetch'];
        $this->cacheGet = $cacheGet ?? static function (string $key): ?array {
            $cached = \get_transient($key);
            return is_array($cached) ? $cached : null;
        };
        $this->cacheSet = $cacheSet ?? static function (string $key, array $data, int $ttl): void {
            \set_transient($key, $data, $ttl);
        };
    }

    /**
     * Wire the WordPress hooks. Call only in admin / cron context.
     */
    public function register(): void
    {
        // WP 5.8+ routes Update-URI plugins to `update_plugins_{hostname}`.
        \add_filter('update_plugins_' . self::UPDATE_HOST, [$this, 'filterUpdatePluginsHosted'], 10, 3);

        // Broad fallback for environments where the host-keyed filter never
        // fires (header stripped, ancient WP). Slug-guarded so it can't touch
        // other plugins' rows.
        \add_filter('pre_set_site_transient_update_plugins', [$this, 'filterUpdatePlugins']);

        // "View version details" modal.
        \add_filter('plugins_api', [$this, 'pluginInformation'], 20, 3);
    }

    /**
     * The remote feed URL (exposed for diagnostics + tests).
     */
    public function getUpdateInfoUrl(): string
    {
        return self::INFO_URL;
    }

    /**
     * Host-keyed callback (`update_plugins_updates.wootsup.com`).
     *
     * WP 5.8 passes ($update=false, array $pluginData, string $pluginFile).
     * We must return either `false` (no update) or an update *array* (NOT the
     * transient object — this filter mutates a single plugin's entry, not the
     * whole transient). Fails safe to the incoming `$update` value.
     *
     * @param array<string,mixed>|false $update     incoming decision (false = none yet)
     * @param array<string,mixed>       $pluginData  plugin headers
     * @param string                    $pluginFile  plugin basename WP is asking about
     * @return array<string,mixed>|false
     */
    public function filterUpdatePluginsHosted($update, array $pluginData = [], string $pluginFile = '')
    {
        // Only answer for ourselves; defer to any prior decision otherwise.
        if ($pluginFile !== '' && $pluginFile !== $this->pluginBasename) {
            return $update;
        }

        $offer = $this->buildOffer();
        if ($offer === null) {
            return $update;
        }

        // The hosted filter expects an associative array, not an object.
        return (array) $offer;
    }

    /**
     * Broad-filter callback (`pre_set_site_transient_update_plugins`).
     *
     * Receives + returns the whole update transient. Injects our offer into
     * `$transient->response` when newer; records a `no_update` entry otherwise
     * (so the "you have the latest" UI is accurate). Fails safe by returning
     * the transient unchanged.
     *
     * @param mixed $transient
     * @return mixed
     */
    public function filterUpdatePlugins($transient)
    {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $info = $this->fetchInfo();
        if ($info === null) {
            return $transient;
        }

        $remoteVersion = $this->remoteVersion($info);
        if ($remoteVersion === null) {
            return $transient;
        }

        // WP's update transient is a stdClass with dynamic ->response /
        // ->no_update arrays. Narrow to a \stdClass-shaped local so the static
        // analyser permits the property reads/writes below.
        /** @var \stdClass $obj */
        $obj = $transient;

        /** @var array<string,object> $response */
        $response = (isset($obj->response) && is_array($obj->response)) ? $obj->response : [];
        /** @var array<string,object> $noUpdate */
        $noUpdate = (isset($obj->no_update) && is_array($obj->no_update)) ? $obj->no_update : [];

        if (version_compare($remoteVersion, $this->currentVersion, '>')) {
            $offer = $this->buildOffer($info);
            if ($offer !== null) {
                $response[$this->pluginBasename] = $offer;
                unset($noUpdate[$this->pluginBasename]);
            }
        } else {
            // Tell WP we're current so the row doesn't show a phantom check.
            $noUpdate[$this->pluginBasename] = $this->buildNoUpdate($remoteVersion);
            unset($response[$this->pluginBasename]);
        }

        $obj->response = $response;
        $obj->no_update = $noUpdate;

        return $obj;
    }

    /**
     * Provide the "View version details" payload (`plugins_api`).
     *
     * @param mixed  $result existing result (false unless another handler answered)
     * @param string $action API action
     * @param mixed  $args   query args (expects ->slug)
     * @return mixed object on match, otherwise the untouched $result
     */
    public function pluginInformation($result, string $action = '', $args = null)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (!is_object($args) || !isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $info = $this->fetchInfo();
        if ($info === null) {
            return $result;
        }

        $version = $this->remoteVersion($info);
        if ($version === null) {
            return $result;
        }

        $sections = isset($info['sections']) && is_array($info['sections']) ? $info['sections'] : [];

        return (object) [
            'name' => (string) ($info['name'] ?? 'YT Builder MCP for YOOtheme Pro (unofficial)'),
            'slug' => $this->slug,
            'version' => $version,
            'author' => '<a href="https://wootsup.com">WootsUp</a>',
            'homepage' => (string) ($info['homepage'] ?? self::HOMEPAGE),
            'requires' => (string) ($info['requires'] ?? '6.0'),
            'tested' => (string) ($info['tested'] ?? ''),
            'requires_php' => (string) ($info['requires_php'] ?? '8.2'),
            'sections' => [
                'description' => (string) ($sections['description']
                    ?? 'Drive the YOOtheme Pro page builder programmatically from AI assistants via MCP. Independent third-party project.'),
                'changelog' => (string) ($sections['changelog'] ?? 'See the GitHub releases for the full changelog.'),
            ],
            'download_link' => $this->downloadUrl($info, $version),
            'banners' => [],
            'icons' => [],
        ];
    }

    /**
     * Build the WP update object (stdClass) when a newer version exists, else
     * null. Shared by both filter entry-points.
     *
     * @param array<string,mixed>|null $info pre-fetched feed (else fetches)
     */
    private function buildOffer(?array $info = null): ?object
    {
        $info ??= $this->fetchInfo();
        if ($info === null) {
            return null;
        }
        $remoteVersion = $this->remoteVersion($info);
        if ($remoteVersion === null) {
            return null;
        }
        if (!version_compare($remoteVersion, $this->currentVersion, '>')) {
            return null;
        }

        return (object) [
            'slug' => $this->slug,
            'plugin' => $this->pluginBasename,
            'new_version' => $remoteVersion,
            'url' => (string) ($info['homepage'] ?? self::HOMEPAGE),
            'package' => $this->downloadUrl($info, $remoteVersion),
            'tested' => (string) ($info['tested'] ?? ''),
            'requires' => (string) ($info['requires'] ?? '6.0'),
            'requires_php' => (string) ($info['requires_php'] ?? '8.2'),
            'icons' => [],
            'banners' => [],
        ];
    }

    /**
     * The "you're current" entry WP shows under `no_update`.
     */
    private function buildNoUpdate(string $remoteVersion): object
    {
        return (object) [
            'slug' => $this->slug,
            'plugin' => $this->pluginBasename,
            'new_version' => $remoteVersion,
            'url' => self::HOMEPAGE,
            'package' => '',
            'requires_php' => '8.2',
        ];
    }

    /**
     * Resolve the latest version advertised by the feed.
     *
     * Accepts both the flat `current_version` shape AND the api-mapper-style
     * `versions[]` array (newest-first) so a future channel-aware feed is a
     * drop-in. Returns null if no usable version is present.
     *
     * @param array<string,mixed> $info
     */
    private function remoteVersion(array $info): ?string
    {
        if (isset($info['versions']) && is_array($info['versions']) && $info['versions'] !== []) {
            $first = $info['versions'][0];
            if (is_array($first) && isset($first['version']) && is_string($first['version']) && $first['version'] !== '') {
                return $first['version'];
            }
        }
        if (isset($info['current_version']) && is_string($info['current_version']) && $info['current_version'] !== '') {
            return $info['current_version'];
        }
        return null;
    }

    /**
     * Resolve the download (package) URL — prefers the feed's explicit
     * `download_url`, else reconstructs the GitHub release-asset convention.
     *
     * @param array<string,mixed> $info
     */
    private function downloadUrl(array $info, string $version): string
    {
        if (isset($info['versions']) && is_array($info['versions']) && $info['versions'] !== []) {
            $first = $info['versions'][0];
            if (is_array($first) && isset($first['download_url']) && is_string($first['download_url']) && $first['download_url'] !== '') {
                return $first['download_url'];
            }
        }
        if (isset($info['download_url']) && is_string($info['download_url']) && $info['download_url'] !== '') {
            return $info['download_url'];
        }
        return self::HOMEPAGE . "/releases/download/v{$version}/yt-builder-mcp_v{$version}.zip";
    }

    /**
     * Fetch + decode the remote feed, with transient caching and an in-request
     * memo. Returns null on any failure (network / parse / shape).
     *
     * @return array<string,mixed>|null
     */
    private function fetchInfo(): ?array
    {
        if ($this->infoFetched) {
            return $this->infoMemo;
        }
        $this->infoFetched = true;

        $cached = ($this->cacheGet)(self::CACHE_KEY);
        if (is_array($cached)) {
            $this->infoMemo = $cached;
            return $cached;
        }

        $body = ($this->fetcher)(self::INFO_URL);
        if (!is_string($body) || $body === '') {
            $this->infoMemo = null;
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $this->infoMemo = null;
            return null;
        }

        ($this->cacheSet)(self::CACHE_KEY, $decoded, self::CACHE_TTL);
        $this->infoMemo = $decoded;
        return $decoded;
    }

    /**
     * Production fetcher: WordPress HTTP API with SSRF protection. Never
     * throws — any error degrades to null so {@see fetchInfo()} fails safe.
     */
    private function defaultFetch(string $url): ?string
    {
        if (!function_exists('wp_safe_remote_get')) {
            return null;
        }

        $response = \wp_safe_remote_get($url, [
            'timeout' => 10,
            'sslverify' => true,
            'reject_unsafe_urls' => true,
            'user-agent' => 'yt-builder-mcp/' . $this->currentVersion,
        ]);

        if (\is_wp_error($response)) {
            return null;
        }

        $code = \wp_remote_retrieve_response_code($response);
        if (is_int($code) && $code >= 400) {
            return null;
        }

        $body = \wp_remote_retrieve_body($response);
        return is_string($body) && $body !== '' ? $body : null;
    }
}
