<?php
/**
 * InspectionController — REST endpoints for element-type discovery.
 *
 * Wave 2 Task 2.4. Routes:
 *
 *   GET /element-types
 *      → list of element-type names available on this site.
 *
 *   GET /element-types/{type_name}/schema
 *      → schema for a single element-type. Wave-2 stub returns [].
 *
 * Bearer-authenticated.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Inspection
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Inspection;

use WootsUp\BuilderMcp\Rest\RestController;

final class InspectionController extends RestController
{
    public function __construct(
        private readonly Inspector $inspector,
        \WootsUp\BuilderMcp\Auth\BearerVerifier $verifier,
    ) {
        parent::__construct($verifier);
    }

    public function register_routes(): void
    {
        $read = $this->bearer_permission_for('read');

        \register_rest_route(self::NAMESPACE, '/element-types', [
            'methods' => 'GET',
            'callback' => [$this, 'list_types'],
            'permission_callback' => $read,
        ]);

        \register_rest_route(self::NAMESPACE, '/element-types/(?P<type_name>[A-Za-z0-9_-]+)/schema', [
            'methods' => 'GET',
            'callback' => [$this, 'get_schema'],
            'permission_callback' => $read,
            'args' => [
                'type_name' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public function list_types(\WP_REST_Request $request): \WP_REST_Response
    {
        unset($request); // unused, signature required by WP REST API.
        // F-03: surface the full structured catalog [{name, label, origin,
        // has_children}, ...] under `items`. Keep `element_types` as the
        // legacy flat name-only list for back-compat with older MCP-TS
        // builds that may still read it.
        //
        // F-03 v2 (Maria-Audit Stream C2 2026-05-22): expose
        // `has_children_support` as an alias of `has_children` on every
        // row. MCP-TS `inspection-format.mapTypeRow` reads the
        // `has_children_support` key (its canonical column name); without
        // this alias every row landed in the table with CHILDREN="false",
        // including canonical containers like grid/section/tabs.
        $catalog = $this->inspector->listCatalog();
        $items = [];
        $names = [];
        foreach ($catalog as $entry) {
            $items[] = [
                'name' => $entry['name'],
                'label' => $entry['label'],
                'origin' => $entry['origin'],
                'has_children' => $entry['has_children'],
                // MCP-TS alias (read by inspection-format.mapTypeRow).
                'has_children_support' => $entry['has_children'],
            ];
            $names[] = $entry['name'];
        }
        return new \WP_REST_Response([
            'items' => $items,
            'total' => count($items),
            'element_types' => $names,
        ], 200);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_schema(\WP_REST_Request $request)
    {
        $type = (string) $request['type_name'];
        $schema = $this->inspector->schema($type);
        if ($schema === null) {
            return new \WP_Error(
                'yootheme_builder_mcp.inspection.unknown_type',
                sprintf('Element-type "%s" is not registered.', $type),
                ['status' => 404],
            );
        }
        return new \WP_REST_Response([
            'type_name' => $type,
            'schema' => $schema,
        ], 200);
    }
}
