<?php
/**
 * BrandAssets — WootsUp logo SVG + brand palette.
 *
 * Pins:
 *  - SVG markup invariants (viewBox, aria-label, teal fill, role=img)
 *  - Size clamping (1..512) so the inline mark can't be poisoned
 *  - Inline-styles block exposes the contract classes the SettingsPage
 *    uses (`ytb-brand-header`, `ytb-brand-cta-primary`, `ytb-tab-panel`,
 *    `ytb-diag-grid`, `ytb-about-cmd`, `ytb-brand-footer`)
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\PlatformWordPress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\WordPress\BrandAssets;

#[CoversClass(BrandAssets::class)]
final class BrandAssetsTest extends TestCase
{
    public function test_logo_renders_with_viewBox_and_accessibility_attrs(): void
    {
        $svg = BrandAssets::renderLogo(32);
        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('viewBox="0 0 33.866666 33.866666"', $svg);
        self::assertStringContainsString('aria-label="WootsUp"', $svg);
        self::assertStringContainsString('role="img"', $svg);
        self::assertStringContainsString('data-testid="wootsup-logo"', $svg);
        self::assertStringContainsString('width="32"', $svg);
        self::assertStringContainsString('height="32"', $svg);
    }

    public function test_logo_uses_brand_teal_for_rect(): void
    {
        $svg = BrandAssets::renderLogo();
        self::assertStringContainsString('fill="' . BrandAssets::COLOR_TEAL . '"', $svg);
        self::assertSame('#2fd1cd', BrandAssets::COLOR_TEAL);
    }

    public function test_logo_clamps_oversized_input(): void
    {
        $svg = BrandAssets::renderLogo(99_999);
        self::assertStringContainsString('width="512"', $svg);
        self::assertStringContainsString('height="512"', $svg);
    }

    public function test_logo_clamps_negative_input_to_one(): void
    {
        $svg = BrandAssets::renderLogo(-7);
        self::assertStringContainsString('width="1"', $svg);
        self::assertStringContainsString('height="1"', $svg);
    }

    public function test_logo_applies_optional_css_class(): void
    {
        $svg = BrandAssets::renderLogo(32, 'ytb-brand-header__mark');
        self::assertStringContainsString('class="ytb-brand-header__mark"', $svg);
    }

    public function test_logo_escapes_css_class_value(): void
    {
        $svg = BrandAssets::renderLogo(32, '"><script>alert(1)</script>');
        self::assertStringNotContainsString('<script', $svg);
    }

    public function test_color_constants_exist_and_are_strings(): void
    {
        self::assertIsString(BrandAssets::COLOR_TEAL);
        self::assertIsString(BrandAssets::COLOR_INK);
        self::assertIsString(BrandAssets::COLOR_TEAL_TINT);
        self::assertIsString(BrandAssets::COLOR_BORDER);
        self::assertIsString(BrandAssets::COLOR_MUTED);
    }

    public function test_inline_styles_define_contract_classes(): void
    {
        $css = BrandAssets::renderInlineStyles();
        foreach ([
            '.ytb-brand-header',
            '.ytb-brand-cta-primary',
            '.ytb-tab-panel',
            '.ytb-diag-grid',
            '.ytb-diag-card',
            '.ytb-about-cmd',
            '.ytb-about-clients',
            '.ytb-brand-footer',
            '.ytb-version-badge',
        ] as $cls) {
            self::assertStringContainsString($cls, $css, "missing brand contract class: {$cls}");
        }
    }

    public function test_inline_styles_use_brand_teal(): void
    {
        $css = BrandAssets::renderInlineStyles();
        self::assertStringContainsString(BrandAssets::COLOR_TEAL, $css);
    }
}
