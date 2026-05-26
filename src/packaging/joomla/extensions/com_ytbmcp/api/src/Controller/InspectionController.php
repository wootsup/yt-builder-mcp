<?php
/**
 * InspectionController — REST endpoints under
 * `yt-builder-mcp/v1/element-types`.
 *
 * Cookbook §3.2 Routes 24-25 + §3.4.21-§3.4.22. Two read-scope endpoints:
 *
 *   GET /v1/element-types               → catalog (cookbook §3.4.21)
 *   GET /v1/element-types/:typeName/schema → schema (cookbook §3.4.22)
 *
 * Pure read-only — no state mutation, no ETag. Delegates to the shared
 * {@see Inspector} domain class which speaks both YT's live registry
 * (when YT is bootstrapped) and the static fallback catalogue (cold
 * REST start, unit-test harness).
 *
 * Cookbook §3.7.1 cross-platform parity: response shape matches the
 * WP-side byte-for-byte — `items` (structured rows with
 * `has_children_support` MCP-TS alias + optional `semantic_role`),
 * `total`, and the legacy `element_types` flat name list.
 *
 * @package    WootsUp\Component\Ytbmcp\Api\Controller
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Component\Ytbmcp\Api\Controller;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Inspection\Inspector;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\AbstractApiController;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaJsonResponse;

final class InspectionController extends AbstractApiController
{
    /**
     * GET /v1/element-types — full catalog of registered element types.
     *
     * Cookbook §3.4.21 + F-03 (Maria-Audit). Each row carries:
     *   - `name`                  — canonical YT element id
     *   - `label`                 — human-readable display string
     *   - `origin`                — registry source (yootheme/essentials/custom)
     *   - `has_children`          — whether the element accepts children
     *   - `has_children_support`  — alias of `has_children` (MCP-TS canonical
     *                                column name)
     *   - `semantic_role`         — optional ARIA role hint (F-COLD-13)
     *
     * The legacy flat `element_types` list is also emitted for
     * back-compat with pre-1.0.1 MCP clients.
     */
    public function listTypes(): void
    {
        $this->dispatch('read', function (array $claims): void {
            unset($claims);

            $inspector = new Inspector();
            $catalog   = $inspector->listCatalog();
            $items = [];
            $names = [];
            foreach ($catalog as $entry) {
                $row = [
                    'name'                 => $entry['name'],
                    'label'                => $entry['label'],
                    'origin'               => $entry['origin'],
                    'has_children'         => $entry['has_children'],
                    // MCP-TS alias — `inspection-format.mapTypeRow` reads
                    // `has_children_support` for its CHILDREN column.
                    'has_children_support' => $entry['has_children'],
                ];
                $role = Inspector::semanticRoleOf($entry['name']);
                if ($role !== null) {
                    $row['semantic_role'] = $role;
                }
                $items[] = $row;
                $names[] = $entry['name'];
            }
            JoomlaJsonResponse::send($this->app(), [
                'items'         => $items,
                'total'         => \count($items),
                'element_types' => $names,
            ], 200);
        });
    }

    /**
     * GET /v1/element-types/:typeName/schema — element-type schema.
     *
     * Cookbook §3.4.22. Returns the schema array for the requested
     * element-type or a structured 404 when the type isn't registered.
     */
    public function getSchema(): void
    {
        $this->dispatch('read', function (array $claims): void {
            unset($claims);

            $type = $this->input?->getString('typeName', '');
            $type = \is_string($type) ? \trim($type) : '';
            if ($type === '') {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.inspection.invalid_request',
                    'typeName is required.',
                    400,
                );
                return;
            }

            $inspector = new Inspector();
            $schema    = $inspector->schema($type);
            if ($schema === null) {
                JoomlaJsonResponse::error(
                    $this->app(),
                    'yootheme_builder_mcp.inspection.unknown_type',
                    \sprintf('Element-type "%s" is not registered.', $type),
                    404,
                );
                return;
            }

            JoomlaJsonResponse::send($this->app(), [
                'type_name' => $type,
                'schema'    => $schema,
            ], 200);
        });
    }
}
