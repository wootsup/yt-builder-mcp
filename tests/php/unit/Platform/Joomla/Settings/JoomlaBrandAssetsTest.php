<?php
/**
 * JoomlaBrandAssets unit-test — pure brand-asset string builders.
 *
 * JoomlaBrandAssets is PSR-4 autoloadable (no Joomla runtime symbols) so it
 * can be instantiated and asserted directly, unlike the MVC view/controller
 * which require the CMS. Pins the brand-parity contract: WootsUp teal + the
 * SVG mark (the only brand element kept after the W11 native-redesign), and
 * that renderInlineStyles() is a deprecated no-op.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Settings
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Settings;

use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Settings\JoomlaBrandAssets;

final class JoomlaBrandAssetsTest extends TestCase
{
    public function test_logo_is_svg_with_brand_teal_and_clamped_size(): void
    {
        $svg = JoomlaBrandAssets::renderLogo(48);
        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('width="48"', $svg);
        self::assertStringContainsString(JoomlaBrandAssets::COLOR_TEAL, $svg);
        self::assertStringContainsString('aria-label="WootsUp"', $svg);
        self::assertStringContainsString('role="img"', $svg);
    }

    public function test_logo_size_is_clamped_between_1_and_512(): void
    {
        self::assertStringContainsString('width="1"', JoomlaBrandAssets::renderLogo(0));
        self::assertStringContainsString('width="512"', JoomlaBrandAssets::renderLogo(9999));
    }

    public function test_logo_css_class_is_escaped(): void
    {
        $svg = JoomlaBrandAssets::renderLogo(32, 'my-logo"onload="x');
        self::assertStringNotContainsString('onload="x', $svg);
        self::assertStringContainsString('class=', $svg);
    }

    /**
     * W11 (2026-05-24): the admin UI was redesigned to render as a native
     * Joomla component, so the bespoke brand surface (the former inline
     * `<style>` block AND its W10-T2 successor component stylesheet) was
     * removed entirely. renderInlineStyles() stays a deprecated no-op for
     * call-site stability. The absence of any custom colour CSS + native-
     * component contract is pinned in
     * {@see \WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin\AdminDarkModePinTest}.
     */
    public function test_inline_styles_is_now_a_deprecated_noop(): void
    {
        self::assertSame(
            '',
            JoomlaBrandAssets::renderInlineStyles(),
            'renderInlineStyles() must emit nothing — the admin UI is now native Joomla/Bootstrap.'
        );
    }
}
