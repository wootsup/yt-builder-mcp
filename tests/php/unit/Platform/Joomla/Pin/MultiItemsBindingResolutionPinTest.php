<?php
/**
 * REGRESSION PIN-TEST (Wave 6.7): Joomla multi-items-awareness binding
 * resolution — every canonical container ↔ item pair MUST resolve
 * IDENTICALLY to the WP path.
 *
 * Mirrors the WP pin
 * {@see \WootsUp\BuilderMcp\Tests\Unit\Elements\MultiItemsPatternPinTest}
 * over the 16 canonical {@see ItemContainerMap::MAP} pairs, but drives the
 * JOOMLA controller's own resolver:
 *
 *   {@see \WootsUp\Component\Ytbmcp\Api\Controller\SourcesController::resolveBindingLevel}
 *
 * The behaviour pinned (YT-Pro Multi-Items rule — bind on the `*_item`
 * child, NOT the container, because `SourceTransform::repeatSource` clones
 * the source-bearing element):
 *
 *   1. bindingLevel='item' on a container target with one `*_item` child
 *      resolves the binding ONTO the child — the returned pointer walks into
 *      `…/children/<idx>` and `level='item'`. The container pointer is NOT
 *      returned (binding never lands on the container).
 *   2. bindingLevel='container' emits a structural `warning` that references
 *      the canonical child type.
 *
 * ── Architecture finding (CRITICAL — shared-delegation vs divergence) ──
 *
 * The Joomla com_ytbmcp SourcesController does NOT delegate to the shared
 * `WootsUp\BuilderMcp\SourceBinding\SourcesController`; it REIMPLEMENTS
 * `resolveBindingLevel()` (+ `firstChildOfType()`) inline, mirroring the WP
 * resolver. The two implementations are behaviourally identical for the two
 * pinned cases (item-child walk + container warning string), with ONE
 * deliberate transport-shape difference:
 *
 *   • WP resolver returns a `\WP_Error` on the bindingLevel='item'-with-no-
 *     item-child path.
 *   • Joomla resolver (no WP_Error class in the Joomla runtime) returns an
 *     ARRAY carrying an `error` key (`{pointer, level, error:{status,code,
 *     message,data}}`) which `mutateBinding()` unwraps into a JoomlaJson-
 *     Response error envelope.
 *
 * Because they DIVERGE in transport shape (array-with-error vs WP_Error) but
 * CONVERGE in behaviour, this is a reimplementation that must be guarded
 * against drift — exactly the smell the Pin-directory README calls out. A
 * future "DRY" refactor that points the Joomla controller at the WP resolver
 * would break in the Joomla runtime (no WP_Error class); a refactor that
 * silently changes the warning string or stops walking into the child would
 * break customer Multi-Items bindings on Joomla only. Both regressions fail
 * this pin loudly.
 *
 * ── Harness note ──
 *
 * The com_ytbmcp api controllers are NOT in the composer PSR-4 autoload
 * (they load at Joomla runtime), so this pin `require_once`s the controller
 * file once — its dependency graph (AbstractApiController→BaseController,
 * ItemContainerMap, BindingSerializer, JoomlaLayout*, JsonPointer,
 * SecurityLogger) is composer-autoloaded or stubbed by joomla-bootstrap.php.
 * `resolveBindingLevel()` / `firstChildOfType()` are pure functions of
 * `(requested, node, pointer)` — no DB, no auth, no echo — so the resolver
 * is exercised BEHAVIOURALLY via Reflection rather than via the full
 * dispatch()→echo pipeline (which would need a minted Bearer + a stateful
 * blob-backed mock DB the harness does not yet provide). The downstream
 * persistence is already covered by the Joomla L1 storage pins
 * (BindOnDatabaseQueryPersistsPinTest / LayoutStorageInlineBindPinTest).
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WootsUp\BuilderMcp\Elements\ItemContainerMap;

final class MultiItemsBindingResolutionPinTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../../../../..';

    private const SOURCES_CONTROLLER =
        self::REPO_ROOT
        . '/src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/SourcesController.php';

    /** Fully-qualified com_ytbmcp controller class-name (runtime-loaded). */
    private const FQCN = 'WootsUp\\Component\\Ytbmcp\\Api\\Controller\\SourcesController';

    public static function setUpBeforeClass(): void
    {
        // The packaging controller is not composer-autoloaded; load it once.
        // _JEXEC + the BaseController stub are provided by joomla-bootstrap.php.
        if (!\class_exists(self::FQCN, false)) {
            $path = self::SOURCES_CONTROLLER;
            self::assertFileExists($path, 'Joomla com_ytbmcp SourcesController must exist.');
            require_once $path;
        }
        self::assertTrue(
            \class_exists(self::FQCN, false),
            'Joomla SourcesController must class-load from the packaging tree.',
        );
    }

    /**
     * Invoke the private static resolver on the Joomla controller.
     * (PHP 8.1+ allows ReflectionMethod::invoke on private statics without
     * the deprecated setAccessible(); the joomla suite runs --fail-on-
     * deprecation, so we deliberately do NOT call setAccessible().)
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function resolve(string $requested, array $node, string $pointer): array
    {
        $rm = new ReflectionMethod(self::FQCN, 'resolveBindingLevel');
        /** @var array<string, mixed> $res */
        $res = $rm->invoke(null, $requested, $node, $pointer);
        return $res;
    }

    /**
     * Build a container node carrying exactly one canonical `*_item` child.
     *
     * @return array<string, mixed>
     */
    private static function containerWithItemChild(string $container, string $item): array
    {
        return [
            'type'     => $container,
            'props'    => [],
            'children' => [
                ['type' => $item, 'props' => []],
            ],
        ];
    }

    /**
     * Architecture / parity guard: the canonical 16-pair MAP is the single
     * source of truth shared by both platforms. If this list drifts, the
     * provider count below (and the WP pin's identical assertion) catches it.
     */
    public function test_canonical_pair_inventory_is_stable(): void
    {
        $expected = [
            'accordion',
            'button',
            'description_list',
            'gallery',
            'grid',
            'list',
            'map',
            'nav',
            'overlay-slider',
            'panel-slider',
            'popover',
            'slideshow',
            'social',
            'subnav',
            'switcher',
            'table',
        ];
        self::assertSame($expected, \array_keys(ItemContainerMap::MAP));
        self::assertCount(16, ItemContainerMap::MAP);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function pairProvider(): iterable
    {
        foreach (ItemContainerMap::MAP as $container => $item) {
            yield $container => [$container, $item];
        }
    }

    /**
     * (a) bindingLevel='item' on a container target with one `*_item` child
     * resolves the binding ONTO the child + the container stays clean.
     *
     * The container pointer is `…/children/0`; the resolver must return
     * `…/children/0/children/0` (the first item child) with level='item'.
     * It must NOT carry an `error` key and must NOT return the container
     * pointer (which would leak the binding onto the container).
     */
    #[DataProvider('pairProvider')]
    public function test_binding_level_item_resolves_container_to_item_child(
        string $container,
        string $item,
    ): void {
        $node          = self::containerWithItemChild($container, $item);
        $containerPtr  = '/templates/tpl/layout/children/0';
        $expectedChild = $containerPtr . '/children/0';

        $res = self::resolve('item', $node, $containerPtr);

        self::assertArrayNotHasKey(
            'error',
            $res,
            \sprintf('Item-level resolution on %s with a %s child must NOT error.', $container, $item),
        );
        self::assertSame('item', $res['level'] ?? null);
        self::assertSame(
            $expectedChild,
            $res['pointer'] ?? null,
            \sprintf('Resolver must walk into the %s child of %s (Multi-Items rule).', $item, $container),
        );
        // The container pointer must NOT be the resolved target — binding
        // never lands on the container.
        self::assertNotSame(
            $containerPtr,
            $res['pointer'] ?? null,
            \sprintf('Binding must move OFF the %s container onto the %s child.', $container, $item),
        );
    }

    /**
     * (b) bindingLevel='container' on a container target emits the structural
     * warning referencing the canonical `*_item` child type, and keeps the
     * pointer on the container (the caller explicitly asked for the legacy
     * pattern).
     */
    #[DataProvider('pairProvider')]
    public function test_binding_level_container_emits_warning_referencing_item_type(
        string $container,
        string $item,
    ): void {
        $node         = self::containerWithItemChild($container, $item);
        $containerPtr = '/templates/tpl/layout/children/0';

        $res = self::resolve('container', $node, $containerPtr);

        self::assertSame('container', $res['level'] ?? null);
        self::assertSame($containerPtr, $res['pointer'] ?? null);
        self::assertArrayHasKey(
            'warning',
            $res,
            \sprintf('Container-level binding on %s must emit a structural warning.', $container),
        );
        $warning = (string) ($res['warning'] ?? '');
        self::assertStringContainsString(
            $item,
            $warning,
            \sprintf('Warning must reference the canonical %s child type.', $item),
        );
        // Pin the canonical wire-text so a future warning-string rewrite that
        // drops the YT-internals rationale fails loudly (parity with WP).
        self::assertStringContainsString('SourceTransform::repeatSource', $warning);
    }

    /**
     * Cross-platform divergence guard (CRITICAL): the bindingLevel='item'-
     * with-NO-item-child path returns an ARRAY-with-`error` on Joomla (the
     * Joomla runtime has no WP_Error class). This pins the documented
     * transport-shape divergence from the WP resolver so a future refactor
     * that re-points Joomla at the WP `WP_Error`-returning resolver — which
     * would fatal in the Joomla runtime — fails here instead of in production.
     */
    public function test_no_item_child_returns_array_error_envelope_not_wp_error(): void
    {
        $bareContainer = ['type' => 'grid', 'props' => [], 'children' => []];
        $res = self::resolve('item', $bareContainer, '/templates/tpl/layout/children/2');

        self::assertArrayHasKey(
            'error',
            $res,
            'Joomla resolver must surface no-item-child as an array `error` key (NOT a WP_Error).',
        );
        /** @var array<string, mixed> $error */
        $error = $res['error'];
        self::assertSame(400, $error['status'] ?? null);
        self::assertSame('yootheme_builder_mcp.source_binding.no_item_child', $error['code'] ?? null);
        self::assertStringContainsString('grid_item', (string) ($error['message'] ?? ''));
    }
}
