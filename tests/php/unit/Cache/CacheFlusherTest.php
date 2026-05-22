<?php
/**
 * CacheFlusher — invalidate YT + WP caches after writes.
 *
 * Wave 3 Task 3.5. Wave 6 Fix 14: nuclear wp_cache_flush() replaced with
 * scoped wp_cache_delete() calls for the options the plugin writes.
 * Without YOOtheme classes loaded (unit-test bootstrap), the YT branch
 * short-circuits to no-op and the WP branch issues per-option deletes.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Cache\CacheFlusher;

#[CoversClass(CacheFlusher::class)]
final class CacheFlusherTest extends TestCase
{
    /**
     * @var array<int, array{key: string, group: string}>
     */
    private array $deleteCalls = [];

    protected function setUp(): void
    {
        // Reset call-log between tests; the wp_cache_delete stub in
        // bootstrap.php has no test hooks, so we wrap it via a global
        // tracking array.
        $GLOBALS['ytb_test_cache_delete_calls'] = [];
    }

    public function test_flush_invokes_scoped_wp_cache_delete(): void
    {
        // Wave-6 Fix 14: the flusher must call wp_cache_delete for the
        // three plugin-owned options + alloptions.
        $this->trackCacheDelete();
        $flusher = new CacheFlusher();
        $flusher->flush();

        $calls = $GLOBALS['ytb_test_cache_delete_calls'];
        self::assertNotEmpty($calls, 'wp_cache_delete should be called at least once');
        $keys = array_map(static fn (array $c): string => $c['key'], $calls);
        self::assertContains('yootheme', $keys);
        self::assertContains('ytb_mcp_keys', $keys);
        self::assertContains('ytb_mcp_signing_secret', $keys);
        self::assertContains('alloptions', $keys);
    }

    public function test_flush_is_noop_safe_without_yt(): void
    {
        // YT classes are absent → the YT branch must short-circuit silently.
        $this->trackCacheDelete();
        $flusher = new CacheFlusher();
        $flusher->flush(); // would throw if branch was not guarded
        self::assertNotEmpty($GLOBALS['ytb_test_cache_delete_calls']);
    }

    public function test_flush_is_idempotent(): void
    {
        $this->trackCacheDelete();
        $flusher = new CacheFlusher();
        $flusher->flush();
        $countAfterFirst = count($GLOBALS['ytb_test_cache_delete_calls']);
        $flusher->flush();
        $countAfterSecond = count($GLOBALS['ytb_test_cache_delete_calls']);
        self::assertSame($countAfterFirst * 2, $countAfterSecond);
    }

    private function trackCacheDelete(): void
    {
        // Wrap the stub once; reset call log per test in setUp.
        if (!function_exists('ytb_test_cache_delete_wrapper_installed')) {
            // The stub from bootstrap.php is already defined; we cannot
            // re-declare. Instead, use the global counter that the stub
            // pushes into when called — but the bootstrap stub doesn't
            // do that yet. Redefine via runkit not available; instead
            // we install the tracking via a sentinel function so the
            // logic in CacheFlusher routes through us.
            //
            // Simpler approach: just call wp_cache_delete ourselves
            // through a helper that captures, then have the bootstrap
            // stub log to globals. We update bootstrap.php to push call
            // entries into ytb_test_cache_delete_calls.
            eval('function ytb_test_cache_delete_wrapper_installed(): bool { return true; }');
        }
    }
}
