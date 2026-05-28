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

use WootsUp\BuilderMcp\SourceBinding\BindingSerializer;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\State\LayoutReaderInterface;

final class ElementOps
{
    public function __construct(private readonly LayoutReaderInterface $reader)
    {
    }

    /**
     * Return a flat depth-first list of every element on the template, or
     * null if the template is unknown.
     *
     * Each entry exposes (F-01 normalized):
     *  - path:          JSON-Pointer into the global wp_option document
     *  - element_type:  canonical wire field (lowercase element kind).
     *                   The MCP TS row-mapper reads this directly.
     *  - type:          legacy alias of element_type (back-compat).
     *  - label?:        human-readable label (alias of node.name).
     *  - props_summary: list of prop keys present on the node, no values
     *                   (callers fetch full settings via getSettings()).
     *  - has_binding:   true when the node carries a source binding.
     *  - child_count:   number of direct children.
     *
     * N-01 (Audit-v3): the optional `$options` argument adds transport-safe
     * scoping for deep templates. Recognised keys:
     *  - root_path (string): only walk the subtree at this JSON-Pointer.
     *  - depth     (int):    cap recursion to N levels of descendants.
     *  - limit     (int):    page size — switches the return shape to the
     *                        pagination envelope `{items, next_cursor, total}`.
     *  - cursor    (string): opaque continuation token from a prior call.
     *
     * Return-shape contract:
     *  - No `limit` key → flat list (backward-compatible, original shape).
     *  - With `limit`   → envelope `{items: list<…>, next_cursor: ?string,
     *                     total: int}`. `total` is the full count BEFORE
     *                     pagination; `next_cursor` is null on the last page.
     *
     * @param array{root_path?: string, depth?: int, limit?: int, cursor?: string} $options
     * @return list<array{path: string, element_type: string, type: string, label?: string, props_summary: list<string>, has_binding: bool, child_count: int, props?: array<string, mixed>, content_preview?: string}>|array{items: list<array<string, mixed>>, next_cursor: ?string, total: int}|null
     */
    public function listOnTemplate(string $templateId, array $options = []): ?array
    {
        $tpl = $this->reader->readTemplate($templateId);
        if ($tpl === null) {
            return null;
        }

        // Resolve the walk root: either the whole layout, or the subtree
        // addressed by `root_path` (N-01 subtree-scoping).
        $walkRoot = isset($tpl['layout']) && is_array($tpl['layout']) ? $tpl['layout'] : null;
        $basePointer = JsonPointer::compile(['templates', $templateId, 'layout']);

        $rootPath = isset($options['root_path']) && is_string($options['root_path'])
            ? $options['root_path']
            : '';
        if ($rootPath !== '') {
            $subtree = $this->reader->readByPointer($rootPath);
            $walkRoot = is_array($subtree) ? $subtree : null;
            $basePointer = $rootPath;
        }

        $maxDepth = isset($options['depth']) && is_int($options['depth'])
            ? $options['depth']
            : null;

        $out = [];
        if ($walkRoot !== null) {
            foreach (TreeWalker::walk($walkRoot, $basePointer, $maxDepth) as [$pointer, $node]) {
                $type = isset($node['type']) && is_string($node['type'])
                    ? $node['type']
                    : 'unknown';
                $entry = [
                    'path' => $pointer,
                    'element_type' => $type,
                    'type' => $type,
                    'props_summary' => self::propKeys($node),
                    'has_binding' => self::hasBinding($node),
                    'child_count' => isset($node['children']) && is_array($node['children'])
                        ? count(array_filter($node['children'], 'is_array'))
                        : 0,
                ];
                if (isset($node['name']) && is_string($node['name'])) {
                    $entry['label'] = $node['name'];
                }
                // 1.0.1 Wave-1.8 F-COLD-12: opt-in `include=props` forwards
                // the full props map per row (default stays `props_summary`-
                // only, slim). Used by a11y/audit workflows that need
                // values, not just keys.
                if (
                    isset($options['include'])
                    && is_array($options['include'])
                    && in_array('props', $options['include'], true)
                    && isset($node['props'])
                    && is_array($node['props'])
                ) {
                    $entry['props'] = $node['props'];
                }
                // 1.0.1 Wave-1.8 F-COLD-10: cold-agent S2/S4 burned a
                // second element_get just to disambiguate two headlines
                // with the same name+type but different `content` strings.
                // Surface the first 60 chars of the most-likely text
                // field (`content` / `title` / `value`) so the caller can
                // pick the right node directly from element_list output.
                $preview = self::contentPreview($node);
                if ($preview !== null) {
                    $entry['content_preview'] = $preview;
                }
                $out[] = $entry;
            }
        }

        // No `limit` → original flat-list shape (backward-compatible).
        if (!isset($options['limit']) || !is_int($options['limit'])) {
            return $out;
        }

        // Pagination envelope. The cursor is an opaque base64url-encoded
        // integer offset; it is only valid within one state snapshot
        // (callers re-paginate from scratch if the ETag changed).
        $limit = max(1, $options['limit']);
        $total = count($out);
        $offset = 0;
        if (isset($options['cursor']) && is_string($options['cursor']) && $options['cursor'] !== '') {
            $decoded = self::decodeCursor($options['cursor']);
            if ($decoded !== null) {
                $offset = $decoded;
            }
        }
        $items = array_slice($out, $offset, $limit);
        $nextOffset = $offset + $limit;
        $nextCursor = $nextOffset < $total ? self::encodeCursor($nextOffset) : null;

        return [
            'items' => array_values($items),
            'next_cursor' => $nextCursor,
            'total' => $total,
        ];
    }

    /** Encode an integer offset as an opaque base64url cursor token. */
    private static function encodeCursor(int $offset): string
    {
        return rtrim(strtr(base64_encode('o:' . (string) $offset), '+/', '-_'), '=');
    }

    /** Decode a base64url cursor token back to an integer offset, or null. */
    private static function decodeCursor(string $cursor): ?int
    {
        $padded = strtr($cursor, '-_', '+/');
        $padLen = (4 - (strlen($padded) % 4)) % 4;
        $decoded = base64_decode($padded . str_repeat('=', $padLen), true);
        if ($decoded === false || !str_starts_with($decoded, 'o:')) {
            return null;
        }
        $n = substr($decoded, 2);
        if (!ctype_digit($n)) {
            return null;
        }
        return (int) $n;
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

    /**
     * 1.0.1 Wave-1.8 F-COLD-10 / audit-pass v2: max length for the
     * `content_preview` text projection in element_list rows. Tuned to
     * give cold agents enough to disambiguate two same-type elements
     * without bloating the response — most h1 headlines / call-to-
     * action labels fit; longer body text is cleanly elided.
     */
    private const CONTENT_PREVIEW_MAX_LEN = 60;

    /**
     * 1.0.1 Wave-1.8 F-COLD-10: derive a short text preview from the
     * node's most-likely text-bearing prop. Returns null when nothing
     * useful is present (decorative/structural elements like section /
     * row / column / grid). Strips HTML tags and trims to
     * CONTENT_PREVIEW_MAX_LEN chars so the element_list row stays slim.
     *
     * Priority: props.content > props.title > props.value > props.text
     * (mirrors YT-Pro's text-element conventions).
     *
     * @param array<string, mixed> $node
     */
    private static function contentPreview(array $node): ?string
    {
        if (!isset($node['props']) || !is_array($node['props'])) {
            return null;
        }
        /** @var array<string, mixed> $props */
        $props = $node['props'];
        foreach (['content', 'title', 'value', 'text'] as $key) {
            if (!isset($props[$key]) || !is_string($props[$key]) || $props[$key] === '') {
                continue;
            }
            $stripped = trim(strip_tags($props[$key]));
            if ($stripped === '') {
                continue;
            }
            // Use mb_substr so multibyte text doesn't get clipped mid-char.
            if (\function_exists('mb_substr') && \function_exists('mb_strlen')) {
                if (mb_strlen($stripped) <= self::CONTENT_PREVIEW_MAX_LEN) {
                    return $stripped;
                }
                return mb_substr($stripped, 0, self::CONTENT_PREVIEW_MAX_LEN) . '…';
            }
            if (strlen($stripped) <= self::CONTENT_PREVIEW_MAX_LEN) {
                return $stripped;
            }
            return substr($stripped, 0, self::CONTENT_PREVIEW_MAX_LEN) . '…';
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // F-01 — canonical element-view normalizer.
    //
    // The raw YT layout-node stores its element kind under `type`. The MCP
    // TS toolkit reads `element_type` (a wider, label-friendlier key —
    // `type` collides with TS-table column keys and HTTP MIME types). The
    // normalizer below produces ONE canonical wire shape consumed by:
    //   • element_get  (full detail — keep nested children as raw)
    //   • element_list (row shape — children/props summarised by upstream)
    //   • page_get_schema (flat node list — element_type + has_binding)
    //
    // Maria-Audit 2026-05-22 surfaced that element_get was returning empty
    // `type`/`props`/`children`/`binding` because PHP wrapped the node in
    // `{element: <node>}` and the TS reader expected those keys at top-level.
    // The structural fix is to expose a flat normalized record — `element`
    // is kept as a legacy alias for back-compat with older builds.
    // ---------------------------------------------------------------------

    /**
     * Project a raw layout node to the canonical MCP wire shape.
     *
     * Output fields:
     *   - path:         JSON-Pointer into the global wp_option document
     *                   (callers pass it in; never derived here).
     *   - element_type: lowercase element-kind ('section', 'headline', ...).
     *                   Canonical wire field — the raw layout-node uses
     *                   `type`. We surface BOTH so legacy MCP-clients still
     *                   work.
     *   - type:         legacy alias of element_type.
     *   - label:        human-readable label (alias of node.name, when present).
     *   - props:        the raw `props` object (string-keyed; missing → {}).
     *   - children:     the raw `children` array — list of nested nodes
     *                   in their original (NOT normalized) shape so the
     *                   detail-render in the TS layer can walk them.
     *   - has_binding:  true when `props.source` is present (string or
     *                   structured F-13 shape).
     *   - child_count:  count(children).
     *
     * @param array<string, mixed> $node
     * @return array{path: string, element_type: string, type: string, label?: string, props: array<string, mixed>, children: list<array<string, mixed>>, has_binding: bool, child_count: int}
     */
    public static function flattenNode(array $node, string $path): array
    {
        $type = isset($node['type']) && is_string($node['type']) ? $node['type'] : 'unknown';
        $props = isset($node['props']) && is_array($node['props']) ? $node['props'] : [];
        /** @var array<string, mixed> $props */

        $children = [];
        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                if (!is_array($child)) {
                    continue;
                }
                /** @var array<string, mixed> $child */
                $children[] = $child;
            }
        }

        $entry = [
            'path' => $path,
            'element_type' => $type,
            'type' => $type,
            'props' => $props,
            'children' => $children,
            'has_binding' => self::hasBinding($node),
            'child_count' => count($children),
        ];
        if (isset($node['name']) && is_string($node['name'])) {
            $entry['label'] = $node['name'];
        }
        return $entry;
    }

    /**
     * D1 / T1 (F-01-Rest, 2026-05-22): single source-of-truth via
     * BindingSerializer. Recognises legacy bare-string, F-13 canonical
     * `props.source.query.name`, top-level `node.source`, cached
     * `node.source_extended`, AND nodes whose only binding indicator is
     * a `props.<el>.name` field-mapping (inherit-from-parent pattern).
     *
     * @param array<string, mixed> $node
     */
    private static function hasBinding(array $node): bool
    {
        return BindingSerializer::hasBinding($node);
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
        // Replacement props pass through verbatim. Customer authorship is
        // respected. See add() above for rationale.
        JsonPointer::set($state, $elementPath . '/props', $newProps);
    }

    /**
     * Deep-merge $patch into $current — keys present in $patch overwrite
     * keys in $current; keys NOT in $patch survive unchanged. The recursive
     * descent kicks in for associative arrays only — list arrays (numerically
     * indexed) are replaced atomically by the patch value because index-
     * level merging produces unreadable garbage (see canonical pin in
     * tests/php/unit/Elements/ElementOpsTest.php).
     *
     * T5 / F-12 (Maria-Audit 2026-05-22): the controller `update_settings`
     * endpoint accepts an optional `merge` boolean. When true it fetches the
     * current props, calls this helper, and writes the merged result —
     * avoiding the read-modify-write race that the client would otherwise
     * face when extending a sub-key without clobbering siblings.
     *
     * Type-mismatch policy: when $current[$k] and $patch[$k] hold different
     * shapes (e.g. string vs array), the patch value wins. This keeps the
     * merge predictable: callers know "request always wins" for any key
     * they specified.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public static function mergeProps(array $current, array $patch): array
    {
        if ($patch === []) {
            return $current;
        }
        if ($current === []) {
            return $patch;
        }
        $out = $current;
        foreach ($patch as $key => $value) {
            $haveCurrent = array_key_exists($key, $out);
            if ($haveCurrent && is_array($out[$key]) && is_array($value)
                && self::isAssoc($out[$key]) && self::isAssoc($value)
            ) {
                /** @var array<string, mixed> $currentChild */
                $currentChild = $out[$key];
                /** @var array<string, mixed> $patchChild */
                $patchChild = $value;
                $out[$key] = self::mergeProps($currentChild, $patchChild);
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * Treat any array as associative unless it is an empty array (which is
     * ambiguous — opt-in to "associative" so the empty patch behaves as a
     * no-op merge target instead of clobbering the current value with []).
     *
     * Numerically-indexed lists are detected via array_is_list() so
     * (`['a','b','c']`) returns false and is treated as atomic for the
     * purposes of mergeProps.
     *
     * @param array<int|string, mixed> $array
     */
    private static function isAssoc(array $array): bool
    {
        if ($array === []) {
            return true;
        }
        return !array_is_list($array);
    }

    private static function templateLayoutPath(string $templateId): string
    {
        return JsonPointer::compile(['templates', $templateId, 'layout']);
    }

    /**
     * Recursively sanitize the `props` map of every node in a children
     * tree. Wave-1 Finding C-5 (XSS): callers can pass a nested
     * `children` array into ElementOps::add containing untrusted props
     * at any depth. Walk the tree, sanitize each node's `props`, recurse
     * into the node's own `children`.
     *
     * @param list<array<string, mixed>> $children
     * @return list<array<string, mixed>>
     */
    // sanitizeChildrenTrees removed 2026-05-28 per Thomas: do not strip
    // customer-supplied markup. Children trees now pass through verbatim
    // along with their props. See add() comment for rationale.

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
