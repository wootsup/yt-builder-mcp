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
     * @param array<string, mixed> $node
     * @return \Generator<int, array{0: string, 1: array<string, mixed>}>
     */
    public static function walk(array $node, string $basePointer): \Generator
    {
        if (!isset($node['children']) || !is_array($node['children'])) {
            return;
        }
        foreach ($node['children'] as $index => $child) {
            if (!is_array($child)) {
                continue;
            }
            $childPointer = $basePointer . '/children/' . (string) $index;
            /** @var array<string, mixed> $child */
            yield [$childPointer, $child];
            yield from self::walk($child, $childPointer);
        }
    }
}
