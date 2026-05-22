<?php
/**
 * YoothemeAdapter — single choke-point for every YOOtheme symbol access.
 *
 * Before Wave-6 Round-2, four modules (HealthController, LayoutWriter,
 * SourceRegistry, Inspector) each open-coded `class_exists('\YOOtheme\…')`
 * + `\YOOtheme\app(…)` calls. That spread the YOOtheme-coupling surface
 * to four places and made future Joomla / version-compat work an N-place
 * change. R2.7 extracts every YT touch into this adapter so all callers
 * funnel through a single API.
 *
 * Every method returns `null` / `false` / `[]` (never throws) when
 * YOOtheme is not loaded — adapter is the boundary, callers are
 * fail-safe by construction.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Yootheme
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Yootheme;

class YoothemeAdapter
{
    /**
     * Return true if the YOOtheme Pro application has booted in the
     * current request. Cheap, no class-loader hit (the false-arg on
     * class_exists disables autoload).
     */
    public function isLoaded(): bool
    {
        return class_exists('\\YOOtheme\\Application', false);
    }

    /**
     * Return YOOtheme's reported version string, or null when YT is not
     * loaded / no detection path succeeds.
     *
     * F-09 fix (Maria-Audit 2026-05-22): the live audit saw `yootheme_version: null`
     * on dev — turns out YT Pro exposes its version via several different
     * symbols across versions, and the old code only probed one
     * (`YOOTHEME_VERSION`). Walk every known surface in order of trust
     * before giving up:
     *
     *   1. `YOOTHEME_VERSION` constant — defined by yootheme-pro plugin
     *      bootstrap on most modern (>=4.x) installs.
     *   2. `\YOOtheme\Theme::VERSION` class constant — older theme-bundled
     *      builds; surfaced via reflection so we don't hard-fail when the
     *      class is absent.
     *   3. `\YOOtheme\app('version')` — DI-registered scalar, available on
     *      the headless YT 5 stack.
     *
     * Returns the first non-empty string; null when every probe misses.
     */
    public function getVersion(): ?string
    {
        if (!$this->isLoaded()) {
            return null;
        }

        // 1. Plugin-bootstrap constant.
        if (defined('YOOTHEME_VERSION')) {
            $v = (string) \YOOTHEME_VERSION;
            if ($v !== '') {
                return $v;
            }
        }

        // 2. Class-constant reflection. `\YOOtheme\Theme::VERSION` is the
        // canonical surface on theme-bundled YT 4 builds. Reflection
        // avoids a hard class-load when the class isn't autoloadable.
        /** @var class-string $themeClass */
        $themeClass = '\\YOOtheme\\Theme';
        if (class_exists($themeClass, false)) {
            try {
                $reflection = new \ReflectionClass($themeClass);
                if ($reflection->hasConstant('VERSION')) {
                    $v = $reflection->getConstant('VERSION');
                    if (is_string($v) && $v !== '') {
                        return $v;
                    }
                }
            } catch (\Throwable) {
                // fall through to next probe
            }
        }

        // 3. DI-registered scalar.
        $appFn = '\\YOOtheme\\app';
        if (function_exists($appFn)) {
            try {
                /** @var mixed $v */
                $v = $appFn('version'); // @phpstan-ignore-line
                if (is_string($v) && $v !== '') {
                    return $v;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return null;
    }

    /**
     * Return the YOOessentials companion-plugin version, or null when
     * YOOessentials is not installed / not loaded.
     *
     * F-09 fix (Maria-Audit 2026-05-22): pages_list element-counts and
     * the element_type_get_schema surface depend on which essentials
     * elements are registered — exposing the version makes "why is
     * `card-grid` missing on this server?" debuggable for support.
     */
    public function getEssentialsVersion(): ?string
    {
        // YOOessentials uses a plugin-bootstrap constant on modern builds.
        if (defined('YOOESSENTIALS_VERSION')) {
            $v = (string) \constant('YOOESSENTIALS_VERSION');
            if ($v !== '') {
                return $v;
            }
        }
        // Class-constant reflection fallback.
        /** @var list<class-string> $candidates */
        $candidates = ['\\Yooessentials\\Plugin', '\\YOOessentials\\Plugin'];
        foreach ($candidates as $candidate) {
            if (class_exists($candidate, false)) {
                try {
                    $reflection = new \ReflectionClass($candidate);
                    if ($reflection->hasConstant('VERSION')) {
                        $v = $reflection->getConstant('VERSION');
                        if (is_string($v) && $v !== '') {
                            return $v;
                        }
                    }
                } catch (\Throwable) {
                    // try next candidate
                }
            }
        }
        return null;
    }

    /**
     * Return the YOOtheme Builder service (used to invoke withParams/load
     * for save-context transforms). Null when YT is not loaded or the
     * Builder service is missing.
     */
    public function getBuilder(): ?object
    {
        if (!$this->isLoaded()) {
            return null;
        }
        if (!class_exists('\\YOOtheme\\Builder', false)) {
            return null;
        }
        $appFn = '\\YOOtheme\\app';
        if (!function_exists($appFn)) {
            return null;
        }
        try {
            /** @var mixed $builder */
            $builder = $appFn('\\YOOtheme\\Builder'); // @phpstan-ignore-line
            return is_object($builder) ? $builder : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Return the YOOtheme Builder Source schema (GraphQL Query type's
     * `getFields()` reader). Null on any failure.
     *
     * @return array<string, mixed>|null
     */
    public function getSourceFields(): ?array
    {
        if (!$this->isLoaded()) {
            return null;
        }
        if (!class_exists('\\YOOtheme\\Builder\\Source', false)) {
            return null;
        }
        $appFn = '\\YOOtheme\\app';
        if (!function_exists($appFn)) {
            return null;
        }
        try {
            /** @var mixed $source */
            $source = $appFn('\\YOOtheme\\Builder\\Source'); // @phpstan-ignore-line
            if (!is_object($source) || !method_exists($source, 'getSchema')) {
                return null;
            }
            /** @var mixed $schema */
            $schema = $source->getSchema();
            if (!is_object($schema) || !method_exists($schema, 'getType')) {
                return null;
            }
            /** @var mixed $queryType */
            $queryType = $schema->getType('Query');
            if (!is_object($queryType) || !method_exists($queryType, 'getFields')) {
                return null;
            }
            /** @var mixed $fields */
            $fields = $queryType->getFields();
            return is_array($fields) ? $fields : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Return enriched source-field entries pulled from the YOOtheme
     * Builder source-schema. Each entry exposes:
     *
     *  - name:  canonical field-name (e.g. `posts.singlePost`)
     *  - label: human-facing label (from `config['metadata']['label']`,
     *           falls back to the name)
     *  - group: group key as YT itself classifies it
     *           (`config['metadata']['group']`, falls back to '')
     *  - type:  GraphQL-Type display-string (`(string) $field->getType()`)
     *           — empty string when type-introspection unavailable.
     *
     * Returns `null` when YT is not loaded so callers can fall through
     * to safe defaults. Reads the FieldDefinition objects returned by
     * `Query->getFields()` — `->config['metadata']` is the canonical
     * source-of-truth for label / group (api-mapper's DynamicQueryType
     * registers the same shape; see spike-source-bridge).
     *
     * @return list<array{name: string, label: string, group: string, type: string}>|null
     */
    public function getSourceFieldEntries(): ?array
    {
        $fields = $this->getSourceFields();
        if ($fields === null) {
            return null;
        }
        $out = [];
        foreach ($fields as $name => $field) {
            $entry = [
                'name' => (string) $name,
                'label' => (string) $name,
                'group' => '',
                'type' => '',
            ];
            // FieldDefinition objects (webonyx/graphql-php) expose ->config
            // as a public array; api-mapper writes 'metadata' there.
            if (is_object($field)) {
                /** @var mixed $config */
                $config = property_exists($field, 'config')
                    ? $field->config // @phpstan-ignore-line
                    : null;
                if (is_array($config) && isset($config['metadata']) && is_array($config['metadata'])) {
                    /** @var array<string, mixed> $metadata */
                    $metadata = $config['metadata'];
                    if (isset($metadata['label']) && is_string($metadata['label'])) {
                        $entry['label'] = $metadata['label'];
                    }
                    if (isset($metadata['group']) && is_string($metadata['group'])) {
                        $entry['group'] = $metadata['group'];
                    }
                }
                if (method_exists($field, 'getType')) {
                    try {
                        /** @var mixed $type */
                        $type = $field->getType();
                        if (is_object($type) && method_exists($type, '__toString')) {
                            $entry['type'] = (string) $type; // @phpstan-ignore-line
                        }
                    } catch (\Throwable) {
                        // best-effort — leave type empty
                    }
                }
            } elseif (is_array($field)) {
                /** @var array<string, mixed> $arr */
                $arr = $field;
                if (isset($arr['metadata']) && is_array($arr['metadata'])) {
                    /** @var array<string, mixed> $metadata */
                    $metadata = $arr['metadata'];
                    if (isset($metadata['label']) && is_string($metadata['label'])) {
                        $entry['label'] = $metadata['label'];
                    }
                    if (isset($metadata['group']) && is_string($metadata['group'])) {
                        $entry['group'] = $metadata['group'];
                    }
                }
                if (isset($arr['type'])) {
                    /** @var mixed $rawType */
                    $rawType = $arr['type'];
                    if (is_string($rawType)) {
                        $entry['type'] = $rawType;
                    } elseif (is_object($rawType) && method_exists($rawType, '__toString')) {
                        $entry['type'] = (string) $rawType; // @phpstan-ignore-line
                    }
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Return the live YOOtheme Builder element-type names. Null on any
     * failure — caller falls back to a static list.
     *
     * @return list<string>|null
     */
    public function getBuilderTypes(): ?array
    {
        if (!class_exists('\\YOOtheme\\Builder', false)) {
            return null;
        }
        try {
            /** @var class-string $builderClass */
            $builderClass = 'YOOtheme\\Builder';
            if (!method_exists($builderClass, 'getTypes')) {
                return null;
            }
            /** @var mixed $types */
            $types = $builderClass::getTypes();
            if (!is_array($types)) {
                return null;
            }
            $out = [];
            foreach (array_keys($types) as $key) {
                $out[] = (string) $key;
            }
            return $out;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Return the live YOOtheme Builder element-type registry projected to a
     * richer per-type shape, or null when YT is not loaded / the registry
     * is unreachable. F-03: feeds Inspector::listTypes() so MCP-clients see
     * the full catalogue (builtin + essentials + uessentials) rather than
     * the 10-element static fallback.
     *
     * Each entry surfaces:
     *  - name:         registry key (e.g. 'section', 'grid_item').
     *  - label:        human-readable label from type config or PascalCase
     *                  fallback.
     *  - origin:       'builtin' (no origin marker), 'essentials' or
     *                  'uessentials' when the type config exposes one.
     *  - has_children: true for container-types ('section', 'row', 'column',
     *                  'grid', 'panel', 'switcher', 'tabs', 'modal',
     *                  'lightbox', 'slideshow', 'slider', 'gallery') and any
     *                  type whose config exposes `'element' => true`.
     *
     * @return list<array{name: string, label: string, origin: string, has_children: bool}>|null
     */
    public function getBuilderTypesDetailed(): ?array
    {
        if (!class_exists('\\YOOtheme\\Builder', false)) {
            return null;
        }
        try {
            /** @var class-string $builderClass */
            $builderClass = 'YOOtheme\\Builder';
            if (!method_exists($builderClass, 'getTypes')) {
                return null;
            }
            /** @var mixed $types */
            $types = $builderClass::getTypes();
            if (!is_array($types)) {
                return null;
            }
            $out = [];
            foreach ($types as $name => $config) {
                if (!is_string($name) && !is_int($name)) {
                    continue;
                }
                $nameStr = (string) $name;
                $out[] = [
                    'name' => $nameStr,
                    'label' => self::extractTypeLabel($nameStr, $config),
                    'origin' => self::extractTypeOrigin($config),
                    'has_children' => self::detectHasChildren($nameStr, $config),
                ];
            }
            return $out;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Return the per-type config (fields, props, defaults) for `$typeName`,
     * or null when YT is not loaded / the type is unknown / the config can
     * not be read. F-05: feeds Inspector::schema() so MCP-clients can
     * introspect the props each element type accepts.
     *
     * The shape follows YOOtheme's own element-config convention:
     *   `{name, label, fieldset: {<group>: {label, fields: {<name>: {...}}}}}`
     *
     * The caller (Inspector::schema) flattens `fieldset.*.fields` into a
     * flat field-list for the wire shape.
     *
     * @return array<string, mixed>|null
     */
    public function getBuilderTypeConfig(string $typeName): ?array
    {
        if (!class_exists('\\YOOtheme\\Builder', false)) {
            return null;
        }
        try {
            /** @var class-string $builderClass */
            $builderClass = 'YOOtheme\\Builder';
            if (!method_exists($builderClass, 'getType')) {
                return null;
            }
            /** @var mixed $type */
            $type = $builderClass::getType($typeName); // @phpstan-ignore-line
            if ($type === null) {
                return null;
            }
            // YT::Builder::getType() returns a Type config object whose
            // public properties / array-access carry the field-set. Two
            // shapes are observed across YT versions: (a) plain array,
            // (b) ArrayObject-ish wrapper exposing getArrayCopy(). Coerce
            // to array defensively.
            if (is_array($type)) {
                /** @var array<string, mixed> $type */
                return $type;
            }
            if (is_object($type)) {
                if (method_exists($type, 'getArrayCopy')) {
                    /** @var mixed $copy */
                    $copy = $type->getArrayCopy();
                    return is_array($copy) ? $copy : null;
                }
                if (method_exists($type, 'toArray')) {
                    /** @var mixed $arr */
                    $arr = $type->toArray();
                    return is_array($arr) ? $arr : null;
                }
                // Best-effort: cast public properties.
                $cast = (array) $type;
                return $cast === [] ? null : $cast;
            }
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param mixed $config
     */
    private static function extractTypeLabel(string $name, $config): string
    {
        if (is_array($config) && isset($config['label']) && is_string($config['label'])) {
            return $config['label'];
        }
        if (is_object($config) && isset($config->label) && is_string($config->label)) { // @phpstan-ignore-line
            return $config->label;
        }
        return ucwords(str_replace(['_', '-'], ' ', $name));
    }

    /**
     * @param mixed $config
     */
    private static function extractTypeOrigin($config): string
    {
        // Origin can be hinted by:
        //   • path:        '.../uessentials/...' or '.../essentials/...'
        //   • templates:   path under one of those vendors
        //   • metadata.origin: explicit marker
        if (is_array($config)) {
            if (isset($config['origin']) && is_string($config['origin'])) {
                return $config['origin'];
            }
            foreach (['path', 'src', 'file'] as $key) {
                if (isset($config[$key]) && is_string($config[$key])) {
                    $hint = strtolower($config[$key]);
                    if (str_contains($hint, 'uessentials')) {
                        return 'uessentials';
                    }
                    if (str_contains($hint, 'essentials')) {
                        return 'essentials';
                    }
                }
            }
        }
        return 'builtin';
    }

    /**
     * @param mixed $config
     */
    private static function detectHasChildren(string $name, $config): bool
    {
        // YT marks container types explicitly via `element: true` (the type
        // accepts inner elements) OR via the `templates: { children: ... }`
        // path. As a defensive default, the canonical container catalogue:
        $knownContainers = [
            'section', 'row', 'column', 'grid', 'grid_item',
            'panel', 'switcher', 'switcher_item',
            'tabs', 'tabs_item',
            'modal', 'modal_item',
            'lightbox', 'lightbox_item',
            'slideshow', 'slideshow_item',
            'slider', 'slider_item',
            'gallery', 'gallery_item',
            'accordion', 'accordion_item',
            'social', 'social_item',
            'button_group', 'button_group_item',
            'map_marker',
        ];
        if (in_array($name, $knownContainers, true)) {
            return true;
        }
        if (is_array($config)) {
            if (isset($config['element']) && $config['element'] === true) {
                return true;
            }
            // Some types declare children via fieldset.children.fields
            if (isset($config['fieldset']['children'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Run YOOtheme's Builder::load() pipeline on $tree with the given
     * context (currently used only for the "save" context). Returns the
     * transformed array on success, or null if YT is not loaded, the
     * Builder is missing, or any step in the pipeline fails.
     *
     * @param array<string, mixed> $tree
     * @return array<string, mixed>|null
     */
    public function loadWithContext(array $tree, string $context): ?array
    {
        $builder = $this->getBuilder();
        if ($builder === null) {
            return null;
        }
        if (!method_exists($builder, 'withParams') || !method_exists($builder, 'load')) {
            return null;
        }
        try {
            $json = json_encode(
                $tree,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException) {
            return null;
        }
        try {
            /** @var mixed $contexted */
            $contexted = $builder->withParams(['context' => $context]);
            if (!is_object($contexted) || !method_exists($contexted, 'load')) {
                return null;
            }
            /** @var mixed $loaded */
            $loaded = $contexted->load($json);
            return is_array($loaded) ? $loaded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Return the YOOtheme cache service. Used by CacheFlusher to invoke
     * `->flush()`. Null if YT is not loaded or the cache service is
     * unavailable.
     */
    public function getCache(): ?object
    {
        if (!$this->isLoaded()) {
            return null;
        }
        $appFn = '\\YOOtheme\\app';
        if (!function_exists($appFn)) {
            return null;
        }
        try {
            /** @var mixed $cache */
            $cache = $appFn('cache'); // @phpstan-ignore-line
            return is_object($cache) ? $cache : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
