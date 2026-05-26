<?php
/**
 * Per-template advisory lock using INSERT IGNORE on `#__ytb_mcp_lock`.
 *
 * The Joomla-portable equivalent of WP `add_option`-CAS (cookbook
 * §4.5.1). INSERT on the PRIMARY KEY column is atomic — either the
 * row is created or it isn't; no read-modify-write race.
 *
 * TTL = 5s. Lock value stores `pid:microtime(float)` so a contender
 * can reclaim an orphaned lock (e.g. PHP fatal mid-write). Polling
 * interval = 50ms (same as WP). Default acquire-timeout = 5000ms.
 *
 * Alternative pattern explored in Spike S2 (file-lock via `flock`)
 * is reserved as a future option — the table-based approach has
 * cleaner observability (lock rows visible in admin DB-grep) and
 * doesn't require a writable JPATH_CACHE.
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\State
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\State;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use WootsUp\BuilderMcp\State\StateLockInterface;
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class JoomlaStateLock implements StateLockInterface
{
    public const LOCK_TTL_SECONDS   = 5;
    public const DEFAULT_TIMEOUT_MS = 5000;
    public const POLL_INTERVAL_US   = 50000;
    public const TABLE              = '#__ytb_mcp_lock';

    /**
     * Canonical pattern from cookbook §4.5.4. Always pair with
     * try/finally — the wrapper does so internally.
     *
     * @param  callable():mixed $callback
     * @return mixed Whatever the callback returns.
     */
    public function withTemplateLock(string $templateId, callable $callback, int $timeoutMs = self::DEFAULT_TIMEOUT_MS): mixed
    {
        $acquired = $this->acquireForTemplate($templateId, $timeoutMs);
        if (!$acquired) {
            throw new \RuntimeException(\sprintf(
                'Could not acquire lock for template "%s" within %dms.',
                $templateId,
                $timeoutMs
            ));
        }
        try {
            return $callback();
        } finally {
            $this->releaseForTemplate($templateId);
        }
    }

    public function acquireForTemplate(string $templateId, int $timeoutMs = self::DEFAULT_TIMEOUT_MS): bool
    {
        if ($templateId === '') {
            return true; // No-op for root/library writes (cookbook §4.5.8)
        }
        $key      = self::lockKey($templateId);
        $deadline = \microtime(true) + ($timeoutMs / 1000);
        do {
            if ($this->tryAcquire($key)) {
                return true;
            }
            $this->reclaimIfStale($key);
            \usleep(self::POLL_INTERVAL_US);
        } while (\microtime(true) < $deadline);

        SecurityLogger::log(SecurityLogger::EVENT_LOCK_TIMEOUT, [
            'template_id' => $templateId,
            'timeout_ms'  => $timeoutMs,
        ]);
        return false;
    }

    public function releaseForTemplate(string $templateId): void
    {
        if ($templateId === '') {
            return;
        }
        $this->deleteLock(self::lockKey($templateId));
    }

    public static function lockKey(string $templateId): string
    {
        return 'tpl_' . \md5($templateId);
    }

    /** Atomic-create-if-absent via INSERT IGNORE. */
    private function tryAcquire(string $key): bool
    {
        try {
            $db          = $this->db();
            $value       = \sprintf('%d:%s', \getmypid(), (string) \microtime(true));
            $now         = \time();
            $serverType  = \method_exists($db, 'getServerType') ? $db->getServerType() : 'mysql';
            if ($serverType === 'postgresql') {
                $sql = 'INSERT INTO ' . $db->quoteName(self::TABLE)
                    . ' (' . $db->quoteName('lock_key') . ', ' . $db->quoteName('lock_value')
                    . ', ' . $db->quoteName('acquired_at') . ')'
                    . ' VALUES (:key, :value, :at)'
                    . ' ON CONFLICT (' . $db->quoteName('lock_key') . ') DO NOTHING';
            } else {
                $sql = 'INSERT IGNORE INTO ' . $db->quoteName(self::TABLE)
                    . ' (' . $db->quoteName('lock_key') . ', ' . $db->quoteName('lock_value')
                    . ', ' . $db->quoteName('acquired_at') . ')'
                    . ' VALUES (:key, :value, :at)';
            }
            // Wave-7 deploy-fix: bind on a DatabaseQuery, not the driver
            // (MysqliDriver has no bind() — see JoomlaOptionStore::add()).
            $query = $db->createQuery();
            $query->setQuery($sql)
                ->bind(':key',   $key,   ParameterType::STRING)
                ->bind(':value', $value, ParameterType::STRING)
                ->bind(':at',    $now,   ParameterType::INTEGER);
            $db->setQuery($query)->execute();
            return (int) $db->getAffectedRows() === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function reclaimIfStale(string $key): void
    {
        try {
            $db    = $this->db();
            $query = $db->createQuery()
                ->select($db->quoteName(['lock_value', 'acquired_at']))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('lock_key') . ' = :key')
                ->bind(':key', $key, ParameterType::STRING);
            $row = $db->setQuery($query)->loadAssoc();
            if (!\is_array($row) || !isset($row['lock_value'])) {
                return;
            }
            $parts = \explode(':', (string) $row['lock_value'], 2);
            if (\count($parts) !== 2) {
                return;
            }
            $age = \microtime(true) - (float) $parts[1];
            if ($age > self::LOCK_TTL_SECONDS) {
                $this->deleteLock($key);
            }
        } catch (\Throwable $e) {
            // Best-effort reclaim — fall through but log for forensics.
            // Round-3 A4 P2: previously a silent catch; now routed via
            // SecurityLogger::EVENT_LOCK_RECLAIM_FAILED so admins can
            // see lock-table driver issues without blowing up writes.
            SecurityLogger::log(SecurityLogger::EVENT_LOCK_RECLAIM_FAILED, [
                'platform' => 'joomla',
                'op'       => 'reclaim_if_stale',
                'lock_key' => $key,
                'reason'   => $e->getMessage(),
            ]);
        }
    }

    private function deleteLock(string $key): void
    {
        try {
            $db    = $this->db();
            $query = $db->createQuery()
                ->delete($db->quoteName(self::TABLE))
                ->where($db->quoteName('lock_key') . ' = :key')
                ->bind(':key', $key, ParameterType::STRING);
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            // Best-effort. Round-3 A4 P2: log the failure so an admin
            // sees lock-rows that never released because the driver
            // refused the DELETE.
            SecurityLogger::log(SecurityLogger::EVENT_LOCK_RECLAIM_FAILED, [
                'platform' => 'joomla',
                'op'       => 'delete_lock',
                'lock_key' => $key,
                'reason'   => $e->getMessage(),
            ]);
        }
    }

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
