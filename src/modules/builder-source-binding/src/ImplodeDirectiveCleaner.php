<?php
/**
 * ImplodeDirectiveCleaner — strip `implode` directives from
 * `props.source.props.*` of a Multi-Items element binding.
 *
 * Implode directives concatenate every value of a repeated source-prop
 * into a single string (e.g. `["a","b","c"] → "a, b, c"`). They are a
 * legitimate YT feature for "show all tags as one comma-separated
 * string" use-cases but they're frequently leftover artifacts on
 * container-level bindings — when the binding moves from container to
 * item, the implode directive becomes meaningless and produces
 * surprising comma-joined output on the rendered page.
 *
 * The cleaner is a pure-function: given a node, return the node with
 * implode keys removed, alongside an audit log of what was removed.
 *
 * Companion reader is {@see MultiItemsInspector}.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\SourceBinding
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\SourceBinding;

final class ImplodeDirectiveCleaner
{
    /**
     * Result of {@see clean()}. Always non-null.
     *
     * @phpstan-type CleanResult array{
     *   node: array<string, mixed>,
     *   cleaned_count: int,
     *   removed_directives: list<array{prop_name: string, directive: array<string, mixed>}>,
     * }
     */

    /**
     * Strip every `implode` key from `node.props.source.props.*` and
     * return the cleaned node alongside an audit log.
     *
     * Empty-array implode directives count as a directive (we still
     * remove the key) — they indicate prior cleanups that left an
     * empty stub behind.
     *
     * @param array<string, mixed> $node
     * @return array{node: array<string, mixed>, cleaned_count: int, removed_directives: list<array{prop_name: string, directive: array<string, mixed>}>}
     */
    public static function clean(array $node): array
    {
        $sourceProps = self::pullSourceProps($node);
        if ($sourceProps === null) {
            return ['node' => $node, 'cleaned_count' => 0, 'removed_directives' => []];
        }

        $removed = [];
        foreach ($sourceProps as $propName => $propValue) {
            if (!is_array($propValue)) {
                continue;
            }
            if (!array_key_exists('implode', $propValue)) {
                continue;
            }
            $directive = is_array($propValue['implode']) ? $propValue['implode'] : [];
            unset($propValue['implode']);
            $sourceProps[$propName] = $propValue;
            $removed[] = ['prop_name' => (string) $propName, 'directive' => $directive];
        }

        if ($removed === []) {
            return ['node' => $node, 'cleaned_count' => 0, 'removed_directives' => []];
        }

        // Re-attach the mutated source-props back to the node.
        $node['props']['source']['props'] = $sourceProps;

        return [
            'node' => $node,
            'cleaned_count' => count($removed),
            'removed_directives' => $removed,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private static function pullSourceProps(array $node): ?array
    {
        if (!isset($node['props']) || !is_array($node['props'])) {
            return null;
        }
        /** @var array<string, mixed> $top */
        $top = $node['props'];
        if (!isset($top['source']) || !is_array($top['source'])) {
            return null;
        }
        /** @var array<string, mixed> $source */
        $source = $top['source'];
        if (!isset($source['props']) || !is_array($source['props'])) {
            return null;
        }
        /** @var array<string, mixed> $sp */
        $sp = $source['props'];
        return $sp;
    }
}
