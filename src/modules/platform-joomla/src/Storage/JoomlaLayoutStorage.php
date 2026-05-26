<?php
/**
 * DAO over YT's persistent Builder-state on Joomla:
 *   #__extensions WHERE element='yootheme' AND folder='system' → custom_data
 *
 * Replaces WP's `wp_option('yootheme')` (cookbook §4.1.1). YT stores
 * a JSON-encoded blob `{library: [...], templates: {...}}` (cookbook
 * §4.1.3) in the `custom_data` MEDIUMTEXT column; this DAO reads / writes
 * that single row.
 *
 * Cookbook §4.13.4 — ADR / Strategy 1: BYPASS YT's `onAfterRespond`
 * deferred-write because REST endpoints must return "saved"
 * synchronously. We hit `#__extensions` directly with the YT-equivalent
 * shape; the YT cache flush (CacheFlusher::flushL1) then makes the
 * write visible to the next render.
 *
 * ETag-encoding flags MUST be `JSON_UNESCAPED_SLASHES |
 * JSON_UNESCAPED_UNICODE` (cookbook §4.1.2) so the sha256 over the
 * stored JSON matches the WP-side hash byte-for-byte.
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
use WootsUp\BuilderMcp\Util\SecurityLogger;

final class JoomlaLayoutStorage
{
    /** YT system-plugin element name in `#__extensions`. */
    public const YT_ELEMENT = 'yootheme';

    /** YT system-plugin folder name in `#__extensions`. */
    public const YT_FOLDER = 'system';

    /**
     * MySQL MEDIUMTEXT column-size ceiling (cookbook §4.1.5 — YT's
     * `#__extensions.custom_data` column is MEDIUMTEXT in the canonical
     * J5/J6 schema). 16 * 1024 * 1024 - 1 bytes is the strict limit;
     * we emit the warning at 14 MB so an operator has runway to migrate
     * to LONGTEXT before truncation occurs.
     */
    public const MEDIUMTEXT_LIMIT_BYTES = 16 * 1024 * 1024;
    public const MEDIUMTEXT_WARN_BYTES  = 14 * 1024 * 1024;

    /** Cached extension_id (lookup once per request). */
    private static ?int $extensionId = null;

    /**
     * Read the full Builder state. Returns the empty state on every
     * failure mode (row missing, JSON corruption, YT not installed).
     * Mirrors the WP-side LayoutReader.php:47-68 contract.
     *
     * @return array{library?: array<int, mixed>, templates?: array<string, mixed>}
     */
    public function readState(): array
    {
        try {
            // Wave-7 deploy-fix: bind() takes $value BY REFERENCE. An inline
            // assignment (`$element = self::YT_ELEMENT`) raises "Argument #2
            // ($value) could not be passed by reference" on J6 — swallowed by
            // the catch, so readState() always returned [] and the ENTIRE L1
            // read pipeline saw an empty Builder state (pages list empty, every
            // template "not found"). Pre-declare the bound values.
            $element = self::YT_ELEMENT;
            $folder  = self::YT_FOLDER;
            $db    = $this->db();
            $query = $db->createQuery()
                ->select($db->quoteName('custom_data'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = :element')
                ->where($db->quoteName('folder')  . ' = :folder')
                ->bind(':element', $element, ParameterType::STRING)
                ->bind(':folder',  $folder,  ParameterType::STRING);
            $raw = $db->setQuery($query)->loadResult();

            if (!\is_string($raw) || $raw === '') {
                return [];
            }
            $decoded = \json_decode($raw, true);
            return \is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Persist the full Builder state. Returns true on success, false on
     * driver error. Uses prepared statement bound by extension_id.
     *
     * Cookbook §4.1.2 JSON-encoding contract — flags pinned so the
     * cross-platform ETag (sha256-over-json) matches WP byte-for-byte.
     */
    public function writeState(array $state): bool
    {
        try {
            $extensionId = $this->resolveExtensionId();
            if ($extensionId === null) {
                return false;
            }
            $encoded = \json_encode($state, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                return false;
            }
            // Audit-A5 P1-3 (Round-3): payload-size pre-flight against
            // the default MEDIUMTEXT 16 MB ceiling on YT's
            // `#__extensions.custom_data` column. Logs a warning ~2 MB
            // before the hard limit so an operator can ALTER the column
            // to LONGTEXT before silent truncation occurs.
            $size = \strlen($encoded);
            if ($size > self::MEDIUMTEXT_WARN_BYTES) {
                SecurityLogger::log(SecurityLogger::EVENT_PAYLOAD_NEAR_MEDIUMTEXT_LIMIT, [
                    'platform'    => 'joomla',
                    'bytes'       => $size,
                    'limit'       => self::MEDIUMTEXT_LIMIT_BYTES,
                    'remediation' => 'YT default custom_data column is MEDIUMTEXT (16MB). '
                        . 'Migrate to LONGTEXT with: '
                        . 'ALTER TABLE #__extensions MODIFY custom_data LONGTEXT;',
                ]);
            }
            $db    = $this->db();
            $query = $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('custom_data') . ' = :data')
                ->where($db->quoteName('extension_id') . ' = :id')
                ->bind(':data', $encoded,     ParameterType::STRING)
                ->bind(':id',   $extensionId, ParameterType::INTEGER);
            $db->setQuery($query)->execute();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Lookup-and-cache the YT system-plugin extension_id. */
    public function resolveExtensionId(): ?int
    {
        if (self::$extensionId !== null) {
            return self::$extensionId;
        }
        try {
            // Wave-7 deploy-fix: pre-declare bound values — inline assignment
            // in bind() fatals "could not be passed by reference" on J6, so
            // the YT extension_id never resolved → all L1 writes returned
            // false ("storage_write_returned_false").
            $element = self::YT_ELEMENT;
            $folder  = self::YT_FOLDER;
            $db    = $this->db();
            $query = $db->createQuery()
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = :element')
                ->where($db->quoteName('folder')  . ' = :folder')
                ->bind(':element', $element, ParameterType::STRING)
                ->bind(':folder',  $folder,  ParameterType::STRING);
            $id = $db->setQuery($query)->loadResult();
            if (\is_numeric($id)) {
                self::$extensionId = (int) $id;
                return self::$extensionId;
            }
        } catch (\Throwable) {
            // Fall through → null
        }
        return null;
    }

    /** @internal Test-only reset hook. */
    public static function resetForTests(): void
    {
        self::$extensionId = null;
    }

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
