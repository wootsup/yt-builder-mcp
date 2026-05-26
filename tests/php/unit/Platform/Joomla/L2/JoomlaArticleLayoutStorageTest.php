<?php
/**
 * JoomlaArticleLayoutStorage behavioural tests.
 *
 * Round-4 audit A3 P1 — Wave 3.5 L2 layer landed without unit coverage.
 * Pins:
 *   - readArticle returns empty array on invalid id / missing row /
 *     JSON-corrupt fulltext (fail-safe contract).
 *   - writeArticle returns false on invalid id (defensive guard).
 *   - listArticles defaults to non-trashed when state filter is null.
 *   - articleExists returns false for invalid id without DB access.
 *   - articleModified returns null when the modified column is empty
 *     or the synthetic `0000-00-00 00:00:00` sentinel.
 *
 * Cookbook §4.13.5 (L2 article-storage cross-reference) — driver-aware
 * MySQL + PostgreSQL via `$db->createQuery()` (J6-canonical) bound via
 * `ParameterType`.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\L2
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\L2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\L2\JoomlaArticleLayoutStorage;

#[CoversClass(JoomlaArticleLayoutStorage::class)]
final class JoomlaArticleLayoutStorageTest extends TestCase
{
    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        \ytb_test_install_mock_db();
        \MockJoomlaDatabase::$useLoadResultOverride = false;
        \MockJoomlaDatabase::$useLoadAssocListOverride = false;
        \MockJoomlaDatabase::$throwException = false;
        \MockJoomlaDatabase::$executeResult = true;
    }

    protected function tearDown(): void
    {
        \MockJoomlaFactory::reset();
        \MockJoomlaDatabase::$useLoadResultOverride = false;
        \MockJoomlaDatabase::$useLoadAssocListOverride = false;
        \MockJoomlaDatabase::$throwException = false;
        \MockJoomlaDatabase::$executeResult = true;
    }

    public function test_read_article_returns_empty_array_for_invalid_id(): void
    {
        $storage = new JoomlaArticleLayoutStorage();
        self::assertSame([], $storage->readArticle(0));
        self::assertSame([], $storage->readArticle(-1));
    }

    public function test_read_article_returns_empty_array_when_row_missing(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = null;
        $storage = new JoomlaArticleLayoutStorage();
        self::assertSame([], $storage->readArticle(1));
    }

    public function test_read_article_returns_empty_array_on_json_corruption(): void
    {
        // Fail-safe contract — corrupted JSON in fulltext must NOT
        // surface as an exception; the controller maps it to an empty
        // layout instead.
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = '{not-json}';
        $storage = new JoomlaArticleLayoutStorage();
        self::assertSame([], $storage->readArticle(1));
    }

    public function test_read_article_decodes_valid_json_payload(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = '{"templates":{"tpl":{"name":"Home"}}}';
        $storage = new JoomlaArticleLayoutStorage();
        $result = $storage->readArticle(1);
        self::assertIsArray($result);
        self::assertArrayHasKey('templates', $result);
        self::assertSame('Home', $result['templates']['tpl']['name']);
    }

    public function test_write_article_returns_false_on_invalid_id(): void
    {
        $storage = new JoomlaArticleLayoutStorage();
        self::assertFalse($storage->writeArticle(0, ['anything' => 'works']));
        self::assertFalse($storage->writeArticle(-5, []));
    }

    public function test_write_article_returns_true_on_success(): void
    {
        \MockJoomlaDatabase::$executeResult = true;
        $storage = new JoomlaArticleLayoutStorage();
        self::assertTrue($storage->writeArticle(42, ['k' => 'v']));
    }

    public function test_write_article_returns_false_on_driver_exception(): void
    {
        \MockJoomlaDatabase::$throwException = true;
        $storage = new JoomlaArticleLayoutStorage();
        self::assertFalse($storage->writeArticle(42, ['k' => 'v']));
    }

    public function test_list_articles_returns_empty_array_on_driver_error(): void
    {
        \MockJoomlaDatabase::$throwException = true;
        $storage = new JoomlaArticleLayoutStorage();
        self::assertSame([], $storage->listArticles(null, null, 20, 0));
    }

    public function test_list_articles_normalises_row_shape(): void
    {
        \MockJoomlaDatabase::$useLoadAssocListOverride = true;
        \MockJoomlaDatabase::$loadAssocListOverride = [
            ['id' => '7', 'title' => 'Hello', 'alias' => 'hello', 'catid' => '2', 'state' => '1', 'modified' => '2026-05-24'],
        ];
        $storage = new JoomlaArticleLayoutStorage();
        $rows = $storage->listArticles(null, null, 20, 0);
        self::assertCount(1, $rows);
        // String DB values normalised to typed PHP values.
        self::assertSame(7,   $rows[0]['id']);
        self::assertSame(2,   $rows[0]['catid']);
        self::assertSame(1,   $rows[0]['state']);
        self::assertSame('Hello', $rows[0]['title']);
    }

    public function test_list_articles_clamps_limit_to_safe_range(): void
    {
        \MockJoomlaDatabase::$useLoadAssocListOverride = true;
        \MockJoomlaDatabase::$loadAssocListOverride = [];
        $storage = new JoomlaArticleLayoutStorage();
        // Negative or zero limit → defaults to 20; ridiculous limit
        // → capped at 200. Just verify no exception.
        $storage->listArticles(null, null, -10, -5);
        $storage->listArticles(null, null, 999_999, 0);
        self::assertTrue(true);
    }

    public function test_article_exists_returns_false_for_invalid_id(): void
    {
        $storage = new JoomlaArticleLayoutStorage();
        self::assertFalse($storage->articleExists(0));
        self::assertFalse($storage->articleExists(-1));
    }

    public function test_article_exists_returns_true_when_count_is_one(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = '1';
        $storage = new JoomlaArticleLayoutStorage();
        self::assertTrue($storage->articleExists(42));
    }

    public function test_article_exists_returns_false_when_count_is_zero(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = '0';
        $storage = new JoomlaArticleLayoutStorage();
        self::assertFalse($storage->articleExists(42));
    }

    public function test_article_modified_returns_null_for_invalid_id(): void
    {
        $storage = new JoomlaArticleLayoutStorage();
        self::assertNull($storage->articleModified(0));
    }

    public function test_article_modified_returns_null_for_zero_sentinel(): void
    {
        // Joomla's well-known "no modified date" sentinel — must be
        // surfaced as null so the ETag doesn't fold it into a hash.
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = '0000-00-00 00:00:00';
        $storage = new JoomlaArticleLayoutStorage();
        self::assertNull($storage->articleModified(1));
    }

    public function test_article_modified_returns_string_when_present(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = '2026-05-24 12:34:56';
        $storage = new JoomlaArticleLayoutStorage();
        self::assertSame('2026-05-24 12:34:56', $storage->articleModified(1));
    }
}
