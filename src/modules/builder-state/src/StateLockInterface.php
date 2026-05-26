<?php
/**
 * Cross-platform contract for per-template advisory locking around the
 * Builder-state read+persist critical section.
 *
 * Both the WordPress {@see StateLock} (add_option-CAS backed) and the
 * Joomla {@see \WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaStateLock}
 * (INSERT IGNORE on `#__ytb_mcp_lock` backed) satisfy this contract.
 * LayoutWriter implementations type-hint against the interface so the
 * same call-pattern works on both platforms without platform-leak in
 * the writer.
 *
 * Cookbook §4.5 fidelity is preserved on both implementations:
 *   - withTemplateLock() acquires under per-template key, runs the
 *     callback, releases in `finally` (lock is released even when the
 *     callback throws).
 *   - acquireForTemplate() polls until the deadline expires; while
 *     contended, the impl reclaims orphaned locks older than LOCK_TTL
 *     seconds (cookbook §4.5.6 — recovers from holder-crash scenarios).
 *   - Empty templateId short-circuits to "always acquired" — root- and
 *     library-scoped writes do not lock (cookbook §4.5.8). This is
 *     guard-railed by the @internal scope of writeByPointer()/delete().
 *
 * Audit-A1 F-003 (Wave 4 fix-round F3) extraction — the interface
 * preserves the public signatures originally defined by StateLock so
 * that existing call-sites continue to work unchanged.
 *
 * @license   GPL-2.0-or-later
 * @package   WootsUp\BuilderMcp\State
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\State;

interface StateLockInterface
{
    /**
     * Run $callback while holding an exclusive lock for $templateId. The
     * lock MUST be released even if the callback throws.
     *
     * @template T
     * @param callable(): T $callback
     * @return T Whatever the callback returns.
     *
     * @throws \RuntimeException When the lock could not be acquired
     *         within $timeoutMs. Controllers translate this to HTTP 503
     *         (`yootheme_builder_mcp.lock_contention`).
     */
    public function withTemplateLock(string $templateId, callable $callback, int $timeoutMs = 5000): mixed;

    /**
     * Acquire an exclusive lock for $templateId. Returns true on success,
     * false when the timeout expired without acquisition.
     *
     * Empty $templateId MUST return true unconditionally (root/library
     * pointers short-circuit the lock per cookbook §4.5.8).
     */
    public function acquireForTemplate(string $templateId, int $timeoutMs = 5000): bool;

    /**
     * Release the lock for $templateId. MUST be safe to call regardless
     * of whether the caller actually holds the lock (idempotent — no-op
     * when no lock is held).
     */
    public function releaseForTemplate(string $templateId): void;
}
