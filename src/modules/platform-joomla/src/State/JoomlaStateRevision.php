<?php
/**
 * Monotonic per-write revision counter (Maria-Audit F-07 ABA defense).
 *
 * Mirrors WP-side `\WootsUp\BuilderMcp\State\StateRevision` (cookbook
 * §4.6). Stored in `#__ytb_mcp_options(option_key='state_revision')`.
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\State
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\State;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;
use WootsUp\BuilderMcp\State\StateRevisionInterface;

final class JoomlaStateRevision implements StateRevisionInterface
{
    public const OPTION_KEY = 'state_revision';

    public function __construct(private readonly JoomlaOptionStore $store = new JoomlaOptionStore())
    {
    }

    public function current(): int
    {
        $raw = $this->store->get(self::OPTION_KEY, '0');
        if (\is_string($raw) && \ctype_digit($raw)) {
            return (int) $raw;
        }
        return 0;
    }

    public function bump(): int
    {
        $next = $this->current() + 1;
        $this->store->set(self::OPTION_KEY, (string) $next);
        return $next;
    }
}
