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
     * @param array<string, mixed> $node
     */
    private static function nodeHasBinding(array $node): bool
    {
        if (!isset($node['props']) || !is_array($node['props'])) {
            return false;
        }
        /** @var array<string, mixed> $props */
        $props = $node['props'];
        if (!array_key_exists('source', $props)) {
            return false;
        }
        /** @var mixed $source */
        $source = $props['source'];
        if (is_string($source)) {
            return $source !== '';
        }
        if (is_array($source)) {
            return isset($source['query']['name'])
                && is_string($source['query']['name'])
                && $source['query']['name'] !== '';
        }
        return false;
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
