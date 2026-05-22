<?php
/**
 * LayoutWriter — canonical write path into wp_option('yootheme').
 *
 * Wave 3 Task 3.1. Provides four methods for mutating the Builder state:
 *
 *  - writeTemplate($id, $tree)  — replace a single template tree under
 *                                 `templates.<id>` and persist via update_option.
 *  - writeByPointer($ptr, $val) — RFC-6901-addressed set into the full state.
 *  - delete($ptr)               — RFC-6901-addressed remove from the full state.
 *  - runSaveTransforms($tree)   — run YOOtheme's Builder::load(context:save)
 *                                 over a template tree (no-op when YT is absent,
 *                                 e.g. unit-test bootstrap).
 *
 * Spike-3 outcome (2026-05-21): all writes that originate outside the
 * Builder JS UI MUST pass through `Builder::load(context:save)` so that
 * YT's load-time transforms run (normalisation, breakpoint-aware prop
 * mirroring, theme-specific element migrations). Skipping save-transforms
 * produces silent template corruption on the next render — verified during
 * dev5 reproduction. Every public mutator on this class therefore funnels
 * through {@see persist} which calls runSaveTransforms before update_option.
 *
 * ETag-bumping is implicit: LayoutReader::etag() hashes the current state,
 * so any persisted change produces a new ETag on the next read.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\State
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\State;

use WootsUp\BuilderMcp\Util\SecurityLogger;
use WootsUp\BuilderMcp\Yootheme\YoothemeAdapter;

final class LayoutWriter
{
    private readonly YoothemeAdapter $yootheme;
    private readonly StateLock $stateLock;

    public function __construct(
        private readonly LayoutReader $reader,
        ?YoothemeAdapter $yootheme = null,
        ?StateLock $stateLock = null,
    ) {
        $this->yootheme = $yootheme ?? new YoothemeAdapter();
        $this->stateLock = $stateLock ?? new StateLock();
    }

    /**
     * Replace `templates.<id>` with $tree and persist. Calls
     * runSaveTransforms($tree) first so YT's load-time normalisation runs.
     *
     * Wave-6 R2.5 Fix: the read+write critical section is wrapped in the
     * per-template StateLock so two concurrent writes to the same
     * template-id serialise instead of racing each other.
     *
     * @param array<string, mixed> $tree
     */
    public function writeTemplate(string $templateId, array $tree): void
    {
        $this->stateLock->withTemplateLock($templateId, function () use ($templateId, $tree): void {
            $transformed = $this->runSaveTransforms($tree);
            $state = $this->reader->read();
            if (!isset($state['templates']) || !is_array($state['templates'])) {
                $state['templates'] = [];
            }
            /** @var array<string, mixed> $templates */
            $templates = $state['templates'];
            $templates[$templateId] = $transformed;
            $state['templates'] = $templates;
            $this->persist($state);
            // F-08 fix (Maria-Audit 2026-05-22): stamp the per-template
            // tracking option so pages_list.modified_at is non-null even
            // when the YT-side blob doesn't carry its own `modified` field.
            (new \WootsUp\BuilderMcp\Pages\PagesMetaStore())->touch($templateId);
        });
    }

    /**
     * Set the value at $pointer (RFC-6901) in the full state tree and
     * persist. The single-template covering this pointer (if any) is run
     * through save-transforms before persistence.
     *
     * Wave-6 R2.5 Fix: when the pointer addresses a node under
     * `/templates/<id>/...`, the read+write critical section runs under
     * the per-template StateLock. Root/library pointers (templateId='')
     * short-circuit the lock — those writes are @internal-gated and not
     * reachable via the public controller surface.
     *
     * @internal Wave-6 — call from controllers only after asserting that the
     *           pointer is scoped to a single template (see
     *           {@see JsonPointer::isWithinPrefix}). Free-form pointers
     *           bypass per-template ownership checks and must be wrapped.
     *
     * @param mixed $value
     */
    public function writeByPointer(string $pointer, $value): void
    {
        $templateId = self::extractTemplateId($pointer);
        $this->stateLock->withTemplateLock($templateId, function () use ($pointer, $value): void {
            $state = $this->reader->read();
            JsonPointer::set($state, $pointer, $value);
            $state = $this->normaliseTemplatesViaTransforms($state, $pointer);
            $this->persist($state);
        });
    }

    /**
     * Remove the value at $pointer (RFC-6901) from the full state tree and
     * persist. No-op (returns silently) when the pointer does not resolve.
     *
     * Wave-6 R2.5 Fix: per-template-locked, same semantics as writeByPointer.
     */
    public function delete(string $pointer): void
    {
        $templateId = self::extractTemplateId($pointer);
        $this->stateLock->withTemplateLock($templateId, function () use ($pointer): void {
            $state = $this->reader->read();
            $removed = JsonPointer::remove($state, $pointer);
            if (!$removed) {
                return;
            }
            $state = $this->normaliseTemplatesViaTransforms($state, $pointer);
            $this->persist($state);
        });
    }

    /**
     * Extract the template-id from a JSON-Pointer if it lives under
     * `/templates/<id>/...`. Returns the empty string when the pointer is
     * root-scoped or library-scoped — StateLock then short-circuits to
     * a no-op (see StateLock::acquireForTemplate).
     */
    private static function extractTemplateId(string $pointer): string
    {
        if ($pointer === '') {
            return '';
        }
        $segments = JsonPointer::parse($pointer);
        if (count($segments) < 2) {
            return '';
        }
        if ($segments[0] !== 'templates') {
            return '';
        }
        return (string) $segments[1];
    }

    /**
     * Pass $tree through YOOtheme's `Builder::load(context:save)` so the
     * normalising save-transforms run. Returns the transformed tree.
     *
     * When YOOtheme is not loaded (unit-test bootstrap), returns $tree
     * unchanged — this is the documented fallback behaviour.
     *
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    public function runSaveTransforms(array $tree): array
    {
        // Wave-6 R2.7: every YOOtheme symbol access now funnels through
        // YoothemeAdapter — keeps this method 4 lines instead of 40 and
        // makes the YT-coupling surface live in one place.
        $loaded = $this->yootheme->loadWithContext($tree, 'save');
        return $loaded ?? $tree;
    }

    /**
     * If $pointer addresses a node inside `/templates/<id>/...`, re-run
     * save-transforms on that template tree. Otherwise (e.g. root or
     * library-only mutation) return the state unchanged.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function normaliseTemplatesViaTransforms(array $state, string $pointer): array
    {
        $segments = JsonPointer::parse($pointer);
        if (count($segments) < 2) {
            return $state;
        }
        if ($segments[0] !== 'templates') {
            return $state;
        }
        $templateId = (string) $segments[1];
        if (!isset($state['templates']) || !is_array($state['templates'])) {
            return $state;
        }
        /** @var array<string, mixed> $templates */
        $templates = $state['templates'];
        if (!isset($templates[$templateId]) || !is_array($templates[$templateId])) {
            return $state;
        }
        /** @var array<string, mixed> $tpl */
        $tpl = $templates[$templateId];
        $templates[$templateId] = $this->runSaveTransforms($tpl);
        $state['templates'] = $templates;
        return $state;
    }

    /**
     * Persist the state. Wave-6 Fix 7 + Fix 26: WordPress' update_option()
     * returns false either on a no-op (value unchanged) OR on a real
     * persistence failure — they are not distinguishable from the return
     * value alone. To avoid spurious 500s on no-op writes (and to surface
     * real failures), we re-read after the call and assert the option
     * holds the expected value.
     *
     * @param array<string, mixed> $state
     *
     * @throws \RuntimeException When the option does not reflect the write
     *         after update_option returns. Controllers translate this into
     *         a 500 with code `yootheme_builder_mcp.write_failed`.
     */
    private function persist(array $state): void
    {
        // Pass `null` for autoload to preserve the existing setting.
        \update_option(LayoutReader::OPTION, $state, null);

        /** @var mixed $verify */
        $verify = \get_option(LayoutReader::OPTION, null);
        // Maria-Story E2E live-bug 2026-05-22: strict `!==` was too brittle —
        // failed on benign PHP roundtrip differences (e.g. nested empty arrays
        // surviving as `[]` here but coming back as `[]` from the get_option
        // cache layer that uses a different array-vs-stdClass internal repr).
        // Use serialise-comparison: identical bytes after maybe_serialize means
        // the option holds an equivalent value. Real write failures still
        // surface as different serialised forms.
        $expected = \serialize($state);
        $actual = \serialize($verify);
        if ($expected !== $actual) {
            // R2.9 security-event breadcrumb so write-failures don't surface
            // only as opaque 500s without a forensics trail.
            SecurityLogger::log(SecurityLogger::EVENT_WRITE_FAILED, [
                'option' => LayoutReader::OPTION,
                'reason' => 'verify_read_did_not_match',
                'expected_bytes' => strlen($expected),
                'actual_bytes' => strlen($actual),
            ]);
            throw new \RuntimeException(
                'LayoutWriter::persist failed — option value did not reflect write.',
            );
        }

        // F-07 fix (Maria-Audit 2026-05-22): bump the monotonic revision
        // AFTER the state-write has been verified. The ETag computed by
        // LayoutReader::etag() is `sha256(state) + '-r' + revision`, so
        // every committed mutation surfaces as a strictly new ETag — even
        // when the post-mutation state happens to byte-equal an earlier
        // state (ABA scenarios like `add then delete`). The bump only
        // happens on successful persistence; if persist() throws above,
        // the revision stays put.
        $this->reader->getRevision()->bump();
    }
}
