<?php
/**
 * PIN TEST (Wave-2 H-6, 2026-05-28). Version drift across all 5+ version-emitting files.
 *
 * Closes audit finding H-6 from the 2026-05-27 portfolio audit. Prior to this
 * test, the yt-builder-mcp Joomla package had 5 places where the package
 * version was hand-declared as a string literal:
 *
 *   1. pkg_ytbmcp.xml                              `<version>...</version>`
 *   2. update.xml                                  `<version>...</version>` + download URL
 *   3. extensions/plg_system_ytbmcp/ytbmcp.xml     `<version>...</version>`
 *   4. extensions/plg_webservices_ytbmcp/ytbmcp.xml `<version>...</version>`
 *   5. extensions/com_ytbmcp/ytbmcp.xml            `<version>...</version>`
 *
 * Plus 6 PHP-side mirrors (PluginVersionSentinel + 3x HealthController/
 * PickupController/HtmlView consts + the install script + the system plugin
 * `YTBMCP_VERSION` const) and the WP `info.json` updater-feed. The Joomla
 * XMLs had drifted to 1.1.0 while the PHP runtime carried 1.1.6. Joomla
 * would report the installed version as 1.1.0 while the actual code on disk
 * was 1.1.6. Update detection breaks (the updater compares the feed to the
 * INSTALLED version, which Joomla reads from the manifest XML; a stale
 * manifest version makes upgrades silently fail to advertise).
 *
 * THIS TEST: pin every version-emitting surface against the canonical
 * `YTB_MCP_VERSION` constant defined in the WP entry-file
 * (`src/yt-builder-mcp.php`). The constant is the single source of truth
 * and is the one place `scripts/release.php :: bumpProductVersionFiles()`
 * starts from when rewriting the rest of the tree.
 *
 * If this test breaks, the fix is NOT to update the literal; it is to
 * (a) re-run `php scripts/release.php release yt-builder-mcp <version>` so
 * every artifact is bumped through the canonical path, OR (b) extend
 * `bumpProductVersionFiles()` if a new version-bearing file was added.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;

final class VersionDriftPinTest extends TestCase
{
    private function repoRoot(): string
    {
        // tests/php/unit/Platform/Joomla/Pin/ → yt-builder-mcp/
        return \dirname(__DIR__, 6);
    }

    /**
     * Read the canonical `YTB_MCP_VERSION` constant from the WP entry-file
     * via regex (we cannot `define()` it in-process: the file is included
     * for its side-effects in Joomla bootstrap tests already, and PHP
     * defines are write-once).
     */
    private function canonicalVersion(): string
    {
        $entry = $this->repoRoot() . '/src/yt-builder-mcp.php';
        self::assertFileExists($entry, "WP entry-file missing: {$entry}");

        $content = (string) \file_get_contents($entry);
        self::assertMatchesRegularExpression(
            "/define\(\s*'YTB_MCP_VERSION'\s*,\s*'([^']+)'\s*\)/",
            $content,
            'YTB_MCP_VERSION constant must be defined in src/yt-builder-mcp.php.'
        );

        $m = [];
        $matched = \preg_match(
            "/define\(\s*'YTB_MCP_VERSION'\s*,\s*'([^']+)'\s*\)/",
            $content,
            $m
        );
        self::assertSame(1, $matched, 'YTB_MCP_VERSION regex must capture exactly one match.');
        $captured = $m[1] ?? '';
        self::assertIsString($captured);
        self::assertNotSame('', $captured, 'YTB_MCP_VERSION must be a non-empty string literal.');

        return $captured;
    }

    /**
     * Pin-1: the 5 Joomla packaging XML files (4 manifests + update feed)
     * each carry `<version>X.Y.Z</version>` matching the canonical constant.
     *
     * This is the "5x drift" the audit explicitly called out. The XMLs are
     * what Joomla's installer reads to populate `#__extensions.manifest_cache`;
     * a drift here means Joomla's Extension Manager reports the wrong
     * version after install/upgrade.
     */
    public function test_joomla_xml_versions_match_canonical_constant(): void
    {
        $canonical = $this->canonicalVersion();
        $packagingDir = $this->repoRoot() . '/src/packaging/joomla';

        $xmlFiles = [
            'pkg_ytbmcp.xml'
                => $packagingDir . '/pkg_ytbmcp.xml',
            'plg_system_ytbmcp/ytbmcp.xml'
                => $packagingDir . '/extensions/plg_system_ytbmcp/ytbmcp.xml',
            'plg_webservices_ytbmcp/ytbmcp.xml'
                => $packagingDir . '/extensions/plg_webservices_ytbmcp/ytbmcp.xml',
            'com_ytbmcp/ytbmcp.xml'
                => $packagingDir . '/extensions/com_ytbmcp/ytbmcp.xml',
            'update.xml'
                => $packagingDir . '/update.xml',
        ];

        foreach ($xmlFiles as $label => $path) {
            self::assertFileExists($path, "Packaging XML missing: {$label}");
            $xml = \simplexml_load_file($path);
            self::assertInstanceOf(
                \SimpleXMLElement::class,
                $xml,
                "Packaging XML is not well-formed: {$label}"
            );

            // update.xml has the `<version>` nested under `<update>` while the
            // four manifest XMLs declare it at top level. Reach into both.
            if ($label === 'update.xml') {
                self::assertTrue(
                    isset($xml->update->version),
                    'update.xml must contain <update><version>.'
                );
                $version = \trim((string) $xml->update->version);
            } else {
                self::assertTrue(
                    isset($xml->version),
                    "{$label} must contain a top-level <version> element."
                );
                $version = \trim((string) $xml->version);
            }

            self::assertSame(
                $canonical,
                $version,
                "Joomla packaging artifact `{$label}` declares version `{$version}`, "
                . "but canonical YTB_MCP_VERSION is `{$canonical}`. "
                . 'Run `php scripts/release.php release yt-builder-mcp <version>` to resync.'
            );
        }
    }

    /**
     * Pin-2: the Joomla update.xml download URL embeds the version twice
     * (folder + filename). A stale URL routes the updater at a missing
     * GitHub release asset; silent install failure with HTTP 404.
     */
    public function test_joomla_update_feed_download_url_embeds_canonical_version(): void
    {
        $canonical = $this->canonicalVersion();
        $feedPath = $this->repoRoot() . '/src/packaging/joomla/update.xml';

        $feed = \simplexml_load_file($feedPath);
        self::assertInstanceOf(\SimpleXMLElement::class, $feed);

        $downloadUrl = \trim((string) $feed->update->downloads->downloadurl);
        self::assertStringContainsString(
            "v{$canonical}/pkg_ytbmcp_v{$canonical}.zip",
            $downloadUrl,
            "Joomla update.xml downloadurl `{$downloadUrl}` must point at the "
            . "GitHub release asset for v{$canonical}."
        );
    }

    /**
     * Pin-3: the system plugin's `YTBMCP_VERSION` class constant (the one
     * PluginVersionSentinel reads at runtime to detect SFTP / Akeeba /
     * ZIP-overwrite upgrades) agrees with the canonical constant. A drift
     * here means the upgrade self-heal sentinel either fires every request
     * (false-positive) or never (false-negative).
     */
    public function test_joomla_system_plugin_version_constant_matches_canonical(): void
    {
        $canonical = $this->canonicalVersion();
        $path = $this->repoRoot()
            . '/src/packaging/joomla/extensions/plg_system_ytbmcp/src/Extension/Ytbmcp.php';
        self::assertFileExists($path, "System plugin file missing: {$path}");

        $content = (string) \file_get_contents($path);
        self::assertMatchesRegularExpression(
            "/public const YTBMCP_VERSION\s*=\s*'([^']+)'/",
            $content,
            'plg_system_ytbmcp Ytbmcp.php must declare a YTBMCP_VERSION constant.'
        );
        $m = [];
        $matched = \preg_match(
            "/public const YTBMCP_VERSION\s*=\s*'([^']+)'/",
            $content,
            $m
        );
        self::assertSame(1, $matched, 'YTBMCP_VERSION regex must capture exactly one match.');
        $captured = $m[1] ?? '';
        self::assertIsString($captured);

        self::assertSame(
            $canonical,
            $captured,
            "plg_system_ytbmcp::YTBMCP_VERSION = `{$captured}` drifted from canonical "
            . "YTB_MCP_VERSION = `{$canonical}`. PluginVersionSentinel reads this "
            . 'value to decide whether an upgrade-self-heal cycle must run.'
        );
    }

    /**
     * Pin-4: the 4 mirror PHP `PLUGIN_VERSION` constants scattered across
     * com_ytbmcp (HealthController, PickupController, HtmlView) and the
     * pkg_ytbmcp install-script must all match the canonical constant.
     *
     * These are deliberately mirrored (the package script cannot reach
     * `YTB_MCP_VERSION` because the WP entry-file is not loaded at Joomla
     * install time), but they must move in lockstep with every release.
     */
    public function test_joomla_php_plugin_version_mirrors_match_canonical(): void
    {
        $canonical = $this->canonicalVersion();
        $root = $this->repoRoot();

        $mirrors = [
            'pkg_ytbmcp/script.php'
                => $root . '/src/packaging/joomla/pkg_ytbmcp/script.php',
            'com_ytbmcp/api/Controller/HealthController.php'
                => $root . '/src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/HealthController.php',
            'com_ytbmcp/api/Controller/PickupController.php'
                => $root . '/src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/PickupController.php',
            'com_ytbmcp/admin/View/Dashboard/HtmlView.php'
                => $root . '/src/packaging/joomla/extensions/com_ytbmcp/administrator/src/View/Dashboard/HtmlView.php',
        ];

        foreach ($mirrors as $label => $path) {
            self::assertFileExists($path, "Mirror file missing: {$label}");
            $content = (string) \file_get_contents($path);

            self::assertMatchesRegularExpression(
                "/(public|private|protected)\s+const\s+PLUGIN_VERSION\s*=\s*'([^']+)'/",
                $content,
                "{$label} must declare a PLUGIN_VERSION constant."
            );
            $m = [];
            $matched = \preg_match(
                "/(public|private|protected)\s+const\s+PLUGIN_VERSION\s*=\s*'([^']+)'/",
                $content,
                $m
            );
            self::assertSame(1, $matched, "PLUGIN_VERSION regex must match in {$label}.");
            $captured = $m[2] ?? '';
            self::assertIsString($captured);

            self::assertSame(
                $canonical,
                $captured,
                "PLUGIN_VERSION in `{$label}` is `{$captured}`, canonical is `{$canonical}`. "
                . 'These PHP constants must stay in lockstep with YTB_MCP_VERSION.'
            );
        }
    }

    /**
     * Pin-5: the WordPress updater feed (`info.json`) advertises the
     * canonical version + a matching download URL. The audit framed this
     * as a Joomla-only "5x drift", but the same drift class hides on the
     * WordPress side: `current_version` was 1.1.0 while the plugin
     * shipped at 1.1.6. WP customers would never see the update offer.
     */
    public function test_wordpress_info_json_advertises_canonical_version(): void
    {
        $canonical = $this->canonicalVersion();
        $path = $this->repoRoot() . '/src/packaging/wordpress/info.json';
        self::assertFileExists($path, "WP info.json missing: {$path}");

        $raw = (string) \file_get_contents($path);
        /** @var array<string, mixed>|null $data */
        $data = \json_decode($raw, true);
        self::assertIsArray($data, 'info.json must decode to an array.');

        self::assertSame(
            $canonical,
            $data['current_version'] ?? null,
            'WP info.json `current_version` must match canonical YTB_MCP_VERSION.'
        );

        $downloadUrl = (string) ($data['download_url'] ?? '');
        self::assertStringContainsString(
            "v{$canonical}/yt-builder-mcp_v{$canonical}.zip",
            $downloadUrl,
            'WP info.json download_url must embed the canonical version '
            . '(folder + filename).'
        );
    }

    /**
     * Pin-6: the NPM package + DXT manifest declare the canonical version.
     * Build-dxt.js already hard-fails on a manifest/package mismatch, but
     * a drift between the NPM package and the PHP runtime would mean an
     * `npx @wootsup/yt-builder-mcp` install at v1.1.6 hitting a v1.1.0
     * REST surface (protocol drift, not just a label drift).
     */
    public function test_npm_package_and_dxt_manifest_match_canonical(): void
    {
        $canonical = $this->canonicalVersion();
        $root = $this->repoRoot();

        $jsonFiles = [
            'packages/mcp/package.json' => $root . '/packages/mcp/package.json',
            'packages/mcp/manifest.json' => $root . '/packages/mcp/manifest.json',
        ];

        foreach ($jsonFiles as $label => $path) {
            self::assertFileExists($path, "Required JSON missing: {$label}");
            /** @var array<string, mixed>|null $data */
            $data = \json_decode((string) \file_get_contents($path), true);
            self::assertIsArray($data, "{$label} must decode to an array.");
            self::assertSame(
                $canonical,
                $data['version'] ?? null,
                "{$label} `version` must match canonical YTB_MCP_VERSION = `{$canonical}`."
            );
        }
    }
}
