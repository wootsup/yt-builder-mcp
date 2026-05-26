<?php
/**
 * PIN TEST (A3-M2, 2026-05-25) — Joomla update-feed URL + feed well-formedness.
 *
 * Cross-platform PARITY counterpart to the WordPress
 * {@see \WootsUp\BuilderMcp\Tests\Unit\PlatformWordPress\PluginUpdaterTest
 * ::test_update_info_url_targets_updates_host_wordpress_path}. Both platforms
 * resolve updates from the SAME host (updates.wootsup.com); a drift in the
 * Joomla `<updateservers>` URL would silently cut every Joomla customer off
 * from updates with no test catching it. We pin two surfaces:
 *
 *   1. The package manifest's `<updateservers><server>` URL is EXACTLY
 *      `https://updates.wootsup.com/yt-builder-mcp/joomla/update.xml`
 *      (Joomla's manifest XML indents the URL with surrounding whitespace —
 *      Joomla itself trims it, so we trim before comparing).
 *   2. The shipped `update.xml` feed is well-formed XML AND its advertised
 *      `<version>` matches the package manifest `<version>` (a feed pointing
 *      at a stale version would make the updater perpetually mis-report).
 *
 * These are PIN tests (not behavioural): the package manifest + update.xml are
 * static packaging artifacts consumed by Joomla's com_installer at runtime —
 * there is no PHP class to instantiate. SimpleXML parsing of the real files is
 * the faithful surface (it is exactly what Joomla's updater does).
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;

final class JoomlaUpdateFeedPinTest extends TestCase
{
    private const EXPECTED_SERVER_URL =
        'https://updates.wootsup.com/yt-builder-mcp/joomla/update.xml';

    private function packagingDir(): string
    {
        return \dirname(__DIR__, 6) . '/src/packaging/joomla';
    }

    private function packageManifestPath(): string
    {
        return $this->packagingDir() . '/pkg_ytbmcp.xml';
    }

    private function updateFeedPath(): string
    {
        return $this->packagingDir() . '/update.xml';
    }

    /**
     * Parse a packaging XML file with SimpleXML, failing the test (rather than
     * emitting a warning) when it is missing or malformed.
     */
    private function loadXml(string $path): \SimpleXMLElement
    {
        self::assertFileExists($path, "Packaging artifact missing: {$path}");
        $xml = \simplexml_load_file($path);
        self::assertInstanceOf(
            \SimpleXMLElement::class,
            $xml,
            "Packaging artifact is not well-formed XML: {$path}"
        );
        return $xml;
    }

    /**
     * A3-M2 (1): the package manifest's update-server URL targets the
     * updates.wootsup.com Joomla path. Parity with the WP info.json URL pin.
     */
    public function test_package_manifest_updateserver_url_targets_updates_host_joomla_path(): void
    {
        $xml = $this->loadXml($this->packageManifestPath());

        self::assertTrue(
            isset($xml->updateservers->server),
            'pkg_ytbmcp.xml must declare an <updateservers><server> element.'
        );

        // Joomla indents the URL inside the element with whitespace/newlines;
        // Joomla's own updater trims it, so trim before the exact compare.
        $serverUrl = \trim((string) $xml->updateservers->server);

        self::assertSame(
            self::EXPECTED_SERVER_URL,
            $serverUrl,
            'Joomla update-server URL must target updates.wootsup.com/yt-builder-mcp/joomla/update.xml.'
        );

        // The server type must be `extension` (Joomla's collection-vs-extension
        // distinction — a `collection` server would expect a different feed shape).
        self::assertSame(
            'extension',
            (string) $xml->updateservers->server['type'],
            'update-server type must be "extension" (single-extension feed).'
        );
    }

    /**
     * A3-M2 (2): the shipped update.xml feed is well-formed and advertises the
     * SAME version as the package manifest (no stale-version drift), targets
     * the pkg_ytbmcp element, and carries a GitHub release download URL.
     */
    public function test_update_feed_is_well_formed_and_version_matches_manifest(): void
    {
        $manifest = $this->loadXml($this->packageManifestPath());
        $feed     = $this->loadXml($this->updateFeedPath());

        $manifestVersion = \trim((string) $manifest->version);
        self::assertNotSame('', $manifestVersion, 'package manifest must declare a <version>.');

        self::assertTrue(isset($feed->update), 'update.xml must contain an <update> entry.');
        $update = $feed->update;

        self::assertSame(
            $manifestVersion,
            \trim((string) $update->version),
            'update.xml <version> must match the package manifest <version> (no stale drift).'
        );

        self::assertSame(
            'pkg_ytbmcp',
            \trim((string) $update->element),
            'update.xml must target the pkg_ytbmcp package element.'
        );

        self::assertSame(
            'package',
            \trim((string) $update->type),
            'update.xml <type> must be "package".'
        );

        // The download URL must point at the GitHub release asset for THIS
        // version (parity with the WP info.json download_url shape).
        $downloadUrl = \trim((string) $update->downloads->downloadurl);
        self::assertStringContainsString(
            "v{$manifestVersion}/pkg_ytbmcp_v{$manifestVersion}.zip",
            $downloadUrl,
            'update.xml downloadurl must point at the GitHub release asset for the advertised version.'
        );
    }

    /**
     * A3-M2 (3): the feed declares Joomla 5 AND 6 target platforms (the port
     * supports both — a missing 6.* target would make Joomla 6 customers see
     * "no compatible update").
     */
    public function test_update_feed_targets_joomla_5_and_6(): void
    {
        $feed = $this->loadXml($this->updateFeedPath());

        $targets = [];
        foreach ($feed->update->targetplatform as $tp) {
            self::assertSame('joomla', (string) $tp['name']);
            $targets[] = (string) $tp['version'];
        }

        self::assertContains('5.*', $targets, 'feed must declare a Joomla 5.* target platform.');
        self::assertContains('6.*', $targets, 'feed must declare a Joomla 6.* target platform.');
    }
}
