<?php
/**
 * JoomlaArticleLayoutReader behavioural tests.
 *
 * Round-4 audit A3 P1 — Wave 3.5 L2 layer landed without unit coverage.
 * Pins the F-07 ABA defense ETag format `<sha256>-r<int>` (cookbook
 * §4.2.5): even an empty article must produce a deterministic ETag with
 * the `-r0` suffix; bumping the per-article revision must produce a
 * distinct ETag even if the content hash is unchanged.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\L2
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\L2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\L2\JoomlaArticleLayoutReader;
use WootsUp\BuilderMcp\Platform\Joomla\L2\JoomlaArticleLayoutStorage;
use WootsUp\BuilderMcp\Platform\Joomla\L2\JoomlaArticleStateRevision;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;

#[CoversClass(JoomlaArticleLayoutReader::class)]
final class JoomlaArticleLayoutReaderTest extends TestCase
{
    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        \ytb_test_install_mock_db();
        \MockJoomlaDatabase::$useLoadResultOverride = false;
    }

    protected function tearDown(): void
    {
        \MockJoomlaFactory::reset();
        \MockJoomlaDatabase::$useLoadResultOverride = false;
    }

    public function test_read_returns_storage_payload(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = '{"templates":{"x":{"name":"X"}}}';
        $reader = new JoomlaArticleLayoutReader(new JoomlaArticleLayoutStorage(), 1);
        $state  = $reader->read();
        self::assertIsArray($state);
        self::assertSame('X', $state['templates']['x']['name']);
    }

    public function test_etag_format_is_sha256_minus_r_int(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = '{}';
        $reader = new JoomlaArticleLayoutReader(new JoomlaArticleLayoutStorage(), 1);
        $etag = $reader->etag();
        // Format: 64 hex chars + literal `-r` + at-least-one digit.
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}-r\d+$/',
            $etag,
            'L2 ETag must follow the F-07 ABA-safe format <sha256>-r<int> (cookbook §4.2.5).'
        );
    }

    public function test_etag_revision_suffix_increments_with_state_revision(): void
    {
        // For this test the load-result override must be OFF: the
        // mock-DB's normal `:key`-keyed in-memory table tracks the
        // revision's stored counter, while readArticle's `:id`-keyed
        // SELECT falls through to null → empty state. The content hash
        // therefore stays stable while the revision suffix advances —
        // which is exactly what F-07 ABA-defense pins.
        \MockJoomlaDatabase::$useLoadResultOverride = false;
        $store  = new JoomlaOptionStore();
        $rev    = new JoomlaArticleStateRevision($store, 1);
        $reader = new JoomlaArticleLayoutReader(new JoomlaArticleLayoutStorage(), 1, $rev);

        $beforeEtag = $reader->etag();
        $rev->bump();
        $afterEtag = $reader->etag();

        self::assertNotSame(
            $beforeEtag,
            $afterEtag,
            'F-07 ABA-defense: bumping the per-article revision must change the ETag even when content hash is unchanged.'
        );
        self::assertStringEndsWith('-r0', $beforeEtag, 'Empty store reads as revision 0.');
        self::assertStringEndsWith('-r1', $afterEtag,  'One bump advances revision to 1.');
    }

    public function test_read_by_pointer_traverses_state(): void
    {
        \MockJoomlaDatabase::$useLoadResultOverride = true;
        \MockJoomlaDatabase::$loadResultOverride = '{"templates":{"tpl":{"name":"Home"}}}';
        $reader = new JoomlaArticleLayoutReader(new JoomlaArticleLayoutStorage(), 1);
        self::assertSame('Home', $reader->readByPointer('/templates/tpl/name'));
    }

    public function test_article_id_accessor_returns_constructor_arg(): void
    {
        $reader = new JoomlaArticleLayoutReader(new JoomlaArticleLayoutStorage(), 99);
        self::assertSame(99, $reader->articleId());
    }

    public function test_get_revision_returns_state_revision_interface(): void
    {
        $reader = new JoomlaArticleLayoutReader(new JoomlaArticleLayoutStorage(), 1);
        $rev = $reader->getRevision();
        self::assertInstanceOf(JoomlaArticleStateRevision::class, $rev);
    }
}
