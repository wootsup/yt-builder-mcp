<?php
/**
 * Cross-platform abstraction over the monotonic state-revision counter.
 *
 * Both the WordPress {@see StateRevision} (wp_option-backed) and the
 * Joomla {@see \WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaStateRevision}
 * (#__ytb_mcp_options-backed) satisfy this contract. The counter is the
 * structural defense against the F-07 ABA-ETag attack (Maria-Audit
 * 2026-05-22, cookbook §4.6): an ETag built purely on `sha256(state)` is
 * vulnerable to round-trip mutations that collapse back to the original
 * state byte-shape. Appending a strictly-monotonic revision turns every
 * committed mutation into a distinct ETag regardless of content.
 *
 * Wave 4 prep (Joomla port) extraction — the interface preserves the
 * `current() / bump()` API originally defined by StateRevision so
 * LayoutReader::etag() and LayoutWriter::persist() work unchanged on
 * either platform.
 *
 * Cookbook §4.6 fidelity is preserved on both implementations.
 *
 * @license   GPL-2.0-or-later
 * @package   WootsUp\BuilderMcp\State
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\State;

interface StateRevisionInterface
{
    /**
     * Return the current revision. MUST return `0` when the counter has
     * never been bumped — callers can rely on `current() >= 0` always.
     */
    public function current(): int;

    /**
     * Atomically increment the revision and return the new value.
     *
     * Implementations SHOULD use a race-safe read-modify-write primitive
     * where available (WP: update_option inside StateLock; Joomla:
     * OptionStore::set under per-template StateLock). At-worst losing
     * one tick of monotonicity is acceptable — the ETag still changes,
     * optimistic-lock still holds.
     *
     * @return int The new revision (>= 1).
     */
    public function bump(): int;
}
