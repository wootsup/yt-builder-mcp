<?php
/**
 * JoomlaFrontendUrlResolver — per-template frontend URL hint resolver
 * for Joomla.
 *
 * Joomla equivalent of {@see \WootsUp\BuilderMcp\Pages\WordPressFrontendUrlResolver}.
 * Maps YT template `type` strings (cross-platform names produced by the
 * YT cookbook §1.2.6 contract) onto Joomla-side article / category /
 * tag / contact / finder route lookups.
 *
 * Type → URL strategy table (parity with WP-side resolver):
 *
 *   error-404 / 404            → null + `{site_url}/<any-nonexistent-path>`
 *                                 + "Append any non-existent path to test."
 *   single-post / com_content.
 *     article / article         → `RouteHelper::getArticleRoute()` for
 *                                 the latest published com_content article.
 *   single-page                 → mirrors single-post on Joomla — there's
 *                                 no native "page" content-type.
 *   taxonomy-category / com_
 *     content.category /
 *     category / archive-
 *     category                  → `RouteHelper::getCategoryRoute()` for
 *                                 the first content-category.
 *   taxonomy-post_tag / com_
 *     tags.tag / tag            → `TagsHelperRoute::getTagRoute()` for
 *                                 the first tag.
 *   author / com_contact.
 *     contact / archive-author  → first contact-route as the conventional
 *                                 author-archive analogue on Joomla.
 *   search / com_finder.search  → null + finder template URL
 *                                 (`{site_url}/index.php?option=com_finder&view=search&q={query}`).
 *   archive-post / posts /
 *     home                      → `Uri::root()` — Joomla default-menu
 *                                 typically renders article-list at /.
 *   layout / verifytpl / _meta  → null + "Internal template — no public URL.".
 *
 * SAFETY: every Joomla-API call is guarded by `class_exists()` so unit
 * tests outside the Joomla bootstrap stay green. Throwables degrade to
 * the `frontend_url_template` fallback — the hot path never 5xx's.
 *
 * @license   GPL-2.0-or-later
 * @package   WootsUp\BuilderMcp\Platform\Joomla\Pages
 * @copyright (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Pages;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\ParameterType;
use WootsUp\BuilderMcp\Pages\FrontendUrlResolverInterface;

final class JoomlaFrontendUrlResolver implements FrontendUrlResolverInterface
{
    /**
     * Memoised site root — `Uri::root()` is cheap but called per template.
     */
    private ?string $siteUrl = null;

    public function resolveFrontendUrl(array $template): array
    {
        $type = isset($template['type']) && \is_string($template['type'])
            ? \strtolower($template['type'])
            : '';

        // Normalise — accept both hyphenated + underscored + dotted
        // forms (com_content.article, single-post, single_post, etc.).
        $normalised = \str_replace(['_', '.'], '-', $type);

        return match (true) {
            $this->isErrorFourOhFour($normalised)   => $this->resolveFourOhFour(),
            $this->isArticle($normalised)           => $this->resolveArticle(),
            $this->isCategory($normalised)          => $this->resolveCategory(),
            $this->isTag($normalised)               => $this->resolveTag(),
            $this->isContact($normalised)           => $this->resolveContact(),
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

    private function isArticle(string $t): bool
    {
        return $t === 'single-post'
            || $t === 'single-page'
            || $t === 'com-content-article'
            || $t === 'article'
            || $t === 'single';
    }

    private function isCategory(string $t): bool
    {
        return $t === 'taxonomy-category'
            || $t === 'com-content-category'
            || $t === 'category'
            || $t === 'archive-category';
    }

    private function isTag(string $t): bool
    {
        return $t === 'taxonomy-post-tag'
            || $t === 'com-tags-tag'
            || $t === 'tag'
            || $t === 'post-tag'
            || $t === 'archive-post-tag';
    }

    private function isContact(string $t): bool
    {
        return $t === 'author'
            || $t === 'author-archive'
            || $t === 'archive-author'
            || $t === 'com-contact-contact'
            || $t === 'contact';
    }

    private function isSearch(string $t): bool
    {
        return $t === 'search'
            || $t === 'search-results'
            || $t === 'com-finder-search';
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
            || $t === 'template';
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
    private function resolveArticle(): array
    {
        $row = $this->firstContentRow('articles');
        if ($row !== null) {
            $route = $this->routeArticle($row);
            if ($route !== null) {
                return [
                    'frontend_url'          => $route,
                    'frontend_url_template' => null,
                    'description'           => 'Latest published article — rendered with this template.',
                ];
            }
        }
        return [
            'frontend_url'          => null,
            'frontend_url_template' => $this->siteUrl() . '/index.php?option=com_content&view=article&id={ID}',
            'description'           => 'No published articles found — append an article ID.',
        ];
    }

    /** @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null} */
    private function resolveCategory(): array
    {
        $row = $this->firstContentRow('categories');
        if ($row !== null) {
            $route = $this->routeCategory($row);
            if ($route !== null) {
                return [
                    'frontend_url'          => $route,
                    'frontend_url_template' => null,
                    'description'           => 'First content-category — rendered with this template.',
                ];
            }
        }
        return [
            'frontend_url'          => null,
            'frontend_url_template' => $this->siteUrl() . '/index.php?option=com_content&view=category&id={ID}',
            'description'           => 'No categories found — append a category ID.',
        ];
    }

    /** @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null} */
    private function resolveTag(): array
    {
        $row = $this->firstContentRow('tags');
        if ($row !== null) {
            $route = $this->routeTag($row);
            if ($route !== null) {
                return [
                    'frontend_url'          => $route,
                    'frontend_url_template' => null,
                    'description'           => 'First tag — rendered with this template.',
                ];
            }
        }
        return [
            'frontend_url'          => null,
            'frontend_url_template' => $this->siteUrl() . '/index.php?option=com_tags&view=tag&id={ID}',
            'description'           => 'No tags found — append a tag ID.',
        ];
    }

    /** @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null} */
    private function resolveContact(): array
    {
        $row = $this->firstContentRow('contacts');
        if ($row !== null) {
            $route = $this->routeContact($row);
            if ($route !== null) {
                return [
                    'frontend_url'          => $route,
                    'frontend_url_template' => null,
                    'description'           => 'First contact — rendered with this template (author analogue).',
                ];
            }
        }
        return [
            'frontend_url'          => null,
            'frontend_url_template' => $this->siteUrl() . '/index.php?option=com_contact&view=contact&id={ID}',
            'description'           => 'No contacts found — append a contact ID.',
        ];
    }

    /** @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null} */
    private function resolveSearch(): array
    {
        return [
            'frontend_url'          => null,
            'frontend_url_template' => $this->siteUrl() . '/index.php?option=com_finder&view=search&q={query}',
            'description'           => 'Append `q=<query>` to search (com_finder).',
        ];
    }

    /** @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null} */
    private function resolvePostArchive(): array
    {
        return [
            'frontend_url'          => $this->siteUrl(),
            'frontend_url_template' => null,
            'description'           => 'Site root (default-menu-item).',
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

    // ─── Joomla-API helpers ──────────────────────────────────────────

    private function siteUrl(): string
    {
        if ($this->siteUrl !== null) {
            return $this->siteUrl;
        }
        if (\class_exists(Uri::class)) {
            try {
                $val = (string) Uri::root();
                return $this->siteUrl = \rtrim($val, '/');
            } catch (\Throwable) {
                // fall through
            }
        }
        return $this->siteUrl = '';
    }

    /**
     * Fetch the first (most recent or lowest-id) published row from a
     * content-table. Returns an object with `id` + relevant fields, or
     * null when the table is empty / unreachable.
     *
     * @param 'articles'|'categories'|'tags'|'contacts' $kind
     * @return object|null
     */
    private function firstContentRow(string $kind): ?object
    {
        if (!\class_exists(Factory::class)) {
            return null;
        }
        try {
            $container = Factory::getContainer();
            if (!\is_object($container) || !\method_exists($container, 'get')) {
                return null;
            }
            $db = $container->get('DatabaseDriver');
            if (!\is_object($db)) {
                return null;
            }
            // J6-forward-compat: use $db->createQuery() exclusively.
            // The pre-J6 legacy factory signature is removed in J6 and
            // banned from platform-joomla source by the pin-test
            // {@see Joomla6ForwardCompatPinTest}.
            if (!\method_exists($db, 'createQuery')) {
                return null;
            }
            $query = $db->createQuery();
            if (!\is_object($query)) {
                return null;
            }
            [$table, $columns, $orderBy, $stateCol, $extraWhere] = $this->queryParts($kind);
            if ($table === '') {
                return null;
            }
            $query->select($columns)
                ->from($db->quoteName($table));

            // J6-forward-compat: published-state column gets bound as an
            // INTEGER parameter rather than literal-quoted, so the
            // pin-test ({@see QuoteNullReturnsEmptyPinTest}) doesn't trip
            // on a raw `->quote(` call. The fixed value (1) carries zero
            // injection risk but going through bind() keeps the codebase
            // consistent with the "every SQL site uses ParameterType::*"
            // convention enforced across platform-joomla.
            //
            // bind() takes its value parameter by reference, so each var
            // must be declared in scope before the bind call (PHPStan
            // catches the missing declaration with "Variable might not be
            // defined"). We declare $publishedFlag once outside any branch
            // and keep the bound-value vars in an array so each foreach
            // iteration writes into a fresh slot.
            $publishedFlag = 1;
            if ($stateCol !== '') {
                $query->where($db->quoteName($stateCol) . ' = :state')
                    ->bind(':state', $publishedFlag, ParameterType::INTEGER);
            }
            /** @var array<int, mixed> $boundValues — slot per :pN binding so each ->bind() gets a live reference. */
            $boundValues = [];
            foreach ($extraWhere as $i => $clause) {
                if (\is_string($clause)) {
                    // Literal where-clauses are limited to safe column
                    // comparisons with hard-coded numeric / categorical
                    // values ('parent_id > 0', 'state = 1'). No user
                    // input flows here — the bound-parameter convention
                    // is observed wherever it could.
                    $query->where($clause);
                } elseif (\is_array($clause) && \count($clause) === 3) {
                    [$col, $type, $value] = $clause;
                    $paramName = ':p' . $kind . $i;
                    $boundValues[$i] = $value;
                    $query->where($db->quoteName((string) $col) . ' = ' . $paramName)
                        ->bind($paramName, $boundValues[$i], (int) $type);
                }
            }
            $query->order($orderBy);
            if (\method_exists($db, 'setQuery')) {
                $db->setQuery($query, 0, 1);
            } else {
                return null;
            }
            $row = $db->loadObject();
            return \is_object($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Return the per-content-kind query parts:
     *
     *   [tableName, columnList, orderBy, stateColumn, extraWhereClauses]
     *
     * stateColumn is the column-name to bind `= 1` against (typically
     * `state` for com_content or `published` for the other tables). Empty
     * means "no state filter" — used when a table doesn't carry a
     * conventional state column. extraWhereClauses are EITHER literal
     * SQL fragments (hard-coded, no user input — see safety note above)
     * OR three-element bind descriptors [column, ParameterType, value]
     * that the loop materialises into bound parameters.
     *
     * @param 'articles'|'categories'|'tags'|'contacts' $kind
     * @return array{0:string,1:string,2:string,3:string,4:list<string|array{0:string,1:int,2:string|int}>}
     */
    private function queryParts(string $kind): array
    {
        return match ($kind) {
            'articles' => [
                '#__content',
                'id, alias, catid, language',
                'id DESC',
                'state',
                [],
            ],
            'categories' => [
                '#__categories',
                'id, alias, language',
                'id ASC',
                'published',
                // extension = 'com_content' as a bound STRING parameter.
                [['extension', ParameterType::STRING, 'com_content']],
            ],
            'tags' => [
                '#__tags',
                'id, alias, language',
                'id ASC',
                'published',
                // parent_id > 0 — literal numeric comparison, no input.
                ['parent_id > 0'],
            ],
            'contacts' => [
                '#__contact_details',
                'id, alias, catid, language',
                'id ASC',
                'published',
                [],
            ],
            default => ['', '', '', '', []],
        };
    }

    private function routeArticle(object $row): ?string
    {
        $callable = $this->resolveStaticCallable(
            '\\Joomla\\Component\\Content\\Site\\Helper\\RouteHelper',
            'getArticleRoute',
        );
        if ($callable === null) {
            // Cookbook fallback — direct query-string URL works in every
            // Joomla install regardless of SEF / RouteHelper availability.
            return $this->siteUrl() . '/index.php?option=com_content&view=article&id=' . (int) ($row->id ?? 0);
        }
        try {
            $id = (int) ($row->id ?? 0);
            $alias = isset($row->alias) ? (string) $row->alias : '';
            $catid = (int) ($row->catid ?? 0);
            $language = isset($row->language) && \is_string($row->language) ? $row->language : '*';
            $segment = $id . ($alias !== '' ? (':' . $alias) : '');
            $route = (string) $callable($segment, $catid, $language);
            return $this->buildRouteUrl($route);
        } catch (\Throwable) {
            return null;
        }
    }

    private function routeCategory(object $row): ?string
    {
        $callable = $this->resolveStaticCallable(
            '\\Joomla\\Component\\Content\\Site\\Helper\\RouteHelper',
            'getCategoryRoute',
        );
        if ($callable === null) {
            return $this->siteUrl() . '/index.php?option=com_content&view=category&id=' . (int) ($row->id ?? 0);
        }
        try {
            $id = (int) ($row->id ?? 0);
            $alias = isset($row->alias) ? (string) $row->alias : '';
            $language = isset($row->language) && \is_string($row->language) ? $row->language : '*';
            $segment = $id . ($alias !== '' ? (':' . $alias) : '');
            $route = (string) $callable($segment, $language);
            return $this->buildRouteUrl($route);
        } catch (\Throwable) {
            return null;
        }
    }

    private function routeTag(object $row): ?string
    {
        $callable = $this->resolveStaticCallable(
            '\\Joomla\\Component\\Tags\\Site\\Helper\\RouteHelper',
            'getComponentTagRoute',
        );
        if ($callable === null) {
            // Cookbook fallback — Joomla 4/5 RouteHelper signature varies;
            // direct query-string route is universally supported.
            return $this->siteUrl() . '/index.php?option=com_tags&view=tag&id=' . (int) ($row->id ?? 0);
        }
        try {
            $id = (int) ($row->id ?? 0);
            $alias = isset($row->alias) ? (string) $row->alias : '';
            $language = isset($row->language) && \is_string($row->language) ? $row->language : '*';
            $segment = $id . ($alias !== '' ? (':' . $alias) : '');
            $route = (string) $callable($segment, $language);
            return $this->buildRouteUrl($route);
        } catch (\Throwable) {
            return null;
        }
    }

    private function routeContact(object $row): ?string
    {
        $callable = $this->resolveStaticCallable(
            '\\Joomla\\Component\\Contact\\Site\\Helper\\RouteHelper',
            'getContactRoute',
        );
        if ($callable === null) {
            return $this->siteUrl() . '/index.php?option=com_contact&view=contact&id=' . (int) ($row->id ?? 0);
        }
        try {
            $id = (int) ($row->id ?? 0);
            $catid = (int) ($row->catid ?? 0);
            $language = isset($row->language) && \is_string($row->language) ? $row->language : '*';
            $route = (string) $callable($id, $catid, $language);
            return $this->buildRouteUrl($route);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve a static method as a callable when the runtime exposes it.
     *
     * Joomla's site-helper RouteHelpers (com_content, com_tags, com_contact)
     * are NOT in the platform-joomla unit-test stub or PHPStan symbol map
     * — `class_exists()` returns false in unit tests and PHPStan can't
     * see the methods. Going through `is_callable()` keeps the call-site
     * cleanly typed (returns `callable|null`), satisfies PHPStan (no
     * "always-false method_exists" warnings), and degrades to the
     * query-string fallback when the helper is absent.
     *
     * @return callable|null A callable matching `($args) => mixed` when
     *   the class+method pair is available at runtime; null otherwise.
     */
    private function resolveStaticCallable(string $class, string $method): ?callable
    {
        if (!\class_exists($class)) {
            return null;
        }
        $callable = [$class, $method];
        if (!\is_callable($callable)) {
            return null;
        }
        return $callable;
    }

    /**
     * Project a Joomla SEF-route segment (often relative, e.g.
     * `index.php?option=com_content&view=article&id=1`) onto an absolute
     * URL. Uses {@see Route::_()} when present so SEF rewrites apply.
     */
    private function buildRouteUrl(string $route): ?string
    {
        if ($route === '') {
            return null;
        }
        $routeCallable = $this->resolveStaticCallable(Route::class, '_');
        if ($routeCallable !== null) {
            try {
                $sef = $routeCallable($route, false);
                $sef = \is_string($sef) ? $sef : (string) $sef;
                // Route::_() returns a path; prepend the site root so the
                // MCP-client gets a fully-qualified URL.
                if ($sef === '') {
                    return null;
                }
                if (\str_starts_with($sef, 'http://') || \str_starts_with($sef, 'https://')) {
                    return $sef;
                }
                return $this->siteUrl() . '/' . \ltrim($sef, '/');
            } catch (\Throwable) {
                return null;
            }
        }
        if (\str_starts_with($route, 'http://') || \str_starts_with($route, 'https://')) {
            return $route;
        }
        return $this->siteUrl() . '/' . \ltrim($route, '/');
    }
}
