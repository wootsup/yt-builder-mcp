<?php
/**
 * WordPressFrontendUrlResolverTest — bilateral pin-tests for the per-
 * template public-URL hint emitter on WordPress.
 *
 * F-Frontend-URL (2026-05-25 customer-flow gap). Each template-type
 * gets at least one behavioral pin verifying the (frontend_url,
 * frontend_url_template, description) triple emitted by the resolver:
 *
 *   - error-404            → null + nonexistent-path template + hint
 *   - single-post          → null + ?p={ID} template (no WP-API in tests)
 *   - single-page          → null + ?page_id={ID} template
 *   - taxonomy-category    → null + ?cat={ID} template
 *   - taxonomy-post_tag    → null + ?tag={slug} template
 *   - author-archive       → null + /author/{username} template
 *   - search               → null + ?s={query} template
 *   - archive-post (posts) → home URL or ?post_type=post pattern
 *   - layout (internal)    → null + "Internal template — no public URL."
 *   - unknown              → all-null
 *
 * The WP-API success-path (e.g. `get_permalink()` of an actual post)
 * lives in `tests/php/integration/` — bootstrapping `wp-load.php` is
 * out of scope for this unit-suite.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Pages;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Pages\WordPressFrontendUrlResolver;

#[CoversClass(WordPressFrontendUrlResolver::class)]
final class WordPressFrontendUrlResolverTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_home_url'] = 'https://example.test';
        $GLOBALS['ytb_test_options']  = [];
    }

    private function resolver(): WordPressFrontendUrlResolver
    {
        return new WordPressFrontendUrlResolver();
    }

    public function test_error_404_emits_nonexistent_path_pattern(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'error-404']);
        self::assertNull($result['frontend_url']);
        self::assertSame('https://example.test/<any-nonexistent-path>', $result['frontend_url_template']);
        self::assertSame('Append any non-existent path to test.', $result['description']);
    }

    public function test_error_404_accepts_short_form(): void
    {
        // YT-Pro hyphenates `error-404` in 4.5+, but older blobs and some
        // demo templates carry the bare `404` string. Both must resolve.
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => '404']);
        self::assertNull($result['frontend_url']);
        self::assertStringEndsWith('/<any-nonexistent-path>', (string) $result['frontend_url_template']);
    }

    public function test_single_post_falls_back_to_pattern_in_unit_bootstrap(): void
    {
        // The unit-test bootstrap does not declare get_posts(), so the
        // WP-API path returns null and we fall through to the pattern.
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'single-post']);
        self::assertNull($result['frontend_url']);
        self::assertSame('https://example.test/?p={ID}', $result['frontend_url_template']);
        self::assertNotNull($result['description']);
    }

    public function test_single_page_emits_page_id_pattern(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'single-page']);
        self::assertNull($result['frontend_url']);
        self::assertSame('https://example.test/?page_id={ID}', $result['frontend_url_template']);
    }

    public function test_taxonomy_category_emits_cat_pattern(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'taxonomy-category']);
        self::assertNull($result['frontend_url']);
        self::assertSame('https://example.test/?cat={ID}', $result['frontend_url_template']);
    }

    public function test_taxonomy_post_tag_emits_tag_pattern(): void
    {
        // YT may emit the type as `taxonomy-post_tag` (underscore in tax
        // slug). The resolver normalises underscores to hyphens.
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'taxonomy-post_tag']);
        self::assertNull($result['frontend_url']);
        self::assertSame('https://example.test/?tag={slug}', $result['frontend_url_template']);
    }

    public function test_author_archive_emits_author_pattern(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'author-archive']);
        self::assertNull($result['frontend_url']);
        self::assertSame('https://example.test/author/{username}', $result['frontend_url_template']);
    }

    public function test_search_emits_query_pattern(): void
    {
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'search']);
        self::assertNull($result['frontend_url']);
        self::assertSame('https://example.test/?s={query}', $result['frontend_url_template']);
        self::assertNotNull($result['description']);
    }

    public function test_archive_post_returns_home_when_show_on_front_is_posts(): void
    {
        $GLOBALS['ytb_test_options']['show_on_front'] = 'posts';
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'archive-post']);
        self::assertSame('https://example.test', $result['frontend_url']);
        self::assertNull($result['frontend_url_template']);
    }

    public function test_archive_post_returns_pattern_when_show_on_front_is_page(): void
    {
        $GLOBALS['ytb_test_options']['show_on_front'] = 'page';
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'archive-post']);
        self::assertNull($result['frontend_url']);
        self::assertSame('https://example.test/?post_type=post', $result['frontend_url_template']);
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
        // YT-Pro's internal `verifytpl` template-type — used for plugin
        // health pings, never publicly addressable.
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'verifytpl']);
        self::assertNull($result['frontend_url']);
        self::assertNull($result['frontend_url_template']);
        self::assertSame('Internal template — no public URL.', $result['description']);
    }

    public function test_unknown_type_returns_all_nulls(): void
    {
        // Forward-compat — when YT-Pro ships a new template-type the
        // resolver doesn't know about yet, it must NOT 5xx. All-null
        // triple keeps the MCP wire-shape stable; the agent sees an
        // explicit "we don't know" rather than a missing key.
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'future-type-2030']);
        self::assertNull($result['frontend_url']);
        self::assertNull($result['frontend_url_template']);
        self::assertNull($result['description']);
    }

    public function test_missing_type_field_does_not_throw(): void
    {
        // PageQuery emits `type: 'template'` as the default when the YT
        // blob carries no type — but a defensive caller could pass an
        // empty record. The resolver MUST NOT throw on missing `type`.
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x']);
        self::assertNull($result['frontend_url']);
        self::assertNull($result['frontend_url_template']);
        // Empty type → no match → resolveUnknown → null description.
        self::assertNull($result['description']);
    }

    public function test_response_shape_has_exactly_three_keys(): void
    {
        // Pin the wire-shape — the MCP TS PAGES_LIST_OUTPUT_SCHEMA assumes
        // exactly these three keys per row's URL hint. Drift here breaks
        // every consumer that walks the row by key-list.
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'error-404']);
        $keys = \array_keys($result);
        \sort($keys);
        self::assertSame(['description', 'frontend_url', 'frontend_url_template'], $keys);
    }

    public function test_site_url_trailing_slash_is_stripped(): void
    {
        // get_home_url() may return a value with a trailing slash on
        // some installs. The resolver must rtrim so emitted URLs don't
        // accumulate double-slashes.
        $GLOBALS['ytb_test_home_url'] = 'https://example.test/';
        $result = $this->resolver()->resolveFrontendUrl(['id' => 'x', 'type' => 'search']);
        self::assertSame('https://example.test/?s={query}', $result['frontend_url_template']);
    }
}
