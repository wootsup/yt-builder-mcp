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
}
