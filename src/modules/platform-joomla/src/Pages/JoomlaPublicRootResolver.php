<?php
/**
 * JoomlaPublicRootResolver — Joomla default-menu-item resolver for the
 * public front-page hint.
 *
 * Joomla equivalent of {@see \WootsUp\BuilderMcp\Pages\WordPressPublicRootResolver}.
 * Walks `Factory::getApplication()->getMenu()->getDefault($lang)` to find
 * the menu-item that renders the site root (URL `/`), then projects it
 * onto a YT template-type hint the agent-facing pages_list can flag.
 *
 * Heuristic chain (in descending preference, all best-effort —
 * uncertainty returns null so the caller falls back to the legacy
 * guess-by-name path, no regression):
 *
 *   1. Default menu-item has `option=com_content` + `view=featured` or
 *      `view=category` → site root renders an article-list → YT
 *      template-type `archive-post` (matches WP-side semantics).
 *   2. Default menu-item has `option=com_yootheme` (YOOtheme Pro's
 *      menu-item type for binding a YT template to a menu route) →
 *      returns the configured template-id from the menu-item params.
 *   3. Anything else (custom routers, third-party components, missing
 *      default-item) → null.
 *
 * Audit-A1 F-004 (Wave 4 fix-round F3) extraction — moves the Joomla
 * lookup into the platform-joomla adapter so the builder-pages module
 * stays pure-PHP and platform-agnostic.
 *
 * Cookbook reference: §1.2.6 (PublicRootResolver platform-adapter
 * contract — default-menu-item → YT template-type projection).
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Pages
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Pages;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use WootsUp\BuilderMcp\Pages\PublicRootResolverInterface;

final class JoomlaPublicRootResolver implements PublicRootResolverInterface
{
    public function resolveSiteFront(): ?string
    {
        if (!\class_exists(Factory::class)) {
            return null; // Unit-test bootstrap outside Joomla.
        }

        try {
            $app = Factory::getApplication();
            if (!\method_exists($app, 'getMenu')) {
                return null;
            }
            $menu = $app->getMenu();
            if ($menu === null || !\method_exists($menu, 'getDefault')) {
                return null;
            }

            // Joomla's `getDefault()` is language-aware. Site root in
            // multilingual installs may bind a different menu-item per
            // language. We use the wildcard `*` (default for all langs)
            // so cold-start hits the canonical fallback regardless of
            // the current request's language context.
            $default = $menu->getDefault('*');
            if ($default === null || !isset($default->component, $default->query)) {
                return null;
            }

            // Heuristic 2 — YOOtheme-Pro menu-item binding a YT template
            // directly. The `template` param is set when the menu-item
            // is "YOOtheme: Template".
            if ($default->component === 'com_yootheme') {
                $params = $default->getParams();
                if ($params !== null && \method_exists($params, 'get')) {
                    $tplId = $params->get('template');
                    if (\is_string($tplId) && $tplId !== '') {
                        return $tplId;
                    }
                }
                return null;
            }

            // Heuristic 1 — article-list rendering (matches WP-side
            // `show_on_front=posts` → archive-post template-type).
            if ($default->component === 'com_content') {
                $view = $default->query['view'] ?? null;
                if ($view === 'featured' || $view === 'category') {
                    return 'archive-post';
                }
            }

            return null;
        } catch (\Throwable) {
            // Best-effort lookup — never surface exceptions to the
            // pages_list hot path. Caller falls back to the legacy
            // guess-by-name heuristic.
            return null;
        }
    }
}
