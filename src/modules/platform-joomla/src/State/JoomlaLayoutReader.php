<?php
/**
 * Joomla twin of {@see WootsUp\BuilderMcp\State\LayoutReader} —
 * canonical read-only window into the YT Builder state stored in
 * `#__extensions.custom_data`.
 *
 * Cookbook §4.2 fidelity: read API + ETag derivation port byte-for-byte
 * from WP. ETag format `<sha256>-r<int>` matches the WP-side exactly
 * (F-07 ABA defense — cookbook §4.2.5).
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\State
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\State;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaLayoutStorage;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\State\LayoutReaderInterface;
use WootsUp\BuilderMcp\State\StateRevisionInterface;

final class JoomlaLayoutReader implements LayoutReaderInterface
{
    public function __construct(
        private readonly JoomlaLayoutStorage $storage = new JoomlaLayoutStorage(),
        private readonly JoomlaStateRevision $revision = new JoomlaStateRevision(),
    ) {
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        return $this->storage->readState();
    }

    /** @return array<string, mixed>|null */
    public function readTemplate(string $templateId): ?array
    {
        $state = $this->read();
        if (!isset($state['templates']) || !\is_array($state['templates'])) {
            return null;
        }
        $tpl = $state['templates'][$templateId] ?? null;
        return \is_array($tpl) ? $tpl : null;
    }

    /** @return list<string> */
    public function listTemplateIds(): array
    {
        $state = $this->read();
        if (!isset($state['templates']) || !\is_array($state['templates'])) {
            return [];
        }
        return \array_map('strval', \array_keys($state['templates']));
    }

    /**
     * F-07 ABA-safe ETag (cookbook §4.2.5): `<sha256>-r<int>`.
     * The revision suffix guarantees a distinct ETag for every committed
     * mutation even when the content hash collides (e.g. add → delete).
     */
    public function etag(): string
    {
        $state   = $this->read();
        $encoded = \json_encode($state, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $hash    = $encoded === false ? \hash('sha256', '') : \hash('sha256', $encoded);
        return $hash . '-r' . (string) $this->revision->current();
    }

    /** Convenience read by RFC-6901 JSON-Pointer. */
    public function readByPointer(string $pointer): mixed
    {
        return JsonPointer::get($this->read(), $pointer);
    }

    public function getRevision(): StateRevisionInterface
    {
        return $this->revision;
    }
}
