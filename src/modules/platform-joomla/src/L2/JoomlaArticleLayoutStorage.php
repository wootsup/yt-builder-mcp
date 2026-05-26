<?php
/**
 * DAO over Joomla's `#__content` table — the L2 per-article layout
 * storage layer (Joomla-extra-scope per [[feedback-parity-is-floor-not-
 * ceiling]]).
 *
 * Joomla's content rows can natively carry a per-article YT-Builder
 * state in the `fulltext` column (JSON-encoded `{library, templates}`).
 * Cookbook §4.13.5 (L2 article-storage cross-reference) — WP follow-up
 * planned via `wp_postmeta._yootheme_page`; on Joomla the native
 * `fulltext` column makes L2 a clean greenfield without waiting on WP.
 *
 * JSON encoding flags are pinned to `JSON_UNESCAPED_SLASHES |
 * JSON_UNESCAPED_UNICODE` (cookbook §4.1.2) so the sha256 over the
 * stored JSON matches the L1 hash-shape byte-for-byte.
 *
 * Driver-aware (MySQL + PostgreSQL) per cookbook §S2 Joomla 6 BCs:
 *   - Uses `$db->createQuery()` (the J6-canonical query builder; the
 *     deprecated `getQuery` factory with truthy arg was removed in J6
 *     and is forbidden by {@see Joomla6ForwardCompatPinTest})
 *   - All parameters bound via {@see ParameterType}
 *   - No string-concatenation into SQL
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\L2
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\L2;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaLayoutStorage;
use WootsUp\BuilderMcp\Util\SecurityLogger;

class JoomlaArticleLayoutStorage
{
    /** Joomla content table (with `#__` prefix-token). */
    public const TABLE = '#__content';

    /**
     * Read the per-article Builder state JSON-decoded from
     * `#__content.fulltext`. Returns the empty array on every failure
     * mode (row missing, JSON corruption, driver error) — matches the
     * L1 fail-safe contract from JoomlaLayoutStorage::readState().
     *
     * @return array{library?: array<int, mixed>, templates?: array<string, mixed>}
     */
    public function readArticle(int $id): array
    {
        if ($id <= 0) {
            return [];
        }
        try {
            $db    = $this->db();
            $query = $db->createQuery()
                ->select($db->quoteName('fulltext'))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);
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
     * Persist the per-article Builder state into `#__content.fulltext`.
     * Returns true on success, false on driver error / encode failure.
     *
     * Cookbook §4.1.2 JSON-encoding contract — flags pinned so the
     * cross-platform ETag (sha256-over-json) is stable.
     *
     * @param array<string, mixed> $tree
     */
    public function writeArticle(int $id, array $tree): bool
    {
        if ($id <= 0) {
            return false;
        }
        try {
            $encoded = \json_encode($tree, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                return false;
            }
            // Round-6 A5 polish — payload-size pre-flight mirror of the L1
            // guard in {@see JoomlaLayoutStorage::writeState}. Joomla's
            // `#__content.fulltext` column is MEDIUMTEXT (16 MB) in the
            // canonical schema; sufficiently large per-article Builder states
            // would silently truncate. Logs a warning ~2 MB before the hard
            // limit so an operator can ALTER the column to LONGTEXT before
            // truncation occurs.
            $size = \strlen($encoded);
            if ($size > JoomlaLayoutStorage::MEDIUMTEXT_WARN_BYTES) {
                SecurityLogger::log(SecurityLogger::EVENT_PAYLOAD_NEAR_MEDIUMTEXT_LIMIT, [
                    'platform'    => 'joomla',
                    'scope'       => 'l2_article',
                    'article_id'  => $id,
                    'bytes'       => $size,
                    'limit'       => JoomlaLayoutStorage::MEDIUMTEXT_LIMIT_BYTES,
                    'remediation' => 'Joomla #__content.fulltext is MEDIUMTEXT (16MB). '
                        . 'Migrate to LONGTEXT: '
                        . 'ALTER TABLE #__content MODIFY fulltext LONGTEXT;',
                ]);
            }
            $db    = $this->db();
            $query = $db->createQuery()
                ->update($db->quoteName(self::TABLE))
                ->set($db->quoteName('fulltext') . ' = :data')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':data', $encoded, ParameterType::STRING)
                ->bind(':id',   $id,      ParameterType::INTEGER);
            $db->setQuery($query)->execute();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Paginated SELECT over `#__content` with optional category + state
     * filters. Used by ArticlesController::list to enumerate articles
     * that may carry per-article Builder layouts.
     *
     * State semantics (Joomla `state` column): 1=published, 0=unpublished,
     * 2=archived, -2=trashed. Passing null returns every state except
     * trashed (-2) — matches the admin list-default.
     *
     * @return list<array{id: int, title: string, alias: string, catid: int, state: int, modified: string}>
     */
    public function listArticles(?int $catId, ?string $state, int $limit, int $offset): array
    {
        $limit  = $limit  <= 0 ? 20 : \min($limit, 200);
        $offset = \max(0, $offset);
        try {
            $db    = $this->db();
            $query = $db->createQuery()
                ->select($db->quoteName(['id', 'title', 'alias', 'catid', 'state', 'modified']))
                ->from($db->quoteName(self::TABLE));

            if ($catId !== null && $catId > 0) {
                $query->where($db->quoteName('catid') . ' = :catid')
                      ->bind(':catid', $catId, ParameterType::INTEGER);
            }

            if ($state === null) {
                // Default — exclude trashed.
                $trashed = -2;
                $query->where($db->quoteName('state') . ' <> :trashed')
                      ->bind(':trashed', $trashed, ParameterType::INTEGER);
            } else {
                // Caller-supplied state filter — only accept the four
                // canonical Joomla content states. Anything else falls
                // back to "all non-trashed" to avoid SQL injection via
                // malformed query-strings.
                $allowed = ['published' => 1, 'unpublished' => 0, 'archived' => 2, 'trashed' => -2];
                if (isset($allowed[$state])) {
                    $stateVal = $allowed[$state];
                    $query->where($db->quoteName('state') . ' = :state')
                          ->bind(':state', $stateVal, ParameterType::INTEGER);
                } else {
                    $trashed = -2;
                    $query->where($db->quoteName('state') . ' <> :trashed')
                          ->bind(':trashed', $trashed, ParameterType::INTEGER);
                }
            }

            $query->order($db->quoteName('modified') . ' DESC');
            $query->setLimit($limit, $offset);

            $rows = $db->setQuery($query)->loadAssocList();
            if (!\is_array($rows)) {
                return [];
            }
            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'id'       => (int) ($row['id'] ?? 0),
                    'title'    => (string) ($row['title'] ?? ''),
                    'alias'    => (string) ($row['alias'] ?? ''),
                    'catid'    => (int) ($row['catid'] ?? 0),
                    'state'    => (int) ($row['state'] ?? 0),
                    'modified' => (string) ($row['modified'] ?? ''),
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Cheap existence probe. */
    public function articleExists(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        try {
            $db    = $this->db();
            $query = $db->createQuery()
                ->select('COUNT(*)')
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);
            $count = $db->setQuery($query)->loadResult();
            return \is_numeric($count) && (int) $count === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Return the `#__content.modified` timestamp for the given article
     * (used as part of L2 ETag composition). Returns null when the row
     * is missing or the column is empty.
     */
    public function articleModified(int $id): ?string
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $db    = $this->db();
            $query = $db->createQuery()
                ->select($db->quoteName('modified'))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);
            $modified = $db->setQuery($query)->loadResult();
            if (!\is_string($modified) || $modified === '' || $modified === '0000-00-00 00:00:00') {
                return null;
            }
            return $modified;
        } catch (\Throwable) {
            return null;
        }
    }

    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
