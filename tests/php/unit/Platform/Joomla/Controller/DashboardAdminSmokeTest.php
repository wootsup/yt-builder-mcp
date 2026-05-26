<?php
/**
 * com_ytbmcp admin Dashboard smoke-test — structural & parity contracts.
 *
 * Wave-9 (2026-05-24). The admin View/Controller/template are autoloaded by
 * Joomla's MVCFactory at runtime (NOT in composer's PSR-4 map), so — like the
 * api-controller smoke suites — we inspect the source files directly with
 * regex/grep assertions on the contract surfaces:
 *
 *   - Controller exposes generateKey + revokeKey tasks, each gated by
 *     an admin-capability check + Session::checkToken (CSRF) + redirect.
 *   - View loads keys / diagnostics / one-shot reveal data.
 *   - Template renders all three tabs (native uitab) + a native page
 *     heading/footer (logo SVG kept) + copy JS, consuming verbatim brand
 *     strings from JoomlaBrandStrings.
 *   - No leftover Wave-1 scaffold text ("Wave 1 scaffold" / "ships in Wave").
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Controller
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Controller;

use PHPUnit\Framework\TestCase;

final class DashboardAdminSmokeTest extends TestCase
{
    private const BASE = 'src/packaging/joomla/extensions/com_ytbmcp/administrator';

    private function root(): string
    {
        return \dirname(__DIR__, 6);
    }

    private function read(string $rel): string
    {
        $path = $this->root() . '/' . self::BASE . '/' . $rel;
        if (!\is_file($path)) {
            self::fail("Expected admin source missing: $path");
        }
        return (string) \file_get_contents($path);
    }

    // --- Controller -------------------------------------------------------

    public function test_controller_exposes_key_lifecycle_tasks(): void
    {
        $src = $this->read('src/Controller/DashboardController.php');
        self::assertMatchesRegularExpression('/public function\s+generateKey\s*\(/', $src);
        self::assertMatchesRegularExpression('/public function\s+revokeKey\s*\(/', $src);
    }

    public function test_controller_enforces_csrf_and_admin_capability(): void
    {
        $src = $this->read('src/Controller/DashboardController.php');
        self::assertStringContainsString('Session::checkToken', $src, 'Mutating tasks must verify the Joomla CSRF token.');
        // The admin gate is delegated to the shared JoomlaAdminAccess
        // choke-point (W9-T3) — both render + tasks enforce the SAME rule.
        self::assertStringContainsString('JoomlaAdminAccess::assert', $src, 'Admin-only gate must mirror WP manage_options via the shared choke-point.');
        self::assertStringContainsString('assertAdmin()', $src, 'generateKey/revokeKey must invoke the admin gate.');
    }

    public function test_view_render_is_admin_gated(): void
    {
        $src = $this->read('src/View/Dashboard/HtmlView.php');
        // Parity with WP SettingsPage::render() manage_options gate: the
        // dashboard render path must deny non-admins before exposing keys.
        self::assertStringContainsString('JoomlaAdminAccess::assert', $src, 'View::display() must gate render with the admin capability check.');
    }

    public function test_controller_wires_keyservice_and_keystore(): void
    {
        $src = $this->read('src/Controller/DashboardController.php');
        self::assertStringContainsString('JoomlaSigningSecret::ensure()', $src);
        self::assertStringContainsString('new KeyService(', $src);
        self::assertStringContainsString('->register(', $src);
        self::assertStringContainsString('->revoke(', $src);
    }

    public function test_controller_stashes_one_shot_reveal_in_user_state(): void
    {
        $src = $this->read('src/Controller/DashboardController.php');
        self::assertStringContainsString('setUserState(HtmlView::STATE_REVEAL_TOKEN', $src);
        self::assertStringContainsString('JoomlaPickupChannel', $src, 'Pickup-nonce flow must be wired for the AI-prompt CTA.');
    }

    // --- View -------------------------------------------------------------

    public function test_view_loads_keys_and_diagnostics(): void
    {
        $src = $this->read('src/View/Dashboard/HtmlView.php');
        self::assertStringContainsString('JoomlaKeyStore', $src);
        self::assertStringContainsString('JoomlaSigningSecret::get()', $src);
        // W11-T4: YT version/presence now comes from the Joomla-native probe
        // (DB #__extensions + manifest fallback), not the runtime YoothemeAdapter.
        self::assertStringContainsString('JoomlaYoothemeProbe', $src);
        self::assertStringContainsString('JoomlaSchemaVersion::get()', $src);
        self::assertStringContainsString('consumeRevealedToken', $src);
    }

    public function test_view_resolves_tab_fail_closed_to_keys(): void
    {
        $src = $this->read('src/View/Dashboard/HtmlView.php');
        self::assertStringContainsString('in_array($requested, self::TABS, true)', $src);
        self::assertStringContainsString('self::TAB_KEYS', $src);
    }

    /**
     * W11-T4 (#13/#19): the Diagnostics tab showed "YOOtheme Pro —" and the
     * "required" notice fired even when YT Pro was installed, because the old
     * View tried a RUNTIME YT bootstrap (YtBootstrapper::ensure()) — but YT
     * Pro only bootstraps on the REST/API + frontend surface (ADR-001), never
     * in the administrator application. The View must now read the version
     * from the Joomla-native {@see JoomlaYoothemeProbe} (DB #__extensions +
     * manifest fallback, no runtime YT load), so it works reliably in admin.
     */
    public function test_view_detects_yt_via_joomla_native_probe_not_runtime_bootstrap(): void
    {
        $src = $this->read('src/View/Dashboard/HtmlView.php');
        self::assertStringContainsString(
            'JoomlaYoothemeProbe',
            $src,
            'View must detect YT via the Joomla-native probe (works in the admin app).'
        );
        self::assertMatchesRegularExpression(
            '/\$this->ytVersion\s*=\s*\(new JoomlaYoothemeProbe\(\)\)->detectVersion\(\)/',
            $src,
            'ytVersion must come from JoomlaYoothemeProbe::detectVersion().'
        );
        // The admin View must NOT runtime-bootstrap YT — that path can never
        // succeed in the administrator application and was the root cause of
        // the false "required" notice. (We pin on the actual CALL, not a
        // mention of the class name in explanatory comments.)
        self::assertDoesNotMatchRegularExpression(
            '/YtBootstrapper::ensure\s*\(/',
            $src,
            'Admin View must not call YtBootstrapper::ensure() (YT never loads in the admin app).'
        );
        // And it must not depend on the runtime YoothemeAdapter for version.
        self::assertStringNotContainsString(
            'new YoothemeAdapter()',
            $src,
            'Admin View must not read the version from the runtime YoothemeAdapter.'
        );
    }

    /**
     * W9-T6 (#13): the View must expose a $ytPresent flag derived from the
     * SAME YT-detection the Diagnostics tab uses — getVersion() non-null
     * after the lazy-bootstrap attempt — so the template can render a
     * branded "YOOtheme Pro required" notice (parity with WP's
     * after_setup_theme fallback admin page) without re-probing YT.
     */
    public function test_view_exposes_yt_present_flag_from_version_detection(): void
    {
        $src = $this->read('src/View/Dashboard/HtmlView.php');
        self::assertStringContainsString('public bool $ytPresent', $src, 'View must expose a $ytPresent flag for the YT-missing notice.');
        self::assertMatchesRegularExpression(
            '/\$this->ytPresent\s*=\s*\$this->ytVersion\s*!==\s*null/',
            $src,
            '$ytPresent must be derived from the same getVersion() signal the Diagnostics tab uses.'
        );
        self::assertStringContainsString("MIN_YT_VERSION = '4.0'", $src, 'Min YT version must match WP YTB_MCP_MIN_YT_VERSION (4.0).');
    }

    /**
     * W9-T6 (#13): the template renders the branded notice ONLY when YT is
     * absent, uses i18n keys (not hardcoded copy), and keeps the keys table
     * usable (the notice sits above the tab nav, not replacing it).
     */
    public function test_template_renders_yt_missing_notice_when_absent(): void
    {
        $src = $this->read('tmpl/dashboard/default.php');
        self::assertMatchesRegularExpression('/if\s*\(\s*!\$this->ytPresent\s*\)/', $src, 'Notice must be gated on !$ytPresent.');
        self::assertStringContainsString('COM_YTBMCP_YT_REQUIRED_HEADING', $src, 'Notice must use an i18n heading key.');
        self::assertStringContainsString('COM_YTBMCP_YT_REQUIRED_BODY', $src, 'Notice must use an i18n body key.');
        self::assertStringContainsString('COM_YTBMCP_YT_REQUIRED_CTA', $src, 'Notice must use an i18n CTA key.');
        // W11: the notice renders as a native Bootstrap warning alert
        // (no custom .ytb-* class).
        self::assertStringContainsString('alert alert-warning', $src, 'YT-missing notice must be a native Bootstrap alert.');
        self::assertStringContainsString('yootheme.com', $src, 'Notice CTA must link to yootheme.com (parity with WP fallback).');
    }

    public function test_yt_required_i18n_keys_exist(): void
    {
        $ini = (string) \file_get_contents($this->root() . '/' . self::BASE . '/language/en-GB/com_ytbmcp.ini');
        self::assertStringContainsString('COM_YTBMCP_YT_REQUIRED_HEADING=', $ini);
        self::assertStringContainsString('COM_YTBMCP_YT_REQUIRED_BODY=', $ini);
        self::assertStringContainsString('COM_YTBMCP_YT_REQUIRED_CTA=', $ini);
    }

    // --- Template ---------------------------------------------------------

    public function test_template_renders_three_tabs(): void
    {
        $src = $this->read('tmpl/dashboard/default.php');
        self::assertStringContainsString('_tab_keys.php', $src);
        self::assertStringContainsString('_tab_diagnostics.php', $src);
        self::assertStringContainsString('_tab_about.php', $src);
    }

    public function test_template_has_native_heading_footer_logo_and_copy_js(): void
    {
        $src = $this->read('tmpl/dashboard/default.php');
        // W11: native Joomla page heading + footer. The logo SVG is the only
        // brand element kept; the heading/footer + unofficial badge are native
        // Bootstrap (no custom .ytb-* brand-surface classes).
        self::assertStringContainsString('JoomlaBrandAssets::renderLogo', $src, 'Logo SVG (only brand element kept) must render in the heading.');
        self::assertStringContainsString('COM_YTBMCP_BRAND_TITLE', $src, 'Native page heading must show the brand title.');
        self::assertStringContainsString('COM_YTBMCP_UNOFFICIAL', $src, 'Heading must carry the nominative "unofficial" label.');
        self::assertMatchesRegularExpression('/badge\s+bg-warning/', $src, 'The unofficial label must render as a native Bootstrap badge.');
        self::assertStringContainsString('COM_YTBMCP_FOOTER_SECURITY', $src, 'Native footer must keep the security-disclosure link.');
        self::assertStringContainsString('data-ytb-copy', $src, 'Copy-to-clipboard behaviour hooks must remain.');
        // W11-T6: header CTA row (Generate Key / Documentation / wootsup.com)
        // + the prominent product-site link in header AND footer.
        self::assertStringContainsString('#ytb-mcp-generate', $src, 'Header must carry a Generate Key CTA deep-linking to the generate form.');
        self::assertStringContainsString('HtmlView::DOCS_URL', $src, 'Header must carry a Documentation CTA.');
        self::assertStringContainsString('HtmlView::HOME_URL', $src, 'Header + footer must link to the wootsup.com product site.');
        self::assertStringContainsString('COM_YTBMCP_FOOTER_HOME', $src, 'Footer must include the wootsup.com link.');
    }

    public function test_yt_builder_home_i18n_keys_exist(): void
    {
        $ini = (string) \file_get_contents($this->root() . '/' . self::BASE . '/language/en-GB/com_ytbmcp.ini');
        self::assertStringContainsString('COM_YTBMCP_VISIT_WOOTSUP=', $ini, 'Header wootsup.com CTA label key must exist.');
        self::assertStringContainsString('COM_YTBMCP_FOOTER_HOME=', $ini, 'Footer wootsup.com link label key must exist.');
    }

    public function test_keys_tab_consumes_verbatim_brand_strings(): void
    {
        $src = $this->read('tmpl/dashboard/_tab_keys.php');
        self::assertStringContainsString('JoomlaBrandStrings::REVEAL_TOKEN_SAVE_WARNING', $src);
        self::assertStringContainsString('JoomlaBrandStrings::AI_PROMPT_TEMPLATE', $src);
        self::assertStringContainsString('JoomlaBrandStrings::REVOKE_CONFIRMATION_PROMPT', $src);
        self::assertStringContainsString('dashboard.generateKey', $src);
        self::assertStringContainsString('dashboard.revokeKey', $src);
        self::assertStringContainsString("HTMLHelper::_('form.token')", $src);
    }

    // --- access.xml (component ACL) --------------------------------------

    public function test_access_xml_exists_and_is_well_formed(): void
    {
        $path = $this->root() . '/' . self::BASE . '/access.xml';
        self::assertFileExists($path, 'Component access.xml must ship so Joomla can govern com_ytbmcp permissions.');

        $xml = \simplexml_load_string((string) \file_get_contents($path));
        self::assertNotFalse($xml, 'access.xml must be well-formed XML.');
        self::assertSame('access', $xml->getName(), 'Root element must be <access>.');
        self::assertSame('com_ytbmcp', (string) $xml['component'], 'access.xml must target com_ytbmcp.');

        $actions = [];
        foreach ($xml->section->action as $action) {
            $actions[] = (string) $action['name'];
        }
        self::assertContains('core.admin', $actions, 'core.admin governs the admin dashboard + key tasks.');
        self::assertContains('core.manage', $actions, 'core.manage is the manager-level fallback.');
        self::assertContains('core.options', $actions, 'core.options lets a site delegate component config.');
    }

    public function test_manifest_ships_access_xml(): void
    {
        $manifest = (string) \file_get_contents($this->root() . '/src/packaging/joomla/extensions/com_ytbmcp/ytbmcp.xml');
        self::assertStringContainsString('<filename>access.xml</filename>', $manifest, 'Manifest must list access.xml so the installer copies it.');
    }

    public function test_no_scaffold_placeholder_text_remains(): void
    {
        foreach ([
            'tmpl/dashboard/default.php',
            'tmpl/dashboard/_tab_keys.php',
            'tmpl/dashboard/_tab_diagnostics.php',
            'tmpl/dashboard/_tab_about.php',
            'src/View/Dashboard/HtmlView.php',
        ] as $rel) {
            $src = $this->read($rel);
            self::assertDoesNotMatchRegularExpression('/Wave\s*1\s*scaffold/i', $src, "$rel still has scaffold text.");
            self::assertDoesNotMatchRegularExpression('/ships in Wave/i', $src, "$rel still has 'ships in Wave' text.");
            self::assertStringNotContainsString('TODO', $src, "$rel still has a TODO marker.");
        }
    }
}
