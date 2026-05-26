<?php
/**
 * BrandAssets — WootsUp logo SVG + brand palette.
 *
 * Pins:
 *  - SVG markup invariants (viewBox, aria-label, teal fill, role=img)
 *  - Size clamping (1..512) so the inline mark can't be poisoned
 *  - W11-T2 (2026-05-24): the WP-Admin Settings page was redesigned to
 *    render with native wp-admin markup, so the bespoke brand surface (the
 *    former `.ytb-*` inline `<style>` block) was removed entirely.
 *    renderInlineStyles() is now a deprecated no-op; the logo SVG is the
 *    ONLY brand element kept. Mirrors the Joomla-side W11 native redesign.
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
        // Only the two brand-mark colours survive the W11-T2 native redesign:
        // teal (logo rect) + ink (logo/text contrast). The former neutral
        // palette (TEAL_TINT / BORDER / MUTED) only fed the dropped `.ytb-*`
        // brand CSS, so those constants were removed.
        self::assertIsString(BrandAssets::COLOR_TEAL);
        self::assertIsString(BrandAssets::COLOR_INK);
    }

    /**
     * W11-T2: the bespoke `.ytb-*` brand stylesheet was dropped so the
     * Settings page renders native to wp-admin. renderInlineStyles() stays a
     * deprecated no-op for call-site stability — it must emit nothing.
     */
    public function test_inline_styles_is_now_a_deprecated_noop(): void
    {
        self::assertSame(
            '',
            BrandAssets::renderInlineStyles(),
            'renderInlineStyles() must emit nothing — the Settings page is now native wp-admin.',
        );
    }
}
