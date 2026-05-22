<?php
/**
 * ElementOps — read-only element operations over the wp_option('yootheme')
 * builder state.
 *
 * Wave 2 Task 2.3 (read-only). Three methods:
 *
 *  - listOnTemplate($templateId)  → flat list of every node in a template,
 *                                   each with path / type / props_summary
 *                                   (= prop keys only, no values).
 *  - get($pointer)                → JSON-Pointer-addressed single node, or null.
 *  - getSettings($pointer)        → props map for the addressed node, or null.
 *
 * Wave 3 will add add()/move()/delete()/setSettings() backed by a
 * LayoutWriter going through `Builder::load(context:save)`.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Elements
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Elements;

use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\State\LayoutReader;

final class ElementOps
{
    public function __construct(private readonly LayoutReader $reader)
    {
    }

    /**
     * Return a flat depth-first list of every element on the template, or
     * null if the template is unknown.
     *
     * Each entry exposes:
     *  - path:          JSON-Pointer into the global wp_option document
     *  - type:          element-type name (e.g. 'section', 'headline')
     *  - props_summary: list of prop keys present on the node, no values
     *                   (callers fetch full settings via getSettings()).
     *
     * @return list<array{path: string, type: string, props_summary: list<string>}>|null
     */
    public function listOnTemplate(string $templateId): ?array
    {
        $tpl = $this->reader->readTemplate($templateId);
        if ($tpl === null) {
            return null;
        }
        $out = [];
        if (isset($tpl['layout']) && is_array($tpl['layout'])) {
            $basePointer = JsonPointer::compile(['templates', $templateId, 'layout']);
            foreach (TreeWalker::walk($tpl['layout'], $basePointer) as [$pointer, $node]) {
                $out[] = [
                    'path' => $pointer,
                    'type' => isset($node['type']) && is_string($node['type'])
                        ? $node['type']
                        : 'unknown',
                    'props_summary' => self::propKeys($node),
                ];
            }
        }
        return $out;
    }

    /**
     * Resolve a JSON-Pointer to a single node, or null if missing / not an array.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $pointer): ?array
    {
        $value = $this->reader->readByPointer($pointer);
        if (!is_array($value)) {
            return null;
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Return the `props` of the node at $pointer, or null if the node is
     * absent. Returns the empty array when the node has no `props` key.
     *
     * @return array<string, mixed>|null
     */
    public function getSettings(string $pointer): ?array
    {
        $node = $this->get($pointer);
        if ($node === null) {
            return null;
        }
        if (!isset($node['props']) || !is_array($node['props'])) {
            return [];
        }
        /** @var array<string, mixed> $props */
        $props = $node['props'];
        return $props;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private static function propKeys(array $node): array
    {
        if (!isset($node['props']) || !is_array($node['props'])) {
            return [];
        }
        $keys = [];
        foreach (array_keys($node['props']) as $key) {
            $keys[] = (string) $key;
        }
        return $keys;
    }

    // ---------------------------------------------------------------------
    // Wave 3 — write operations (pure state mutations, no persistence).
    //
    // These methods operate on a passed-in state-by-reference: callers
    // (ElementsController) read the current state via LayoutReader, run
    // a write-method here to mutate it in-memory, then persist via
    // LayoutWriter::writeTemplate (which runs save-transforms + cache-flush).
    // ---------------------------------------------------------------------

    /**
     * Append a new element under `parentPath`'s children-array. Returns the
     * JSON-Pointer of the inserted element.
     *
     * `parentPath` may either be:
     *  - a JSON-Pointer to a node (the new element is appended to its
     *    `children` array; intermediate `children` is auto-created); or
     *  - empty string (the new element is appended to the template's
     *    top-level `layout.children`).
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $props
     * @param list<array<string, mixed>> $children
     */
    public function add(
        array &$state,
        string $templateId,
        string $parentPath,
        string $elementType,
        array $props = [],
        array $children = []
    ): string {
        $parentPath = $parentPath === '' ? self::templateLayoutPath($templateId) : $parentPath;

        // Resolve the parent node in $state. The parent must be reachable
        // because we're mutating an in-memory snapshot. If parent has no
        // `children`, we create one.
        $parent = JsonPointer::get($state, $parentPath);
        if (!is_array($parent)) {
            throw new \InvalidArgumentException(
                sprintf('Parent pointer "%s" does not resolve to an array node.', $parentPath),
            );
        }

        $newElement = [
            'type' => $elementType,
        ];
        if ($props !== []) {
            $newElement['props'] = $props;
        }
        if ($children !== []) {
            $newElement['children'] = $children;
        }

        // Make sure parent.children exists as a list.
        $childrenPath = $parentPath . '/children';
        $existingChildren = JsonPointer::get($state, $childrenPath);
        if (!is_array($existingChildren)) {
            JsonPointer::set($state, $childrenPath, []);
            $existingChildren = [];
        }
        /** @var list<mixed> $existingChildren */
        $insertIndex = count($existingChildren);

        $insertionPath = $childrenPath . '/' . (string) $insertIndex;
        JsonPointer::set($state, $insertionPath, $newElement);

        return $insertionPath;
    }

    /**
     * Delete the element at $elementPath from $state.
     *
     * @param array<string, mixed> $state
     */
    public function delete(array &$state, string $templateId, string $elementPath): void
    {
        if ($elementPath === '') {
            throw new \InvalidArgumentException('Cannot delete the template root via ElementOps::delete().');
        }
        $removed = JsonPointer::remove($state, $elementPath);
        if (!$removed) {
            throw new \InvalidArgumentException(
                sprintf('Element at "%s" not found — nothing to delete.', $elementPath),
            );
        }
    }

    /**
     * Move the element at $fromPath into $toParentPath at index $toIndex.
     * Returns the new pointer of the moved element.
     *
     * Implementation: read source node → remove from old location → insert
     * at new location. Because deletions on lists reindex the array, the
     * insertion-index calculation accounts for the case where source and
     * destination share a parent and the source index is less than the
     * destination index.
     *
     * @param array<string, mixed> $state
     */
    public function move(
        array &$state,
        string $templateId,
        string $fromPath,
        string $toParentPath,
        int $toIndex
    ): string {
        $node = JsonPointer::get($state, $fromPath);
        if (!is_array($node)) {
            throw new \InvalidArgumentException(
                sprintf('Source element "%s" not found or not an array.', $fromPath),
            );
        }
        /** @var array<string, mixed> $node */

        if ($toIndex < 0) {
            throw new \InvalidArgumentException('move(): toIndex must be >= 0.');
        }

        $toParentPath = $toParentPath === '' ? self::templateLayoutPath($templateId) : $toParentPath;
        $toChildrenPath = $toParentPath . '/children';

        // Compute whether the source and destination share the same parent
        // children-array, AND whether removing the source would shift the
        // destination index down by one.
        $sourceParentChildrenPath = self::stripTrailingIndex($fromPath);
        $sourceIndex = self::trailingIndex($fromPath);
        $sameParent = ($sourceParentChildrenPath === $toChildrenPath);
        $adjustedToIndex = $toIndex;
        if ($sameParent && $sourceIndex !== null && $sourceIndex < $toIndex) {
            $adjustedToIndex = $toIndex - 1;
        }

        // Remove the source.
        $removed = JsonPointer::remove($state, $fromPath);
        if (!$removed) {
            throw new \InvalidArgumentException(
                sprintf('Failed to remove source element at "%s".', $fromPath),
            );
        }

        // Make sure destination children exist.
        $destChildren = JsonPointer::get($state, $toChildrenPath);
        if (!is_array($destChildren)) {
            JsonPointer::set($state, $toChildrenPath, []);
            $destChildren = [];
        }
        /** @var list<mixed> $destChildren */

        // Splice into destination at adjusted index.
        $countDest = count($destChildren);
        if ($adjustedToIndex > $countDest) {
            $adjustedToIndex = $countDest;
        }
        $destChildren = array_values($destChildren);
        array_splice($destChildren, $adjustedToIndex, 0, [$node]);
        JsonPointer::set($state, $toChildrenPath, $destChildren);

        return $toChildrenPath . '/' . (string) $adjustedToIndex;
    }

    /**
     * Insert a deep copy of the element at $elementPath as a sibling
     * immediately after it. Returns the new clone's pointer.
     *
     * @param array<string, mixed> $state
     */
    public function clone(array &$state, string $templateId, string $elementPath): string
    {
        $node = JsonPointer::get($state, $elementPath);
        if (!is_array($node)) {
            throw new \InvalidArgumentException(
                sprintf('Source element "%s" not found — cannot clone.', $elementPath),
            );
        }
        /** @var array<string, mixed> $node */

        $parentChildrenPath = self::stripTrailingIndex($elementPath);
        $sourceIndex = self::trailingIndex($elementPath);
        if ($parentChildrenPath === null || $sourceIndex === null) {
            throw new \InvalidArgumentException(
                sprintf('Element "%s" is not addressable for cloning (must end in /children/<i>).', $elementPath),
            );
        }
        $insertIndex = $sourceIndex + 1;
        $existing = JsonPointer::get($state, $parentChildrenPath);
        if (!is_array($existing)) {
            throw new \InvalidArgumentException('Parent children-array missing during clone — state corrupt.');
        }
        /** @var list<mixed> $existing */
        $copy = self::deepCopy($node);
        $existing = array_values($existing);
        array_splice($existing, $insertIndex, 0, [$copy]);
        JsonPointer::set($state, $parentChildrenPath, $existing);

        return $parentChildrenPath . '/' . (string) $insertIndex;
    }

    /**
     * Replace the `props` of the element at $elementPath with $newProps.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $newProps
     */
    public function updateSettings(
        array &$state,
        string $templateId,
        string $elementPath,
        array $newProps
    ): void {
        $node = JsonPointer::get($state, $elementPath);
        if (!is_array($node)) {
            throw new \InvalidArgumentException(
                sprintf('Element "%s" not found — cannot updateSettings.', $elementPath),
            );
        }
        JsonPointer::set($state, $elementPath . '/props', $newProps);
    }

    private static function templateLayoutPath(string $templateId): string
    {
        return JsonPointer::compile(['templates', $templateId, 'layout']);
    }

    /**
     * Given a pointer like `/templates/x/layout/children/3`, return the
     * pointer to the parent children-array `/templates/x/layout/children`.
     * Returns null if the pointer does not end in a digit-index segment.
     */
    private static function stripTrailingIndex(string $pointer): ?string
    {
        if ($pointer === '') {
            return null;
        }
        $segments = JsonPointer::parse($pointer);
        if ($segments === []) {
            return null;
        }
        $last = end($segments);
        if (!ctype_digit($last)) {
            return null;
        }
        array_pop($segments);
        return JsonPointer::compile($segments);
    }

    private static function trailingIndex(string $pointer): ?int
    {
        $segments = JsonPointer::parse($pointer);
        if ($segments === []) {
            return null;
        }
        $last = end($segments);
        if (!ctype_digit($last)) {
            return null;
        }
        return (int) $last;
    }

    /**
     * Deep-copy a node tree by JSON round-trip (cheap and lossless for the
     * JSON-safe shape YOOtheme persists).
     *
     * Wave-6 Fix 16: use JSON_THROW_ON_ERROR + log + bubble; silent
     * fall-through to the source reference made aliasing bugs invisible.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function deepCopy(array $node): array
    {
        try {
            $json = json_encode(
                $node,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if (function_exists('error_log')) {
                \error_log(
                    '[yt-builder-mcp] ElementOps::deepCopy failed: ' . $e->getMessage(),
                );
            }
            throw new \RuntimeException(
                'ElementOps::deepCopy: node tree is not JSON-encodable.',
                0,
                $e,
            );
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('ElementOps::deepCopy: decoded value is not an array.');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
