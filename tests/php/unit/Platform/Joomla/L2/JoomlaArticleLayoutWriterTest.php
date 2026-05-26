<?php
/**
 * JoomlaArticleLayoutWriter behavioural tests.
 *
 * Round-4 audit A3 P1 — Wave 3.5 L2 layer landed without unit coverage.
 * Pins:
 *   - writeArticle/writeByPointer/delete reject invalid (≤0) ids.
 *   - YT-absent environments fall through `runSaveTransforms` to the
 *     unchanged tree (cookbook §4.10.3 fail-safe).
 *   - Per-article lock-key shape stays `article_<id>` so two articles'
 *     writes do not contend (cookbook §4.13.5).
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\L2
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\L2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\L2\JoomlaArticleLayoutWriter;

#[CoversClass(JoomlaArticleLayoutWriter::class)]
final class JoomlaArticleLayoutWriterTest extends TestCase
{
    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        \ytb_test_install_mock_db();
    }

    protected function tearDown(): void
    {
        \MockJoomlaFactory::reset();
    }

    public function test_write_article_rejects_invalid_id(): void
    {
        $writer = new JoomlaArticleLayoutWriter();
        $this->expectException(\InvalidArgumentException::class);
        $writer->writeArticle(0, ['k' => 'v']);
    }

    public function test_write_by_pointer_rejects_invalid_id(): void
    {
        $writer = new JoomlaArticleLayoutWriter();
        $this->expectException(\InvalidArgumentException::class);
        $writer->writeByPointer(-3, '/foo', 'value');
    }

    public function test_delete_rejects_invalid_id(): void
    {
        $writer = new JoomlaArticleLayoutWriter();
        $this->expectException(\InvalidArgumentException::class);
        $writer->delete(0, '/foo');
    }

    public function test_run_save_transforms_falls_through_when_yt_absent(): void
    {
        // YT not bootstrapped → cookbook §4.10.3 fail-safe: tree
        // returned unchanged so REST `save` still succeeds.
        $writer = new JoomlaArticleLayoutWriter();
        $tree   = ['templates' => ['x' => ['name' => 'X']]];
        $out    = $writer->runSaveTransforms($tree);
        self::assertSame($tree, $out, 'Without YT, runSaveTransforms must return the input tree unchanged.');
    }

    public function test_lock_key_format_is_article_underscore_id(): void
    {
        // Per-article isolation depends on this exact shape. Cookbook
        // §4.13.5 — the WP-side L1 uses `tpl_<hash>`, so the L2
        // namespace must NOT collide.
        self::assertSame('article_1',  JoomlaArticleLayoutWriter::lockKey(1));
        self::assertSame('article_42', JoomlaArticleLayoutWriter::lockKey(42));
        self::assertSame('article_999999', JoomlaArticleLayoutWriter::lockKey(999_999));
    }

    public function test_lock_keys_are_isolated_per_article(): void
    {
        // Sanity check: two articles MUST have distinct lock-keys so
        // their writes never contend.
        self::assertNotSame(
            JoomlaArticleLayoutWriter::lockKey(1),
            JoomlaArticleLayoutWriter::lockKey(2),
        );
    }
}

