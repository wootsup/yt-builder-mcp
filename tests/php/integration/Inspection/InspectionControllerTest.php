<?php
/**
 * InspectionController — element-type discovery endpoint test.
 *
 * Wave-6 Fix 17: previously untested. Covers the two routes that the
 * MCP `yootheme_builder_element_types_list` + `..._get_schema` tools
 * call.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Integration\Inspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Inspection\InspectionController;
use WootsUp\BuilderMcp\Inspection\Inspector;
use WootsUp\BuilderMcp\Tests\TestVerifierFactory;

#[CoversClass(InspectionController::class)]
#[CoversClass(Inspector::class)]
final class InspectionControllerTest extends TestCase
{
    private function controller(): InspectionController
    {
        return new InspectionController(
            new Inspector(),
            TestVerifierFactory::verifier(),
        );
    }

    public function test_list_types_returns_element_types_payload(): void
    {
        $req = new \WP_REST_Request('GET', '/');
        $resp = $this->controller()->list_types($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        self::assertArrayHasKey('element_types', $data);
        self::assertIsArray($data['element_types']);
    }

    public function test_get_schema_404_for_unknown_type(): void
    {
        $req = new \WP_REST_Request('GET', '/');
        $req['type_name'] = 'definitely-not-a-real-element-type';
        $resp = $this->controller()->get_schema($req);
        self::assertInstanceOf(\WP_Error::class, $resp);
        /** @var \WP_Error $resp */
        $data = $resp->get_error_data();
        self::assertSame(404, $data['status']);
    }

    public function test_get_schema_returns_payload_for_fallback_type(): void
    {
        // Wave-2 Inspector returns [] for built-in fallback types like
        // 'headline'. Controller wraps that into a {type_name, schema} payload.
        $req = new \WP_REST_Request('GET', '/');
        $req['type_name'] = 'headline';
        $resp = $this->controller()->get_schema($req);
        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        self::assertSame('headline', $data['type_name']);
        self::assertArrayHasKey('schema', $data);
    }

    public function test_list_types_includes_built_in_headline(): void
    {
        // The fallback catalogue (Wave-2 baseline) must surface the
        // canonical element types so MCP clients can suggest valid values
        // for `element_type` in add-element calls.
        $req = new \WP_REST_Request('GET', '/');
        $resp = $this->controller()->list_types($req);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        self::assertContains('headline', $data['element_types']);
    }

    // -------------------------------------------------------------
    // F-03 — list_types emits structured items[] catalog.
    // -------------------------------------------------------------

    public function test_list_types_emits_structured_items_array(): void
    {
        $req = new \WP_REST_Request('GET', '/');
        $resp = $this->controller()->list_types($req);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        self::assertArrayHasKey('items', $data);
        self::assertArrayHasKey('total', $data);
        self::assertIsArray($data['items']);
        self::assertNotEmpty($data['items']);
        self::assertSame(count($data['items']), $data['total']);
        // Every item carries the F-03 shape.
        foreach ($data['items'] as $item) {
            self::assertArrayHasKey('name', $item);
            self::assertArrayHasKey('label', $item);
            self::assertArrayHasKey('origin', $item);
            self::assertArrayHasKey('has_children', $item);
            // F-03 v2: MCP-TS reads `has_children_support` as the canonical
            // column key (inspection-format.mapTypeRow). The REST envelope
            // surfaces both `has_children` (PHP-side / legacy) and
            // `has_children_support` (TS alias) — every row must carry both.
            self::assertArrayHasKey('has_children_support', $item);
            self::assertSame(
                $item['has_children'],
                $item['has_children_support'],
                "has_children and has_children_support must agree per row"
            );
        }
    }

    public function test_list_types_no_item_has_empty_label_or_origin(): void
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

    public function test_list_types_surfaces_item_children_with_has_children_true(): void
    {
        // Maria-Audit v2 F-03: the 16 *_item types from ItemContainerMap
        // must report has_children=true — they are valid binding targets
        // for the Multi-Items pattern and accept inner field-bindings.
        $req = new \WP_REST_Request('GET', '/');
        $resp = $this->controller()->list_types($req);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        $byName = [];
        foreach ($data['items'] as $item) {
            $byName[$item['name']] = $item;
        }
        foreach (\WootsUp\BuilderMcp\Elements\ItemContainerMap::MAP as $container => $item) {
            self::assertArrayHasKey($container, $byName, "Missing container '$container'");
            self::assertArrayHasKey($item, $byName, "Missing item-child '$item'");
            self::assertTrue(
                $byName[$container]['has_children'],
                "$container must report has_children=true (it accepts $item children)"
            );
            self::assertTrue(
                $byName[$item]['has_children'],
                "$item must report has_children=true (it accepts arbitrary inner elements)"
            );
        }
    }

    // -------------------------------------------------------------
    // F-05 — get_schema emits structured fields[] payload.
    // -------------------------------------------------------------

    public function test_get_schema_emits_structured_payload(): void
    {
        $req = new \WP_REST_Request('GET', '/');
        $req['type_name'] = 'section';
        $resp = $this->controller()->get_schema($req);
        /** @var \WP_REST_Response $resp */
        $data = $resp->get_data();
        self::assertSame('section', $data['type_name']);
        self::assertArrayHasKey('schema', $data);
        $schema = $data['schema'];
        self::assertSame('section', $schema['name']);
        self::assertSame('Section', $schema['label']);
        self::assertSame('builtin', $schema['origin']);
        self::assertTrue($schema['has_children']);
        self::assertIsArray($schema['fields']);
    }
}
