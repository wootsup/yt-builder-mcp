<?php
/**
 * TransientCleanupTask — periodic GC for `#__ytb_mcp_transients`.
 *
 * Joomla 5/6 `com_scheduler` task plugin trait that periodically deletes
 * expired rows from the YT Builder MCP transients table. The table
 * holds short-lived data with explicit `expires_at` columns:
 *
 *   - RateLimiter buckets (60s window per kid, per pickup-IP-hash)
 *   - PickupChannel one-shot codes (300s TTL, IP-bound, single-use)
 *
 * The reader/writer treat expired rows as absent and may delete
 * opportunistically, but a non-trivial fraction of the table never
 * gets touched again after expiry — long-tail rows accumulate. This
 * task issues a single `DELETE … WHERE expires_at < UNIX_TIMESTAMP()`
 * per execution.
 *
 * Audit-A4 P1-2 (Wave 4 fix-round F3) — closes the long-tail growth
 * defense gap. Schedule the task to run hourly in System → Scheduled
 * Tasks (admin can pick any cron expression; daily is fine for low-
 * traffic sites).
 *
 * Subscribed events:
 *   - onTaskOptionsList → register the routine ID so admins can pick
 *     it from the "New Scheduled Task" dropdown.
 *   - onExecuteTask     → executed by `com_scheduler` when the cron
 *     condition matches.
 *
 * The system plugin's main subscriber ({@see \WootsUp\Plugin\System\Ytbmcp\Extension\Ytbmcp})
 * forwards these events to this class so the cookbook-level "one
 * extension, one event-subscriber" invariant is preserved.
 *
 * @package    WootsUp\Plugin\System\Ytbmcp\Task
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Plugin\System\Ytbmcp\Task;

defined('_JEXEC') or die;

use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaTransientStore;

final class TransientCleanupTask
{
    /** Routine identifier exposed in `com_scheduler`'s "New Task" dropdown. */
    public const ROUTINE_ID = 'plg_system_ytbmcp.transient_cleanup';

    /**
     * Append our routine to the list of available scheduled-task types.
     *
     * Joomla 5/6 dispatches an event whose `addOption(string $id, string $title)`
     * method registers a new entry. Some installs use the older
     * `addItem(array)` signature — we feature-detect and use whichever
     * the running Joomla exposes.
     *
     * @param object $event Joomla `\Joomla\Component\Scheduler\Administrator\Event\TaskOptionListEvent`
     *                      (typed as object so this class loads on installs
     *                      where com_scheduler is absent — listener early-exits).
     */
    public function onTaskOptionsList(object $event): void
    {
        $title = 'YT Builder MCP — Transient Cleanup';
        if (\method_exists($event, 'addOption')) {
            $event->addOption(self::ROUTINE_ID, $title);
            return;
        }
        if (\method_exists($event, 'addItem')) {
            $event->addItem([
                'id'    => self::ROUTINE_ID,
                'title' => $title,
                'desc'  => 'PLG_SYSTEM_YTBMCP_TASK_TRANSIENT_CLEANUP_DESC',
            ]);
        }
    }

    /**
     * Run the cleanup when this routine fires. Returns
     * {@see Status::OK} on success (whatever the deletion count was —
     * zero deletions is a legitimate outcome) or {@see Status::KNOCKOUT}
     * on storage failure.
     *
     * The handler is a no-op for any routine ID other than
     * {@see ROUTINE_ID} — `com_scheduler` dispatches `onExecuteTask` to
     * every subscribed listener; each must self-gate by routine.
     */
    public function onExecuteTask(ExecuteTaskEvent $event): int
    {
        if ($event->getRoutineId() !== self::ROUTINE_ID) {
            return Status::NO_RUN;
        }

        try {
            $store   = new JoomlaTransientStore();
            $deleted = $store->cleanExpired();
            if (\method_exists($event, 'setOutput')) {
                $event->setOutput(\sprintf(
                    '[yt-builder-mcp] transient cleanup ok — removed %d expired row(s).',
                    $deleted
                ));
            }
            return Status::OK;
        } catch (\Throwable $e) {
            if (\method_exists($event, 'setOutput')) {
                $event->setOutput('[yt-builder-mcp] transient cleanup failed: ' . $e->getMessage());
            }
            return Status::KNOCKOUT;
        }
    }
}
