<?php
/**
 * Inspector — element-type catalog.
 *
 * Wave 2 Task 2.4. Provides MCP clients with the list of available element
 * types they can compose into a layout, plus a (Wave-2 stubbed) per-type
 * schema. When YOOtheme Pro is loaded the Inspector defers to the
 * theme-registered Builder type registry; otherwise it returns the small
 * static fallback list of "always-present" types so unit-tests and
 * MCP-client-side help screens still work.
 *
 * Wave 3 will replace the empty-array schema with real introspection from
 * `\YOOtheme\Builder::getType($name)->getFields()`.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Inspection
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Inspection;

use WootsUp\BuilderMcp\Yootheme\YoothemeAdapter;

final class Inspector
{
    private ?YoothemeAdapter $yt = null;

    public function __construct(?YoothemeAdapter $yootheme = null)
    {
        $this->yt = $yootheme;
    }

    /**
     * Static fallback list — covers the always-present built-in types so
     * the MCP-Setup-Wizard can produce meaningful help even when YOOtheme
     * is not (yet) installed, and so the unit-tests don't need to mount
     * the full theme.
     *
     * @var list<string>
     */
    private const FALLBACK_TYPES = [
        'grid',
        'section',
        'row',
        'column',
        'headline',
        'text',
        'image',
        'gallery',
        'button',
        'divider',
    ];

    /**
     * Return the list of element-type names that can be composed into a
     * Builder layout.
     *
     * @return list<string>
     */
    public function listTypes(): array
    {
        $live = $this->yootheme()->getBuilderTypes();
        if ($live !== null) {
            return $live;
        }
        return self::FALLBACK_TYPES;
    }

    /**
     * Lazy adapter accessor — keeps test-mocking trivial.
     */
    private function yootheme(): YoothemeAdapter
    {
        return $this->yt ??= new YoothemeAdapter();
    }

    /**
     * Return the schema for the given element type, or null if the type is
     * unknown. Wave-2 returns an empty array for every known type — the
     * shape is `array<string, mixed>` so Wave-3 can fill it without
     * breaking the contract.
     *
     * @return array<string, mixed>|null
     */
    public function schema(string $typeName): ?array
    {
        if (!in_array($typeName, $this->listTypes(), true)) {
            return null;
        }
        // Wave-2 stub. Wave-3: real introspection.
        return [];
    }

}
