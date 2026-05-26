<?php
/**
 * #__ytb_mcp_options DAO — the WP `wp_option` equivalent for the Joomla
 * platform. Provides race-safe atomic create-if-absent via INSERT IGNORE
 * (the Joomla analogue to WP's `add_option`).
 *
 * Cookbook §4.13.2 — `add_option` on WP is the only race-safe atomic
 * primitive; on Joomla we use INSERT IGNORE on a dedicated table with
 * PRIMARY KEY on (option_key). Same atomicity guarantee.
 *
 * Schema:
 *   option_key   VARCHAR(191) PRIMARY KEY
 *   option_value LONGTEXT     (UTF-8mb4, JSON when callers store maps)
 *   autoload     TINYINT(1)   (0/1 — currently advisory; reserved for
 *                              cookie-cached load optimisations in v2)
 *   created_at   BIGINT       (Unix timestamp)
 *   updated_at   BIGINT       (Unix timestamp)
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Storage
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Storage;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

final class JoomlaOptionStore
{
    /** @var string Joomla table name (with `#__` prefix-token). */
    public const TABLE = '#__ytb_mcp_options';

    /**
     * Read an option's value. Returns the default when absent.
     *
     * @param mixed $default
     * @return mixed The decoded value (caller decides serialisation).
     */
    public function get(string $key, $default = null)
    {
        if ($key === '') {
            return $default;
        }
        try {
            $db    = $this->db();
            $query = $db->createQuery()
                ->select($db->quoteName('option_value'))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('option_key') . ' = :key')
                ->bind(':key', $key, ParameterType::STRING);
            $value = $db->setQuery($query)->loadResult();
            return $value === null ? $default : $value;
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * Atomic create-if-absent. Returns true when the row was created;
     * false when a row with the same key already exists. The race-safe
     * counterpart to WP `add_option`.
     */
    public function add(string $key, string $value, bool $autoload = false): bool
    {
        if ($key === '') {
            return false;
        }
        try {
            $db  = $this->db();
            $now = \time();
            $al  = $autoload ? 1 : 0;

            // INSERT IGNORE = Joomla-portable atomic create-if-absent.
            // MySQL: literal INSERT IGNORE; PostgreSQL: ON CONFLICT DO NOTHING.
            $serverType = \method_exists($db, 'getServerType') ? $db->getServerType() : 'mysql';
            if ($serverType === 'postgresql') {
                $sql = 'INSERT INTO ' . $db->quoteName(self::TABLE)
                    . ' (' . $db->quoteName('option_key') . ', ' . $db->quoteName('option_value')
                    . ', ' . $db->quoteName('autoload') . ', ' . $db->quoteName('created_at')
                    . ', ' . $db->quoteName('updated_at') . ')'
                    . ' VALUES (:key, :value, :autoload, :ct, :ut)'
                    . ' ON CONFLICT (' . $db->quoteName('option_key') . ') DO NOTHING';
            } else {
                $sql = 'INSERT IGNORE INTO ' . $db->quoteName(self::TABLE)
                    . ' (' . $db->quoteName('option_key') . ', ' . $db->quoteName('option_value')
                    . ', ' . $db->quoteName('autoload') . ', ' . $db->quoteName('created_at')
                    . ', ' . $db->quoteName('updated_at') . ')'
                    . ' VALUES (:key, :value, :autoload, :ct, :ut)';
            }
            // Wave-7 deploy-fix: bind on a DatabaseQuery, NOT on the driver.
            // `$db->setQuery($rawString)` returns the DatabaseDriver, which
            // has NO bind() method (bind lives on DatabaseQuery) — calling
            // ->bind() on it fataled with "Call to undefined method
            // MysqliDriver::bind()", swallowed by the catch, so EVERY option
            // write silently failed (no signing_secret, no keys → all
            // Bearer auth broke). Build the raw SQL on a query object first.
            $query = $db->createQuery();
            $query->setQuery($sql)
                ->bind(':key',      $key,   ParameterType::STRING)
                ->bind(':value',    $value, ParameterType::STRING)
                ->bind(':autoload', $al,    ParameterType::INTEGER)
                ->bind(':ct',       $now,   ParameterType::INTEGER)
                ->bind(':ut',       $now,   ParameterType::INTEGER);
            $db->setQuery($query)->execute();
            $affected = (int) $db->getAffectedRows();
            return $affected === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Insert-or-update. Returns true on persistence success, false on
     * driver error. Unlike {@see add}, this is NOT atomic — callers
     * requiring create-if-absent semantics MUST use add().
     */
    public function set(string $key, string $value, ?bool $autoload = null): bool
    {
        if ($key === '') {
            return false;
        }
        try {
            $db  = $this->db();
            $now = \time();
            $al  = $autoload === null ? 0 : ($autoload ? 1 : 0);

            $serverType = \method_exists($db, 'getServerType') ? $db->getServerType() : 'mysql';
            if ($serverType === 'postgresql') {
                $sql = 'INSERT INTO ' . $db->quoteName(self::TABLE)
                    . ' (' . $db->quoteName('option_key') . ', ' . $db->quoteName('option_value')
                    . ', ' . $db->quoteName('autoload') . ', ' . $db->quoteName('created_at')
                    . ', ' . $db->quoteName('updated_at') . ')'
                    . ' VALUES (:key, :value, :autoload, :ct, :ut)'
                    . ' ON CONFLICT (' . $db->quoteName('option_key') . ') DO UPDATE SET '
                    . $db->quoteName('option_value') . ' = EXCLUDED.' . $db->quoteName('option_value')
                    . ', ' . $db->quoteName('updated_at') . ' = EXCLUDED.' . $db->quoteName('updated_at');
            } else {
                $sql = 'INSERT INTO ' . $db->quoteName(self::TABLE)
                    . ' (' . $db->quoteName('option_key') . ', ' . $db->quoteName('option_value')
                    . ', ' . $db->quoteName('autoload') . ', ' . $db->quoteName('created_at')
                    . ', ' . $db->quoteName('updated_at') . ')'
                    . ' VALUES (:key, :value, :autoload, :ct, :ut)'
                    . ' ON DUPLICATE KEY UPDATE '
                    . $db->quoteName('option_value') . ' = VALUES(' . $db->quoteName('option_value') . ')'
                    . ', ' . $db->quoteName('updated_at') . ' = VALUES(' . $db->quoteName('updated_at') . ')';
            }
            // Wave-7 deploy-fix: bind on a DatabaseQuery, not the driver
            // (see add() for the full rationale — MysqliDriver has no bind()).
            $query = $db->createQuery();
            $query->setQuery($sql)
                ->bind(':key',      $key,   ParameterType::STRING)
                ->bind(':value',    $value, ParameterType::STRING)
                ->bind(':autoload', $al,    ParameterType::INTEGER)
                ->bind(':ct',       $now,   ParameterType::INTEGER)
                ->bind(':ut',       $now,   ParameterType::INTEGER);
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
            $db    = $this->db();
            $query = $db->createQuery()
                ->delete($db->quoteName(self::TABLE))
                ->where($db->quoteName('option_key') . ' = :key')
                ->bind(':key', $key, ParameterType::STRING);
            $db->setQuery($query)->execute();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
