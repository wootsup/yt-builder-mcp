<?php
/**
 * Per-article monotonic state-revision counter — L2 twin of
 * {@see \WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaStateRevision}.
 *
 * Each article gets its own monotonic counter so the F-07 ABA-ETag
 * defense (Maria-Audit 2026-05-22, cookbook §4.6) applies per-article
 * without coupling article revisions to the global L1 template
 * revision. Keyed as `state_revision_article_<id>` in
 * {@see JoomlaOptionStore}.
 *
 * Cookbook §4.13.5 cross-reference (L2 article-storage).
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\L2
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\L2;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;
use WootsUp\BuilderMcp\State\StateRevisionInterface;

final class JoomlaArticleStateRevision implements StateRevisionInterface
{
    public const OPTION_KEY_PREFIX = 'state_revision_article_';

    private readonly string $optionKey;

    public function __construct(
        private readonly JoomlaOptionStore $store,
        private readonly int $articleId,
    ) {
        $this->optionKey = self::OPTION_KEY_PREFIX . $articleId;
    }

    public function current(): int
    {
        $raw = $this->store->get($this->optionKey, '0');
        if (\is_string($raw) && \ctype_digit($raw)) {
            return (int) $raw;
        }
        return 0;
    }

    public function bump(): int
    {
        $next = $this->current() + 1;
        $this->store->set($this->optionKey, (string) $next);
        return $next;
    }

    public function articleId(): int
    {
        return $this->articleId;
    }
}
