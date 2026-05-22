<?php
/**
 * SettingsPage — tab-aware Rich Brand Page render path.
 *
 * Covers the additions from Plugin-Audit Round 2 (2026-05-22):
 *  - Tools-submenu placement (2026-05-22 revision) — `add_submenu_page`
 *    with parent `tools.php`; tabs are inline (?tab=keys|diagnostics|about)
 *    rather than separate sidebar entries
 *  - Active-tab detection from `$_GET['tab']` (with allow-list fallback)
 *  - Capability gate on each tab (single `current_user_can` gate)
 *  - Brand header on every tab (logo + version badge + CTA row)
 *  - About-tab contains the NPM install command + 6-client list
 *  - Diagnostics-tab exposes plugin/YT/WP/PHP/schema version cells
 *  - Footer brand-lockup ("WootsUp — A getimo productions company")
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\PlatformWordPress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Auth\KeyStore;
use WootsUp\BuilderMcp\Auth\SigningSecret;
use WootsUp\BuilderMcp\Platform\WordPress\SettingsPage;

#[CoversClass(SettingsPage::class)]
final class SettingsPageTabsTest extends TestCase
{
    protected function setUp(): void
    {
        // H3 — TEST-STUBS-1: WP-function stubs hoisted to tests/php/bootstrap.php
        // so PHPStan can see them and duplicate evals across test files don't
        // fight over the global symbol table. We only reset per-test state here.
        $GLOBALS['ytb_test_options'] = [];
        $GLOBALS['ytb_test_menu_pages'] = [];
        $GLOBALS['ytb_test_submenu_pages'] = [];
        $GLOBALS['ytb_test_cap_allowed'] = true;
        unset($_GET['tab']);
    }

    protected function tearDown(): void
    {
        unset($_GET['tab']);
    }

    private function page(): SettingsPage
    {
        return new SettingsPage(
            new KeyService(SigningSecret::ensure()),
            new KeyStore(),
        );
    }

    private function renderOutput(SettingsPage $page): string
    {
        ob_start();
        try {
            $page->render();
        } finally {
            $output = (string) ob_get_clean();
        }
        return $output;
    }

    public function test_add_menu_registers_settings_under_tools_submenu(): void
    {
        $this->page()->add_menu();

        // No top-level menu page — the plugin is a utility, lives under Tools.
        self::assertArrayNotHasKey(SettingsPage::SLUG, $GLOBALS['ytb_test_menu_pages']);

        $slugs = array_column($GLOBALS['ytb_test_submenu_pages'], 'slug');
        self::assertContains(SettingsPage::SLUG, $slugs);

        $entry = null;
        foreach ($GLOBALS['ytb_test_submenu_pages'] as $row) {
            if ($row['slug'] === SettingsPage::SLUG) {
                $entry = $row;
                break;
            }
        }
        self::assertNotNull($entry);
        self::assertSame('tools.php', $entry['parent']);
        self::assertSame('manage_options', $entry['capability']);
        self::assertSame('YT Builder MCP', $entry['menu_title']);
    }

    public function test_render_aborts_without_capability_on_every_tab(): void
    {
        $GLOBALS['ytb_test_cap_allowed'] = false;
        foreach (['', 'keys', 'diagnostics', 'about', 'bogus'] as $tab) {
            $_GET['tab'] = $tab;
            $page = $this->page();
            $caught = null;
            try {
                $page->render();
            } catch (\RuntimeException $e) {
                $caught = $e;
            }
            self::assertNotNull($caught, "tab '{$tab}' did not enforce capability");
            self::assertStringContainsString('Insufficient permissions', (string) $caught->getMessage());
        }
    }

    public function test_keys_is_the_default_tab(): void
    {
        $output = $this->renderOutput($this->page());
        self::assertStringContainsString('>Bearer Keys<', $output);
        self::assertStringContainsString('Generate New Key', $output);
        // The diagnostics grid wrapper element (not the CSS rule) must
        // NOT appear on the default tab. The inline <style> block always
        // declares `.ytb-diag-grid` — we look for the DIV usage instead.
        self::assertStringNotContainsString('<div class="ytb-diag-grid"', $output);
    }

    public function test_unknown_tab_falls_back_to_keys(): void
    {
        $_GET['tab'] = '../../etc/passwd';
        $output = $this->renderOutput($this->page());
        self::assertStringContainsString('Generate New Key', $output);
    }

    public function test_diagnostics_tab_emits_versions_card_and_endpoint_count(): void
    {
        $_GET['tab'] = SettingsPage::TAB_DIAGNOSTICS;
        // Pre-register a single endpoint so the count cell is non-zero.
        $GLOBALS['ytb_test_rest_routes'] = ['/yt-builder-mcp/v1/health'];

        $output = $this->renderOutput($this->page());

        self::assertStringContainsString('Diagnostics', $output);
        self::assertStringContainsString('ytb-diag-grid', $output);
        self::assertStringContainsString('Plugin', $output);
        self::assertStringContainsString('YOOtheme Pro', $output);
        self::assertStringContainsString('WordPress', $output);
        self::assertStringContainsString('Signing secret', $output);
        // The Bearer-key count must appear (0 = freshly initialised KeyStore).
        self::assertStringContainsString('Bearer keys', $output);
        // The endpoint list must surface the registered route.
        self::assertStringContainsString('/yt-builder-mcp/v1/health', $output);
    }

    public function test_about_tab_includes_npm_install_command_and_nine_clients(): void
    {
        $_GET['tab'] = SettingsPage::TAB_ABOUT;
        $output = $this->renderOutput($this->page());

        self::assertStringContainsString('About YOOtheme Builder MCP (unofficial)', $output);
        self::assertStringContainsString('npx -y @wootsup/yt-builder-mcp setup', $output);
        // Wave B (2026-05-22) expanded the auto-configure surface from 6 → 9
        // clients. All 9 must appear in the About-tab pill list.
        foreach ([
            'Claude Desktop', 'Claude Code', 'Cursor', 'Zed', 'Continue',
            'Cline', 'Roo Code', 'Codex CLI', 'Gemini CLI',
        ] as $client) {
            self::assertStringContainsString($client, $output);
        }
        // License + repo links.
        self::assertStringContainsString('GPL-2.0-or-later', $output);
        self::assertStringContainsString('MIT', $output);
        self::assertStringContainsString('github.com/wootsup/yt-builder-mcp', $output);
    }

    public function test_every_tab_renders_brand_header_with_logo_and_version_badge(): void
    {
        foreach ([SettingsPage::TAB_KEYS, SettingsPage::TAB_DIAGNOSTICS, SettingsPage::TAB_ABOUT] as $tab) {
            $_GET['tab'] = $tab;
            $output = $this->renderOutput($this->page());
            self::assertStringContainsString('ytb-brand-header', $output, "tab {$tab}: brand header missing");
            self::assertStringContainsString('data-testid="wootsup-logo"', $output, "tab {$tab}: logo missing");
            self::assertStringContainsString('ytb-version-badge', $output, "tab {$tab}: version badge missing");
        }
    }

    public function test_every_tab_renders_footer_brand_lockup(): void
    {
        foreach ([SettingsPage::TAB_KEYS, SettingsPage::TAB_DIAGNOSTICS, SettingsPage::TAB_ABOUT] as $tab) {
            $_GET['tab'] = $tab;
            $output = $this->renderOutput($this->page());
            self::assertStringContainsString('ytb-brand-footer', $output, "tab {$tab}: brand footer missing");
            self::assertStringContainsString('WootsUp', $output);
            self::assertStringContainsString('getimo productions', $output);
        }
    }

    public function test_tab_navigation_marks_active_tab(): void
    {
        $_GET['tab'] = SettingsPage::TAB_ABOUT;
        $output = $this->renderOutput($this->page());
        // Two anchors — the about anchor has nav-tab-active, the others do not.
        self::assertMatchesRegularExpression(
            '/<a [^>]*tab=about[^>]*class="nav-tab nav-tab-active"/',
            $output,
        );
    }

    public function test_slug_constants_are_stable_contract(): void
    {
        self::assertSame('ytb-mcp-settings', SettingsPage::SLUG);
        self::assertSame('keys', SettingsPage::TAB_KEYS);
        self::assertSame('diagnostics', SettingsPage::TAB_DIAGNOSTICS);
        self::assertSame('about', SettingsPage::TAB_ABOUT);
    }

    // ── Wave C — Reveal-Box 3-CTA + Pickup-Nonce flow ──────────────────

    /**
     * When the URL carries `?revealed=<kid>&pickup=<nonce>` and a token is
     * available, all three Wave-C sections are rendered: AI prompt CTA,
     * manual fallback, and token-with-copy-button.
     */
    public function test_reveal_box_renders_all_three_ctas_with_pickup(): void
    {
        $kid = 'testkid01';
        $nonce = 'AbCdEf012345-_AbCdEf012345-_AbCdEf012345-_AB';
        // Seed transient so `consume_revealed_token` returns a real token.
        $GLOBALS['ytb_test_transients'] = [
            'ytb_mcp_revealed_token_' . $kid => ['value' => 'ytb_live_payloadFOO.sigBAR', 'expires' => 0],
        ];
        $_GET['revealed'] = $kid;
        $_GET['pickup'] = $nonce;

        $output = $this->renderOutput($this->page());

        // Section 1 — AI prompt
        self::assertStringContainsString('Paste this prompt into your AI assistant', $output);
        self::assertStringContainsString('--pickup', $output);
        self::assertStringContainsString('--nonce ' . $nonce, $output);
        self::assertStringContainsString('Copy AI prompt', $output);
        self::assertStringContainsString('/wp-json/yt-builder-mcp/v1/setup/pickup', $output);

        // Section 2 — Manual fallback (collapsed details)
        self::assertStringContainsString('<details', $output);
        self::assertStringContainsString('Or run the wizard manually', $output);
        self::assertStringContainsString('npx -y @wootsup/yt-builder-mcp setup', $output);

        // Section 3 — Token + copy button
        self::assertStringContainsString('ytb_live_payloadFOO.sigBAR', $output);
        self::assertStringContainsString('Copy token', $output);

        // The token MUST NOT appear inside the AI-prompt code block — the
        // entire point of pickup mode is that the token never travels through
        // chat. We assert the prompt-block contains the pickup URL but not
        // the token string.
        $promptStart = strpos($output, 'Paste this prompt into your AI assistant');
        $promptEnd = strpos($output, 'Or run the wizard manually');
        self::assertNotFalse($promptStart);
        self::assertNotFalse($promptEnd);
        $promptSlice = substr($output, $promptStart, $promptEnd - $promptStart);
        self::assertStringNotContainsString('ytb_live_payloadFOO.sigBAR', $promptSlice, 'Token leaked into AI prompt');
    }

    /**
     * If the wp-admin URL lacks the `pickup=` param (e.g. transient failed
     * to store on this host), the AI-prompt section gracefully degrades —
     * only manual setup + token CTAs remain. No PHP warnings.
     */
    public function test_reveal_box_without_pickup_falls_back_to_manual_only(): void
    {
        $kid = 'testkid02';
        $GLOBALS['ytb_test_transients'] = [
            'ytb_mcp_revealed_token_' . $kid => ['value' => 'ytb_live_payloadAAA.sigBBB', 'expires' => 0],
        ];
        $_GET['revealed'] = $kid;
        unset($_GET['pickup']);

        $output = $this->renderOutput($this->page());

        // AI-prompt section is absent (gated by pickup-nonce).
        self::assertStringNotContainsString('Paste this prompt into your AI assistant', $output);
        self::assertStringNotContainsString('--pickup', $output);
        // Manual + token sections still present.
        self::assertStringContainsString('Or run the wizard manually', $output);
        self::assertStringContainsString('ytb_live_payloadAAA.sigBBB', $output);
        self::assertStringContainsString('Copy token', $output);
    }

    /**
     * Malformed pickup-nonce in the URL (length out of range, non-base64url
     * chars) is silently dropped — the reveal box renders without the AI
     * prompt section. Prevents log-noise + leakage from attacker-controlled
     * URLs.
     */
    public function test_reveal_box_rejects_malformed_pickup_nonce(): void
    {
        $kid = 'testkid03';
        $GLOBALS['ytb_test_transients'] = [
            'ytb_mcp_revealed_token_' . $kid => ['value' => 'ytb_live_CCC.DDD', 'expires' => 0],
        ];
        $_GET['revealed'] = $kid;
        $_GET['pickup'] = '<script>alert(1)</script>'; // sanitized + shape-rejected

        $output = $this->renderOutput($this->page());

        self::assertStringNotContainsString('Paste this prompt into your AI assistant', $output);
        self::assertStringNotContainsString('--pickup', $output);
        // The malformed payload must not appear verbatim anywhere (the
        // inline copy-script block contains a legit `<script>` tag — that's
        // expected and unrelated to the malformed nonce echo).
        self::assertStringNotContainsString('alert(1)', $output);
        // Token section still works.
        self::assertStringContainsString('ytb_live_CCC.DDD', $output);
    }

    /**
     * The multi-button copy-script accepts data-ytb-copy / data-ytb-copy-status
     * attributes (Wave C refactor — replaces the single-button #ytb-mcp-copy-token
     * pattern). At least the token-copy button is wired.
     */
    public function test_copy_script_wires_data_ytb_copy_buttons(): void
    {
        $kid = 'testkid04';
        $GLOBALS['ytb_test_transients'] = [
            'ytb_mcp_revealed_token_' . $kid => ['value' => 'ytb_live_EEE.FFF', 'expires' => 0],
        ];
        $_GET['revealed'] = $kid;

        $output = $this->renderOutput($this->page());

        self::assertStringContainsString('data-ytb-copy="ytb-mcp-revealed-token"', $output);
        self::assertStringContainsString('querySelectorAll(\'[data-ytb-copy]\')', $output);
    }
}
