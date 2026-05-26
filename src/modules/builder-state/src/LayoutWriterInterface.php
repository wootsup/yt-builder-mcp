<?php
/**
 * Cross-platform abstraction over the canonical write path into the
 * YT Builder state.
 *
 * Both the WordPress {@see LayoutWriter} (wp_option('yootheme')-backed)
 * and the Joomla {@see \WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutWriter}
 * (#__extensions.custom_data-backed) satisfy this contract. Domain
 * consumers (REST controllers, source-binding writers) type-hint
 * against the interface so the same logic runs on both platforms
 * unchanged.
 *
 * Cookbook §4.3 fidelity is preserved on both implementations:
 *   - writeTemplate() runs save-transforms via the platform-specific
 *     YT-bootstrap path then persists under per-template StateLock
 *   - writeByPointer() / delete() wrap the read-modify-write cycle in
 *     the per-template StateLock derived from the pointer's first two
 *     segments (root/library-scoped pointers short-circuit the lock —
 *     those writes are @internal-gated and not exposed to public APIs)
 *   - runSaveTransforms() is the single funnel through which every
 *     outside-Builder-JS mutation passes so YT's load-time
 *     normalisation runs (cookbook §4.10.3 failure #1)
 *   - persist() (private) verifies the write succeeded and bumps
 *     StateRevisionInterface only on success (no ETag-lie on failure)
 *
 * Wave 4 prep (Joomla port) extraction — the interface preserves the
 * public method signatures originally defined by LayoutWriter so that
 * existing call-sites continue to work without behavioural change.
 *
 * @license   GPL-2.0-or-later
 * @package   WootsUp\BuilderMcp\State
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\State;

interface LayoutWriterInterface
{
    /**
     * Replace `templates.<id>` with $tree and persist. Implementations
     * MUST first pass $tree through {@see runSaveTransforms()} so YT's
     * load-time normalisation runs (cookbook §4.3.1), and MUST wrap the
     * read+persist critical section in the per-template StateLock so
     * two concurrent writes to the same template serialise.
     *
     * @param array<string, mixed> $tree
     *
     * @throws \RuntimeException When persistence cannot be verified
     *         (verify-read mismatch). Controllers translate this into
     *         a 500 with code `yootheme_builder_mcp.write_failed`.
     */
    public function writeTemplate(string $templateId, array $tree): void;

    /**
     * Set the value at $pointer (RFC-6901) in the full state tree and
     * persist. Implementations SHOULD derive the template-id from the
     * pointer's first two segments (`/templates/<id>/…`) and run the
     * read+persist critical section under that per-template StateLock.
     * Root- and library-scoped pointers short-circuit the lock.
     *
     * @internal Call from controllers only after asserting that the
     *           pointer is scoped to a single template (see
     *           {@see JsonPointer::isWithinPrefix}). Free-form pointers
     *           bypass per-template ownership checks and must be wrapped.
     *
     * @param mixed $value
     *
     * @throws \RuntimeException On verify-read mismatch (see writeTemplate).
     */
    public function writeByPointer(string $pointer, mixed $value): void;

    /**
     * Remove the value at $pointer (RFC-6901) from the full state tree
     * and persist. MUST be a silent no-op (no throw, no persist) when
     * the pointer does not resolve.
     *
     * Per-template StateLock wrapping applies as in writeByPointer().
     *
     * @throws \RuntimeException On verify-read mismatch when a real
     *         removal triggered a persist.
     */
    public function delete(string $pointer): void;

    /**
     * Pass $tree through YOOtheme's `Builder::load(context:save)` so the
     * normalising save-transforms run, and return the transformed tree.
     *
     * Implementations MUST be tolerant of an absent / un-bootstrapped
     * YOOtheme runtime (unit-test bootstrap, REST/CLI cold-start): on
     * any failure return $tree unchanged — cookbook §4.10.3 fail-fall-
     * through pattern. Failures SHOULD be logged via SecurityLogger so
     * the audit trail captures drift.
     *
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    public function runSaveTransforms(array $tree): array;
}
