<?php
/**
 * PublicRootResolverInterface — cross-platform front-page hint resolver.
 *
 * 1.0.1 Wave-1.8 F-COLD-2 / F-COLD-16 surfaced a recurring cold-agent
 * failure mode: agents asked "which page is the homepage?" had to guess
 * the canonical front-page template from a list of names, and 4/4 cold
 * runs picked the archive-post template incorrectly. The fix was a
 * `is_public_homepage` boolean on the pages_list response, computed by
 * looking at the platform's "what does `/` render?" setting.
 *
 * On WordPress that lookup is one `get_option('show_on_front')` call.
 * On Joomla it requires walking `Factory::getApplication()->getMenu()->getDefault()`
 * and checking the resulting menu-item's binding to a YT template.
 *
 * Audit-A1 F-004 (Wave 4 fix-round F3) — the resolver is now an
 * injectable interface so PageQuery (a "pure-PHP" builder-pages module)
 * stops leaking the WordPress `get_option` call into platform-agnostic
 * code. The default WP impl wraps the legacy lookup byte-for-byte; the
 * Joomla impl is supplied by the platform-joomla adapter.
 *
 * Returns null when the public root cannot be determined (e.g. `show_on_front=page`
 * on WP with no YT-side mapping wired yet, or Joomla default menu-item not
 * resolvable from `JMenu::getDefault()`). The caller falls back to the
 * legacy guess-by-name heuristic — no regression.
 *
 * @license   GPL-2.0-or-later
 * @package   WootsUp\BuilderMcp\Pages
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Pages;

interface PublicRootResolverInterface
{
    /**
     * Return the YT template `type` string the site's public front-page
     * (URL `/`) resolves to, or null when unknown.
     *
     * Conventional values: `archive-post` (WP `show_on_front=posts` or
     * Joomla default-menu-item rendering article-list), or the YT
     * template-id of a singular front-page binding.
     *
     * Implementations MUST be safe to call from REST-controller hot
     * paths — no I/O beyond a single platform-native lookup (one
     * `get_option` / one `getMenu` call). Failures fall through to
     * null; the caller does NOT see exceptions from here.
     */
    public function resolveSiteFront(): ?string;
}
