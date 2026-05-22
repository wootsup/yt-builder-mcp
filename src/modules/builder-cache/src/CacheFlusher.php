<?php
/**
 * CacheFlusher — invalidate every cache layer that may hold a stale view
 * of the Builder state after a Wave-3 write.
 *
 * Wave 3 Task 3.5. Two layers, in order:
 *
 *   1. YOOtheme Pro cache (`\YOOtheme\app('cache')->flush()`). Only present
 *      when YT is loaded. Wraps node-tree render caches, ObjectType-resolved
 *      values, and the compiled GraphQL schema. Spike-2 confirmed that
 *      without this flush, the next render serves the pre-write tree.
 *
 *   2. Wave-6 Fix 14: scoped object-cache invalidation. Previously this
 *      called `wp_cache_flush()` — nuclear, blows away every plugin's
 *      cache entries on shared object-cache backends (Redis/Memcached
 *      with multiple sites). Replaced with targeted `wp_cache_delete`
 *      calls for the options the plugin writes (yootheme + our two
 *      auth options).
 *
 * The flusher swallows internal errors — failure to flush is logged but
 * never aborts the calling write-endpoint.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Cache
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Cache;

use WootsUp\BuilderMcp\Util\SecurityLogger;
use WootsUp\BuilderMcp\Yootheme\YoothemeAdapter;

final class CacheFlusher
{
    private readonly YoothemeAdapter $yootheme;

    public function __construct(?YoothemeAdapter $yootheme = null)
    {
        $this->yootheme = $yootheme ?? new YoothemeAdapter();
    }

    /**
     * Flush YT + WP caches. Defensive: any exception inside YT's cache
     * service is caught (we don't want a flush failure to surface as a
     * write-endpoint 500 — the write itself succeeded).
     */
    public function flush(): void
    {
        $this->flushYoothemeCache();
        $this->flushWordPressCache();
    }

    private function flushYoothemeCache(): void
    {
        // Wave-6 R2.7: YT cache access funnels through YoothemeAdapter.
        $cache = $this->yootheme->getCache();
        if ($cache === null) {
            return;
        }
        try {
            if (method_exists($cache, 'flush')) {
                $cache->flush();
                return;
            }
            // Some YT versions expose `clear()` instead.
            if (method_exists($cache, 'clear')) {
                $cache->clear();
            }
        } catch (\Throwable $e) {
            // R2.9 security-event log: cache flush failed silently before;
            // now we leave a breadcrumb so ops can investigate.
            SecurityLogger::log('cache_flush_failed', [
                'layer' => 'yootheme',
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function flushWordPressCache(): void
    {
        // Wave-6 Fix 14: scoped invalidation — only the options the plugin
        // writes need their object-cache mirrors evicted. Avoids the
        // wp_cache_flush() nuclear blast on shared object-cache hosts.
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        $targets = [
            ['yootheme', 'options'],
            ['ytb_mcp_keys', 'options'],
            ['ytb_mcp_signing_secret', 'options'],
        ];
        foreach ($targets as [$key, $group]) {
            try {
                \wp_cache_delete($key, $group);
            } catch (\Throwable) {
                // Best-effort — continue with the next target.
            }
        }
        // Also evict the alloptions container — autoload reads land there.
        try {
            \wp_cache_delete('alloptions', 'options');
        } catch (\Throwable) {
            // ignore
        }
    }
}
