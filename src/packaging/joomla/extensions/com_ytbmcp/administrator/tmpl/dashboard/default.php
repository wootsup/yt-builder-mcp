<?php
/**
 * Dashboard view default layout — native Joomla admin surface (Wave-11).
 *
 * W11 (2026-05-24): the admin UI was redesigned to look like a first-class
 * NATIVE Joomla component. The bespoke "brand" surface (custom admin.css +
 * `.ytb-*` colour classes + WebAssetManager registration) is gone. The body
 * is now standard Bootstrap / Atum — a native page heading (with the WootsUp
 * logo + an "unofficial" badge as the ONLY brand elements), the canonical
 * Joomla `uitab` Bootstrap tab set, and Bootstrap cards / tables / forms /
 * alerts inside each tab. Because every component is native Atum-themed, the
 * page renders correctly in BOTH Joomla light and dark mode with zero custom
 * colour CSS.
 *
 * Three tabs (Bearer Keys / Diagnostics / About) + one-shot reveal box +
 * click-to-copy JS. The opening tab is driven by the `&tab=` request var
 * (resolved in HtmlView, fail-closed to Keys) → fed to uitab as `active`.
 *
 * Verbatim customer-trust copy is sourced from
 * {@see \WootsUp\BuilderMcp\Platform\Joomla\Settings\JoomlaBrandStrings}
 * (cookbook §6 Appendix B); structural labels come from the .ini via
 * {@see \Joomla\CMS\Language\Text}.
 *
 * @var \WootsUp\Component\Ytbmcp\Administrator\View\Dashboard\HtmlView $this
 *
 * @license   GPL-2.0-or-later
 * @copyright (C) 2026 getimo productions
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use WootsUp\BuilderMcp\Platform\Joomla\Settings\JoomlaBrandAssets;
use WootsUp\Component\Ytbmcp\Administrator\View\Dashboard\HtmlView;

/** @var HtmlView $this */

$esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>

<div class="com-ytbmcp-dashboard">

    <?php // Native page heading: logo (only brand element) + title + version + unofficial badge. ?>
    <div class="d-flex align-items-center gap-3 mb-3">
        <span class="flex-shrink-0"><?php echo JoomlaBrandAssets::renderLogo(40); ?></span>
        <div>
            <h1 class="h4 mb-0 d-flex align-items-center flex-wrap gap-2">
                <?php echo Text::_('COM_YTBMCP_BRAND_TITLE'); ?>
                <span class="badge bg-secondary">v<?php echo $esc($this->pluginVersion); ?></span>
                <span class="badge bg-warning text-dark text-uppercase"
                      title="<?php echo Text::_('COM_YTBMCP_UNOFFICIAL_TOOLTIP'); ?>"><?php echo Text::_('COM_YTBMCP_UNOFFICIAL'); ?></span>
            </h1>
            <p class="text-muted small mb-0"><?php echo Text::_('COM_YTBMCP_BRAND_TAGLINE'); ?></p>
        </div>
    </div>

    <?php // Header CTA row — native Bootstrap buttons. "Generate Key" deep-links
          // to the Keys tab + scrolls to the generate form; Documentation is a
          // secondary outline button; wootsup.com is the prominent product-site
          // CTA (the only link to the product home anywhere on the surface). ?>
    <div class="d-flex flex-wrap gap-2 mb-4">
        <a class="btn btn-primary" href="index.php?option=com_ytbmcp&amp;tab=keys#ytb-mcp-generate">
            <span class="icon-key" aria-hidden="true"></span>
            <?php echo Text::_('COM_YTBMCP_GENERATE_KEY'); ?>
        </a>
        <a class="btn btn-outline-secondary" href="<?php echo $esc(HtmlView::DOCS_URL); ?>" target="_blank" rel="noopener noreferrer">
            <?php echo Text::_('COM_YTBMCP_DOCUMENTATION'); ?>
        </a>
        <?php // No explicit icon here: Atum auto-appends an external-link icon to
              // target="_blank" buttons; an explicit span would double it. Matches
              // the Documentation button above (single auto-icon). ?>
        <a class="btn btn-success" href="<?php echo $esc(HtmlView::HOME_URL); ?>" target="_blank" rel="noopener noreferrer">
            <?php echo Text::_('COM_YTBMCP_VISIT_WOOTSUP'); ?>
        </a>
    </div>

    <?php // YOOtheme-missing notice (#13) — native Bootstrap alert. ?>
    <?php // Keys remain manageable below; only the page-builder REST surface is inert without YT. ?>
    <?php if (!$this->ytPresent) : ?>
        <div class="alert alert-warning" role="alert">
            <h2 class="alert-heading h5"><?php echo Text::_('COM_YTBMCP_YT_REQUIRED_HEADING'); ?></h2>
            <p><?php
                echo Text::sprintf(
                    'COM_YTBMCP_YT_REQUIRED_BODY',
                    '<strong>YOOtheme Pro ' . $esc(HtmlView::MIN_YT_VERSION) . '</strong>'
                );
            ?></p>
            <a class="btn btn-warning" href="https://yootheme.com/" target="_blank" rel="noopener noreferrer"><?php echo Text::_('COM_YTBMCP_YT_REQUIRED_CTA'); ?></a>
        </div>
    <?php endif; ?>

    <?php // Native Atum-themed Bootstrap tab set (uitab). The opening tab is
          // selected from the URL-resolved $this->activeTab. ?>
    <?php echo HTMLHelper::_('uitab.startTabSet', 'ytbmcp', ['active' => $this->activeTab, 'recall' => true]); ?>

        <?php echo HTMLHelper::_('uitab.addTab', 'ytbmcp', HtmlView::TAB_KEYS, Text::_('COM_YTBMCP_TAB_KEYS')); ?>
            <?php require __DIR__ . '/_tab_keys.php'; ?>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>

        <?php echo HTMLHelper::_('uitab.addTab', 'ytbmcp', HtmlView::TAB_DIAGNOSTICS, Text::_('COM_YTBMCP_TAB_DIAGNOSTICS')); ?>
            <?php require __DIR__ . '/_tab_diagnostics.php'; ?>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>

        <?php echo HTMLHelper::_('uitab.addTab', 'ytbmcp', HtmlView::TAB_ABOUT, Text::_('COM_YTBMCP_TAB_ABOUT')); ?>
            <?php require __DIR__ . '/_tab_about.php'; ?>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <?php // Footer — native muted text + core link styling. ?>
    <div class="border-top mt-4 pt-3 d-flex align-items-center gap-2 flex-wrap text-muted small">
        <span class="flex-shrink-0"><?php echo JoomlaBrandAssets::renderLogo(18); ?></span>
        <div>
            &copy; WootsUp &mdash; A getimo productions company &middot;
            <a href="<?php echo $esc(HtmlView::HOME_URL); ?>" target="_blank" rel="noopener noreferrer"><?php echo Text::_('COM_YTBMCP_FOOTER_HOME'); ?></a> &middot;
            <a href="<?php echo $esc(HtmlView::DOCS_URL); ?>" target="_blank" rel="noopener noreferrer"><?php echo Text::_('COM_YTBMCP_FOOTER_DOCS'); ?></a> &middot;
            <a href="https://github.com/wootsup/yt-builder-mcp/issues" target="_blank" rel="noopener noreferrer"><?php echo Text::_('COM_YTBMCP_FOOTER_ISSUES'); ?></a> &middot;
            <a href="mailto:security@wootsup.com"><?php echo Text::_('COM_YTBMCP_FOOTER_SECURITY'); ?></a>
        </div>
    </div>
</div>

<?php // Click-to-copy helper (multi-button via data attributes). Behaviour, not brand styling. ?>
<script>
(function () {
    var COPY_OK = <?php echo json_encode(Text::_('COM_YTBMCP_COPY_OK')); ?>;
    var COPY_FAIL = <?php echo json_encode(Text::_('COM_YTBMCP_COPY_FAIL')); ?>;
    function writeText(text, done, fail) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, fail);
            return;
        }
        try {
            var hidden = document.createElement('textarea');
            hidden.value = text;
            hidden.setAttribute('readonly', '');
            hidden.style.position = 'absolute';
            hidden.style.left = '-9999px';
            document.body.appendChild(hidden);
            hidden.select();
            document.execCommand('copy');
            document.body.removeChild(hidden);
            done();
        } catch (e) { fail(); }
    }
    var buttons = document.querySelectorAll('[data-ytb-copy]');
    Array.prototype.forEach.call(buttons, function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-ytb-copy') || '');
            var status = document.getElementById(btn.getAttribute('data-ytb-copy-status') || '');
            if (!target) { return; }
            var text = target.value || target.getAttribute('data-ytb-text') || target.textContent || '';
            writeText(text,
                function () { if (status) { status.classList.remove('text-danger'); status.classList.add('text-success'); status.textContent = COPY_OK; setTimeout(function () { status.textContent = ''; }, 2400); } },
                function () { if (status) { status.classList.remove('text-success'); status.classList.add('text-danger'); status.textContent = COPY_FAIL; } });
        });
    });
})();
</script>
