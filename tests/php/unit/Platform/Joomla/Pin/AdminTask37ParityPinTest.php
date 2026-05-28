<?php
/**
 * STATIC PIN TEST (Task #37, 2026-05-28): com_ytbmcp admin UX parity with
 * the WordPress SettingsPage Task #37 fixes.
 *
 * The WP-side `SettingsPage.php` was patched (see
 * `tests/php/unit/PlatformWordPress/SettingsPageTabsTest.php` Task #37 block)
 * with three UX changes. The Joomla counterpart must carry the equivalent
 * fixes for cross-platform parity:
 *
 *   Fix 1 (icon alignment): the `dashicons-external` span inside the
 *          "wootsup.com" header CTA carries `vertical-align:middle` +
 *          `line-height:1` on WordPress. The Joomla counterpart has NO
 *          inline icon span inside the wootsup.com button (Atum auto-appends
 *          an external-link icon via the `target="_blank"` selector), so
 *          there is nothing to align. This test pins that no inline icon
 *          span is reintroduced into the wootsup.com button.
 *
 *   Fix 2 (redundant inner tab title): the Bearer Keys + Diagnostics tab
 *          content must NOT repeat the tab name in an inner `<h2>` directly
 *          under the tab-bar. The uitab nav above already identifies the
 *          active section; the inner title would be redundant.
 *
 *   Fix 3 (top-row Generate Key): the header CTA row must NOT contain a
 *          "Generate Key" deep-link button. The Bearer Keys tab is reachable
 *          via the primary uitab nav, and the form-submit button on the
 *          Keys tab carries the "Generate Key" label exclusively.
 *
 * Like `AdminDarkModePinTest`, these are source-grep static pin-tests
 * because the Joomla MVC view layer is autoloaded by the MVCFactory at
 * runtime (not in composer's PSR-4 map), so the contract is asserted
 * against the template source directly.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\TestCase;

final class AdminTask37ParityPinTest extends TestCase
{
    private const COMPONENT_BASE =
        'src/packaging/joomla/extensions/com_ytbmcp';

    private const ADMIN_BASE = self::COMPONENT_BASE . '/administrator';

    private const DEFAULT_TMPL_REL = self::ADMIN_BASE . '/tmpl/dashboard/default.php';

    private const KEYS_TMPL_REL = self::ADMIN_BASE . '/tmpl/dashboard/_tab_keys.php';

    private const DIAGNOSTICS_TMPL_REL = self::ADMIN_BASE . '/tmpl/dashboard/_tab_diagnostics.php';

    private const ABOUT_TMPL_REL = self::ADMIN_BASE . '/tmpl/dashboard/_tab_about.php';

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

    /**
     * Returns the contents of the header CTA row block in default.php (the
     * `<div class="d-flex flex-wrap gap-2 mb-4">...</div>` block that holds
     * the header CTA buttons). Falls back to the whole file if the block
     * markers cannot be located, so an accidental wrapper-class rename
     * fails the test loudly rather than silently passing.
     */
    private static function readHeaderCtaRow(): string
    {
        $tmpl = self::read(self::DEFAULT_TMPL_REL);
        $startMarker = 'class="d-flex flex-wrap gap-2 mb-4"';
        $start = \strpos($tmpl, $startMarker);
        if ($start === false) {
            return $tmpl;
        }
        $end = \strpos($tmpl, '</div>', $start);
        if ($end === false) {
            return $tmpl;
        }

        return \substr($tmpl, $start, ($end - $start) + 6);
    }

    // ── Fix 3: top-row "Generate Key" button is gone ──────────────────────

    public function test_header_cta_row_does_not_contain_generate_key_button(): void
    {
        $row = self::readHeaderCtaRow();
        // The forbidden header CTA was an `<a class="btn btn-primary"
        // href="index.php?option=com_ytbmcp&amp;tab=keys#ytb-mcp-generate">
        // <span class="icon-key" ...></span> COM_YTBMCP_GENERATE_KEY </a>`.
        // Pin: no anchor with the deep-link href in the header row, and no
        // reference to the Generate Key language key in the header row.
        self::assertStringNotContainsString(
            '#ytb-mcp-generate',
            $row,
            'Header CTA row must not deep-link to the generate-form anchor (Task #37).',
        );
        self::assertStringNotContainsString(
            'COM_YTBMCP_GENERATE_KEY',
            $row,
            'Header CTA row must not carry the Generate Key label (Task #37).',
        );
        self::assertStringNotContainsString(
            'icon-key',
            $row,
            'Header CTA row must not carry the icon-key glyph (Task #37: '
            . 'Generate Key button was removed).',
        );
    }

    public function test_generate_form_anchor_target_still_exists_on_keys_tab(): void
    {
        // The deep-link anchor target stays on the generate form for any
        // external links that may still point at it (Task #37 only removed
        // the header CTA, not the anchor).
        $keysTmpl = self::read(self::KEYS_TMPL_REL);
        self::assertStringContainsString(
            'id="ytb-mcp-generate"',
            $keysTmpl,
            'The deep-link anchor target on the generate form must remain '
            . 'so external links targeting it still resolve.',
        );
    }

    public function test_generate_key_label_remains_on_keys_tab_form_submit(): void
    {
        // The form-submit button on the Keys tab still carries the
        // Generate Key label — only the duplicate header CTA was removed.
        $keysTmpl = self::read(self::KEYS_TMPL_REL);
        self::assertStringContainsString(
            'COM_YTBMCP_GENERATE_KEY',
            $keysTmpl,
            'The generate form submit button must still use the Generate '
            . 'Key label (only the redundant header CTA was removed).',
        );
    }

    // ── Fix 2: tab content does not repeat the tab name as inner title ────

    public function test_keys_tab_does_not_repeat_tab_name_as_inner_title(): void
    {
        $tmpl = self::read(self::KEYS_TMPL_REL);
        // The forbidden pattern is an inner `<h2>` containing the literal
        // tab label "Bearer Keys" directly under the uitab nav. The
        // language key for the existing-keys subsection
        // (`COM_YTBMCP_EXISTING_KEYS`) is fine — the pin only forbids an
        // h2 whose text-content equals the tab name.
        self::assertDoesNotMatchRegularExpression(
            '/<h2[^>]*>\s*Bearer Keys\s*<\/h2>/',
            $tmpl,
            'Keys tab must not repeat the tab name in an inner <h2> title '
            . '(Task #37: the uitab nav above already identifies the section).',
        );
        // Also pin the language-key form — a literal Text::_ call rendering
        // exactly the tab-label key as an inner h2 would be the same UX bug.
        self::assertDoesNotMatchRegularExpression(
            '/<h2[^>]*>\s*<\?php\s+echo\s+Text::_\([\'"]COM_YTBMCP_TAB_KEYS[\'"]\)\s*;\s*\?>\s*<\/h2>/',
            $tmpl,
            'Keys tab must not render COM_YTBMCP_TAB_KEYS as an inner <h2>.',
        );
    }

    public function test_diagnostics_tab_does_not_repeat_tab_name_as_inner_title(): void
    {
        $tmpl = self::read(self::DIAGNOSTICS_TMPL_REL);
        self::assertDoesNotMatchRegularExpression(
            '/<h2[^>]*>\s*Diagnostics\s*<\/h2>/',
            $tmpl,
            'Diagnostics tab must not repeat the tab name in an inner <h2> '
            . 'title (Task #37).',
        );
        self::assertDoesNotMatchRegularExpression(
            '/<h2[^>]*>\s*<\?php\s+echo\s+Text::_\([\'"]COM_YTBMCP_TAB_DIAGNOSTICS[\'"]\)\s*;\s*\?>\s*<\/h2>/',
            $tmpl,
            'Diagnostics tab must not render COM_YTBMCP_TAB_DIAGNOSTICS as an inner <h2>.',
        );
    }

    public function test_about_tab_keeps_its_heading_for_legal_context(): void
    {
        // Parity with WP: the About-tab H2 is KEPT — it carries more context
        // than just the tab name (it includes the unofficial-third-party
        // legal framing in its language key).
        $tmpl = self::read(self::ABOUT_TMPL_REL);
        self::assertMatchesRegularExpression(
            '/<h2[^>]*>\s*<\?php\s+echo\s+Text::_\([\'"]COM_YTBMCP_ABOUT_HEADING[\'"]\)\s*;\s*\?>\s*<\/h2>/',
            $tmpl,
            'About tab must keep its heading (COM_YTBMCP_ABOUT_HEADING) — it '
            . 'carries the unofficial-third-party legal note.',
        );
    }

    // ── Fix 1: icon alignment in the wootsup.com header CTA ───────────────

    public function test_wootsup_cta_does_not_carry_a_misaligned_inline_icon(): void
    {
        // On WordPress the dashicons-external span inside the wootsup.com
        // button needed an explicit `vertical-align:middle;line-height:1`
        // to stop hanging below the text baseline. The Joomla counterpart
        // uses Atum's auto-injected external-link icon (a CSS pseudo-element
        // on `target="_blank"` buttons) — there is NO inline icon span to
        // misalign. This pin catches the regression where someone adds an
        // explicit icon span back without the vertical-align rule.
        $tmpl = self::read(self::DEFAULT_TMPL_REL);
        // Locate the wootsup.com button block.
        $needle = 'href="<?php echo $esc(HtmlView::HOME_URL); ?>"';
        $pos = \strpos($tmpl, $needle);
        self::assertNotFalse($pos, 'wootsup.com header CTA button must exist.');
        $blockEnd = \strpos($tmpl, '</a>', $pos);
        self::assertNotFalse($blockEnd, 'wootsup.com CTA <a> must be closed.');
        $block = \substr($tmpl, $pos, ($blockEnd - $pos) + 4);

        // No inline icon span allowed inside the wootsup.com button — Atum
        // auto-injects the external-link glyph via CSS.
        self::assertDoesNotMatchRegularExpression(
            '/<span class="icon-[a-z-]+"/i',
            $block,
            'wootsup.com CTA must not carry an inline icon span — Atum auto-'
            . 'appends an external-link icon via CSS on target="_blank" links '
            . '(adding an explicit span would double the icon and reintroduce '
            . 'the WP Task #37 baseline-alignment bug).',
        );
    }
}
