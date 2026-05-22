<?php
/**
 * TreeWalker — generator-based depth-first walk over a layout node tree.
 *
 * Wave 2 Task 2.3. Yields `[pointer, node]` tuples in pre-order. The walker
 * does NOT yield the root node itself — only its descendants. Callers
 * receive each descendant once, addressed by a JSON-Pointer composed by
 * concatenating the supplied base-pointer with `/children/<index>`
 * suffixes.
 *
 * The walker is read-only and never mutates the supplied tree.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Elements
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Elements;

final class TreeWalker
{
    /**
     * Depth-first pre-order walk over a layout node's descendants.
     *
     * N-01 (Audit-v3): the optional `$maxDepth` argument caps recursion so
     * `element_list` callers can request only the top N levels of a deep
     * 96-node template. `$maxDepth` counts levels of descendants below the
     * supplied node:
     *   - `null` (default) → unbounded, original behaviour.
     *   - `0` → emit nothing.
     *   - `1` → direct children only.
     *   - `2` → children + grandchildren, etc.
     *
     * @param array<string, mixed> $node
     * @param int|null $maxDepth Recursion cap (levels of descendants); null = unbounded.
     * @return \Generator<int, array{0: string, 1: array<string, mixed>}>
     */
    public static function walk(array $node, string $basePointer, ?int $maxDepth = null): \Generator
    {
        if ($maxDepth !== null && $maxDepth <= 0) {
            return;
        }
        if (!isset($node['children']) || !is_array($node['children'])) {
            return;
        }
        $childDepth = $maxDepth === null ? null : $maxDepth - 1;
        foreach ($node['children'] as $index => $child) {
            if (!is_array($child)) {
                continue;
            }
            $childPointer = $basePointer . '/children/' . (string) $index;
            /** @var array<string, mixed> $child */
            yield [$childPointer, $child];
            yield from self::walk($child, $childPointer, $childDepth);
        }
    }

    /**
     * Count every descendant of the given layout node (recursive, depth-first,
     * same enumeration rules as walk()).
     *
     * F-02: this is the single source of truth for "how many elements live in
     * this template". Callers (PageQuery::list, ElementOps::listOnTemplate,
     * PageQuery::schema) all funnel through this counter so the totals in
     * `pages_list.elements_count`, `element_list.total`, and
     * `page_get_schema.total` are guaranteed to agree for any given state.
     *
     * The root node itself is NOT counted — consistent with walk() which only
     * yields descendants. A leaf returns 0. A node with no `children` key
     * also returns 0.
     *
     * @param array<string, mixed> $node
     */
    public static function countDescendants(array $node): int
    {
        if (!isset($node['children']) || !is_array($node['children'])) {
            return 0;
        }
        $total = 0;
        foreach ($node['children'] as $child) {
            if (!is_array($child)) {
                continue;
            }
            /** @var array<string, mixed> $child */
            $total++;
            $total += self::countDescendants($child);
        }
        return $total;
    }
}
