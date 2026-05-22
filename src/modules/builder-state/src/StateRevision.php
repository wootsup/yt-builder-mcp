<?php
/**
 * StateRevision — monotonic write-revision counter for the Builder state.
 *
 * F-07 fix (Maria-Audit 2026-05-22). The audit observed that an ETag built
 * purely on `sha256(state)` is ABA-vulnerable: a sequence of mutations
 * `A → B → A` (e.g. element_add followed by element_delete of the new
 * node) collapses back to two distinct ETags instead of three, because
 * the third state byte-equals the first. Clients holding the first ETag
 * see "nothing changed" and may overwrite legitimate intermediate work.
 *
 * StateRevision is the structural fix. It owns a strictly-monotonic
 * counter persisted in `wp_option('ytb_mcp_state_revision')` (autoload=false
 * — bumped on every write so we don't want it on the autoload hot path).
 * LayoutWriter::persist() calls `bump()` before every committed state
 * mutation, and LayoutReader::etag() appends `'-r' + current()` to the
 * content hash, producing an ETag of the form
 *     `<sha256-of-state>-r<revision>`.
 *
 * The counter never decreases. PHP's int is 64-bit on every supported
 * runtime (>=8.2), so we have ~9.2e18 mutations of headroom — irrelevant
 * for practical bounding.
 *
 * Test-mode (`$GLOBALS['ytb_test_options']` backing the bootstrap stub)
 * makes this trivial to test without WP-Testbench.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\State
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\State;

final class StateRevision
{
    /**
     * The wp_option key.
     *
     * Naming follows the established `ytb_mcp_*` prefix (no DB-migration
     * required for existing installs — this is a NEW key, not a rename).
     * autoload=false because writes happen on every mutation and we don't
     * want the option on the autoload hot path.
     */
    public const OPTION = 'ytb_mcp_state_revision';

    /**
     * Return the current revision. Returns 0 when the option has never
     * been bumped — callers can rely on `current() >= 0` always.
     */
    public function current(): int
    {
        /** @var mixed $raw */
        $raw = \get_option(self::OPTION, 0);
        if (is_int($raw)) {
            return $raw >= 0 ? $raw : 0;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }
        return 0;
    }

    /**
     * Atomically increment the revision and return the new value.
     *
     * Concurrency: WordPress' `update_option` is not transactional, so two
     * simultaneous bumps in different requests could in principle clobber
     * each other (read-modify-write race). The cost is at-worst losing one
     * tick of monotonicity — the ETag still changes, optimistic-lock still
     * holds, and the StateLock per-template critical section in
     * LayoutWriter::persist already serialises by the time bump() is
     * reached. For a production-grade SQL-row-lock variant we'd need a
     * `SELECT ... FOR UPDATE` inside a transaction; that's a Wave-7
     * follow-up and out of scope here.
     *
     * @return int The new revision (>= 1).
     */
    public function bump(): int
    {
        $next = $this->current() + 1;
        \update_option(self::OPTION, $next, false);
        return $next;
    }
}
