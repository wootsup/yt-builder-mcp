<?php
/**
 * WordPressPublicRootResolver — WP `show_on_front` lookup for the public
 * front-page hint.
 *
 * Default implementation of {@see PublicRootResolverInterface} on
 * WordPress. Preserves the legacy 1.0.1 Wave-1.8 F-COLD-2 / F-COLD-16
 * behaviour byte-for-byte:
 *
 *   - `show_on_front=posts` (WP-default) → returns `'archive-post'`
 *   - `show_on_front=page`               → returns null
 *     (the page-front lookup lives YT-side and is not cleanly
 *     accessible from PageQuery yet — deferred to a follow-up wave)
 *
 * Audit-A1 F-004 extraction (Wave 4 fix-round F3): moved out of
 * PageQuery::computePublicRootHint() so the pure-PHP module stops
 * calling `\get_option()` directly. Zero behavioural change vs the
 * inlined version — same default ('posts'), same return values, same
 * `function_exists` guard.
 *
 * @license   GPL-2.0-or-later
 * @package   WootsUp\BuilderMcp\Pages
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Pages;

final class WordPressPublicRootResolver implements PublicRootResolverInterface
{
    public function resolveSiteFront(): ?string
    {
        if (!\function_exists('get_option')) {
            // Unit-test bootstrap / non-WP environment — no signal.
            return null;
        }
        /** @var mixed $showOnFront */
        $showOnFront = \get_option('show_on_front', 'posts');
        if ($showOnFront === 'posts') {
            return 'archive-post';
        }
        // `show_on_front === 'page'` path — defer to a follow-up wave
        // (requires YT-side template→page binding lookup).
        return null;
    }
}
