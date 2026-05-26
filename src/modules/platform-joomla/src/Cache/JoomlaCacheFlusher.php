<?php
/**
 * JoomlaCacheFlusher — scope-specific cache-invalidation adapter for the
 * Joomla platform, implementing the contract documented in ADR-002
 * (`docs/adr/2026-05-24-joomla-port-foundation-adrs.md`).
 *
 * Two storage layers map to different cache surfaces:
 *
 *   - **L1 — Type Templates** (`#__extensions.custom_data WHERE
 *     element='yootheme' AND folder='system'`). Joomla reloads the row
 *     per-request — there is NO Joomla autoload-options-cache equivalent
 *     to WP's `'alloptions'` bucket. Only the YT cache layer (which
 *     wraps node-tree renders, ObjectType-resolved values, and the
 *     compiled GraphQL schema) is structurally required.
 *
 *   - **L2 — Per-Article Content** (`#__content.fulltext` via
 *     `com_content`). The Joomla `com_content` cache-group DOES hold
 *     stale views; our direct-write bypasses the model events that would
 *     auto-evict, so we must call `clean('com_content')` ourselves.
 *     Additionally, when the `plg_system_cache` page-cache plugin is
 *     enabled, the `page` cache-group also needs clearing.
 *
 * WP-parity invariant (cookbook §2.10.15): cache-flush failures are
 * caught, logged via SecurityLogger, and NEVER undo the underlying
 * write. The calling controller must observe write-success irrespective
 * of cache-flush outcome.
 *
 * **Anti-patterns explicitly forbidden** (codified per ADR-002):
 *
 *   - NEVER call `\Joomla\CMS\Cache\Cache::cleanCache()` — same
 *     regression-class as the WP-side `wp_cache_flush()` blunder fixed
 *     in Wave-6 Fix 14. Nuclear flush blows away unrelated extensions'
 *     cache groups on shared backends.
 *   - NEVER call the deprecated static `\JCache::getInstance('callback')`
 *     or `\Joomla\CMS\Cache\Cache::getInstance(...)` — deprecated in J5,
 *     throws `E_USER_DEPRECATED` in J6. ALWAYS resolve
 *     `CacheControllerFactoryInterface` from the DI container.
 *   - NEVER `rm -rf` or otherwise touch `templates/yootheme/cache/*` on
 *     the filesystem — those are content-hashed compiled package configs,
 *     NOT user state. Deletion forces unnecessary recompile and races
 *     with concurrent renders.
 *
 * Cross-references:
 *   - ADR-002 (Wave 0 Spike S1 outcome) — `docs/adr/2026-05-24-joomla-port-foundation-adrs.md`
 *   - Cookbook §4.8 — WP CacheFlusher reference implementation
 *   - Cookbook §4.13.3 — WP↔Joomla cache equivalents table
 *   - WP-side reference — `modules/builder-cache/src/CacheFlusher.php`
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Cache
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Cache;

use WootsUp\BuilderMcp\Util\SecurityLogger;

defined('_JEXEC') or die;

final class JoomlaCacheFlusher
{
    /**
     * Flush caches affected by an L1 (Type Template) write.
     *
     * `#__extensions.custom_data` writes only require the YT cache layer
     * to be invalidated — there is no Joomla-side equivalent to WP's
     * `'alloptions'` bucket. See ADR-002 §"Decision".
     */
    public function flushL1(): void
    {
        $this->flushYoothemeCache();
    }

    /**
     * Flush caches affected by an L2 (per-article) write.
     *
     * Per-article writes touch `#__content.fulltext`; we bypass the
     * `com_content` model events that would auto-evict, so we explicitly
     * clean the `com_content` cache-group ourselves. Additionally, if
     * the `plg_system_cache` page-cache plugin is enabled, the `page`
     * cache-group needs invalidation too. See ADR-002 §"Decision".
     *
     * The `$articleId` parameter is preserved for future per-key
     * cache-eviction support; Joomla's stock `clean()` API does not
     * accept a key-filter, so this method currently scopes by group
     * only.
     */
    public function flushL2(int $articleId): void
    {
        $this->flushYoothemeCache();
        $this->cleanGroup('com_content');

        if ($this->isPageCachePluginEnabled()) {
            $this->cleanGroup('page');
        }
    }

    /**
     * Flush YT's internal cache (Layer 1, applies to BOTH L1 + L2).
     *
     * YT may not be bootstrapped on every call-site (see ADR-001 +
     * cookbook §S2 — `com_api` requests bypass YT bootstrap). The
     * `function_exists` guard makes this a graceful no-op when YT is
     * unavailable: read-only routes that don't touch the Builder still
     * succeed; write routes that DO touch the Builder will have
     * triggered bootstrap-ensure before reaching the flusher.
     */
    private function flushYoothemeCache(): void
    {
        if (!\function_exists('\YOOtheme\app')) {
            return;
        }

        try {
            $cache = \YOOtheme\app('cache');
            if ($cache === null) {
                return;
            }

            if (\method_exists($cache, 'flush')) {
                $cache->flush();
                return;
            }

            // Some YT versions expose `clear()` instead of `flush()`.
            if (\method_exists($cache, 'clear')) {
                $cache->clear();
            }
        } catch (\Throwable $e) {
            SecurityLogger::log(SecurityLogger::EVENT_CACHE_FLUSH_FAILED, [
                'platform' => 'joomla',
                'layer'    => 'yt',
                'reason'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clean a single Joomla cache-group via the DI-resolved
     * `CacheControllerFactoryInterface`. Failure is logged but never
     * propagated.
     *
     * @param  string  $group  Joomla cache-group name (e.g. 'com_content', 'page').
     */
    private function cleanGroup(string $group): void
    {
        try {
            $factory = \Joomla\CMS\Factory::getContainer()->get(
                \Joomla\CMS\Cache\CacheControllerFactoryInterface::class
            );
            $controller = $factory->createCacheController('callback', ['defaultgroup' => $group]);
            $controller->clean($group);
        } catch (\Throwable $e) {
            SecurityLogger::log(SecurityLogger::EVENT_CACHE_FLUSH_FAILED, [
                'platform' => 'joomla',
                'layer'    => $group,
                'reason'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Runtime-check whether the `plg_system_cache` page-cache plugin is
     * enabled. The page cache-group is only meaningful when this plugin
     * is active (it stores rendered front-end HTML); flushing it when
     * the plugin is disabled is a wasted call.
     *
     * Wrapped defensively — `PluginHelper::isEnabled` shouldn't throw,
     * but the cache-flush invariant demands we never let a probe failure
     * abort the calling write.
     */
    private function isPageCachePluginEnabled(): bool
    {
        try {
            return \Joomla\CMS\Plugin\PluginHelper::isEnabled('system', 'cache');
        } catch (\Throwable) {
            return false;
        }
    }
}
