<?php
/**
 * BindingSerializer — single source-of-truth for structured binding-read.
 *
 * D1 / T1 — F-01-Rest (Maria-Audit v3, 2026-05-22).
 *
 * The PRE-D1 readers were each carrying their own slim heuristic — most
 * recognised only `query.name` and ignored `props.<el>.name` mappings,
 * directives, query.field.arguments and the source_extended carry-over.
 * The audit found that `element_get_binding` on a native YT post-template
 * binding returned `source_name=null, field_mappings=[]` even when the
 * layout-blob clearly carried `props.source.query.name=posts.singlePost`
 * + `props.source.props.content.name=metaString` mappings.
 *
 * The fix is structural: ONE serializer, used by every reader. The
 * serializer accepts the four shapes observed in live YT4 layouts:
 *
 *  1. `$node['props']['source']`     — F-13 canonical (writer puts it here).
 *  2. `$node['source']`               — top-level (some pre-bind cached YT4
 *                                       trees carry the source object at
 *                                       the root).
 *  3. `$node['source_extended']`     — YT4 internal cached/expanded shape.
 *  4. bare string `props['source']`  — legacy pre-F-13 user data.
 *
 * Output record:
 *
 *   {
 *     source_name:      string|null,                  // = query.name
 *     query_field?:     string,                       // = query.field.name
 *     field_mappings:   list<{element_prop, source_field, filters?}>,
 *     directives?:      list<{name, arguments?}>,     // = query.field.directives
 *     query_arguments?: array,                        // = query.field.arguments
 *     raw_source:       array,                        // verbatim source blob
 *   }
 *
 * Returns `null` only when no binding indicators are present at all (no
 * `source` prop, structured shape with empty `query.name` AND no
 * `props.<el>.name` mappings).
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\SourceBinding
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\SourceBinding;

final class BindingSerializer
{
    /**
     * Pull the structured binding off a layout node. Returns the canonical
     * record, or null when the node carries no binding indicators.
     *
     * @param array<string, mixed> $node
     * @return array{
     *     source_name: string|null,
     *     query_field?: string,
     *     field_mappings: list<array{element_prop: string, source_field: string, filters?: mixed}>,
     *     directives?: list<array{name: string, arguments?: mixed}>,
     *     query_arguments?: mixed,
     *     raw_source: array<string, mixed>|string
     * }|null
     */
    public static function serialize(array $node): ?array
    {
        $source = self::resolveSource($node);
        if ($source === null) {
            return null;
        }

        // Legacy plain-string shape — surface as a bare source_name.
        if (is_string($source)) {
            if ($source === '') {
                return null;
            }
            return [
                'source_name' => $source,
                'field_mappings' => [],
                'raw_source' => $source,
            ];
        }

        $sourceName = self::extractSourceName($source);
        $fieldMappings = self::extractFieldMappings($source);

        // Surface null only when BOTH halves are empty — that's the
        // degenerate write `props.source = {query: {}}` with no
        // field-bindings. Anything else is "bound enough" to be a binding.
        if ($sourceName === null && $fieldMappings === []) {
            return null;
        }

        $result = [
            'source_name' => $sourceName,
            'field_mappings' => $fieldMappings,
            'raw_source' => $source,
        ];

        // query.field.* — the GraphQL field selector underneath the
        // source name. Only emitted when actually present in the blob.
        $field = self::resolveQueryField($source);
        if ($field !== null) {
            if (isset($field['name']) && is_string($field['name'])) {
                $result['query_field'] = $field['name'];
            }
            if (isset($field['arguments'])) {
                $result['query_arguments'] = $field['arguments'];
            }
            if (isset($field['directives']) && is_array($field['directives'])) {
                $directives = self::extractDirectives($field['directives']);
                if ($directives !== []) {
                    $result['directives'] = $directives;
                }
            }
        }

        return $result;
    }

    /**
     * True iff the node carries any binding indicator. Mirrors
     * `serialize() !== null` without paying the full parse cost.
     *
     * @param array<string, mixed> $node
     */
    public static function hasBinding(array $node): bool
    {
        return self::serialize($node) !== null;
    }

    /**
     * Resolve which carrier slot holds the source for this node.
     * Precedence: props.source > source > source_extended.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>|string|null
     */
    private static function resolveSource(array $node)
    {
        // F-13 canonical: props.source.
        if (isset($node['props']) && is_array($node['props'])
            && array_key_exists('source', $node['props'])
        ) {
            $candidate = $node['props']['source'];
            if (is_array($candidate) || is_string($candidate)) {
                return $candidate;
            }
        }
        // Top-level source carrier.
        if (isset($node['source']) && (is_array($node['source']) || is_string($node['source']))) {
            return $node['source'];
        }
        // YT4 cached/expanded shape.
        if (isset($node['source_extended']) && is_array($node['source_extended'])) {
            return $node['source_extended'];
        }
        return null;
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function extractSourceName(array $source): ?string
    {
        if (!isset($source['query']) || !is_array($source['query'])) {
            return null;
        }
        $name = $source['query']['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }
        return $name;
    }

    /**
     * Project `source.props` into the canonical list-of-mapping shape.
     *
     * @param array<string, mixed> $source
     * @return list<array{element_prop: string, source_field: string, filters?: mixed}>
     */
    private static function extractFieldMappings(array $source): array
    {
        if (!isset($source['props']) || !is_array($source['props'])) {
            return [];
        }
        $out = [];
        foreach ($source['props'] as $elementProp => $propValue) {
            if (!is_string($elementProp) || $elementProp === '') {
                continue;
            }
            if (!is_array($propValue)) {
                continue;
            }
            if (!isset($propValue['name']) || !is_string($propValue['name'])) {
                continue;
            }
            $mapping = [
                'element_prop' => $elementProp,
                'source_field' => $propValue['name'],
            ];
            if (array_key_exists('filters', $propValue)
                && $propValue['filters'] !== null
                && $propValue['filters'] !== []
                && !($propValue['filters'] instanceof \stdClass)
            ) {
                $mapping['filters'] = $propValue['filters'];
            }
            $out[] = $mapping;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>|null
     */
    private static function resolveQueryField(array $source): ?array
    {
        if (!isset($source['query']) || !is_array($source['query'])) {
            return null;
        }
        if (!isset($source['query']['field']) || !is_array($source['query']['field'])) {
            return null;
        }
        /** @var array<string, mixed> $field */
        $field = $source['query']['field'];
        return $field;
    }

    /**
     * @param array<int|string, mixed> $rawDirectives
     * @return list<array{name: string, arguments?: mixed}>
     */
    private static function extractDirectives(array $rawDirectives): array
    {
        $out = [];
        foreach ($rawDirectives as $directive) {
            if (!is_array($directive)) {
                continue;
            }
            $name = $directive['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            $entry = ['name' => $name];
            if (array_key_exists('arguments', $directive)) {
                $entry['arguments'] = $directive['arguments'];
            }
            $out[] = $entry;
        }
        return $out;
    }
}
