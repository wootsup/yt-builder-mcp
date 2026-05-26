<?php
/**
 * JoomlaFrontendUrlResolverTest — bilateral pin-tests for the per-
 * template public-URL hint emitter on Joomla.
 *
 * F-Frontend-URL (2026-05-25 customer-flow gap). Joomla counterpart of
 * {@see \WootsUp\BuilderMcp\Tests\Unit\Pages\WordPressFrontendUrlResolverTest}.
 * Each template-type gets at least one behavioral pin verifying the
 * (frontend_url, frontend_url_template, description) triple emitted by
 * the resolver:
 *
 *   - error-404                    → null + nonexistent-path template + hint
 *   - single-post                  → null + index.php?option=com_content&view=article&id={ID}
 *   - taxonomy-category            → null + ?option=com_content&view=category&id={ID}
 *   - taxonomy-post_tag            → null + ?option=com_tags&view=tag&id={ID}
 *   - author (com_contact.contact) → null + ?option=com_contact&view=contact&id={ID}
 *   - search (com_finder.search)   → null + ?option=com_finder&view=search&q={query}
 *   - archive-post (posts / home)  → Uri::root() (Joomla default-menu)
 *   - layout (internal)            → null + "Internal template — no public URL."
 *   - unknown                      → all-null
 *
 * The unit-test Joomla bootstrap does not provide RouteHelper / Route::_()
 * stubs, so the resolver consistently falls through to its query-string
 * fallback URL (cookbook-recommended — works on every Joomla install
 * regardless of SEF configuration). Integration coverage of the
 * RouteHelper happy-path lives in `tests/php/integration/Joomla/`.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pages
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pages;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Pages\JoomlaFrontendUrlResolver;

#[CoversClass(JoomlaFrontendUrlResolver::class)]
final class JoomlaFrontendUrlResolverTest extends TestCase
{
    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        ytb_test_install_mock_db();
        // Pin the Uri::root() return for deterministic URL assertions.
        \Joomla\CMS\Uri\Uri::$root = 'https://joomla.test/';
    }

    private function resolver(): JoomlaFrontendUrlResolver
    {
        return new JoomlaFrontendUrlResolver();
    }

    public function test_error_404_emits_nonexistent_path_pattern(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'error-404']);
        self::assertNull($result['frontend_url']);
        self::assertSame('https://joomla.test/<any-nonexistent-path>', $result['frontend_url_template']);
        self::assertSame('Append any non-existent path to test.', $result['description']);
    }

    public function test_single_post_falls_back_to_query_string_template(): void
    {
        // The unit-test Joomla bootstrap has no #__content rows seeded, so
        // firstContentRow() returns null → resolveArticle() falls through
        // to its query-string template fallback.
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'single-post']);
        self::assertNull($result['frontend_url']);
        self::assertSame(
            'https://joomla.test/index.php?option=com_content&view=article&id={ID}',
            $result['frontend_url_template'],
        );
        self::assertNotNull($result['description']);
    }

    public function test_com_content_article_alias_resolves(): void
    {
        // The cookbook-documented type string format for Joomla is
        // `com_content.article`. The resolver normalises dots → hyphens
        // so both `single-post` (WP-style) and `com_content.article`
        // route to the same article branch.
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'com_content.article']);
        self::assertNull($result['frontend_url']);
        self::assertStringContainsString('option=com_content', (string) $result['frontend_url_template']);
        self::assertStringContainsString('view=article', (string) $result['frontend_url_template']);
    }

    public function test_taxonomy_category_emits_category_query_string(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'taxonomy-category']);
        self::assertNull($result['frontend_url']);
        self::assertSame(
            'https://joomla.test/index.php?option=com_content&view=category&id={ID}',
            $result['frontend_url_template'],
        );
    }

    public function test_com_content_category_alias_resolves(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'com_content.category']);
        self::assertStringContainsString('view=category', (string) $result['frontend_url_template']);
    }

    public function test_taxonomy_post_tag_emits_tag_query_string(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'taxonomy-post_tag']);
        self::assertNull($result['frontend_url']);
        self::assertSame(
            'https://joomla.test/index.php?option=com_tags&view=tag&id={ID}',
            $result['frontend_url_template'],
        );
    }

    public function test_com_tags_tag_alias_resolves(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'com_tags.tag']);
        self::assertStringContainsString('option=com_tags', (string) $result['frontend_url_template']);
    }

    public function test_author_archive_maps_to_com_contact_contact(): void
    {
        // Joomla has no native author-archive — the cookbook maps the
        // WP-side `author` template-type to Joomla's `com_contact.contact`
        // for graceful cross-platform parity.
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'author-archive']);
        self::assertNull($result['frontend_url']);
        self::assertSame(
            'https://joomla.test/index.php?option=com_contact&view=contact&id={ID}',
            $result['frontend_url_template'],
        );
    }

    public function test_search_emits_com_finder_query_pattern(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'search']);
        self::assertNull($result['frontend_url']);
        self::assertSame(
            'https://joomla.test/index.php?option=com_finder&view=search&q={query}',
            $result['frontend_url_template'],
        );
        self::assertNotNull($result['description']);
    }

    public function test_com_finder_search_alias_resolves(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'com_finder.search']);
        self::assertStringContainsString('option=com_finder', (string) $result['frontend_url_template']);
    }

    public function test_archive_post_returns_site_root(): void
    {
        // Joomla default-menu-item typically renders the article-list at
        // /. Unlike WP there's no show_on_front split — Uri::root() is
        // the canonical answer.
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'archive-post']);
        self::assertSame('https://joomla.test', $result['frontend_url']);
        self::assertNull($result['frontend_url_template']);
    }

    public function test_layout_is_internal_with_no_public_url(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'layout']);
        self::assertNull($result['frontend_url']);
        self::assertNull($result['frontend_url_template']);
        self::assertSame('Internal template — no public URL.', $result['description']);
    }

    public function test_verifytpl_is_internal(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'verifytpl']);
        self::assertNull($result['frontend_url']);
        self::assertNull($result['frontend_url_template']);
        self::assertSame('Internal template — no public URL.', $result['description']);
    }

    public function test_unknown_type_returns_all_nulls(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'future-type-2030']);
        self::assertNull($result['frontend_url']);
        self::assertNull($result['frontend_url_template']);
        self::assertNull($result['description']);
    }

    public function test_missing_type_field_does_not_throw(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x']);
        self::assertNull($result['frontend_url']);
        self::assertNull($result['frontend_url_template']);
        self::assertNull($result['description']);
    }

    public function test_response_shape_has_exactly_three_keys(): void
    {
        // Pin the wire-shape — parity with WP-side resolver. The MCP TS
        // PAGES_LIST_OUTPUT_SCHEMA assumes exactly these three keys per
        // row's URL hint regardless of platform.
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'error-404']);
        $keys = \array_keys($result);
        \sort($keys);
        self::assertSame(['description', 'frontend_url', 'frontend_url_template'], $keys);
    }

    public function test_site_root_trailing_slash_is_stripped(): void
    {
        \Joomla\CMS\Uri\Uri::$root = 'https://joomla.test/';
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'search']);
        self::assertSame(
            'https://joomla.test/index.php?option=com_finder&view=search&q={query}',
            $result['frontend_url_template'],
        );
    }
}
