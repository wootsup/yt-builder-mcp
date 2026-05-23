<?php
/**
 * Inspector — element-type catalog + per-type schema.
 *
 * F-03 (Maria-Audit 2026-05-22): listTypes() now returns the FULL element
 * registry — built-ins + YOOessentials + uEssentials — by delegating to
 * YoothemeAdapter::getBuilderTypesDetailed(). Each entry carries:
 *   - name:         registry key
 *   - label:        human label
 *   - origin:       'builtin' / 'essentials' / 'uessentials'
 *   - has_children: true for container types
 *
 * F-05 (Maria-Audit 2026-05-22): schema() introspects the per-type config
 * (fieldset → fields) and returns a flat list of field-defs the element
 * accepts, so MCP-clients can show prop-pickers / validate writes.
 *
 * The Wave-2 fallback list (the 10-element static catalogue) is kept as
 * defense-in-depth: when YOOtheme is not loaded (unit-test bootstrap) or
 * the registry is unreachable, the Inspector still surfaces the canonical
 * built-in shape with origin='builtin'.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Inspection
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Inspection;

use WootsUp\BuilderMcp\Elements\ItemContainerMap;
use WootsUp\BuilderMcp\Yootheme\YoothemeAdapter;

final class Inspector
{
    private ?YoothemeAdapter $yt = null;

    public function __construct(?YoothemeAdapter $yootheme = null)
    {
        $this->yt = $yootheme;
    }

    /**
     * Static fallback catalogue — the canonical YOOtheme built-in element
     * types. Used when the live registry is unreachable (unit-test
     * bootstrap without YT loaded, or YT::Builder missing).
     *
     * Container types (those that accept inner elements) are marked.
     *
     * @var list<array{name: string, label: string, origin: string, has_children: bool}>
     */
    private const FALLBACK_CATALOG = [
        // ── Structural containers (YT 4.5.33 — always accept children) ──
        ['name' => 'section', 'label' => 'Section', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'row', 'label' => 'Row', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'column', 'label' => 'Column', 'origin' => 'builtin', 'has_children' => true],
        // ── Multi-item containers (16 pairs, mirrors ItemContainerMap::MAP) ──
        ['name' => 'accordion', 'label' => 'Accordion', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'accordion_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'button', 'label' => 'Button', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'button_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'description_list', 'label' => 'Description List', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'description_list_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'gallery', 'label' => 'Gallery', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'gallery_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'grid', 'label' => 'Grid', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'grid_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'list', 'label' => 'List', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'list_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'map', 'label' => 'Map', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'map_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'nav', 'label' => 'Nav', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'nav_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'overlay-slider', 'label' => 'Overlay Slider', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'overlay-slider_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'panel-slider', 'label' => 'Panel Slider', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'panel-slider_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'popover', 'label' => 'Popover', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'popover_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'slideshow', 'label' => 'Slideshow', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'slideshow_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'social', 'label' => 'Social', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'social_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'subnav', 'label' => 'Subnav', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'subnav_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'switcher', 'label' => 'Switcher', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'switcher_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'table', 'label' => 'Table', 'origin' => 'builtin', 'has_children' => true],
        ['name' => 'table_item', 'label' => 'Item', 'origin' => 'builtin', 'has_children' => true],
        // ── Pure leaf elements (no inner elements) ──
        ['name' => 'alert', 'label' => 'Alert', 'origin' => 'builtin', 'has_children' => false],
        ['name' => 'code', 'label' => 'Code', 'origin' => 'builtin', 'has_children' => false],
        ['name' => 'countdown', 'label' => 'Countdown', 'origin' => 'builtin', 'has_children' => false],
        ['name' => 'divider', 'label' => 'Divider', 'origin' => 'builtin', 'has_children' => false],
        ['name' => 'fragment', 'label' => 'Fragment', 'origin' => 'builtin', 'has_children' => false],
        ['name' => 'headline', 'label' => 'Headline', 'origin' => 'builtin', 'has_children' => false],
        ['name' => 'html', 'label' => 'HTML', 'origin' => 'builtin', 'has_children' => false],
        ['name' => 'icon', 'label' => 'Icon', 'origin' => 'builtin', 'has_children' => false],
        ['name' => 'image', 'label' => 'Image', 'origin' => 'builtin', 'has_children' => false],
        ['name' => 'layout', 'label' => 'Layout', 'origin' => 'builtin', 'has_children' => false],
        ['name' => 'overlay', 'label' => 'Overlay', 'origin' => 'builtin', 'has_children' => false],
        ['name' => 'panel', 'label' => 'Panel', 'origin' => 'builtin', 'has_children' => false],
        ['name' => 'quotation', 'label' => 'Quotation', 'origin' => 'builtin', 'has_children' => false],
        ['name' => 'text', 'label' => 'Text', 'origin' => 'builtin', 'has_children' => false],
        ['name' => 'totop', 'label' => 'Totop', 'origin' => 'builtin', 'has_children' => false],
        ['name' => 'video', 'label' => 'Video', 'origin' => 'builtin', 'has_children' => false],
    ];

    /**
     * Return the structured element-type catalogue.
     *
     * F-03 wire shape:
     *   [{name, label, origin, has_children}, ...]
     *
     * @return list<array{name: string, label: string, origin: string, has_children: bool}>
     */
    public function listCatalog(): array
    {
        $live = $this->yootheme()->getBuilderTypesDetailed();
        if ($live !== null && $live !== []) {
            return $live;
        }
        return self::FALLBACK_CATALOG;
    }

    /**
     * Return the list of element-type names (back-compat with Wave-2 callers).
     * F-03: same source as listCatalog() — guaranteed agreement.
     *
     * @return list<string>
     */
    public function listTypes(): array
    {
        $out = [];
        foreach ($this->listCatalog() as $entry) {
            $out[] = $entry['name'];
        }
        return $out;
    }

    /**
     * Return true if `$typeName` is a registered element type. Used by
     * F-11 (element_add input validation) to reject unknown types with
     * a structured 400 instead of letting an unknown-type element land
     * in the layout tree.
     */
    public function isKnownType(string $typeName): bool
    {
        return in_array($typeName, $this->listTypes(), true);
    }

    /**
     * Return the catalogue entry for `$typeName`, or null if unknown.
     *
     * @return array{name: string, label: string, origin: string, has_children: bool}|null
     */
    public function getCatalogEntry(string $typeName): ?array
    {
        foreach ($this->listCatalog() as $entry) {
            if ($entry['name'] === $typeName) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Return the per-type schema (props/fields the element accepts).
     *
     * F-05 wire shape:
     *   {
     *     name:    type name (echoes input),
     *     label:   human label,
     *     origin:  'builtin' / 'essentials' / 'uessentials',
     *     has_children: bool,
     *     fields:  [
     *       {name, type, label?, default?, group?},
     *       ...
     *     ]
     *   }
     *
     * Returns null when the type is unknown to both the live registry
     * AND the static fallback catalogue.
     *
     * @return array<string, mixed>|null
     */
    public function schema(string $typeName): ?array
    {
        $entry = $this->getCatalogEntry($typeName);
        if ($entry === null) {
            return null;
        }

        $config = $this->yootheme()->getBuilderTypeConfig($typeName);
        return [
            'name' => $typeName,
            'label' => $entry['label'],
            'origin' => $entry['origin'],
            'has_children' => $entry['has_children'],
            // 1.0.1 Wave-1.8 P1 F-COLD-13: surface the WAI-ARIA-flavoured
            // semantic role for this element-type so cold a11y-audit
            // agents (S6 scenario) don't have to fall back on folklore
            // mapping ("is headline a heading? subnav_item a link?").
            // Known types only — unknown types omit the field rather
            // than emit a wrong guess.
            ...(($role = self::semanticRoleOf($typeName)) !== null
                ? ['semantic_role' => $role]
                : []),
            'fields' => self::extractFields($config),
        ];
    }

    /**
     * 1.0.1 Wave-1.8 P1 F-COLD-13: small static map from YT element-type
     * name → WAI-ARIA-flavoured semantic role. Only the well-known core
     * types are mapped; everything else returns null (silent) so a
     * cold-agent doesn't lock onto a wrong assumption.
     *
     * Values are deliberately a small enum: `heading`, `link`, `img`,
     * `region`, `list`, `listitem`, `separator`, `button`, `video`,
     * `text`, `none`. These mirror ARIA roles modulo the element-types
     * YT exposes — full ARIA taxonomy would over-specify for the cold-
     * agent use-case.
     */
    public static function semanticRoleOf(string $typeName): ?string
    {
        static $map = null;
        if ($map === null) {
            $map = [
                // Headings + text
                'headline' => 'heading',
                'text' => 'text',
                // Media
                'image' => 'img',
                'video' => 'video',
                'icon' => 'img',
                // Links / navigation
                'button' => 'link',
                'button_item' => 'link',
                'nav' => 'list',
                'nav_item' => 'link',
                'subnav' => 'list',
                'subnav_item' => 'link',
                // Container regions
                'section' => 'region',
                'row' => 'region',
                'column' => 'region',
                // Multi-Items containers + items (item is the row)
                'accordion' => 'list',
                'accordion_item' => 'listitem',
                'gallery' => 'list',
                'gallery_item' => 'listitem',
                'grid' => 'list',
                'grid_item' => 'listitem',
                'list' => 'list',
                'list_item' => 'listitem',
                'slideshow' => 'list',
                'slideshow_item' => 'listitem',
                'slider' => 'list',
                'overlay-slider' => 'list',
                'overlay-slider_item' => 'listitem',
                'panel-slider' => 'list',
                'panel-slider_item' => 'listitem',
                'switcher' => 'list',
                'switcher_item' => 'listitem',
                'social' => 'list',
                'social_item' => 'link',
                'popover' => 'list',
                'popover_item' => 'listitem',
                'description_list' => 'list',
                'description_list_item' => 'listitem',
                'table' => 'list',
                'table_item' => 'listitem',
                'map' => 'region',
                'map_item' => 'listitem',
                'pagination' => 'list',
                // Structural / decorative
                'divider' => 'separator',
                'spacer' => 'none',
                'code' => 'none',
                'html' => 'none',
            ];
        }
        return $map[$typeName] ?? null;
    }

    /**
     * Lazy adapter accessor — keeps test-mocking trivial.
     */
    private function yootheme(): YoothemeAdapter
    {
        return $this->yt ??= new YoothemeAdapter();
    }

    /**
     * Flatten a YT type config's `fieldset.*.fields` into a flat field list.
     * Returns the empty list when config is null / has no fieldset.
     *
     * @param array<string, mixed>|null $config
     * @return list<array{name: string, type: string, label?: string, default?: mixed, group?: string}>
     */
    private static function extractFields(?array $config): array
    {
        if ($config === null) {
            return [];
        }
        $out = [];
        // Shape A: top-level `fields` map.
        if (isset($config['fields']) && is_array($config['fields'])) {
            foreach ($config['fields'] as $fieldName => $fieldDef) {
                if (!is_string($fieldName) || !is_array($fieldDef)) {
                    continue;
                }
                /** @var array<string, mixed> $fieldDef */
                $out[] = self::projectField($fieldName, $fieldDef, null);
            }
        }
        // Shape B: `fieldset.<group>.fields` map.
        if (isset($config['fieldset']) && is_array($config['fieldset'])) {
            foreach ($config['fieldset'] as $groupName => $group) {
                if (!is_array($group)) {
                    continue;
                }
                /** @var array<string, mixed> $group */
                $groupLabel = isset($group['label']) && is_string($group['label']) ? $group['label'] : (is_string($groupName) ? $groupName : null);
                if (!isset($group['fields']) || !is_array($group['fields'])) {
                    continue;
                }
                /** @var array<string|int, mixed> $fields */
                $fields = $group['fields'];
                foreach ($fields as $fieldName => $fieldDef) {
                    if (!is_string($fieldName) || !is_array($fieldDef)) {
                        continue;
                    }
                    /** @var array<string, mixed> $fieldDef */
                    $out[] = self::projectField($fieldName, $fieldDef, $groupLabel);
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $def
     * @return array{name: string, type: string, label?: string, default?: mixed, enum?: list<string>, group?: string}
     */
    private static function projectField(string $name, array $def, ?string $group): array
    {
        $type = isset($def['type']) && is_string($def['type']) ? $def['type'] : 'string';
        $entry = ['name' => $name, 'type' => $type];
        if (isset($def['label']) && is_string($def['label'])) {
            $entry['label'] = $def['label'];
        }
        if (array_key_exists('default', $def)) {
            $entry['default'] = $def['default'];
        }
        // 1.0.1 Wave-1.8 P1 F-COLD-14: surface the enum (select / radio
        // options) so cold agents don't have to guess valid values for
        // a `type:"select"` field. YT-side `options` may be a flat list
        // or a label-keyed map — normalise to a list-of-strings here.
        $enum = self::extractEnum($def);
        if ($enum !== null) {
            $entry['enum'] = $enum;
        }
        if ($group !== null) {
            $entry['group'] = $group;
        }
        return $entry;
    }

    /**
     * 1.0.1 Wave-1.8 P1 F-COLD-14: normalize YT field `options` to a
     * `list<string>` enum projection. YT supports two shapes:
     *
     *  - flat list:  options: ["small", "medium", "large"]
     *  - label-map:  options: { "Small": "small", "Medium": "medium" }
     *
     * Returns null when the field has no options (not a select/radio).
     *
     * @param array<string, mixed> $def
     * @return list<string>|null
     */
    private static function extractEnum(array $def): ?array
    {
        if (!isset($def['options']) || !is_array($def['options'])) {
            return null;
        }
        // In both YT shapes the VALUE is the slug:
        //   flat list:  ["small", "medium", "large"]        → value = slug
        //   label map:  {"Small": "small", "Medium": "..."} → value = slug, key = label
        // So `$value` is always what we want to enumerate.
        // Audit-A5 fold-in (Wave-1.8 audit-pass): collapsed an earlier
        // dead `$isList ? $value : $value` ternary into the single path.
        $values = [];
        foreach ($def['options'] as $value) {
            if (is_string($value) || is_int($value) || is_bool($value)) {
                $values[] = (string) $value;
            }
        }
        return $values === [] ? null : $values;
    }
}
