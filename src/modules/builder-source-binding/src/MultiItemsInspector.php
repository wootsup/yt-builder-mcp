<?php
/**
 * MultiItemsInspector — surface Multi-Items binding state on an element.
 *
 * Implements the read-side of the YT-Pro Multi-Items pattern. The
 * inspector reports:
 *
 *  - whether the addressed element is a multi-item container, a child
 *    item, or neither;
 *  - whether a binding is currently attached (and at which level);
 *  - whether the binding carries an `implode` directive on any of its
 *    source.props (a frequent source of comma-joined output);
 *  - the recommended fix if the binding sits on the container level
 *    (always: move the binding to the `*_item` child).
 *
 * Companion writer is {@see ImplodeDirectiveCleaner}.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\SourceBinding
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\SourceBinding;

use WootsUp\BuilderMcp\Elements\ElementOps;
use WootsUp\BuilderMcp\Elements\ItemContainerMap;

final class MultiItemsInspector
{
    public function __construct(private readonly ElementOps $ops)
    {
    }

    /**
     * Produce a Multi-Items report for $pointer, or null when the
     * element cannot be resolved.
     *
     * Output shape:
     * ```
     * [
     *   'element_path'           => "/templates/.../children/0",
     *   'element_type'           => "grid_item",
     *   'is_container'           => bool,
     *   'is_item'                => bool,
     *   'container_type'         => string|null,
     *   'item_type'              => string|null,
     *   'current_binding_level'  => 'none'|'container'|'item',
     *   'has_implode_directives' => bool,
     *   'warning'?               => string,   // when binding is on container level
     *   'recommended_fix'?       => string,
     * ]
     * ```
     *
     * @return array<string, mixed>|null
     */
    public function inspect(string $templateId, string $pointer): ?array
    {
        $node = $this->ops->get($pointer);
        if ($node === null) {
            return null;
        }
        $type = isset($node['type']) && is_string($node['type']) ? $node['type'] : '';

        $isContainer = ItemContainerMap::isContainer($type);
        $isItem = ItemContainerMap::isItem($type);

        $containerType = $isItem ? ItemContainerMap::containerOf($type) : ($isContainer ? $type : null);
        $itemType = $isContainer ? ItemContainerMap::itemOf($type) : ($isItem ? $type : null);

        // Canonical binding detection. Multi-Items must register a binding
        // when ANY of the four live YT4 indicators is present, scanned over
        // the three carrier slots (props.source, source, source_extended)
        // and the legacy bare-string `props.source = 'name'` shape:
        //
        //   (i)   `source.query.name`              (e.g. "posts.singlePost")
        //   (ii)  `source.query.field.name`        (e.g. "relatedPosts" —
        //                                           GraphQL sub-field, the
        //                                           shape that H-9 was
        //                                           under-reporting)
        //   (iii) `source.props.<el>.name`         (field-mapping only —
        //                                           the F-01-Rest read where
        //                                           a node carries no
        //                                           `query.*` but binds
        //                                           individual prop slots)
        //   (iv)  bare-string `props.source`       (legacy pre-F-13 user
        //                                           data — source name only)
        //
        // Pre-fix this method scanned only (i) at the props.source carrier,
        // so any node bound via (ii)/(iii)/(iv) or on a non-props carrier
        // reported `current_binding_level=none`. This is the H-9 regression.
        $hasBinding = self::nodeHasBinding($node);
        $bindingLevel = 'none';
        if ($hasBinding) {
            $bindingLevel = $isContainer ? 'container' : 'item';
        }

        $report = [
            'element_path' => $pointer,
            'element_type' => $type,
            'is_container' => $isContainer,
            'is_item' => $isItem,
            'container_type' => $containerType,
            'item_type' => $itemType,
            'current_binding_level' => $bindingLevel,
            'has_implode_directives' => self::nodeHasImplodeDirective($node),
        ];

        // Surface the warning + fix recommendation only when the
        // binding sits on the container level — that is the structural
        // bug Multi-Items aims to surface.
        if ($bindingLevel === 'container' && $itemType !== null) {
            $report['warning'] =
                'Binding lives on the container. YT-Pro SourceTransform::repeatSource '
                . 'clones the container N times instead of repeating items inside it. '
                . 'Move the binding to a "' . $itemType . '" child element.';
            $report['recommended_fix'] = sprintf(
                'Re-bind on the first "%s" child via bind_source with bindingLevel=\'item\'.',
                $itemType,
            );
        }

        return $report;
    }

    /**
     * Detect any Multi-Items binding indicator on a layout node.
     *
     * Canonical algorithm:
     *
     *   1. Resolve the source carrier (precedence: props.source > source >
     *      source_extended). The carrier may be array OR legacy string.
     *   2. For a non-empty STRING carrier → binding present.
     *   3. For an ARRAY carrier → binding present iff any of:
     *        a) `query.name`        is a non-empty string.
     *        b) `query.field.name`  is a non-empty string.
     *        c) `props.<el>.name`   exists for at least one prop.
     *
     * @param array<string, mixed> $node
     */
    private static function nodeHasBinding(array $node): bool
    {
        $source = self::resolveSourceCarrier($node);
        if ($source === null) {
            return false;
        }
        if (is_string($source)) {
            return $source !== '';
        }
        // (a) top-level query.name
        if (isset($source['query']) && is_array($source['query'])) {
            $name = $source['query']['name'] ?? null;
            if (is_string($name) && $name !== '') {
                return true;
            }
            // (b) nested query.field.name (e.g. relatedPosts)
            if (isset($source['query']['field']) && is_array($source['query']['field'])) {
                $fieldName = $source['query']['field']['name'] ?? null;
                if (is_string($fieldName) && $fieldName !== '') {
                    return true;
                }
            }
        }
        // (c) per-prop field-mapping presence (the F-01-Rest field-only
        // shape: `props.source.props.content.name = 'metaString'`).
        if (isset($source['props']) && is_array($source['props'])) {
            foreach ($source['props'] as $propValue) {
                if (is_array($propValue)
                    && isset($propValue['name'])
                    && is_string($propValue['name'])
                    && $propValue['name'] !== ''
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Resolve which carrier slot holds the source for this node.
     * Precedence: props.source > source > source_extended. Mirrors
     * BindingSerializer::resolveSource() so both readers agree on which
     * blob carries the binding for a given node shape.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>|string|null
     */
    private static function resolveSourceCarrier(array $node)
    {
        if (isset($node['props']) && is_array($node['props'])
            && array_key_exists('source', $node['props'])
        ) {
            $candidate = $node['props']['source'];
            if (is_array($candidate) || is_string($candidate)) {
                return $candidate;
            }
        }
        if (isset($node['source']) && (is_array($node['source']) || is_string($node['source']))) {
            return $node['source'];
        }
        if (isset($node['source_extended']) && is_array($node['source_extended'])) {
            return $node['source_extended'];
        }
        return null;
    }

    /**
     * Walk `props.source.props.*.implode` and return true on the first
     * non-empty `implode` directive found.
     *
     * @param array<string, mixed> $node
     */
    private static function nodeHasImplodeDirective(array $node): bool
    {
        $sourceProps = self::sourcePropsOf($node);
        foreach ($sourceProps as $propValue) {
            if (!is_array($propValue)) {
                continue;
            }
            if (isset($propValue['implode']) && is_array($propValue['implode']) && $propValue['implode'] !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function sourcePropsOf(array $node): array
    {
        if (!isset($node['props']) || !is_array($node['props'])) {
            return [];
        }
        /** @var array<string, mixed> $propsTop */
        $propsTop = $node['props'];
        if (!isset($propsTop['source']) || !is_array($propsTop['source'])) {
            return [];
        }
        /** @var array<string, mixed> $source */
        $source = $propsTop['source'];
        if (!isset($source['props']) || !is_array($source['props'])) {
            return [];
        }
        /** @var array<string, mixed> $out */
        $out = $source['props'];
        return $out;
    }
}
