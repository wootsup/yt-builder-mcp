<?php
/**
 * WordPressFrontendUrlResolver — per-template frontend URL hint resolver
 * for WordPress.
 *
 * Default implementation of {@see FrontendUrlResolverInterface} on
 * WordPress. Pattern-matches the YT template `type` field and emits a
 * permalink-style `frontend_url` (when one exists) plus a
 * `frontend_url_template` pattern + short description hint so the
 * MCP-agent can answer "what is the URL of my <type> template?"
 * without guessing.
 *
 * Type → URL strategy table:
 *
 *   error-404 / 404         → null + `{site_url}/<any-nonexistent-path>`
 *                              + "Append any non-existent path to test."
 *   single-post / post      → latest published post permalink, else
 *                              `{site_url}/?p={ID}`.
 *   single-page / page      → latest published page permalink, else
 *                              `{site_url}/?page_id={ID}`.
 *   taxonomy-category /
 *     category / archive-
 *     category               → first category permalink, else
 *                              `{site_url}/?cat={ID}`.
 *   taxonomy-post_tag /
 *     post_tag / tag /
 *     archive-post_tag       → first tag permalink, else
 *                              `{site_url}/?tag={slug}`.
 *   author / author-archive → first user permalink, else
 *                              `{site_url}/author/{username}`.
 *   search                  → null + `{site_url}/?s={query}` +
 *                              "Append `?s=<query>` to search."
 *   archive-post / posts    → site home (`get_home_url()`) when the
 *                              site renders posts at /; otherwise null +
 *                              `{site_url}/?post_type=post` pattern hint.
 *   layout / _meta / verifytpl → null + "Internal template — no public URL.".
 *
 * Cookbook reference: §1.2.6 (resolver platform-adapter contract).
 * Related: {@see WordPressPublicRootResolver} — same pattern for the
 * `is_public_homepage` hint.
 *
 * SAFETY: every WP-API call (`get_posts`, `get_terms`, `get_users`,
 * `get_permalink`, `get_term_link`, `get_author_posts_url`) is guarded
 * by `function_exists()` so unit tests outside the WP bootstrap stay
 * green. Throwables are caught at the call-site and degrade to the
 * `frontend_url_template` fallback — the hot path never 5xx's.
 *
 * @license   GPL-2.0-or-later
 * @package   WootsUp\BuilderMcp\Pages
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Pages;

final class WordPressFrontendUrlResolver implements FrontendUrlResolverInterface
{
    /**
     * Memoised site URL — avoids re-calling `get_home_url()` per template
     * row in a large pages_list response (N=20+ on typical demo sites).
     */
    private ?string $siteUrl = null;

    public function resolveFrontendUrl(array $template): array
    {
        $type = isset($template['type']) && \is_string($template['type'])
            ? \strtolower($template['type'])
            : '';

        // Normalise — YT uses both hyphenated and underscored variants
        // across the 4.x → 5.x range.
        $normalised = \str_replace('_', '-', $type);

        return match (true) {
            $this->isErrorFourOhFour($normalised)   => $this->resolveFourOhFour(),
            $this->isSinglePost($normalised)        => $this->resolveSinglePost(),
            $this->isSinglePage($normalised)        => $this->resolveSinglePage(),
            $this->isCategoryTaxonomy($normalised)  => $this->resolveCategoryArchive(),
            $this->isTagTaxonomy($normalised)       => $this->resolveTagArchive(),
            $this->isAuthorArchive($normalised)     => $this->resolveAuthorArchive(),
            $this->isSearch($normalised)            => $this->resolveSearch(),
            $this->isPostArchive($normalised)       => $this->resolvePostArchive(),
            $this->isInternal($normalised)          => $this->resolveInternal(),
            default                                  => $this->resolveUnknown(),
        };
    }

    // ─── Type predicates ─────────────────────────────────────────────

    private function isErrorFourOhFour(string $t): bool
    {
        return $t === 'error-404' || $t === '404' || $t === 'error404';
    }

    private function isSinglePost(string $t): bool
    {
        return $t === 'single-post' || $t === 'post' || $t === 'single';
    }

    private function isSinglePage(string $t): bool
    {
        return $t === 'single-page' || $t === 'page';
    }

    private function isCategoryTaxonomy(string $t): bool
    {
        return $t === 'taxonomy-category'
            || $t === 'category'
            || $t === 'archive-category';
    }

    private function isTagTaxonomy(string $t): bool
    {
        return $t === 'taxonomy-post-tag'
            || $t === 'post-tag'
            || $t === 'tag'
            || $t === 'archive-post-tag';
    }

    private function isAuthorArchive(string $t): bool
    {
        return $t === 'author' || $t === 'author-archive' || $t === 'archive-author';
    }

    private function isSearch(string $t): bool
    {
        return $t === 'search' || $t === 'search-results';
    }

    private function isPostArchive(string $t): bool
    {
        return $t === 'archive-post' || $t === 'posts' || $t === 'home';
    }

    private function isInternal(string $t): bool
    {
        return $t === 'layout'
            || $t === '_meta'
            || $t === 'meta'
            || $t === 'verifytpl'
            || $t === 'template'; // generic YT default — internal until bound to a route.
    }

    // ─── Resolvers ───────────────────────────────────────────────────

    /** @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null} */
    private function resolveFourOhFour(): array
    {
        return [
            'frontend_url'          => null,
            'frontend_url_template' => $this->siteUrl() . '/<any-nonexistent-path>',
            'description'           => 'Append any non-existent path to test.',
        ];
    }

    /** @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null} */
    private function resolveSinglePost(): array
    {
        $url = $this->latestPermalink('post');
        if ($url !== null) {
            return [
                'frontend_url'          => $url,
                'frontend_url_template' => null,
                'description'           => 'Latest published post — rendered with this template.',
            ];
        }
        return [
            'frontend_url'          => null,
            'frontend_url_template' => $this->siteUrl() . '/?p={ID}',
            'description'           => 'No published posts found — append a post ID.',
        ];
    }

    /** @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null} */
    private function resolveSinglePage(): array
    {
        $url = $this->latestPermalink('page');
        if ($url !== null) {
            return [
                'frontend_url'          => $url,
                'frontend_url_template' => null,
                'description'           => 'Latest published page — rendered with this template.',
            ];
        }
        return [
            'frontend_url'          => null,
            'frontend_url_template' => $this->siteUrl() . '/?page_id={ID}',
            'description'           => 'No published pages found — append a page ID.',
        ];
    }

    /** @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null} */
    private function resolveCategoryArchive(): array
    {
        $url = $this->firstTermPermalink('category');
        if ($url !== null) {
            return [
                'frontend_url'          => $url,
                'frontend_url_template' => null,
                'description'           => 'First category archive — rendered with this template.',
            ];
        }
        return [
            'frontend_url'          => null,
            'frontend_url_template' => $this->siteUrl() . '/?cat={ID}',
            'description'           => 'No categories found — append a category ID.',
        ];
    }

    /** @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null} */
    private function resolveTagArchive(): array
    {
        $url = $this->firstTermPermalink('post_tag');
        if ($url !== null) {
            return [
                'frontend_url'          => $url,
                'frontend_url_template' => null,
                'description'           => 'First tag archive — rendered with this template.',
            ];
        }
        return [
            'frontend_url'          => null,
            'frontend_url_template' => $this->siteUrl() . '/?tag={slug}',
            'description'           => 'No tags found — append a tag slug.',
        ];
    }

    /** @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null} */
    private function resolveAuthorArchive(): array
    {
        $url = $this->firstAuthorPermalink();
        if ($url !== null) {
            return [
                'frontend_url'          => $url,
                'frontend_url_template' => null,
                'description'           => 'First author archive — rendered with this template.',
            ];
        }
        return [
            'frontend_url'          => null,
            'frontend_url_template' => $this->siteUrl() . '/author/{username}',
            'description'           => 'No users found — append a user-nicename.',
        ];
    }

    /** @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null} */
    private function resolveSearch(): array
    {
        return [
            'frontend_url'          => null,
            'frontend_url_template' => $this->siteUrl() . '/?s={query}',
            'description'           => 'Append `?s=<query>` to search.',
        ];
    }

    /** @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null} */
    private function resolvePostArchive(): array
    {
        // The post-archive template renders at site root if WP
        // `show_on_front=posts`. When `=page`, the post-archive template
        // is unreachable from a canonical URL unless a "Posts page" is
        // configured. We surface the safer pattern hint either way.
        $home = $this->siteUrl();
        $showOnFront = \function_exists('get_option') ? \get_option('show_on_front', 'posts') : 'posts';
        if ($showOnFront === 'posts') {
            return [
                'frontend_url'          => $home,
                'frontend_url_template' => null,
                'description'           => 'Post archive rendered at site root.',
            ];
        }
        return [
            'frontend_url'          => null,
            'frontend_url_template' => $home . '/?post_type=post',
            'description'           => 'Post archive — site root is bound to a static page.',
        ];
    }

    /** @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null} */
    private function resolveInternal(): array
    {
        return [
            'frontend_url'          => null,
            'frontend_url_template' => null,
            'description'           => 'Internal template — no public URL.',
        ];
    }

    /** @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null} */
    private function resolveUnknown(): array
    {
        return [
            'frontend_url'          => null,
            'frontend_url_template' => null,
            'description'           => null,
        ];
    }

    // ─── WP-API helpers ──────────────────────────────────────────────

    private function siteUrl(): string
    {
        if ($this->siteUrl !== null) {
            return $this->siteUrl;
        }
        if (\function_exists('get_home_url')) {
            try {
                $val = \get_home_url();
                if (\is_string($val) && $val !== '') {
                    return $this->siteUrl = \rtrim($val, '/');
                }
            } catch (\Throwable) {
                // fall through
            }
        }
        return $this->siteUrl = '';
    }

    private function latestPermalink(string $postType): ?string
    {
        if (!\function_exists('get_posts') || !\function_exists('get_permalink')) {
            return null;
        }
        try {
            $posts = \get_posts([
                'post_type'      => $postType,
                'post_status'    => 'publish',
                'numberposts'    => 1,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'suppress_filters' => true,
            ]);
            if (!\is_array($posts) || $posts === []) {
                return null;
            }
            $first = $posts[0];
            $link = \get_permalink($first);
            return \is_string($link) && $link !== '' ? $link : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function firstTermPermalink(string $taxonomy): ?string
    {
        if (!\function_exists('get_terms') || !\function_exists('get_term_link')) {
            return null;
        }
        try {
            $terms = \get_terms([
                'taxonomy'   => $taxonomy,
                'number'     => 1,
                'hide_empty' => false,
            ]);
            if (!\is_array($terms) || $terms === []) {
                return null;
            }
            $first = $terms[0];
            $link = \get_term_link($first);
            return \is_string($link) && $link !== '' ? $link : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function firstAuthorPermalink(): ?string
    {
        if (!\function_exists('get_users') || !\function_exists('get_author_posts_url')) {
            return null;
        }
        try {
            $users = \get_users([
                'number'  => 1,
                'orderby' => 'ID',
                'order'   => 'ASC',
                'fields'  => ['ID'],
            ]);
            if (!\is_array($users) || $users === []) {
                return null;
            }
            $first = $users[0];
            $id = \is_object($first) && isset($first->ID) ? (int) $first->ID : 0;
            if ($id <= 0) {
                return null;
            }
            $link = \get_author_posts_url($id);
            return \is_string($link) && $link !== '' ? $link : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
