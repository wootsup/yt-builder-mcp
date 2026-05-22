<?php
/**
 * SettingsPage — WP-Admin UI for key issuance + revocation.
 *
 * Wave-6 Fix 17: previously untested. The page logic is mostly
 * presentational, but the capability gate + nonce verification are
 * security-critical. We test the construction path + the read-side
 * render method.
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
final class SettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [];

        // Provide WP-admin function stubs required by the render path.
        if (!function_exists('current_user_can')) {
            eval('function current_user_can(string $cap): bool { return $GLOBALS["ytb_test_cap_allowed"] ?? true; }');
        }
        if (!function_exists('wp_die')) {
            eval('function wp_die(string $msg = "", string $title = "", $args = []): void { throw new \RuntimeException("wp_die: " . $msg); }');
        }
        if (!function_exists('__')) {
            eval('function __(string $text, string $domain = ""): string { return $text; }');
        }
        if (!function_exists('esc_html__')) {
            eval('function esc_html__(string $text, string $domain = ""): string { return htmlspecialchars($text, ENT_QUOTES, "UTF-8"); }');
        }
        if (!function_exists('esc_html')) {
            eval('function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, "UTF-8"); }');
        }
        if (!function_exists('esc_attr')) {
            eval('function esc_attr(string $text): string { return htmlspecialchars($text, ENT_QUOTES, "UTF-8"); }');
        }
        if (!function_exists('esc_url')) {
            eval('function esc_url(string $url): string { return $url; }');
        }
        if (!function_exists('admin_url')) {
            eval('function admin_url(string $path = ""): string { return "https://example.test/wp-admin/" . $path; }');
        }
        if (!function_exists('wp_nonce_field')) {
            eval('function wp_nonce_field(string $action): void { echo "<input type=\"hidden\" name=\"_wpnonce\" />"; }');
        }
        if (!function_exists('submit_button')) {
            eval('function submit_button(string $text = "Submit"): void { echo "<button>$text</button>"; }');
        }
        if (!function_exists('date_i18n')) {
            eval('function date_i18n(string $fmt, int $ts): string { return date($fmt, $ts); }');
        }
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }
        if (!defined('YEAR_IN_SECONDS')) {
            define('YEAR_IN_SECONDS', 31536000);
        }
        $GLOBALS['ytb_test_cap_allowed'] = true;
    }

    private function settingsPage(): SettingsPage
    {
        return new SettingsPage(
            new KeyService(SigningSecret::ensure()),
            new KeyStore(),
        );
    }

    public function test_render_aborts_without_manage_options_capability(): void
    {
        $GLOBALS['ytb_test_cap_allowed'] = false;
        $page = $this->settingsPage();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient permissions');
        $page->render();
    }

    public function test_render_emits_keys_heading_when_allowed(): void
    {
        ob_start();
        try {
            $this->settingsPage()->render();
        } finally {
            $output = ob_get_clean();
        }
        self::assertNotEmpty($output);
        self::assertStringContainsString('Bearer Keys', (string) $output);
        self::assertStringContainsString('Generate New Key', (string) $output);
    }

    public function test_render_lists_existing_keys_from_keystore(): void
    {
        $store = new KeyStore();
        $store->register('kid-xyz', [
            'label' => 'My Key',
            'scope' => 'write',
            'created_at' => time(),
            'expires_at' => null,
            'revoked_at' => null,
        ]);
        $page = new SettingsPage(new KeyService(SigningSecret::ensure()), $store);

        ob_start();
        try {
            $page->render();
        } finally {
            $output = ob_get_clean();
        }
        self::assertStringContainsString('My Key', (string) $output);
        self::assertStringContainsString('kid-xyz', (string) $output);
    }

    public function test_slug_constant_is_stable(): void
    {
        // The slug is the URL contract for the WP-admin sub-page; changing
        // it breaks bookmarks and setup-wizard probing.
        self::assertSame('ytb-mcp-settings', SettingsPage::SLUG);
    }
}
