<?php
/**
 * Cross-platform abstraction over the read-only window into the YT
 * Builder state.
 *
 * Both the WordPress {@see LayoutReader} (wp_option('yootheme')-backed)
 * and the Joomla {@see \WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutReader}
 * (#__extensions.custom_data-backed) satisfy this contract. Domain
 * consumers ({@see \WootsUp\BuilderMcp\Pages\PageQuery},
 * {@see \WootsUp\BuilderMcp\Elements\ElementOps}, REST controllers)
 * type-hint against the interface so the same logic runs on both
 * platforms unchanged.
 *
 * Cookbook §4.2 fidelity is preserved on both implementations:
 *   - read() is fail-safe (empty array on miss / corrupt blob)
 *   - readTemplate() returns null on miss (never throws)
 *   - etag() format is `<sha256>-r<int>` (F-07 ABA defense, §4.2.5)
 *   - readByPointer() delegates to {@see JsonPointer} unchanged
 *   - getRevision() exposes the per-platform StateRevisionInterface so
 *     LayoutWriter implementations can bump inside their critical section
 *
 * Wave 4 prep (Joomla port) extraction — the interface preserves the
 * public method signatures originally defined by LayoutReader so that
 * existing call-sites continue to work without behavioural change.
 *
 * @license   GPL-2.0-or-later
 * @package   WootsUp\BuilderMcp\State
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\State;

interface LayoutReaderInterface
{
    /**
     * Return the entire builder state — `library` + `templates` top-level
     * keys — as an associative array.
     *
     * Implementations MUST be fail-safe: a missing / corrupt blob
     * returns the empty array rather than throwing, so a third-party
     * mutation of the underlying store cannot blow up read paths.
     *
     * @return array<string, mixed>
     */
    public function read(): array;

    /**
     * Return the JSON tree for a single template (top-level entry under
     * `templates.<id>` in the state), or `null` if the template is
     * unknown. Implementations MUST never throw on a missing template.
     *
     * @return array<string, mixed>|null
     */
    public function readTemplate(string $templateId): ?array;

    /**
     * Return the list of template-IDs (top-level keys of `templates`).
     * Returns the empty list when no templates exist.
     *
     * @return list<string>
     */
    public function listTemplateIds(): array;

    /**
     * Compute a deterministic ETag for the current state.
     *
     * Format: `<sha256-of-state>-r<revision>` where the revision component
     * is the value of {@see StateRevisionInterface::current()}. The
     * revision suffix is the F-07 ABA defense (Maria-Audit 2026-05-22,
     * cookbook §4.2.5): a pure content hash collapses on `add → delete`
     * round-trips and would let clients lose intermediate progress on
     * optimistic-lock writes; appending the monotonic revision makes
     * every committed mutation surface as a distinct ETag.
     */
    public function etag(): string;

    /**
     * Resolve an RFC-6901 JSON-Pointer against the full state snapshot.
     * Implementations SHOULD delegate to {@see JsonPointer::get} for
     * canonical semantics (returns `null` for missing paths; throws
     * `InvalidArgumentException` on structurally-invalid pointers).
     */
    public function readByPointer(string $pointer): mixed;

    /**
     * Return the underlying revision tracker. Exposed so writer
     * implementations (LayoutWriterInterface::writeTemplate / writeByPointer
     * / delete) can `bump()` it inside their critical section after a
     * successful persist.
     */
    public function getRevision(): StateRevisionInterface;
}
