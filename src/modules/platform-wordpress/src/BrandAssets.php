<?php
/**
 * BrandAssets — WootsUp brand primitives for the WP-Admin Settings page.
 *
 * Single class (PSR-4 one-file-one-class) bundling the inline-SVG brand
 * mark and the palette constants used by the Settings-page surface.
 *
 * The SVG is ported verbatim from the api-mapper React component
 * (`src/modules/admin-ui/src/components/Layout/WootsUpLogo.tsx`); paths
 * are static, only the numeric `size` attr is variable, so direct `echo`
 * of the return value is safe (no user-controllable input on this path).
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Platform\WordPress
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\WordPress;

final class BrandAssets
{
    /** Primary WootsUp teal — CTAs, badges, brand-mark. */
    public const COLOR_TEAL = '#2fd1cd';

    /** Brand ink (dark text on light surfaces). */
    public const COLOR_INK = '#0a1421';

    /** Subtle teal tint for badges + soft highlights (rgba string). */
    public const COLOR_TEAL_TINT = 'rgba(47, 209, 205, 0.15)';

    /** Neutral border for cards / section dividers. */
    public const COLOR_BORDER = '#e0e0e0';

    /** Muted text (footer, secondary metadata). */
    public const COLOR_MUTED = '#646970';

    /**
     * Render the WootsUp logo as an inline SVG string.
     *
     * Sourced-From: src/modules/admin-ui/src/components/Layout/WootsUpLogo.tsx
     * (api-mapper). Paths preserved verbatim from the React source; the
     * React `style={{ fill: ... }}` mappings are converted to attribute
     * strings on each `<path>`.
     *
     * @param int    $size     Square edge length in pixels (clamped 1..512).
     * @param string $cssClass Optional CSS class for layout positioning.
     */
    public static function renderLogo(int $size = 32, string $cssClass = ''): string
    {
        $size = max(1, min(512, $size));
        $sizeAttr = (string) $size;
        $classAttr = $cssClass !== ''
            ? ' class="' . htmlspecialchars($cssClass, ENT_QUOTES, 'UTF-8') . '"'
            : '';

        return '<svg width="' . $sizeAttr . '" height="' . $sizeAttr . '"'
            . ' viewBox="0 0 33.866666 33.866666"'
            . $classAttr
            . ' aria-label="WootsUp"'
            . ' role="img"'
            . ' xmlns="http://www.w3.org/2000/svg"'
            . ' data-testid="wootsup-logo">'
            . '<rect fill="' . self::COLOR_TEAL . '" width="33.896137" height="33.959145"'
            . ' x="-0.063003972" y="-0.063003972" rx="4" />'
            . '<path fill="#ffffff" d="m 22.076252,27.633291 c 0,1.712604 -1.382772,3.095375 -3.089034,3.095375'
            . ' -1.712606,0 -3.095377,-1.382771 -3.095377,-3.095375 0,-1.706265 1.382771,-3.089035 3.095377,-3.089035'
            . ' 1.706262,0 3.089034,1.38277 3.089034,3.089035 z" />'
            . '<path fill="#ffffff" d="m 15.860126,25.812852 c 0,1.630146 -1.325683,2.949488 -2.955831,2.949488'
            . ' -1.623804,0 -2.9494878,-1.319342 -2.9494878,-2.949488 0,-1.630146 1.3256838,-2.949487 2.9494878,-2.949487'
            . ' 1.630148,0 2.955831,1.319341 2.955831,2.949487 z" />'
            . '<path fill="#ffffff" d="m 19.761125,4.5004133 c -0.685041,0 -1.45267,0.031755 -1.712732,0.069815'
            . ' -1.560374,0.2346901 -3.031921,0.8373131 -3.938968,1.6111546 -0.773845,0.6596679 -1.344739,1.325591 -1.649202,1.9218318'
            . ' -0.253719,0.5074365 -0.272621,0.5773159 -0.272621,1.0657252 0,0.4947507 0.01256,0.5453789 0.196531,0.773724'
            . ' 0.285434,0.3552068 0.475723,0.4631228 1.579378,0.9007888 0.862645,0.348861 1.072027,0.405909 1.516037,0.43128'
            . ' 0.799215,0.05075 1.0719,-0.120578 1.566654,-0.99591 0.367892,-0.6533252 0.526492,-0.8499449 0.869014,-1.0782918'
            . ' 0.513781,-0.3361755 1.052973,-0.4629962 1.871218,-0.4312815 0.602583,0.025374 0.767425,0.05081 1.084574,0.1967038'
            . ' 1.090994,0.5010928 1.725431,1.5793965 1.674688,2.8226185 -0.01903,0.513779 -0.04439,0.608783 -0.298108,1.128907'
            . ' -0.241034,0.488407 -0.380642,0.659723 -1.014942,1.294021 -0.40595,0.405948 -1.211445,1.122689 -1.788658,1.598413'
            . ' -1.471571,1.198822 -2.118646,1.979047 -2.404079,2.892433 -0.145889,0.456696 -0.38049,2.124928 -0.38049,2.670423'
            . ' 0,0.437664 0.139418,0.938839 0.329708,1.179873 0.241034,0.310807 0.570957,0.405855 1.382859,0.418541'
            . ' 0.399608,0.0063 1.027526,0.03176 1.395419,0.05079 1.097337,0.06977 1.414434,0.0063 1.731584,-0.374209'
            . ' 0.209318,-0.241033 0.29812,-0.558291 0.29812,-1.046701 0,-0.545495 0.158537,-1.192351 0.374199,-1.57293'
            . ' 0.241033,-0.418638 0.793,-0.951583 1.75079,-1.693713 1.649176,-1.274937 2.44201,-2.042467 3.031883,-2.930483'
            . ' 0.666013,-0.995845 1.046568,-2.112135 1.154398,-3.387073 C 28.190838,11.05908 27.861065,9.505003 27.340941,8.4013266'
            . ' 26.776416,7.2088463 25.55227,6.0799067 24.048983,5.3631518 22.64084,4.6971385 21.664021,4.5004133 19.761125,4.5004133 Z" />'
            . '<path fill="#ffffff" d="M 10.189498,21.696256 C 9.7835468,21.62014 9.4790836,21.410821 9.3141658,21.08733'
            . ' 9.2761081,21.017556 9.0731324,20.376914 8.8701569,19.660157 8.6608384,18.949744 8.3310033,17.884122 8.1407136,17.287882'
            . ' 7.9504243,16.697984 7.6079032,15.581618 7.3795558,14.814118 6.8848029,13.145913 5.9650701,10.234484 5.4386025,8.6931391'
            . ' 4.9945937,7.3928271 4.9248208,6.9678471 5.0833953,6.4413792 c 0.3171491,-1.0909934 2.2454165,-1.99804 4.2434567,-1.99804'
            . ' 0.6406415,0 0.95779,0.088802 1.243225,0.329835 0.202975,0.1649179 0.431323,0.532811 0.564525,0.8943611'
            . ' 0.164918,0.4440088 1.687235,8.0302177 2.194673,10.9226177 0.09514,0.526468 0.253721,1.338371 0.348864,1.80775'
            . ' 0.272749,1.281283 0.29812,1.630147 0.139547,1.947297 -0.228348,0.444008 -0.539156,0.653327 -1.376429,0.932419'
            . ' -1.052936,0.348864 -1.845808,0.501095 -2.251759,0.418637 z" />'
            . '</svg>';
    }

    /**
     * Return the inline `<style>` block for the Settings-page brand surfaces.
     *
     * Kept inline (no enqueued stylesheet) so the page is fully self-contained
     * — operators can copy/paste the rendered DOM into bug reports without
     * losing the visual context.
     */
    public static function renderInlineStyles(): string
    {
        return '<style id="ytb-mcp-brand-styles">'
            . '.ytb-brand-header{display:flex;align-items:center;gap:16px;padding:20px 24px;background:#fff;border:1px solid '
            . self::COLOR_BORDER . ';border-radius:6px;margin:16px 0 0;}'
            . '.ytb-brand-header__mark{flex:0 0 auto;}'
            . '.ytb-brand-header__body{flex:1 1 auto;}'
            . '.ytb-brand-header__title{margin:0;font-size:20px;line-height:1.2;color:' . self::COLOR_INK . ';display:flex;align-items:center;gap:10px;flex-wrap:wrap;}'
            . '.ytb-brand-header__tagline{margin:6px 0 0;color:' . self::COLOR_MUTED . ';font-size:13px;}'
            . '.ytb-brand-header__ctas{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;}'
            . '.ytb-version-badge{background:' . self::COLOR_TEAL_TINT . ';color:' . self::COLOR_INK
            . ';padding:2px 8px;border-radius:4px;font-size:12px;font-weight:500;font-family:Menlo,Consolas,monospace;}'
            . '.ytb-unofficial-badge{background:#fff4d1;color:#7a5b00;border:1px solid #e8d180;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.4px;cursor:help;}'
            . '.ytb-brand-cta-primary{background:' . self::COLOR_TEAL . ' !important;color:' . self::COLOR_INK
            . ' !important;border-color:' . self::COLOR_TEAL . ' !important;text-shadow:none !important;box-shadow:none !important;}'
            . '.ytb-brand-cta-primary:hover,.ytb-brand-cta-primary:focus{background:#26b8b4 !important;border-color:#26b8b4 !important;color:' . self::COLOR_INK . ' !important;}'
            . '.ytb-tab-panel{background:#fff;border:1px solid ' . self::COLOR_BORDER . ';border-top:none;padding:20px 24px;margin-bottom:24px;}'
            . '.ytb-tab-panel .form-table select{min-width:160px;padding:4px 8px;font-size:14px;appearance:auto;-webkit-appearance:auto;}'
            . '.ytb-diag-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-top:12px;}'
            . '.ytb-diag-card{border:1px solid ' . self::COLOR_BORDER . ';padding:14px 16px;border-radius:4px;background:#fafafa;}'
            . '.ytb-diag-card h3{margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:0.5px;color:' . self::COLOR_MUTED . ';}'
            . '.ytb-diag-card dl{display:grid;grid-template-columns:auto 1fr;gap:6px 12px;margin:0;font-size:13px;}'
            . '.ytb-diag-card dt{color:' . self::COLOR_MUTED . ';font-weight:500;}'
            . '.ytb-diag-card dd{margin:0;font-family:Menlo,Consolas,monospace;word-break:break-all;}'
            . '.ytb-about-cmd{display:block;background:' . self::COLOR_INK . ';color:#e6f7f6;padding:12px 14px;border-radius:4px;font-family:Menlo,Consolas,monospace;font-size:13px;overflow-x:auto;}'
            . '.ytb-about-clients{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 16px;padding:0;list-style:none;}'
            . '.ytb-about-clients li{background:#f0f0f1;color:' . self::COLOR_INK . ';padding:4px 10px;border-radius:12px;font-size:12px;}'
            . '.ytb-brand-footer{margin-top:32px;padding:16px 0;border-top:1px solid ' . self::COLOR_BORDER . ';color:'
            . self::COLOR_MUTED . ';font-size:13px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}'
            . '.ytb-brand-footer__mark{flex:0 0 auto;}'
            . '.ytb-brand-footer__copy{flex:1 1 auto;}'
            . '</style>';
    }
}
