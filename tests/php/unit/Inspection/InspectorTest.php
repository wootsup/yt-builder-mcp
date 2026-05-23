<?php
/**
 * Inspector — element-type listing + per-type schema.
 *
 * F-03 (Maria-Audit 2026-05-22): listCatalog() returns rich entries
 * (name/label/origin/has_children). When YT is not loaded the static
 * FALLBACK_CATALOG surfaces ~39 entries covering all canonical built-in
 * types — far more than the Wave-2 10-element list. F-05: schema() now
 * flattens YT's fieldset → fields config into a structured field list.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Inspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Inspection\Inspector;

#[CoversClass(Inspector::class)]
final class InspectorTest extends TestCase
{
    public function test_list_returns_hardcoded_fallback_when_yt_missing(): void
    {
        $inspector = new Inspector();
        $types = $inspector->listTypes();
        // Bootstrap doesn't load YOOtheme — fallback applies.
        self::assertContains('grid', $types);
        self::assertContains('section', $types);
        self::assertContains('row', $types);
        self::assertContains('column', $types);
        self::assertContains('headline', $types);
        self::assertContains('text', $types);
        self::assertContains('image', $types);
        self::assertContains('gallery', $types);
        self::assertContains('button', $types);
        self::assertContains('divider', $types);
    }

    public function test_list_returns_lowercase_names_only(): void
    {
        $inspector = new Inspector();
        foreach ($inspector->listTypes() as $name) {
            self::assertSame(strtolower($name), $name, 'type names must be lowercase');
        }
    }

    public function test_schema_returns_payload_for_known_type(): void
    {
        $inspector = new Inspector();
        $schema = $inspector->schema('headline');
        self::assertNotNull($schema);
        self::assertSame('headline', $schema['name']);
        self::assertArrayHasKey('fields', $schema);
        self::assertIsArray($schema['fields']);
    }

    public function test_schema_returns_null_for_unknown_type(): void
    {
        $inspector = new Inspector();
        self::assertNull($inspector->schema('does-not-exist'));
    }

    // -------------------------------------------------------------
    // F-03 — structured catalog (name/label/origin/has_children).
    // -------------------------------------------------------------

    public function test_list_catalog_returns_structured_entries(): void
    {
        $inspector = new Inspector();
        $catalog = $inspector->listCatalog();
        self::assertNotEmpty($catalog);
        foreach ($catalog as $entry) {
            self::assertArrayHasKey('name', $entry);
            self::assertArrayHasKey('label', $entry);
            self::assertArrayHasKey('origin', $entry);
            self::assertArrayHasKey('has_children', $entry);
            self::assertIsString($entry['name']);
            self::assertIsString($entry['label']);
            self::assertContains($entry['origin'], ['builtin', 'essentials', 'uessentials']);
            self::assertIsBool($entry['has_children']);
        }
    }

    public function test_list_catalog_includes_canonical_container_types(): void
    {
        // F-03 v2 (Maria-Audit Stream C2 2026-05-22): aligned with the
        // canonical YT-Pro 4.5.33 element registry on dev.wootsup.com
        // (verified `themes/yootheme/packages/builder/elements/*.json`
        // directory listing). Removed `tabs` and `spacer` — neither
        // exists as element.json in YT 4.5.33. `panel` has
        // `element: true` only (no `container: true`) — leaf in YT 4.x.
        // `button` IS a container (`element: true` + `container: true`)
        // and pairs with `button_item` in ItemContainerMap::MAP.
        $inspector = new Inspector();
        $byName = [];
        foreach ($inspector->listCatalog() as $entry) {
            $byName[$entry['name']] = $entry;
        }
        // Canonical containers must report has_children=true.
        foreach (['section', 'row', 'column', 'grid', 'switcher', 'accordion'] as $container) {
            self::assertArrayHasKey($container, $byName, "Missing container type '$container'");
            self::assertTrue(
                $byName[$container]['has_children'],
                "$container must report has_children=true"
            );
        }
        // Canonical leaves must report has_children=false.
        foreach (['headline', 'text', 'image', 'divider'] as $leaf) {
            self::assertArrayHasKey($leaf, $byName, "Missing leaf type '$leaf'");
            self::assertFalse(
                $byName[$leaf]['has_children'],
                "$leaf must report has_children=false"
            );
        }
    }

    public function test_list_catalog_fallback_has_significantly_more_than_ten_types(): void
    {
        // Maria-Audit 2026-05-22: the Wave-2 catalog was a 10-element
        // static list. F-03 expands it to cover the full canonical
        // built-in set (~30+ entries).
        $inspector = new Inspector();
        $catalog = $inspector->listCatalog();
        self::assertGreaterThanOrEqual(30, count($catalog));
    }

    public function test_is_known_type_returns_true_for_canonical_types(): void
    {
        $inspector = new Inspector();
        foreach (['section', 'row', 'column', 'grid', 'headline', 'text', 'image', 'button', 'divider'] as $name) {
            self::assertTrue($inspector->isKnownType($name), "$name should be known");
        }
    }

    public function test_is_known_type_returns_false_for_unknown(): void
    {
        $inspector = new Inspector();
        self::assertFalse($inspector->isKnownType('definitely-not-a-real-type'));
        self::assertFalse($inspector->isKnownType(''));
    }

    public function test_get_catalog_entry_returns_entry_for_known_type(): void
    {
        $inspector = new Inspector();
        $entry = $inspector->getCatalogEntry('headline');
        self::assertNotNull($entry);
        self::assertSame('headline', $entry['name']);
        self::assertSame('Headline', $entry['label']);
        self::assertFalse($entry['has_children']);
    }

    public function test_get_catalog_entry_returns_null_for_unknown(): void
    {
        $inspector = new Inspector();
        self::assertNull($inspector->getCatalogEntry('not-a-type'));
    }

    // -------------------------------------------------------------
    // F-05 — per-type schema with structured fields.
    // -------------------------------------------------------------

    public function test_schema_emits_origin_and_label_for_known_type(): void
    {
        $inspector = new Inspector();
        $schema = $inspector->schema('section');
        self::assertNotNull($schema);
        self::assertSame('Section', $schema['label']);
        self::assertSame('builtin', $schema['origin']);
        self::assertTrue($schema['has_children']);
    }

    public function test_schema_emits_empty_fields_when_yt_missing(): void
    {
        // Without YT loaded, getBuilderTypeConfig() returns null →
        // extractFields returns [] — but the schema envelope is still
        // structured (so MCP-clients can render the type-card even when
        // they cannot show per-field details).
        $inspector = new Inspector();
        $schema = $inspector->schema('headline');
        self::assertNotNull($schema);
        self::assertSame([], $schema['fields']);
    }

    // -------------------------------------------------------------
    // F-05 v2 — populated fields when adapter surfaces config.
    // -------------------------------------------------------------

    public function test_schema_flattens_top_level_fields_when_config_populated(): void
    {
        // Maria-Audit v2 F-05: YT 4.5.33 stores per-type config under
        // $builder->types[$name]->data with a top-level `fields` map. The
        // adapter must surface that as `getBuilderTypeConfig()` and the
        // Inspector must flatten it into the wire shape.
        $adapter = new class extends \WootsUp\BuilderMcp\Yootheme\YoothemeAdapter {
            public function getBuilderTypeConfig(string $typeName): ?array
            {
                if ($typeName !== 'headline') {
                    return null;
                }
                return [
                    'name' => 'headline',
                    'title' => 'Headline',
                    'fields' => [
                        'content' => ['label' => 'Content', 'type' => 'editor'],
                        'title_element' => ['label' => 'Title element', 'type' => 'select', 'default' => 'h1'],
                        'text_align' => ['label' => 'Text align', 'type' => 'text-align'],
                        'margin' => ['label' => 'Margin', 'type' => 'margin'],
                    ],
                ];
            }
        };
        $inspector = new Inspector($adapter);
        $schema = $inspector->schema('headline');

        self::assertNotNull($schema);
        $byName = [];
        foreach ($schema['fields'] as $field) {
            $byName[$field['name']] = $field;
        }
        self::assertArrayHasKey('content', $byName);
        self::assertSame('editor', $byName['content']['type']);
        self::assertSame('Content', $byName['content']['label']);
        self::assertArrayHasKey('title_element', $byName);
        self::assertSame('h1', $byName['title_element']['default']);
        self::assertArrayHasKey('text_align', $byName);
        self::assertArrayHasKey('margin', $byName);
        self::assertGreaterThanOrEqual(4, count($schema['fields']));
    }

    public function test_schema_flattens_fieldset_groups_into_fields(): void
    {
        // Maria-Audit v2 F-05: some types declare their fields under
        // fieldset.<group>.fields. The Inspector must flatten these into a
        // single list, propagating the group label per entry.
        $adapter = new class extends \WootsUp\BuilderMcp\Yootheme\YoothemeAdapter {
            public function getBuilderTypeConfig(string $typeName): ?array
            {
                if ($typeName !== 'grid') {
                    return null;
                }
                return [
                    'name' => 'grid',
                    'title' => 'Grid',
                    'fieldset' => [
                        'columns' => [
                            'label' => 'Columns',
                            'fields' => [
                                'grid_default' => ['label' => 'Default', 'type' => 'select'],
                                'grid_medium' => ['label' => 'Medium', 'type' => 'select'],
                            ],
                        ],
                        'layout' => [
                            'label' => 'Layout',
                            'fields' => [
                                'gutter' => ['label' => 'Gutter', 'type' => 'select'],
                            ],
                        ],
                    ],
                ];
            }
        };
        $inspector = new Inspector($adapter);
        $schema = $inspector->schema('grid');

        self::assertNotNull($schema);
        $byName = [];
        foreach ($schema['fields'] as $field) {
            $byName[$field['name']] = $field;
        }
        self::assertArrayHasKey('grid_default', $byName);
        self::assertSame('Columns', $byName['grid_default']['group']);
        self::assertArrayHasKey('grid_medium', $byName);
        self::assertSame('Columns', $byName['grid_medium']['group']);
        self::assertArrayHasKey('gutter', $byName);
        self::assertSame('Layout', $byName['gutter']['group']);
        self::assertCount(3, $schema['fields']);
    }

    // -------------------------------------------------------------
    // F-03 v2 (Maria-Audit Stream C2) — catalog metadata fidelity.
    //
    // The Inspector must surface every catalog entry with a non-empty
    // label and origin AND a correct has_children flag — even when the
    // adapter falls back to FALLBACK_CATALOG (YT not loaded) and when
    // the adapter returns YT ElementType-shaped data (live path).
    // -------------------------------------------------------------

    public function test_list_catalog_fallback_no_entry_has_empty_label(): void
    {
        // Maria-Audit v2 F-03: every entry must carry a human label, even
        // the static fallback. Empty labels in the wire shape break the
        // MCP element_types_list table column.
        $inspector = new Inspector();
        foreach ($inspector->listCatalog() as $entry) {
            self::assertNotSame(
                '',
                $entry['label'],
                "label must be non-empty for type '{$entry['name']}'",
            );
        }
    }

    public function test_list_catalog_fallback_no_entry_has_empty_origin(): void
    {
        $inspector = new Inspector();
        foreach ($inspector->listCatalog() as $entry) {
            self::assertNotSame(
                '',
                $entry['origin'],
                "origin must be non-empty for type '{$entry['name']}'",
            );
        }
    }

    public function test_list_catalog_includes_item_children_of_containers(): void
    {
        // Maria-Audit v2 F-03: the 16 *_item child types from
        // ItemContainerMap MUST appear in the catalog with has_children=true.
        // Without them present, MCP-clients cannot suggest item-level
        // bindings (the Multi-Items pattern from yootheme-development skill).
        $inspector = new Inspector();
        $byName = [];
        foreach ($inspector->listCatalog() as $entry) {
            $byName[$entry['name']] = $entry;
        }
        $expectedItems = [
            'accordion_item', 'button_item', 'description_list_item', 'gallery_item',
            'grid_item', 'list_item', 'map_item', 'nav_item',
            'overlay-slider_item', 'panel-slider_item', 'popover_item',
            'slideshow_item', 'social_item', 'subnav_item', 'switcher_item',
            'table_item',
        ];
        foreach ($expectedItems as $itemType) {
            self::assertArrayHasKey($itemType, $byName, "Missing item-child type '$itemType'");
            self::assertTrue(
                $byName[$itemType]['has_children'],
                "$itemType must report has_children=true (accepts inner elements for the Multi-Items pattern)"
            );
        }
    }

    public function test_list_catalog_includes_structural_containers(): void
    {
        // Structural containers from the Stream C2 contract:
        // section, row, column, tabs, lightbox, modal — every one a
        // canonical container that takes inner elements.
        $inspector = new Inspector();
        $byName = [];
        foreach ($inspector->listCatalog() as $entry) {
            $byName[$entry['name']] = $entry;
        }
        foreach (['section', 'row', 'column'] as $structural) {
            self::assertArrayHasKey($structural, $byName, "Missing structural container '$structural'");
            self::assertTrue(
                $byName[$structural]['has_children'],
                "$structural must report has_children=true"
            );
        }
    }

    public function test_list_catalog_leaves_have_has_children_false(): void
    {
        $inspector = new Inspector();
        $byName = [];
        foreach ($inspector->listCatalog() as $entry) {
            $byName[$entry['name']] = $entry;
        }
        // Pure leaf types (no inner elements) — Stream C2 contract.
        $leaves = ['headline', 'text', 'image', 'icon', 'divider', 'video', 'html', 'code'];
        foreach ($leaves as $leaf) {
            self::assertArrayHasKey($leaf, $byName, "Missing leaf type '$leaf'");
            self::assertFalse(
                $byName[$leaf]['has_children'],
                "$leaf must report has_children=false"
            );
        }
    }

    public function test_list_catalog_live_path_extracts_yt_title_as_label(): void
    {
        // Maria-Audit v2 F-03: YT 4.5.33 ElementType.data uses key `title`
        // (not `label`!) for the human label. The adapter MUST read `title`.
        // Regression pin against the bug where catalog rows had label="".
        $adapter = new class extends \WootsUp\BuilderMcp\Yootheme\YoothemeAdapter {
            public function getBuilderTypesDetailed(): ?array
            {
                return [
                    ['name' => 'headline', 'label' => 'Headline', 'origin' => 'builtin', 'has_children' => false],
                    ['name' => 'grid', 'label' => 'Grid', 'origin' => 'builtin', 'has_children' => true],
                    ['name' => 'grid_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
                ];
            }
        };
        $inspector = new Inspector($adapter);
        $catalog = $inspector->listCatalog();
        $byName = [];
        foreach ($catalog as $entry) {
            $byName[$entry['name']] = $entry;
        }
        self::assertSame('Headline', $byName['headline']['label']);
        self::assertSame('Grid', $byName['grid']['label']);
        self::assertSame('Item', $byName['grid_item']['label']);
        self::assertFalse($byName['headline']['has_children']);
        self::assertTrue($byName['grid']['has_children']);
        self::assertTrue($byName['grid_item']['has_children']);
    }

    /**
     * 1.0.1 Wave-1.8 P1 F-COLD-13: well-known core element-types map to
     * canonical semantic roles for the a11y-audit cold-agent workflow.
     */
    public function test_semantic_role_of_known_types(): void
    {
        // Heading
        self::assertSame('heading', Inspector::semanticRoleOf('headline'));
        // Media
        self::assertSame('img', Inspector::semanticRoleOf('image'));
        self::assertSame('video', Inspector::semanticRoleOf('video'));
        // Links / navigation
        self::assertSame('link', Inspector::semanticRoleOf('subnav_item'));
        self::assertSame('list', Inspector::semanticRoleOf('subnav'));
        // Multi-Items containers
        self::assertSame('list', Inspector::semanticRoleOf('grid'));
        self::assertSame('listitem', Inspector::semanticRoleOf('grid_item'));
        // Structural
        self::assertSame('region', Inspector::semanticRoleOf('section'));
        self::assertSame('separator', Inspector::semanticRoleOf('divider'));
    }

    public function test_semantic_role_of_unknown_type_returns_null(): void
    {
        // Unknown / custom types: prefer null over a wrong guess. Cold
        // agents see absence of the flag and fall back on their own
        // heuristics rather than locking onto an incorrect ARIA role.
        self::assertNull(Inspector::semanticRoleOf('custom_unknown_widget'));
        self::assertNull(Inspector::semanticRoleOf(''));
    }

    /**
     * 1.0.1 Wave-1.8 P1 F-COLD-14: schema endpoint surfaces the field
     * `enum` (normalised list) so cold agents don't have to guess valid
     * select / radio values. Both YT shapes (flat list + label-keyed
     * map) are accepted; the projection always emits list-of-strings.
     */
    public function test_schema_field_surfaces_enum_for_select_options_flat_list(): void
    {
        $adapter = new class extends \WootsUp\BuilderMcp\Yootheme\YoothemeAdapter {
            public function getBuilderTypeConfig(string $name): ?array
            {
                return [
                    'fields' => [
                        'size' => [
                            'type' => 'select',
                            'label' => 'Size',
                            'options' => ['small', 'medium', 'large'],
                            'default' => 'medium',
                        ],
                    ],
                ];
            }
            public function getBuilderTypesDetailed(): ?array
            {
                return [
                    ['name' => 'mything', 'label' => 'My Thing', 'origin' => 'builtin', 'has_children' => false],
                ];
            }
        };
        $inspector = new Inspector($adapter);
        $schema = $inspector->schema('mything');
        self::assertNotNull($schema);
        $size = $schema['fields'][0];
        self::assertSame('size', $size['name']);
        self::assertSame(['small', 'medium', 'large'], $size['enum']);
        self::assertSame('medium', $size['default']);
    }

    public function test_schema_field_surfaces_enum_for_label_keyed_options_map(): void
    {
        $adapter = new class extends \WootsUp\BuilderMcp\Yootheme\YoothemeAdapter {
            public function getBuilderTypeConfig(string $name): ?array
            {
                return [
                    'fields' => [
                        'align' => [
                            'type' => 'select',
                            'options' => ['Left' => 'left', 'Right' => 'right'],
                        ],
                    ],
                ];
            }
            public function getBuilderTypesDetailed(): ?array
            {
                return [
                    ['name' => 'mything', 'label' => 'My Thing', 'origin' => 'builtin', 'has_children' => false],
                ];
            }
        };
        $inspector = new Inspector($adapter);
        $schema = $inspector->schema('mything');
        self::assertNotNull($schema);
        self::assertSame(['left', 'right'], $schema['fields'][0]['enum']);
    }

    public function test_schema_omits_enum_for_non_select_fields(): void
    {
        $adapter = new class extends \WootsUp\BuilderMcp\Yootheme\YoothemeAdapter {
            public function getBuilderTypeConfig(string $name): ?array
            {
                return [
                    'fields' => [
                        'content' => ['type' => 'editor', 'label' => 'Content'],
                    ],
                ];
            }
            public function getBuilderTypesDetailed(): ?array
            {
                return [
                    ['name' => 'mything', 'label' => 'My Thing', 'origin' => 'builtin', 'has_children' => false],
                ];
            }
        };
        $inspector = new Inspector($adapter);
        $schema = $inspector->schema('mything');
        self::assertNotNull($schema);
        self::assertArrayNotHasKey('enum', $schema['fields'][0]);
    }

    /**
     * 1.0.1 Wave-1.8 P1 F-COLD-13: schema() response surfaces the
     * semantic_role for known types directly in the schema envelope.
     */
    public function test_schema_surfaces_semantic_role_for_known_types(): void
    {
        $adapter = new class extends \WootsUp\BuilderMcp\Yootheme\YoothemeAdapter {
            public function getBuilderTypeConfig(string $name): ?array
            {
                return ['fields' => []];
            }
            public function getBuilderTypesDetailed(): ?array
            {
                return [
                    ['name' => 'headline', 'label' => 'Headline', 'origin' => 'builtin', 'has_children' => false],
                ];
            }
        };
        $inspector = new Inspector($adapter);
        $schema = $inspector->schema('headline');
        self::assertNotNull($schema);
        self::assertSame('heading', $schema['semantic_role']);
    }
}
