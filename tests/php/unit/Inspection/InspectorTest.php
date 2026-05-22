<?php
/**
 * Inspector — element-type listing.
 *
 * Wave 2 Task 2.4. The Inspector returns a static fallback list when
 * YOOtheme is not present (the unit-test bootstrap doesn't load it) and
 * defers to YOOtheme's own type registry otherwise. Schema is a stub for
 * Wave 2 — Wave 3 will pull real introspection data.
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

    public function test_schema_returns_empty_stub_for_known_type(): void
    {
        $inspector = new Inspector();
        // Wave-2 contract: schema is empty for every type. Wave-3 will fill it.
        self::assertSame([], $inspector->schema('headline'));
        self::assertSame([], $inspector->schema('image'));
    }

    public function test_schema_returns_null_for_unknown_type(): void
    {
        $inspector = new Inspector();
        self::assertNull($inspector->schema('does-not-exist'));
    }
}
