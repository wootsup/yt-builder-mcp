<?php
/**
 * #__ytb_mcp_transients DAO — TTL-aware key/value store, replaces WP
 * set_transient/get_transient. Explicit TTL column avoids JCache's
 * coarse-granularity issue (cookbook §5.2 table — Joomla gotcha).
 *
 * @package WootsUp\BuilderMcp\Platform\Joomla\Storage
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Storage;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

final class JoomlaTransientStore
{
    public const TABLE = '#__ytb_mcp_transients';

    public function get(string $key): mixed
    {
        if ($key === '') {
            return null;
        }
        try {
            $db = $this->db();
            $now = \time();
            $query = $db->createQuery()
                ->select($db->quoteName('payload'))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('transient_key') . ' = :key')
                ->where($db->quoteName('expires_at') . ' >= :now')
                ->bind(':key', $key, ParameterType::STRING)
                ->bind(':now', $now, ParameterType::INTEGER);
            $raw = $db->setQuery($query)->loadResult();
            if (!\is_string($raw) || $raw === '') {
                return null;
            }
            $decoded = \json_decode($raw, true);
            return $decoded === null ? $raw : $decoded;
        } catch (\Throwable) {
            return null;
        }
    }

    public function set(string $key, mixed $value, int $ttlSeconds): bool
    {
        if ($key === '' || $ttlSeconds <= 0) {
            return false;
        }
        try {
            $db = $this->db();
            $now = \time();
            $expires = $now + $ttlSeconds;
            $payload = \is_string($value) ? $value : (string) \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $serverType = \method_exists($db, 'getServerType') ? $db->getServerType() : 'mysql';
            if ($serverType === 'postgresql') {
                $sql = 'INSERT INTO ' . $db->quoteName(self::TABLE)
                    . ' (' . $db->quoteName('transient_key') . ', ' . $db->quoteName('payload')
                    . ', ' . $db->quoteName('expires_at') . ', ' . $db->quoteName('created_at') . ')'
                    . ' VALUES (:key, :payload, :exp, :ct)'
                    . ' ON CONFLICT (' . $db->quoteName('transient_key') . ') DO UPDATE SET '
                    . $db->quoteName('payload') . ' = EXCLUDED.' . $db->quoteName('payload')
                    . ', ' . $db->quoteName('expires_at') . ' = EXCLUDED.' . $db->quoteName('expires_at');
            } else {
                $sql = 'INSERT INTO ' . $db->quoteName(self::TABLE)
                    . ' (' . $db->quoteName('transient_key') . ', ' . $db->quoteName('payload')
                    . ', ' . $db->quoteName('expires_at') . ', ' . $db->quoteName('created_at') . ')'
                    . ' VALUES (:key, :payload, :exp, :ct)'
                    . ' ON DUPLICATE KEY UPDATE '
                    . $db->quoteName('payload') . ' = VALUES(' . $db->quoteName('payload') . ')'
                    . ', ' . $db->quoteName('expires_at') . ' = VALUES(' . $db->quoteName('expires_at') . ')';
            }
            // Wave-7 deploy-fix: bind on a DatabaseQuery, not the driver
            // (MysqliDriver has no bind() — see JoomlaOptionStore::add()).
            $query = $db->createQuery();
            $query->setQuery($sql)
                ->bind(':key', $key, ParameterType::STRING)
                ->bind(':payload', $payload, ParameterType::STRING)
                ->bind(':exp', $expires, ParameterType::INTEGER)
                ->bind(':ct', $now, ParameterType::INTEGER);
            $db->setQuery($query)->execute();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function delete(string $key): bool
    {
        if ($key === '') {
            return false;
        }
        try {
            $db = $this->db();
            $query = $db->createQuery()
                ->delete($db->quoteName(self::TABLE))
                ->where($db->quoteName('transient_key') . ' = :key')
                ->bind(':key', $key, ParameterType::STRING);
            $db->setQuery($query)->execute();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function cleanExpired(): int
    {
        try {
            $db = $this->db();
            $now = \time();
            $query = $db->createQuery()
                ->delete($db->quoteName(self::TABLE))
                ->where($db->quoteName('expires_at') . ' < :now')
                ->bind(':now', $now, ParameterType::INTEGER);
            $db->setQuery($query)->execute();
            return (int) $db->getAffectedRows();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
