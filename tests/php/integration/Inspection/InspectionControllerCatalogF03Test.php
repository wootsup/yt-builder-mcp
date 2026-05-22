<?php
/**
 * InspectionController catalog — F-03 v2 (Maria-Audit Stream C2).
 *
 * Pins the wire shape of `GET /element-types`:
 *
 *   {
 *     items: [
 *       {
 *         name, label, origin, has_children, has_children_support, group?
 *       }, ...
 *     ],
 *     total: <int>,
 *     element_types: [<string>, ...]   // legacy flat list (back-compat)
 *   }
 *
 * Three regression pins target the audit findings:
 *
 *   1. No row carries an empty `label` or `origin`.
 *   2. Every canonical container/item pair from `ItemContainerMap::MAP`
 *      surfaces with `has_children=true`.
 *   3. The MCP-TS reader (inspection-format.mapTypeRow) keys on
 *      `has_children_support`; the REST envelope MUST surface that
 *      key as an alias of `has_children` on every row.
 *
 * Separate file from `InspectionControllerTest.php` to avoid parallel-
 * subagent file collisions during the Stream C1/C2/C3 sweep.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Integration\Inspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Elements\ItemContainerMap;
use WootsUp\BuilderMcp\Inspection\InspectionController;
use WootsUp\BuilderMcp\Inspection\Inspector;
use WootsUp\BuilderMcp\Tests\TestVerifierFactory;

#[CoversClass(InspectionController::class)]
#[CoversClass(Inspector::class)]
final class InspectionControllerCatalogF03Test extends TestCase
{
    private function controller(): InspectionController
    {
        return new InspectionController(
            new Inspector(),
            TestVerifierFactory::verifier(),
        );
    }

    public function test_no_item_has_empty_label_or_origin(): void
    {
        // Maria-Audit v2 F-03: regression pin against the audit-finding
        // "label: """ / "origin: """ on the live REST.
        $req = new \WP_REST_Request('GET', '/');
        $resp = $this->controller()->list_types($req);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        foreach ($data['items'] as $item) {
            self::assertNotSame('', $item['label'], "label empty for '{$item['name']}'");
            self::assertNotSame('', $item['origin'], "origin empty for '{$item['name']}'");
        }
    }

    public function test_every_row_surfaces_has_children_support_alias(): void
    {
        // F-03 v2: MCP-TS inspection-format.mapTypeRow reads
        // `has_children_support` as the canonical column key (not
        // `has_children`). The REST envelope surfaces both keys for every
        // row so the MCP-TS layer reads the correct value.
        $req = new \WP_REST_Request('GET', '/');
        $resp = $this->controller()->list_types($req);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        foreach ($data['items'] as $item) {
            self::assertArrayHasKey(
                'has_children_support',
                $item,
                "has_children_support alias missing for '{$item['name']}'"
            );
            self::assertSame(
                $item['has_children'],
                $item['has_children_support'],
                "has_children and has_children_support must agree per row '{$item['name']}'"
            );
        }
    }

    public function test_canonical_container_item_pairs_have_children(): void
    {
        // Maria-Audit v2 F-03: the 16 *_item types from ItemContainerMap
        // must surface with has_children=true — they are valid binding
        // targets for the Multi-Items pattern and accept inner field-
        // bindings/elements.
        $req = new \WP_REST_Request('GET', '/');
        $resp = $this->controller()->list_types($req);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        $byName = [];
        foreach ($data['items'] as $item) {
            $byName[$item['name']] = $item;
        }
        foreach (ItemContainerMap::MAP as $container => $itemType) {
            self::assertArrayHasKey($container, $byName, "Missing container '$container'");
            self::assertArrayHasKey($itemType, $byName, "Missing item-child '$itemType'");
            self::assertTrue(
                $byName[$container]['has_children'],
                "$container must report has_children=true (accepts $itemType children)"
            );
            self::assertTrue(
                $byName[$itemType]['has_children'],
                "$itemType must report has_children=true (Multi-Items pattern target)"
            );
        }
    }

    public function test_structural_containers_have_children(): void
    {
        $req = new \WP_REST_Request('GET', '/');
        $resp = $this->controller()->list_types($req);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        $byName = [];
        foreach ($data['items'] as $item) {
            $byName[$item['name']] = $item;
        }
        foreach (['section', 'row', 'column'] as $structural) {
            self::assertArrayHasKey($structural, $byName, "Missing structural container '$structural'");
            self::assertTrue(
                $byName[$structural]['has_children'],
                "$structural must report has_children=true"
            );
        }
    }
}
