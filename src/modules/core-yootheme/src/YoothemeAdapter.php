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

        // 0. Theme-style.css header (canonical for YT 4.x bundled-as-theme).
        //    F-09 follow-up: YT 4.5.33 dev ships YT as a Theme, not a Plugin,
        //    so none of the YT constants/classes exposed `VERSION`. The theme
        //    style.css `Version:` header is the single source of truth here.
        if (function_exists('wp_get_theme')) {
            try {
                $theme = \wp_get_theme('yootheme');
                $v = (string) $theme->get('Version');
                if ($v !== '' && $v !== 'false') {
                    return $v;
                }
            } catch (\Throwable) {
                // Fall through.
            }
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
        // F-05 v2 (2026-05-22): allow the composer-autoloader to resolve
        // `\YOOtheme\Builder` on cold REST boots. Same bug-class as the
        // F-04 fix for `\YOOtheme\Builder\Source` (see getSourceFields).
        // YT lazy-loads its Builder class via the autoloader; probing
        // with the second arg `false` (no autoload) returns false on
        // a fresh request and the getBuilder() path silently bails →
        // every downstream caller (getBuilderTypes, getBuilderTypesDetailed,
        // getBuilderTypeConfig) returns null and Inspector falls back to
        // the FALLBACK_CATALOG with empty per-type fields.
        if (!class_exists('\\YOOtheme\\Builder')) {
            return null;
        }
        $appFn = '\\YOOtheme\\app';
        if (!function_exists($appFn)) {
            return null;
        }
        try {
            // F-04 fix (Maria-Audit T2.3 2026-05-22): YOOtheme's DI container
            // (`\YOOtheme\Container`) keys services by raw string identity
            // — `'\YOOtheme\Builder'` and `'YOOtheme\Builder'` are TWO
            // distinct service keys. The leading-backslash form misses the
            // registered service definition, falls through to
            // `class_exists()` autoload and reflection-instantiates a
            // *bare* Builder bypassing the factory closure that wires
            // transforms. Pass the YT-canonical no-leading-backslash form.
            /** @var mixed $builder */
            $builder = $appFn('YOOtheme\\Builder'); // @phpstan-ignore-line
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
        // F-04 fix (Maria-Audit re-verify 2026-05-22): YT lazy-loads
        // `\YOOtheme\Builder\Source` via the composer autoloader. With
        // autoload=false we got `false` on dev and bailed out → empty
        // sources groups. Trigger autoload so YT's class-map resolves.
        if (!class_exists('\\YOOtheme\\Builder\\Source')) {
            return null;
        }
        $appFn = '\\YOOtheme\\app';
        if (!function_exists($appFn)) {
            return null;
        }
        try {
            // F-04 fix (Maria-Audit T2.3 2026-05-22): YOOtheme's DI container
            // keys services by raw string identity — `'\YOOtheme\Builder\Source'`
            // (with leading backslash) is a DIFFERENT key than the registered
            // `'YOOtheme\Builder\Source'`. Asking for the leading-backslash form
            // misses the service-definition cache; YT's `resolveService()` then
            // falls through to `class_exists()` + reflection-instantiation
            // which creates a bare `Source` object WITHOUT running the factory
            // closure registered in `builder-source/bootstrap.php` —
            // `Event::emit('source.init', $source)` never fires, no type
            // listeners populate the schema, and `getQueryType()` returns
            // null. Pass the YT-canonical no-leading-backslash identifier so
            // the service-factory closure actually runs and the source-schema
            // gets populated (live-verified 225 fields on dev).
            /** @var mixed $source */
            $source = $appFn('YOOtheme\\Builder\\Source'); // @phpstan-ignore-line
            if (!is_object($source) || !method_exists($source, 'getSchema')) {
                return null;
            }
            /** @var mixed $schema */
            $schema = $source->getSchema();
            if (!is_object($schema)) {
                return null;
            }
            // F-04 fix (Maria-Audit re-verify 2026-05-22): YT 4.5.33's schema
            // does NOT register the root query type under the name "Query" in
            // its type-map — `$schema->getType('Query')` returns null. The
            // canonical accessor is `getQueryType()` which returns the actual
            // ObjectType (live-verified: 225 fields on dev). Prefer that, fall
            // back to getType('Query') only when the canonical accessor is
            // somehow absent (older webonyx/graphql-php builds).
            /** @var mixed $queryType */
            $queryType = null;
            if (method_exists($schema, 'getQueryType')) {
                $queryType = $schema->getQueryType();
            }
            if (!is_object($queryType) && method_exists($schema, 'getType')) {
                $queryType = $schema->getType('Query');
            }
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
     * F-05 (Maria-Audit v2 2026-05-22): YT 4.5.33 keeps the registry as
     * an instance property `$builder->types` (an array of `ElementType`
     * objects), with NO static accessor methods on `\YOOtheme\Builder`.
     * The previous probe `Builder::getTypes()` is unreachable on YT 4.x
     * (method_exists is always false) so the adapter silently returned
     * null and downstream callers fell through to FALLBACK_CATALOG.
     *
     * Read the instance property first. Keep the static-method path as
     * defense-in-depth for any future YT-5+ release that may expose a
     * static accessor.
     *
     * @return list<string>|null
     */
    public function getBuilderTypes(): ?array
    {
        // F-05 v2 (2026-05-22): allow autoload so the YT class-map can
        // resolve `\YOOtheme\Builder` on cold REST boots. See getBuilder()
        // for the full root-cause analysis (same lazy-load behaviour as
        // `\YOOtheme\Builder\Source`).
        if (!class_exists('\\YOOtheme\\Builder')) {
            return null;
        }
        try {
            // 1. Instance access via YT DI container — canonical on YT 4.x.
            $builder = $this->getBuilder();
            if ($builder !== null && isset($builder->types) && is_array($builder->types)) {
                /** @var array<int|string, mixed> $types */
                $types = $builder->types;
                $out = [];
                foreach (array_keys($types) as $key) {
                    $out[] = (string) $key;
                }
                return $out;
            }
            // 2. Defense-in-depth: static-accessor probe for future YT
            //    versions that may expose Builder::getTypes().
            /** @var class-string $builderClass */
            $builderClass = 'YOOtheme\\Builder';
            if (!method_exists($builderClass, 'getTypes')) {
                return null;
            }
            /** @var mixed $types */
            $types = $builderClass::getTypes(); // @phpstan-ignore-line
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
        // F-05 v2 (2026-05-22): allow autoload so the YT class-map can
        // resolve `\YOOtheme\Builder` on cold REST boots. See getBuilder()
        // for the full root-cause analysis (same lazy-load behaviour as
        // `\YOOtheme\Builder\Source`).
        if (!class_exists('\\YOOtheme\\Builder')) {
            return null;
        }
        try {
            // F-05 v2 (2026-05-22): YT 4.5.33 keeps the registry on the
            // Builder instance — $builder->types is an array<string,
            // ElementType>. Instance-access first, static-accessor as
            // defense for future YT versions.
            $rawTypes = null;
            $builder = $this->getBuilder();
            if ($builder !== null && isset($builder->types) && is_array($builder->types)) {
                $rawTypes = $builder->types;
            } else {
                /** @var class-string $builderClass */
                $builderClass = 'YOOtheme\\Builder';
                if (method_exists($builderClass, 'getTypes')) {
                    /** @var mixed $maybe */
                    $maybe = $builderClass::getTypes(); // @phpstan-ignore-line
                    if (is_array($maybe)) {
                        $rawTypes = $maybe;
                    }
                }
            }
            if ($rawTypes === null) {
                return null;
            }

            $out = [];
            foreach ($rawTypes as $name => $config) {
                if (!is_string($name) && !is_int($name)) {
                    continue;
                }
                $nameStr = (string) $name;
                // ElementType wraps the raw config array under public ->data.
                // self::extract* helpers accept array OR object; pass the
                // unwrapped data when available so label/origin/has_children
                // detection sees the actual fields.
                $configForExtract = self::unwrapElementType($config);
                $out[] = [
                    'name' => $nameStr,
                    'label' => self::extractTypeLabel($nameStr, $configForExtract),
                    'origin' => self::extractTypeOrigin($configForExtract),
                    'has_children' => self::detectHasChildren($nameStr, $configForExtract),
                ];
            }
            return $out;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Unwrap a YT ElementType wrapper to its raw config array.
     *
     * YT 4.5.33 wraps each registered type in
     * `\YOOtheme\Builder\ElementType` — a `\JsonSerializable` proxy whose
     * `public array $data` carries the canonical type config (fields,
     * fieldset, label, etc.). For internal projection we want the raw
     * array. Caller passes ANY value (array / ElementType / unknown
     * object); we return:
     *   - the input itself if already an array,
     *   - $object->data (when accessible) for ElementType-shaped objects,
     *   - the result of ->getArrayCopy() / ->toArray() if available,
     *   - the original input otherwise (callers handle non-array gracefully).
     *
     * @param mixed $config
     * @return mixed
     */
    private static function unwrapElementType($config)
    {
        if (is_array($config)) {
            return $config;
        }
        if (is_object($config)) {
            // Direct public `data` property — YT 4.x ElementType shape.
            if (isset($config->data) && is_array($config->data)) { // @phpstan-ignore-line
                return $config->data; // @phpstan-ignore-line
            }
            if (method_exists($config, 'getArrayCopy')) {
                try {
                    /** @var mixed $copy */
                    $copy = $config->getArrayCopy();
                    if (is_array($copy)) {
                        return $copy;
                    }
                } catch (\Throwable) {
                    // fall through
                }
            }
            if (method_exists($config, 'toArray')) {
                try {
                    /** @var mixed $arr */
                    $arr = $config->toArray();
                    if (is_array($arr)) {
                        return $arr;
                    }
                } catch (\Throwable) {
                    // fall through
                }
            }
            // JsonSerializable fallback — ElementType implements it and
            // strips render-only keys (templates/transforms/updates/path).
            // That's exactly what we want for label/origin/has_children.
            if ($config instanceof \JsonSerializable) {
                try {
                    /** @var mixed $serialized */
                    $serialized = $config->jsonSerialize();
                    if (is_array($serialized)) {
                        return $serialized;
                    }
                } catch (\Throwable) {
                    // fall through
                }
            }
        }
        return $config;
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
        // F-05 v2 (2026-05-22): allow autoload so the YT class-map can
        // resolve `\YOOtheme\Builder` on cold REST boots. See getBuilder()
        // for the full root-cause analysis (same lazy-load behaviour as
        // `\YOOtheme\Builder\Source`).
        if (!class_exists('\\YOOtheme\\Builder')) {
            return null;
        }
        try {
            // F-05 v2 (Maria-Audit 2026-05-22): YT 4.5.33 has NO static
            // `Builder::getType()` method — the registry is the instance
            // property `$builder->types[$name]` holding an `ElementType`
            // wrapper whose public `->data` array is the canonical
            // type-config (fields + fieldset).
            //
            // The previous probe `Builder::getType($name)` is unreachable
            // on YT 4.x (method_exists is always false), so the adapter
            // silently returned null and Inspector::schema() emitted
            // `fields: []` for every type — AI clients had to guess props
            // from layout examples. Reach for the instance property first;
            // keep the static-method fallback for future YT-5+ releases.

            // 1. Instance access via YT DI container.
            $builder = $this->getBuilder();
            if ($builder !== null && isset($builder->types) && is_array($builder->types)) {
                /** @var array<string, mixed> $types */
                $types = $builder->types;
                if (!array_key_exists($typeName, $types)) {
                    return null;
                }
                /** @var mixed $type */
                $type = $types[$typeName];
                $unwrapped = self::unwrapElementType($type);
                if (is_array($unwrapped)) {
                    /** @var array<string, mixed> $unwrapped */
                    return $unwrapped;
                }
            }

            // 2. Defense-in-depth: static-accessor probe for future YT.
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
            $unwrapped = self::unwrapElementType($type);
            return is_array($unwrapped) ? $unwrapped : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param mixed $config
     */
    private static function extractTypeLabel(string $name, $config): string
    {
        // YT 4.5.33 stores the human-facing label under `title` in
        // element.json; some configs/registrations also expose `label`.
        // Probe both before falling back to the PascalCased type name.
        if (is_array($config)) {
            if (isset($config['title']) && is_string($config['title']) && $config['title'] !== '') {
                return $config['title'];
            }
            if (isset($config['label']) && is_string($config['label']) && $config['label'] !== '') {
                return $config['label'];
            }
        }
        if (is_object($config)) {
            /** @var mixed $title */
            $title = $config->title ?? null; // @phpstan-ignore-line
            if (is_string($title) && $title !== '') {
                return $title;
            }
            /** @var mixed $label */
            $label = $config->label ?? null; // @phpstan-ignore-line
            if (is_string($label) && $label !== '') {
                return $label;
            }
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
        // F-05 v2 (Maria-Audit 2026-05-22): YT 4.5.33 signals "type holds
        // child elements" via the explicit `container: true` flag on the
        // type config (verified live: section/row/column/grid/switcher/
        // accordion/button/panel are all flagged; *_item leaves are not).
        // The old code probed `element: true` which is wrong — `element`
        // means "is a renderable Builder element", a flag set on EVERY
        // visible type (including leaves like headline / text / image).
        //
        // Order:
        //   1. Explicit `container: true` flag from config.
        //   2. Defensive known-container catalogue (covers cases where
        //      type-config was unwrapped to a stripped JsonSerialize copy
        //      that omitted `container`).
        //   3. `templates.children` / `fieldset.children` shape hints.
        if (is_array($config)) {
            if (isset($config['container']) && $config['container'] === true) {
                return true;
            }
            // Some types declare children via fieldset.children.fields
            if (isset($config['fieldset']['children'])) {
                return true;
            }
            if (isset($config['templates']) && is_array($config['templates'])
                && array_key_exists('children', $config['templates'])
            ) {
                return true;
            }
        }
        // Defensive default — canonical YT 4.5.33 container catalogue.
        // Source of truth:
        //   • Structural containers (3): `section`, `row`, `column` —
        //     always accept children (no `*_item` child type).
        //   • Multi-item containers + items (16 pairs): the canonical
        //     `WootsUp\BuilderMcp\Elements\ItemContainerMap::MAP`. Each
        //     `*_item` child accepts arbitrary inner elements per the
        //     YT-Pro 4.5.33 Multi-Items pattern (live-verified on
        //     dev.wootsup.com 2026-05-22).
        //
        // The `_item` child types report `has_children=true` because
        // they ARE the binding target for source-driven repeat blocks
        // and MUST allow inner field-bindings (headlines, images, etc.)
        // — the yootheme-development skill encodes this contract.
        $structural = ['section', 'row', 'column'];
        if (in_array($name, $structural, true)) {
            return true;
        }
        if (\WootsUp\BuilderMcp\Elements\ItemContainerMap::isContainer($name)) {
            return true;
        }
        if (\WootsUp\BuilderMcp\Elements\ItemContainerMap::isItem($name)) {
            return true;
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
