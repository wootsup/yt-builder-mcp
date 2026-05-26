<?php
/**
 * Per-article read-only window into the YT-Builder state stored in
 * `#__content.fulltext`. L2 twin of
 * {@see \WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutReader}
 * — same ETag contract and JsonPointer semantics, scoped to a single
 * article by id baked in at construction.
 *
 * Cookbook §4.13.5 (L2 article-storage cross-reference) + §4.2.5
 * (F-07 ABA defense ETag format `<sha256>-r<int>`).
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\L2
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\L2;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\State\StateRevisionInterface;

final class JoomlaArticleLayoutReader
{
    private readonly JoomlaArticleStateRevision $revision;

    public function __construct(
        private readonly JoomlaArticleLayoutStorage $storage,
        private readonly int $articleId,
        ?JoomlaArticleStateRevision $revision = null,
    ) {
        $this->revision = $revision ?? new JoomlaArticleStateRevision(new JoomlaOptionStore(), $articleId);
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        return $this->storage->readArticle($this->articleId);
    }

    /**
     * F-07 ABA-safe ETag (cookbook §4.2.5): `<sha256>-r<int>`.
     * The revision suffix guarantees a distinct ETag for every committed
     * mutation even when the content hash collides (add → delete round
     * trips).
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

    public function articleId(): int
    {
        return $this->articleId;
    }
}
