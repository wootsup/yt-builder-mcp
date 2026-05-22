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

final class YoothemeAdapter
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
     * Return YOOtheme's reported version string (from the YOOTHEME_VERSION
     * constant) or null if YT is not loaded / version cannot be detected.
     */
    public function getVersion(): ?string
    {
        if (!$this->isLoaded()) {
            return null;
        }
        if (defined('YOOTHEME_VERSION')) {
            return (string) \YOOTHEME_VERSION;
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
