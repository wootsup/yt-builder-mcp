<?php
/**
 * JoomlaArticleStateRevision behavioural tests.
 *
 * Round-4 audit A3 P1 — Wave 3.5 L2 layer landed without unit coverage.
 * Pins the per-article F-07 ABA defense contract: each article gets
 * its own monotonic counter keyed at
 * `state_revision_article_<id>` so two articles' revisions do not
 * collide and an empty store returns 0.
 *
 * Cookbook §4.13.5 cross-reference (L2 article-storage).
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\L2
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\L2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\L2\JoomlaArticleStateRevision;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;

#[CoversClass(JoomlaArticleStateRevision::class)]
final class JoomlaArticleStateRevisionTest extends TestCase
{
    private JoomlaOptionStore $store;

    protected function setUp(): void
    {
        \MockJoomlaFactory::reset();
        \ytb_test_install_mock_db();
        $this->store = new JoomlaOptionStore();
    }

    protected function tearDown(): void
    {
        \MockJoomlaFactory::reset();
    }

    public function test_current_returns_zero_when_empty(): void
    {
        $rev = new JoomlaArticleStateRevision($this->store, 42);
        self::assertSame(0, $rev->current());
    }

    public function test_bump_increments_monotonically_per_article(): void
    {
        $rev = new JoomlaArticleStateRevision($this->store, 42);
        self::assertSame(1, $rev->bump());
        self::assertSame(2, $rev->bump());
        self::assertSame(3, $rev->bump());
        self::assertSame(3, $rev->current());
    }

    public function test_per_article_isolation(): void
    {
        // Two articles' counters MUST NOT collide. F-07 ABA defense
        // depends on this per-article keying.
        $revA = new JoomlaArticleStateRevision($this->store, 1);
        $revB = new JoomlaArticleStateRevision($this->store, 2);

        $revA->bump();
        $revA->bump();
        $revB->bump();

        self::assertSame(2, $revA->current(), 'Article 1 should have been bumped twice.');
        self::assertSame(1, $revB->current(), 'Article 2 should have been bumped once.');
    }

    public function test_article_id_accessor_returns_constructor_arg(): void
    {
        $rev = new JoomlaArticleStateRevision($this->store, 7);
        self::assertSame(7, $rev->articleId());
    }

    public function test_option_key_prefix_matches_published_contract(): void
    {
        self::assertSame(
            'state_revision_article_',
            JoomlaArticleStateRevision::OPTION_KEY_PREFIX,
            'Option-key prefix is part of the storage contract — changing it would orphan existing per-article counters.'
        );
    }
}
