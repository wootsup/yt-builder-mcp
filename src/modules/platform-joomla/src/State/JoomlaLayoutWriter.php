<?php
/**
 * Joomla twin of {@see WootsUp\BuilderMcp\State\LayoutWriter} —
 * canonical persistence path for YT Builder state mutations.
 *
 * Cookbook §4.3 fidelity:
 *   - Wraps every persist in per-template StateLock (cookbook §4.5)
 *   - Runs YT save-transforms via YtBootstrapper-protected
 *     `\YOOtheme\app('builder')->withParams(context:'save')->load($json)`
 *     (cookbook §4.1.7 + S2 spike finding — bypasses YT's
 *     `onAfterRespond` deferred-write so REST returns "saved"
 *     synchronously per ADR-001 Strategy 1)
 *   - Verify-read after persist (cookbook §4.3.6c) — Joomla DB driver
 *     has no object-cache layer to evict, but we still re-read to
 *     confirm the row was actually written
 *   - Revision-bump only on success (no ETag-lie on persist failure)
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\State
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\State;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Platform\Joomla\Exception\YTNotBootstrappedException;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaLayoutStorage;
use WootsUp\BuilderMcp\Platform\Joomla\Util\YtBootstrapper;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\State\LayoutWriterInterface;
use WootsUp\BuilderMcp\State\StateLockInterface;
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class JoomlaLayoutWriter implements LayoutWriterInterface
{
    /**
     * Audit-A1 F-003 (Wave 4 fix-round F3): the constructor accepts the
     * cross-platform {@see StateLockInterface}. In production this is
     * always {@see JoomlaStateLock}; tests substitute their own impl.
     */
    private readonly StateLockInterface $stateLock;

    public function __construct(
        private readonly JoomlaLayoutReader $reader = new JoomlaLayoutReader(),
        private readonly JoomlaLayoutStorage $storage = new JoomlaLayoutStorage(),
        ?StateLockInterface $stateLock = null,
        private readonly JoomlaPagesMetaStore $pagesMeta = new JoomlaPagesMetaStore(),
    ) {
        $this->stateLock = $stateLock ?? new JoomlaStateLock();
    }

    /** Full-template replace under per-template lock. */
    public function writeTemplate(string $templateId, array $tree): void
    {
        if ($templateId === '') {
            throw new \InvalidArgumentException('templateId must not be empty.');
        }
        $this->stateLock->withTemplateLock($templateId, function () use ($templateId, $tree): void {
            $transformed = $this->runSaveTransforms($tree);
            $state       = $this->reader->read();
            if (!isset($state['templates']) || !\is_array($state['templates'])) {
                $state['templates'] = [];
            }
            $state['templates'][$templateId] = $transformed;
            $this->persist($state);
            $this->pagesMeta->touch($templateId);
        });
    }

    /**
     * RFC-6901 set under per-template lock. The template-id is derived
     * from the pointer's first two segments (templates/<id>/…); root-/
     * library-scoped writes short-circuit the lock (cookbook §4.5.8).
     */
    public function writeByPointer(string $pointer, mixed $value): void
    {
        $templateId = $this->extractTemplateId($pointer);
        $this->stateLock->withTemplateLock($templateId, function () use ($pointer, $value): void {
            $state = $this->reader->read();
            JsonPointer::set($state, $pointer, $value);
            $this->persist($state);
        });
    }

    /** RFC-6901 remove under per-template lock. Silent no-op on miss. */
    public function delete(string $pointer): void
    {
        $templateId = $this->extractTemplateId($pointer);
        $this->stateLock->withTemplateLock($templateId, function () use ($pointer): void {
            $state   = $this->reader->read();
            $removed = JsonPointer::remove($state, $pointer);
            if ($removed) {
                $this->persist($state);
            }
        });
    }

    /**
     * Lazy YT-bootstrap + invoke `\YOOtheme\app('builder')->withParams(['context'=>'save'])->load($json)`.
     *
     * Audit-A1 F-002 (Wave 4 fix-round F3): the bootstrap call now lives
     * INSIDE the try/catch so a YT-absent / YT-unbootstrappable
     * environment (REST cold-start before YT loads, unit-test harness,
     * Joomla CLI) falls through to the cookbook §4.10.3 fail-safe path
     * — `$tree` returned unchanged. The {@see LayoutWriterInterface}
     * docblock requires implementations to be tolerant of YT-absence
     * here; previously `YtBootstrapper::ensure()` could throw past the
     * writer, breaking the contract on Joomla.
     *
     * The controller-layer YT-503 translation still applies for write
     * paths that actually need the bootstrap to succeed — but that
     * translation already happens inside
     * {@see \WootsUp\BuilderMcp\Platform\Joomla\Rest\AbstractApiController::dispatch}
     * for genuine bootstrap failures coming from other code paths.
     */
    public function runSaveTransforms(array $tree): array
    {
        try {
            YtBootstrapper::ensure();
            $json = \json_encode($tree, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            /** @psalm-suppress UndefinedFunction Resolved by YtBootstrapper::ensure() */
            $builder = \YOOtheme\app('builder');
            if (\is_object($builder) && \method_exists($builder, 'withParams')) {
                $loaded = $builder->withParams(['context' => 'save'])->load($json);
                if (\is_array($loaded)) {
                    return $loaded;
                }
            }
            return $tree;
        } catch (\Throwable $e) {
            // Save-transform fail-fall-through — matches WP-side
            // YoothemeAdapter behaviour (cookbook §4.10.3 failure #1).
            // Logged via SecurityLogger so audit-trail captures drift.
            // Round-3 A4 P2: the literal 'save_transform_fallback' is
            // now SecurityLogger::EVENT_SAVE_TRANSFORM_FALLBACK.
            SecurityLogger::log(SecurityLogger::EVENT_SAVE_TRANSFORM_FALLBACK, [
                'platform' => 'joomla',
                'reason'   => $e->getMessage(),
            ]);
            return $tree;
        }
    }

    /**
     * The only call-site that hits `JoomlaLayoutStorage::writeState`.
     * Verify-read after write per cookbook §4.3.6c. On mismatch logs
     * EVENT_WRITE_FAILED + throws RuntimeException (controller maps to
     * HTTP 500 with code `yootheme_builder_mcp.write_failed`).
     */
    private function persist(array $state): void
    {
        $ok = $this->storage->writeState($state);
        if (!$ok) {
            SecurityLogger::log(SecurityLogger::EVENT_WRITE_FAILED, [
                'platform' => 'joomla',
                'reason'   => 'storage_write_returned_false',
            ]);
            throw new \RuntimeException('Failed to persist Builder state to #__extensions.custom_data.');
        }

        // Verify-read: encode our expected state, encode what's now in DB,
        // compare byte-for-byte. Catches silent mu-plugin filters,
        // truncating columns, etc. WP-side LayoutWriter.php:255-275.
        $verify = $this->storage->readState();
        $exp    = \json_encode($state,  \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $act    = \json_encode($verify, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($exp !== $act) {
            SecurityLogger::log(SecurityLogger::EVENT_WRITE_FAILED, [
                'platform' => 'joomla',
                'reason'   => 'verify_read_mismatch',
                'exp_len'  => $exp === false ? 0 : \strlen($exp),
                'act_len'  => $act === false ? 0 : \strlen($act),
            ]);
            throw new \RuntimeException('Verify-read mismatch after persisting Builder state.');
        }

        // Revision bump ONLY on successful persist — cookbook §4.3.6d.
        $this->reader->getRevision()->bump();
    }

    /**
     * Pointer→template-id extraction. Pointers shaped
     * `/templates/<id>/…` resolve to <id>; everything else returns the
     * empty string (root/library-scoped writes — short-circuit lock).
     */
    private function extractTemplateId(string $pointer): string
    {
        if ($pointer === '' || $pointer[0] !== '/') {
            return '';
        }
        $segments = JsonPointer::parse($pointer);
        if (\count($segments) < 2 || $segments[0] !== 'templates') {
            return '';
        }
        return (string) $segments[1];
    }
}
