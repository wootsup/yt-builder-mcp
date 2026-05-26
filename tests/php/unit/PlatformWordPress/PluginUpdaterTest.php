<?php
/**
 * PluginUpdater — WP custom auto-updater (W9-T10).
 *
 * Pins the self-hosted-plugin update flow that gives the WordPress build
 * update-parity with the Joomla `<updateservers>` declaration. Both
 * platforms resolve from updates.wootsup.com:
 *
 *   - Joomla: updates.wootsup.com/yt-builder-mcp/joomla/update.xml
 *   - WordPress: updates.wootsup.com/yt-builder-mcp/wordpress/info.json
 *
 * The class is engineered for pure unit testing: the remote fetch is an
 * injectable callable, so these tests never touch the WP HTTP API. We pin:
 *  - injects an update object when remote version is NEWER
 *  - no-ops when remote version is SAME or OLDER
 *  - fails safe (returns transient unchanged, never fatal) on:
 *      · network error (fetcher returns null)
 *      · malformed / non-JSON body
 *      · JSON missing the expected shape
 *  - the injected update object carries package = GitHub release asset zip,
 *    new_version, tested, requires, requires_php, slug, plugin
 *  - plugins_api returns version-details (description + changelog) for our
 *    slug only, passes through for everything else
 *  - the remote check is cached (fetcher called once across two reads)
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\PlatformWordPress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\WordPress\PluginUpdater;

#[CoversClass(PluginUpdater::class)]
final class PluginUpdaterTest extends TestCase
{
    private const BASENAME = 'yt-builder-mcp/yt-builder-mcp.php';
    private const SLUG = 'yt-builder-mcp';

    /**
     * A well-formed info.json body that the live feed-generator emits.
     *
     * @param string $version remote "current_version"
     */
    private function remoteJson(string $version): string
    {
        return (string) json_encode([
            'product' => 'yt-builder-mcp',
            'name' => 'YT Builder MCP for YOOtheme Pro (unofficial)',
            'slug' => self::SLUG,
            'current_version' => $version,
            'tested' => '6.7',
            'requires' => '6.0',
            'requires_php' => '8.2',
            'homepage' => 'https://github.com/wootsup/yt-builder-mcp',
            'download_url' => "https://github.com/wootsup/yt-builder-mcp/releases/download/v{$version}/yt-builder-mcp_v{$version}.zip",
            'sections' => [
                'description' => 'Drive your page builder from AI assistants.',
                'changelog' => '<h4>1.2.0</h4><ul><li>New tool.</li></ul>',
            ],
        ]);
    }

    /**
     * Build an updater whose remote fetch returns a fixed body (or null).
     *
     * @param string|null $body   raw HTTP body the fetcher yields
     * @param string      $current installed version constant value
     * @param int|null    $callCounter by-ref fetch invocation counter
     */
    private function makeUpdater(?string $body, string $current = '1.0.1', ?int &$callCounter = null): PluginUpdater
    {
        $callCounter = 0;
        $store = [];

        return new PluginUpdater(
            self::BASENAME,
            self::SLUG,
            $current,
            // fetcher
            function (string $url) use ($body, &$callCounter): ?string {
                $callCounter++;
                return $body;
            },
            // cache get
            function (string $key) use (&$store): ?array {
                return $store[$key] ?? null;
            },
            // cache set
            function (string $key, array $data, int $ttl) use (&$store): void {
                $store[$key] = $data;
            },
        );
    }

    private function emptyTransient(): object
    {
        return (object) [
            'checked' => [self::BASENAME => '1.0.1'],
            'response' => [],
            'no_update' => [],
        ];
    }

    public function test_injects_update_when_remote_is_newer(): void
    {
        $updater = $this->makeUpdater($this->remoteJson('1.2.0'), '1.0.1');

        $result = $updater->filterUpdatePlugins($this->emptyTransient());

        self::assertObjectHasProperty('response', $result);
        self::assertArrayHasKey(self::BASENAME, $result->response);

        $offer = $result->response[self::BASENAME];
        self::assertSame('1.2.0', $offer->new_version);
        self::assertSame(self::SLUG, $offer->slug);
        self::assertSame(self::BASENAME, $offer->plugin);
        self::assertSame(
            'https://github.com/wootsup/yt-builder-mcp/releases/download/v1.2.0/yt-builder-mcp_v1.2.0.zip',
            $offer->package,
        );
        self::assertSame('6.7', $offer->tested);
        self::assertSame('6.0', $offer->requires);
        self::assertSame('8.2', $offer->requires_php);
    }

    public function test_noop_when_remote_is_same(): void
    {
        $updater = $this->makeUpdater($this->remoteJson('1.0.1'), '1.0.1');

        $result = $updater->filterUpdatePlugins($this->emptyTransient());

        self::assertArrayNotHasKey(self::BASENAME, $result->response);
        self::assertArrayHasKey(self::BASENAME, $result->no_update);
    }

    public function test_noop_when_remote_is_older(): void
    {
        $updater = $this->makeUpdater($this->remoteJson('0.9.0'), '1.0.1');

        $result = $updater->filterUpdatePlugins($this->emptyTransient());

        self::assertArrayNotHasKey(self::BASENAME, $result->response);
    }

    public function test_fails_safe_on_network_error(): void
    {
        $updater = $this->makeUpdater(null, '1.0.1');
        $in = $this->emptyTransient();

        $result = $updater->filterUpdatePlugins($in);

        self::assertArrayNotHasKey(self::BASENAME, $result->response);
        self::assertSame($in, $result, 'transient must pass through unchanged on fetch failure');
    }

    public function test_fails_safe_on_malformed_json(): void
    {
        $updater = $this->makeUpdater('<html>503 Service Unavailable</html>', '1.0.1');

        $result = $updater->filterUpdatePlugins($this->emptyTransient());

        self::assertArrayNotHasKey(self::BASENAME, $result->response);
    }

    public function test_fails_safe_on_missing_version_field(): void
    {
        $updater = $this->makeUpdater((string) json_encode(['name' => 'x']), '1.0.1');

        $result = $updater->filterUpdatePlugins($this->emptyTransient());

        self::assertArrayNotHasKey(self::BASENAME, $result->response);
    }

    public function test_returns_transient_untouched_when_checked_is_empty(): void
    {
        $updater = $this->makeUpdater($this->remoteJson('9.9.9'), '1.0.1');
        $in = (object) ['checked' => [], 'response' => [], 'no_update' => []];

        $result = $updater->filterUpdatePlugins($in);

        self::assertSame($in, $result);
    }

    public function test_plugins_api_returns_details_for_our_slug(): void
    {
        $updater = $this->makeUpdater($this->remoteJson('1.2.0'), '1.0.1');

        $info = $updater->pluginInformation(false, 'plugin_information', (object) ['slug' => self::SLUG]);

        self::assertIsObject($info);
        self::assertSame('1.2.0', $info->version);
        self::assertSame(self::SLUG, $info->slug);
        self::assertArrayHasKey('description', $info->sections);
        self::assertArrayHasKey('changelog', $info->sections);
        self::assertSame(
            'https://github.com/wootsup/yt-builder-mcp/releases/download/v1.2.0/yt-builder-mcp_v1.2.0.zip',
            $info->download_link,
        );
    }

    public function test_plugins_api_passes_through_for_other_slug(): void
    {
        $updater = $this->makeUpdater($this->remoteJson('1.2.0'), '1.0.1');

        $passthrough = $updater->pluginInformation(false, 'plugin_information', (object) ['slug' => 'akismet']);

        self::assertFalse($passthrough);
    }

    public function test_plugins_api_passes_through_for_other_action(): void
    {
        $updater = $this->makeUpdater($this->remoteJson('1.2.0'), '1.0.1');

        $passthrough = $updater->pluginInformation(false, 'query_plugins', (object) ['slug' => self::SLUG]);

        self::assertFalse($passthrough);
    }

    public function test_remote_check_is_cached_across_reads(): void
    {
        $calls = 0;
        $updater = $this->makeUpdater($this->remoteJson('1.2.0'), '1.0.1', $calls);

        $updater->filterUpdatePlugins($this->emptyTransient());
        $updater->filterUpdatePlugins($this->emptyTransient());

        self::assertSame(1, $calls, 'remote info.json must be fetched at most once (cached)');
    }

    public function test_update_info_url_targets_updates_host_wordpress_path(): void
    {
        $updater = $this->makeUpdater($this->remoteJson('1.2.0'), '1.0.1');

        self::assertSame(
            'https://updates.wootsup.com/yt-builder-mcp/wordpress/info.json',
            $updater->getUpdateInfoUrl(),
        );
    }
}
