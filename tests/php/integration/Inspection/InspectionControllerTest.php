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
}
