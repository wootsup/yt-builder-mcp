<?php
/**
 * STATIC PARITY PIN TESTS (Wave-9 T2 — gaps #3 / #4):
 * the Joomla deliverable ships the Claude-Desktop `.dxt` one-click bundle at
 * parity with WordPress.
 *
 * The `.dxt` artifact itself is git-ignored (`*.dxt`) and built fresh at
 * release-time, so these tests guard the STATIC WIRING that makes the bundle
 * installable + downloadable — the three seams a regression could silently
 * break:
 *
 *   1. The component manifest (`ytbmcp.xml`) declares a `<media>` element that
 *      installs `media/yt-builder-mcp.dxt` into the site's `media/com_ytbmcp/`
 *      directory (gap #4). Without it, the build-injected `.dxt` would never
 *      be copied to the webroot and the CTA would 404.
 *
 *   2. The reveal-box template (`_tab_keys.php`) wires a "Download for Claude
 *      Desktop" CTA pointing at `media/com_ytbmcp/yt-builder-mcp.dxt` under the
 *      site root (gap #4), mirroring WP's `render_revealed_token_notice()`.
 *
 *   3. The CTA's i18n label key exists in `com_ytbmcp.ini`.
 *
 * The build-script side (gap #3 — `injectDxtIntoJoomlaMedia()` in
 * scripts/release.php) is covered by the release packaging test
 * (scripts/__tests__/release-multi-product.test.php), which lives in the main
 * repo's PHP test harness rather than the plugin's PHPUnit joomla group.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;

final class DxtMediaParityPinTest extends TestCase
{
    private const MANIFEST_REL =
        'src/packaging/joomla/extensions/com_ytbmcp/ytbmcp.xml';

    private const TEMPLATE_REL =
        'src/packaging/joomla/extensions/com_ytbmcp/administrator/tmpl/dashboard/_tab_keys.php';

    private const INI_REL =
        'src/packaging/joomla/extensions/com_ytbmcp/administrator/language/en-GB/com_ytbmcp.ini';

    private static function repoRoot(): string
    {
        return \dirname(__DIR__, 6);
    }

    private static function read(string $relPath): string
    {
        $path = self::repoRoot() . '/' . $relPath;
        if (!\is_file($path)) {
            self::fail("Expected file missing: $path");
        }
        $contents = \file_get_contents($path);
        self::assertIsString($contents, "Could not read $path");

        return $contents;
    }

    // ── #4: manifest <media> element ──────────────────────────────────────

    /**
     * The manifest MUST declare a <media> element installing into
     * media/com_ytbmcp/. Parsed via SimpleXML so a malformed element fails
     * (not a loose grep that a comment could satisfy).
     */
    public function test_manifest_declares_media_element_for_com_ytbmcp(): void
    {
        $xml = \simplexml_load_string(self::read(self::MANIFEST_REL));
        self::assertNotFalse($xml, 'ytbmcp.xml must be well-formed XML.');

        $media = $xml->media;
        self::assertNotNull($media, 'Component manifest must declare a <media> element (gap #4).');
        self::assertCount(1, $media, 'Exactly one <media> element expected.');

        self::assertSame(
            'com_ytbmcp',
            (string) $media['destination'],
            '<media destination> must target the media/com_ytbmcp/ directory.'
        );
        self::assertSame(
            'media',
            (string) $media['folder'],
            "<media folder> must point at the package's staged media/ folder."
        );
    }

    /** The <media> element MUST enumerate the DXT bundle filename. */
    public function test_manifest_media_ships_the_dxt_bundle(): void
    {
        $xml = \simplexml_load_string(self::read(self::MANIFEST_REL));
        self::assertNotFalse($xml);

        $filenames = [];
        foreach ($xml->media->filename as $fn) {
            $filenames[] = (string) $fn;
        }
        self::assertContains(
            'yt-builder-mcp.dxt',
            $filenames,
            '<media> must ship yt-builder-mcp.dxt so install copies it to media/com_ytbmcp/.'
        );
    }

    /**
     * The runtime .encryption_key usage MUST remain intact: the resolver still
     * targets media/com_ytbmcp/.encryption_key, and the manifest must NOT
     * claim to ship the key-file (it is auto-generated lazily at runtime).
     */
    public function test_manifest_does_not_ship_the_runtime_encryption_key(): void
    {
        $xml = \simplexml_load_string(self::read(self::MANIFEST_REL));
        self::assertNotFalse($xml);

        foreach ($xml->media->filename as $fn) {
            self::assertNotSame(
                '.encryption_key',
                (string) $fn,
                'The .encryption_key is auto-generated at runtime by '
                . 'JoomlaEncryptionKeyResolver — it must NOT be shipped by the package.'
            );
        }
    }

    // ── #4: reveal-box CTA wiring ─────────────────────────────────────────

    /**
     * The reveal-box template MUST wire the download CTA at the canonical
     * site-relative media path, mirroring WP's plugins_url(...) CTA.
     */
    public function test_template_wires_dxt_download_cta(): void
    {
        $tmpl = self::read(self::TEMPLATE_REL);

        self::assertStringContainsString(
            "'/media/com_ytbmcp/yt-builder-mcp.dxt'",
            $tmpl,
            'Reveal box must build the DXT URL under media/com_ytbmcp/ (Uri::root-relative).'
        );
        self::assertStringContainsString(
            '$this->siteUrl',
            $tmpl,
            'The DXT URL must be anchored at the site root via $this->siteUrl (= rtrim(Uri::root(), "/")).'
        );
        self::assertStringContainsString(
            'download',
            $tmpl,
            'The CTA anchor must carry the HTML5 download attribute.'
        );
        self::assertStringContainsString(
            "Text::_('COM_YTBMCP_DOWNLOAD_DXT')",
            $tmpl,
            'The CTA caption must use the COM_YTBMCP_DOWNLOAD_DXT i18n key.'
        );
    }

    /** The existing Site-URL + token + copy + Advanced UX MUST be preserved. */
    public function test_template_preserves_existing_reveal_box_ux(): void
    {
        $tmpl = self::read(self::TEMPLATE_REL);

        self::assertStringContainsString('ytb-mcp-site-url', $tmpl, 'Site URL field preserved.');
        self::assertStringContainsString('ytb-mcp-revealed-token', $tmpl, 'Token field preserved.');
        self::assertStringContainsString('data-ytb-copy', $tmpl, 'Copy buttons preserved.');
        self::assertStringContainsString(
            'npx -y @wootsup/yt-builder-mcp setup',
            $tmpl,
            'Advanced (manual npx) setup block preserved.'
        );
        self::assertStringContainsString(
            'AI_PROMPT_TEMPLATE',
            $tmpl,
            'Advanced (AI-prompt) block preserved.'
        );
    }

    // ── #4: i18n key ──────────────────────────────────────────────────────

    public function test_ini_defines_download_cta_label(): void
    {
        $ini = self::read(self::INI_REL);
        self::assertMatchesRegularExpression(
            '/^COM_YTBMCP_DOWNLOAD_DXT="[^"]+"/m',
            $ini,
            'com_ytbmcp.ini must define a non-empty COM_YTBMCP_DOWNLOAD_DXT label.'
        );
    }
}
