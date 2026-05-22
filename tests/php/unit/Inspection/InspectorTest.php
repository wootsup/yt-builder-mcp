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
        $inspector = new Inspector();
        $byName = [];
        foreach ($inspector->listCatalog() as $entry) {
            $byName[$entry['name']] = $entry;
        }
        // Canonical containers must report has_children=true.
        foreach (['section', 'row', 'column', 'grid', 'panel', 'switcher', 'tabs'] as $container) {
            self::assertArrayHasKey($container, $byName, "Missing container type '$container'");
            self::assertTrue(
                $byName[$container]['has_children'],
                "$container must report has_children=true"
            );
        }
        // Canonical leaves must report has_children=false.
        foreach (['headline', 'text', 'image', 'button', 'divider', 'spacer'] as $leaf) {
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
}
