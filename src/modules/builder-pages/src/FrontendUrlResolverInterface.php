<?php
/**
 * FrontendUrlResolverInterface — per-template public URL resolver.
 *
 * 2026-05-25 customer-flow gap (F-Frontend-URL): when a customer asks
 * "What is the URL of my 404 template?" the agent must answer without
 * operator input. The MCP tool layer needs a per-template URL hint on
 * `pages_list` so an LLM walking the response can construct or surface a
 * testable link (`frontend_url` when a canonical permalink exists,
 * `frontend_url_template` when only a pattern is meaningful, e.g.
 * search or 404).
 *
 * The resolver is platform-specific because:
 *
 *   - WordPress draws single-post / category / author URLs from
 *     `get_permalink()` / `get_term_link()` / `get_author_posts_url()`.
 *   - Joomla draws article / category / tag URLs from
 *     `RouteHelper::getArticleRoute()` / `getCategoryRoute()` /
 *     `TagsHelperRoute::getTagRoute()`.
 *
 * `PageQuery::list()` consumes any implementation via DI; the default
 * WordPress impl ({@see WordPressFrontendUrlResolver}) is wired in
 * `PageQuery`'s constructor for back-compat, the Joomla impl
 * (`Platform\Joomla\Pages\JoomlaFrontendUrlResolver`) is supplied by the
 * Joomla `PagesController`.
 *
 * Returned shape — all three keys ALWAYS present so the wire shape stays
 * stable; `null` is the honest value when a key is irrelevant:
 *
 *   - `frontend_url: ?string` — fully-qualified public URL when one
 *     exists (latest post / category / author etc.); null otherwise.
 *   - `frontend_url_template: ?string` — pattern with `{site_url}` /
 *     `{query}` placeholders when the URL needs a runtime parameter
 *     (search, 404-test, fallback when no public content exists yet).
 *   - `description: ?string` — short human hint when a template URL
 *     needs an instruction (e.g. "Append any non-existent path to test.").
 *
 * Implementations MUST be safe to call from the `/pages` REST hot path:
 *
 *   - At most one DB lookup per call (no N+1 cascades — caller iterates
 *     across N templates).
 *   - Any failure (deleted post, missing taxonomy, hidden author) returns
 *     `null` for `frontend_url` + a template fallback instead of
 *     throwing; the agent gets the pattern hint and stays usable.
 *   - No 5xx; the hot path stays green.
 *
 * @license   GPL-2.0-or-later
 * @package   WootsUp\BuilderMcp\Pages
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Pages;

interface FrontendUrlResolverInterface
{
    /**
     * Resolve the public URL hint(s) for a template.
     *
     * @param array<string, mixed> $template Template-meta array. Carries at
     *   least `id` (string) and `type` (string) — these are the two fields
     *   PageQuery already emits. May also carry `name`, `title` etc., not
     *   required.
     *
     * @return array{frontend_url: string|null, frontend_url_template: string|null, description: string|null}
     */
    public function resolveFrontendUrl(array $template): array;
}
