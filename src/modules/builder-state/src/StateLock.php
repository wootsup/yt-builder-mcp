<?php
/**
 * StateLock — best-effort per-template advisory lock around persistence.
 *
 * Wave 6 Round-2 R2.10. Closes the remaining TOCTOU window on
 * concurrent writes to the same template: even after the re-read-then-
 * persist guard inside LayoutWriter::persist, two requests arriving in
 * the same millisecond can both pass the ETag check before either
 * persists. This lock makes the read+write critical-section serialised
 * within a single template-id, using `add_option` semantics (the lone
 * atomic-create-if-absent primitive WordPress reliably ships).
 *
 * Why `add_option` and not transients:
 *  - `set_transient` is NOT race-safe — WP looks up the option, then
 *    writes; two callers both see "no current value" and both succeed.
 *  - `add_option` IS race-safe — the underlying INSERT IGNORE either
 *    creates the row or it doesn't; only one caller's call returns true.
 *
 * The lock is released either explicitly via release(), or by the
 * stale-detector: any acquire() older than LOCK_TTL_SECONDS is treated
 * as orphaned (the holder presumably died with a fatal) and forcibly
 * cleared so writes don't wedge indefinitely.
 *
 * Lock value carries pid + microtime so debugging "who holds the lock"
 * is a one-line option-read.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\State
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\State;

use WootsUp\BuilderMcp\Util\SecurityLogger;

/**
 * @internal `final` keyword intentionally omitted so test doubles can
 *           extend this class to record / simulate lock behaviour. Do not
 *           extend in production code — the lock's correctness depends on
 *           the exact add_option-CAS semantics implemented here.
 *
 * Implements {@see StateLockInterface} (Audit-A1 F-003, Wave 4 fix-round
 * F3) so LayoutWriter implementations can type-hint against the cross-
 * platform contract.
 */
class StateLock implements StateLockInterface
{
    public const LOCK_TTL_SECONDS = 5;
    public const DEFAULT_TIMEOUT_MS = 5000;
    public const POLL_INTERVAL_US = 50000; // 50ms

    /**
     * Acquire an exclusive lock for the given template-id. Returns true
     * on success, false if the timeout expired without acquisition.
     */
    public function acquireForTemplate(string $templateId, int $timeoutMs = self::DEFAULT_TIMEOUT_MS): bool
    {
        if ($templateId === '') {
            return true; // No-op for non-template writes (root pointers).
        }
        if (!function_exists('add_option')) {
            return true; // Test environment without WP — pretend acquired.
        }

        $key = self::optionKey($templateId);
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            if ($this->tryAcquire($key)) {
                return true;
            }
            // Lock contended — check if it's stale (orphaned).
            $this->reclaimIfStale($key);
            usleep(self::POLL_INTERVAL_US);
        }

        SecurityLogger::log(SecurityLogger::EVENT_LOCK_TIMEOUT, [
            'template_id' => $templateId,
            'timeout_ms' => $timeoutMs,
        ]);
        return false;
    }

    /**
     * Release the lock for the given template-id. Always safe to call —
     * if no lock is held, this is a no-op.
     */
    public function releaseForTemplate(string $templateId): void
    {
        if ($templateId === '' || !function_exists('delete_option')) {
            return;
        }
        \delete_option(self::optionKey($templateId));
    }

    /**
     * Run $callback while holding the per-template lock. The lock is
     * released even if the callback throws.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws \RuntimeException When the lock could not be acquired
     *         within $timeoutMs.
     */
    public function withTemplateLock(string $templateId, callable $callback, int $timeoutMs = self::DEFAULT_TIMEOUT_MS): mixed
    {
        $acquired = $this->acquireForTemplate($templateId, $timeoutMs);
        if (!$acquired) {
            throw new \RuntimeException(
                sprintf('Could not acquire lock for template "%s" within %dms.', $templateId, $timeoutMs),
            );
        }
        try {
            return $callback();
        } finally {
            $this->releaseForTemplate($templateId);
        }
    }

    public static function optionKey(string $templateId): string
    {
        // md5 collapses arbitrary template-id syntax to a fixed shape.
        return 'ytb_mcp_lock_tpl_' . md5($templateId);
    }

    private function tryAcquire(string $key): bool
    {
        if (!function_exists('add_option')) {
            return true;
        }
        $value = sprintf('%d:%s', function_exists('getmypid') ? getmypid() : 0, (string) microtime(true));
        // add_option returns false if the option already exists — exactly the
        // semantics we want for a CAS-style lock.
        return (bool) \add_option($key, $value, '', false);
    }

    /**
     * If a lock value is older than LOCK_TTL_SECONDS, delete it. This
     * recovers from holder-crash scenarios so writes don't wedge forever.
     */
    private function reclaimIfStale(string $key): void
    {
        if (!function_exists('get_option') || !function_exists('delete_option')) {
            return;
        }
        $value = \get_option($key, null);
        if (!is_string($value)) {
            return;
        }
        $parts = explode(':', $value, 2);
        if (count($parts) !== 2) {
            // Malformed — clear it.
            \delete_option($key);
            return;
        }
        $age = microtime(true) - (float) $parts[1];
        if ($age > self::LOCK_TTL_SECONDS) {
            \delete_option($key);
        }
    }
}
