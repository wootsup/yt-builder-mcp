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
        $catalog = $this->inspector->listCatalog();
        $names = [];
        foreach ($catalog as $entry) {
            $names[] = $entry['name'];
        }
        return new \WP_REST_Response([
            'items' => $catalog,
            'total' => count($catalog),
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
