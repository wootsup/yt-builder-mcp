<?php
/**
 * SourcesController::list_sources — kind-alias projection.
 *
 * T7 (Audit-v3 B.9): every source row surfaces a `kind` alias of the
 * GraphQL `type` field so the MCP TS table mapper can fill its KIND
 * column without a client-side rename. `type` is preserved for
 * backwards-compatibility.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Integration\SourceBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Cache\CacheFlusher;
use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\SourceBinding\SourceRegistry;
use WootsUp\BuilderMcp\SourceBinding\SourcesController;
use WootsUp\BuilderMcp\State\LayoutReader;
use WootsUp\BuilderMcp\State\LayoutWriter;
use WootsUp\BuilderMcp\Tests\TestVerifierFactory;

#[CoversClass(SourcesController::class)]
final class SourcesListTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $entries
     */
    private function controller(array $entries): SourcesController
    {
        $registry = new SourceRegistry(null, static fn (): array => $entries);
        $reader = new LayoutReader();
        $writer = new LayoutWriter($reader);
        $ops = new ElementOps($reader);
        $flusher = new CacheFlusher();
        return new SourcesController(
            $registry,
            $ops,
            $reader,
            $writer,
            $flusher,
            TestVerifierFactory::verifier(),
        );
    }

    public function test_list_sources_adds_kind_alias_of_type_to_each_row(): void
    {
        $controller = $this->controller([
            ['name' => 'posts.singlePost', 'label' => 'Post', 'group' => 'WordPress', 'type' => 'Post'],
            ['name' => 'posts.posts', 'label' => 'Posts', 'group' => 'WordPress', 'type' => 'PostList'],
        ]);

        /** @var \WP_REST_Response $resp */
        $resp = $controller->list_sources(new \WP_REST_Request('GET', '/'));
        $sources = $resp->get_data()['sources'];

        foreach ($sources as $rows) {
            foreach ($rows as $row) {
                self::assertArrayHasKey('kind', $row);
                self::assertSame($row['type'], $row['kind']);
            }
        }
    }

    public function test_list_sources_preserves_type_for_backward_compatibility(): void
    {
        $controller = $this->controller([
            ['name' => 'posts.singlePost', 'label' => 'Post', 'group' => 'WordPress', 'type' => 'Post'],
        ]);

        /** @var \WP_REST_Response $resp */
        $resp = $controller->list_sources(new \WP_REST_Request('GET', '/'));
        $sources = $resp->get_data()['sources'];

        $wordpress = $sources['wordpress'];
        self::assertNotEmpty($wordpress);
        self::assertSame('Post', $wordpress[0]['type']);
        self::assertSame('Post', $wordpress[0]['kind']);
    }

    public function test_list_sources_empty_groups_yield_no_rows(): void
    {
        $controller = $this->controller([]);

        /** @var \WP_REST_Response $resp */
        $resp = $controller->list_sources(new \WP_REST_Request('GET', '/'));
        $sources = $resp->get_data()['sources'];

        self::assertSame([], $sources['wordpress']);
        self::assertSame([], $sources['apimapper']);
        self::assertSame([], $sources['essentials']);
    }

    /**
     * 1.0.1 Wave-1.8 P1 F-COLD-23: cold-agent S3 had to find 1 native
     * `posts` entry in a 210-row sources listing. The new `?group=`
     * filter scopes the response to a single origin, dropping the
     * other groups from the envelope entirely.
     */
    public function test_list_sources_group_filter_returns_only_one_origin(): void
    {
        $controller = $this->controller([
            ['name' => 'posts.posts', 'label' => 'Posts', 'group' => 'WordPress', 'type' => 'PostsQuery'],
            ['name' => 'apiMapperFlow1', 'label' => 'Flow', 'group' => 'API Mapper', 'type' => 'ApiMapperFlow'],
        ]);

        $req = new \WP_REST_Request('GET', '/');
        $req->set_param('group', 'wordpress');
        /** @var \WP_REST_Response $resp */
        $resp = $controller->list_sources($req);
        $sources = $resp->get_data()['sources'];

        self::assertArrayHasKey('wordpress', $sources);
        self::assertArrayNotHasKey('apimapper', $sources);
        self::assertCount(1, $sources['wordpress']);
        self::assertSame('posts.posts', $sources['wordpress'][0]['name']);
    }

    public function test_list_sources_kind_filter_returns_only_matching_type(): void
    {
        $controller = $this->controller([
            ['name' => 'posts.posts', 'label' => 'Posts', 'group' => 'WordPress', 'type' => 'PostsQuery'],
            ['name' => 'posts.singlePost', 'label' => 'Post', 'group' => 'WordPress', 'type' => 'Post'],
            ['name' => 'apiMapperFlow1', 'label' => 'Flow', 'group' => 'API Mapper', 'type' => 'PostsQuery'],
        ]);

        $req = new \WP_REST_Request('GET', '/');
        $req->set_param('kind', 'PostsQuery');
        /** @var \WP_REST_Response $resp */
        $resp = $controller->list_sources($req);
        $sources = $resp->get_data()['sources'];

        // Both wordpress (posts.posts) and apimapper (Flow1) carry `PostsQuery`.
        self::assertArrayHasKey('wordpress', $sources);
        self::assertArrayHasKey('apimapper', $sources);
        self::assertCount(1, $sources['wordpress']);
        self::assertSame('posts.posts', $sources['wordpress'][0]['name']);
        // `posts.singlePost` (kind=Post) was filtered out.
        $kinds = array_map(static fn(array $r): string => $r['kind'], $sources['wordpress']);
        self::assertNotContains('Post', $kinds);
    }
}
