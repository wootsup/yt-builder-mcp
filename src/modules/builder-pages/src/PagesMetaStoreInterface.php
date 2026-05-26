<?php
/**
 * PagesMetaStoreInterface — platform-agnostic per-template `modified_at`
 * tracking contract (Wave 7 deploy-fix).
 *
 * The concrete WP {@see PagesMetaStore} talks to `wp_option`; the Joomla
 * twin {@see \WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaPagesMetaStore}
 * talks to `#__ytb_mcp_options`. {@see PageQuery} depends on THIS interface
 * so the Joomla controllers can inject the Joomla store — previously
 * PageQuery hard-typed the concrete WP class, so on Joomla it fell back to
 * `new PagesMetaStore()` which called WordPress's `get_option()` and fataled
 * ("Call to undefined function get_option()") on every `/pages` list.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Pages
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Pages;

interface PagesMetaStoreInterface
{
    /**
     * Read the entire `{template_id => meta}` map.
     *
     * @return array<string, array{modified_at: string}>
     */
    public function all(): array;

    /** Look up the modified_at ISO-8601 for one template, or null. */
    public function modifiedAt(string $templateId): ?string;

    /** Stamp `modified_at` for one template with the current UTC time. */
    public function touch(string $templateId): void;

    /** Forget the entry for one template (used on delete). */
    public function forget(string $templateId): void;
}
