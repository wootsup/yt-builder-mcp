<?php
/**
 * STATIC PIN TEST (Wave-11): com_ytbmcp native Joomla admin look.
 *
 * The admin UI was redesigned (Wave-11, 2026-05-24) to render as a
 * first-class NATIVE Joomla component. The previous approach — a bespoke
 * "brand" surface (custom `media/com_ytbmcp/css/admin.css` registered via the
 * WebAssetManager + `.ytb-*` colour classes across the four dashboard
 * templates) — was dropped entirely. The ONLY brand element kept is the logo
 * SVG. Because every body component is now native Bootstrap / Atum, the page
 * is correct in BOTH Joomla light and dark mode with ZERO custom colour CSS
 * and no WAM dependency — the dark-mode bug the W10 sheet papered over is
 * fixed structurally instead.
 *
 * This pin guards the three structural seams of the redesign so a future
 * refactor cannot silently re-introduce the custom-brand approach:
 *
 *   (a) NO custom colour CSS / WAM registration remains: the component
 *       stylesheet is gone, the View does not registerAndUseStyle it, the
 *       manifest no longer ships a css/ folder, and the templates carry no
 *       hardcoded neutral hex colours.
 *   (b) The view uses native Bootstrap / uitab components: the page heading,
 *       the canonical `uitab` Bootstrap tab set, and Bootstrap cards / tables
 *       / forms / alerts / buttons.
 *   (c) The logo SVG is still rendered (the only brand element kept).
 *
 * Mirrors the grep-style controller-smoke pins (DashboardAdminSmokeTest,
 * DxtMediaParityPinTest) — the MVC view layer is autoloaded by Joomla's
 * MVCFactory at runtime (not in composer's PSR-4 map), so the contract is
 * asserted against the source files directly.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;

final class AdminDarkModePinTest extends TestCase
{
    private const COMPONENT_BASE =
        'src/packaging/joomla/extensions/com_ytbmcp';

    private const ADMIN_BASE = self::COMPONENT_BASE . '/administrator';

    private const CSS_REL = self::COMPONENT_BASE . '/media/css/admin.css';

    private const MANIFEST_REL = self::COMPONENT_BASE . '/ytbmcp.xml';

    private const VIEW_REL = self::ADMIN_BASE . '/src/View/Dashboard/HtmlView.php';

    private const DEFAULT_TMPL_REL = self::ADMIN_BASE . '/tmpl/dashboard/default.php';

    /** All four dashboard template surfaces. */
    private const TEMPLATE_RELS = [
        self::DEFAULT_TMPL_REL,
        self::ADMIN_BASE . '/tmpl/dashboard/_tab_keys.php',
        self::ADMIN_BASE . '/tmpl/dashboard/_tab_diagnostics.php',
        self::ADMIN_BASE . '/tmpl/dashboard/_tab_about.php',
    ];

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

    // ── (a) NO custom colour CSS / WAM registration remains ───────────────

    public function test_custom_component_stylesheet_is_removed(): void
    {
        $path = self::repoRoot() . '/' . self::CSS_REL;
        self::assertFileDoesNotExist(
            $path,
            'The custom brand stylesheet must be gone — the admin UI is now '
            . 'native Joomla/Bootstrap with no custom colour CSS.'
        );
    }

    public function test_view_does_not_register_a_custom_stylesheet(): void
    {
        $src = self::read(self::VIEW_REL);
        self::assertStringNotContainsString(
            'registerAndUseStyle',
            $src,
            'View must not register a custom WebAssetManager stylesheet — '
            . 'native Atum/Bootstrap components carry their own theming.'
        );
        self::assertStringNotContainsString(
            'admin.css',
            $src,
            'View must not reference the removed admin.css.'
        );
    }

    public function test_manifest_no_longer_ships_a_css_folder(): void
    {
        $xml = \simplexml_load_string(self::read(self::MANIFEST_REL));
        self::assertNotFalse($xml, 'ytbmcp.xml must be well-formed XML.');

        $folders = [];
        foreach ($xml->media->folder as $folder) {
            $folders[] = (string) $folder;
        }
        self::assertNotContains(
            'css',
            $folders,
            '<media> must NOT ship a css/ folder — the custom stylesheet was removed.'
        );
    }

    public function test_templates_carry_no_hardcoded_neutral_colours(): void
    {
        // The light-only neutrals the original WP port hardcoded. None must
        // survive — native Bootstrap/Atum components own all neutral theming.
        $forbidden = [
            '#fff', '#fafafa', '#f7f7f7', '#f0f0f1', '#eee', '#ddd',
            '#e0e0e0', '#646970', '#111', '#333', '#444', '#555', '#666', '#888',
        ];
        foreach (self::TEMPLATE_RELS as $rel) {
            // Strip the logo SVG (it legitimately carries the brand teal) so
            // the brand mark cannot trip the neutral-colour guard.
            $blob = \preg_replace('/<svg\b.*?<\/svg>/is', '', self::read($rel)) ?? '';
            foreach ($forbidden as $hex) {
                self::assertStringNotContainsStringIgnoringCase(
                    $hex,
                    $blob,
                    "$rel still hardcodes the neutral colour $hex — use a native "
                    . 'Bootstrap utility / Atum-themed component instead.'
                );
            }
        }
    }

    public function test_templates_carry_no_custom_ytb_colour_classes(): void
    {
        // The bespoke brand-surface class prefix must be gone from the body
        // markup (data-ytb-copy* hooks for the copy JS are behaviour, not
        // brand styling, and are explicitly allowed).
        foreach (self::TEMPLATE_RELS as $rel) {
            $blob = self::read($rel);
            self::assertDoesNotMatchRegularExpression(
                '/class="[^"]*\bytb-[a-z]/i',
                $blob,
                "$rel still uses a custom .ytb-* CSS class — the redesign uses "
                . 'native Bootstrap utility classes only.'
            );
        }
    }

    // ── (b) the view uses native Bootstrap / uitab components ─────────────

    public function test_view_renders_native_joomla_toolbar(): void
    {
        $src = self::read(self::VIEW_REL);
        self::assertStringContainsString(
            'ToolbarHelper::title',
            $src,
            'View must render a standard native Joomla component toolbar title.'
        );
    }

    public function test_default_template_uses_native_uitab_tabset(): void
    {
        $tmpl = self::read(self::DEFAULT_TMPL_REL);
        foreach ([
            "uitab.startTabSet",
            "uitab.addTab",
            "uitab.endTab",
            "uitab.endTabSet",
        ] as $helper) {
            self::assertStringContainsString(
                $helper,
                $tmpl,
                "Tabs must use the canonical native Joomla uitab helper ($helper)."
            );
        }
    }

    public function test_templates_use_native_bootstrap_components(): void
    {
        // Each native primitive the redesign relies on must appear in the body
        // markup (across the four templates combined).
        $combined = '';
        foreach (self::TEMPLATE_RELS as $rel) {
            $combined .= "\n" . self::read($rel);
        }
        foreach ([
            'card'         => 'native Bootstrap .card layout',
            'table'        => 'native Bootstrap .table',
            'btn'          => 'native Bootstrap .btn buttons',
            'form-label'   => 'native Bootstrap .form-label',
            'form-control' => 'native Bootstrap .form-control inputs',
            'form-select'  => 'native Bootstrap .form-select dropdowns',
            'alert'        => 'native Bootstrap .alert notices',
            'badge'        => 'native Bootstrap .badge',
        ] as $needle => $why) {
            self::assertStringContainsString(
                $needle,
                $combined,
                "Redesign must use $why (missing class token: $needle)."
            );
        }
    }

    public function test_yt_missing_notice_is_a_native_bootstrap_alert(): void
    {
        $tmpl = self::read(self::DEFAULT_TMPL_REL);
        self::assertMatchesRegularExpression(
            '/class="alert alert-warning"/',
            $tmpl,
            'The YOOtheme-missing notice must render as a native Bootstrap '
            . 'warning alert.'
        );
    }

    // ── (c) the logo SVG is still rendered (only brand element kept) ──────

    public function test_logo_svg_is_still_rendered(): void
    {
        $tmpl = self::read(self::DEFAULT_TMPL_REL);
        self::assertStringContainsString(
            'JoomlaBrandAssets::renderLogo',
            $tmpl,
            'The WootsUp logo SVG is the one brand element kept — it must still '
            . 'render in the native page heading.'
        );
    }

    // ── (d) W11-T6: header CTAs + prominent wootsup.com + reveal card ─────

    public function test_header_renders_documentation_and_wootsup_ctas(): void
    {
        $tmpl = self::read(self::DEFAULT_TMPL_REL);
        // Task #37 (2026-05-28): the redundant "Generate Key" header CTA was
        // removed for parity with the WP SettingsPage. The Bearer Keys tab is
        // reachable via the primary uitab nav-bar; the form-submit button on
        // the Keys tab carries the "Generate Key" label exclusively.
        //
        // Documentation secondary CTA.
        self::assertStringContainsString('COM_YTBMCP_DOCUMENTATION', $tmpl, 'Header must carry a Documentation CTA.');
        self::assertStringContainsString('HtmlView::DOCS_URL', $tmpl, 'Documentation CTA must link to DOCS_URL.');
        // Prominent wootsup.com product-site CTA (native button, not a bare link).
        self::assertStringContainsString('HtmlView::HOME_URL', $tmpl, 'Header must carry a prominent wootsup.com CTA.');
        self::assertMatchesRegularExpression(
            '/class="btn btn-success"[^>]*href="<\?php echo \$esc\(HtmlView::HOME_URL\)/',
            $tmpl,
            'wootsup.com must render as a prominent native button.'
        );
    }

    public function test_home_url_constant_points_to_product_site(): void
    {
        $src = self::read(self::VIEW_REL);
        self::assertMatchesRegularExpression(
            "/const HOME_URL\s*=\s*'https:\/\/wootsup\.com'/",
            $src,
            'HtmlView must expose a HOME_URL constant pointing at the product site.'
        );
    }

    public function test_footer_links_to_wootsup_com(): void
    {
        $tmpl = self::read(self::DEFAULT_TMPL_REL);
        self::assertStringContainsString('COM_YTBMCP_FOOTER_HOME', $tmpl, 'Footer must include a wootsup.com link.');
    }

    public function test_reveal_box_is_a_native_card_not_a_success_alert(): void
    {
        $tmpl = self::read(self::ADMIN_BASE . '/tmpl/dashboard/_tab_keys.php');
        // The reveal box must NOT be a contextual success alert (Atum recolours
        // contained anchors/buttons green+underline, breaking the download btn).
        self::assertStringNotContainsString(
            'alert alert-success',
            $tmpl,
            'Reveal box must be a native .card, NOT an .alert.alert-success.'
        );
        self::assertMatchesRegularExpression(
            '/class="card[^"]*"[^>]*role="status"/',
            $tmpl,
            'Reveal box must render as a native Bootstrap .card.'
        );
        // The DXT download CTA stays a native primary button.
        self::assertStringContainsString('btn btn-primary btn-lg', $tmpl, 'Download CTA must be a native primary button.');
    }

    public function test_reveal_copy_buttons_are_uniform_outline_secondary(): void
    {
        $tmpl = self::read(self::ADMIN_BASE . '/tmpl/dashboard/_tab_keys.php');
        // Both copy buttons (Site URL + Bearer token) must share ONE style.
        // The old markup used btn-primary on the token copy button — that
        // inconsistency must be gone.
        self::assertSame(
            2,
            \preg_match_all('/btn btn-outline-secondary" data-ytb-copy="ytb-mcp-(site-url|revealed-token)"/', $tmpl),
            'Site URL + Bearer token copy buttons must both be btn-outline-secondary.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/btn btn-primary" data-ytb-copy="ytb-mcp-revealed-token"/',
            $tmpl,
            'The Bearer-token copy button must not be a primary button (inconsistent with Site URL).'
        );
    }
}
