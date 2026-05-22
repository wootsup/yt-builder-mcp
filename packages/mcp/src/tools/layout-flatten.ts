/**
 * Layout-flatten helper — depth-first walk that turns a nested YOOtheme
 * layout tree into a flat array of `{ path, parent_path, depth,
 * element_type, props }` records.
 *
 * Used by `yootheme_builder_page_get_layout` when called with
 * `flat: true`. Per Design §4.4.1:
 *
 *   - Walk-order: depth-first, parent BEFORE children, original child
 *     order preserved (no sort).
 *   - `path` uses JSON-Pointer-style notation rooted at `/layout/<key>`
 *     and descends via `/children/<index>` segments. The root segments
 *     mirror the keys the REST plugin produces (string keys "0","1",…
 *     when the top-level layout is an object; numeric indices when it
 *     happens to be an array).
 *   - `parent_path` is `null` for top-level entries.
 *   - The emitted record's `children` field is REMOVED — children become
 *     their own emitted entries with their own `path`. Keeping the nested
 *     array would defeat the purpose of flattening.
 *   - Non-object children are skipped silently but their index is
 *     preserved in the parent's child-numbering (the next valid sibling
 *     keeps its original index).
 *
 * Pure function — no I/O, no client dependency. Test-covered in
 * `tests/tools/layout-flatten.test.ts`.
 *
 * @license MIT
 */

/** A single emitted record in the flattened layout. */
export interface FlatElement {
    /** JSON-Pointer-style path, e.g. "/layout/0/children/2". */
    path: string;
    /** Path of the parent element, or `null` for top-level entries. */
    parent_path: string | null;
    /** 0-based depth (top-level entries have depth 0). */
    depth: number;
    /** Element type string (the `type` field on the YT node, "" if missing). */
    element_type: string;
    /** Element props (passthrough; `undefined` when the node has none). */
    props?: Record<string, unknown>;
    /**
     * Children intentionally absent — see file-header. Kept in the type
     * so consumers can confirm the field is `undefined` rather than
     * assume it exists.
     */
    children?: undefined;
    /**
     * Allow passthrough of any other top-level fields the REST plugin
     * may add in future (e.g. `label`, `has_binding`, `name`). The
     * walker preserves these verbatim so projection via `pickFields`
     * downstream stays meaningful.
     */
    [extra: string]: unknown;
}

/**
 * Flatten a YOOtheme nested layout tree.
 *
 * @param layout - The top-level layout container. Accepts either an
 *   object keyed by stringified indices ("0","1",…) or an actual array.
 *   Returns `[]` for empty input or anything that isn't iterable.
 */
export function flattenLayout(layout: unknown): FlatElement[] {
    const out: FlatElement[] = [];

    if (layout === null || typeof layout !== 'object') {
        return out;
    }

    // Iterate top-level entries in insertion order. For arrays this gives
    // numeric indices; for objects keyed by "0","1",… it gives the same
    // ordering YT serialises.
    const entries = Array.isArray(layout)
        ? layout.map((v, i): [string, unknown] => [String(i), v])
        : Object.entries(layout as Record<string, unknown>);

    for (const [key, node] of entries) {
        walk(node, `/layout/${key}`, null, 0, out);
    }

    return out;
}

/**
 * Depth-first walker. Emits the node, then recurses into its children.
 * Skips non-object nodes silently while preserving child-index numbering.
 */
function walk(
    node: unknown,
    path: string,
    parentPath: string | null,
    depth: number,
    out: FlatElement[],
): void {
    if (node === null || typeof node !== 'object' || Array.isArray(node)) {
        return;
    }
    const obj = node as Record<string, unknown>;

    const elementType = typeof obj.type === 'string' ? obj.type : '';
    const props =
        obj.props !== null && typeof obj.props === 'object' && !Array.isArray(obj.props)
            ? (obj.props as Record<string, unknown>)
            : undefined;

    // Build a passthrough shallow-clone WITHOUT the children field. We
    // copy every other top-level key so future REST additions (label,
    // has_binding, …) survive the flatten.
    const emitted: FlatElement = {
        path,
        parent_path: parentPath,
        depth,
        element_type: elementType,
    };
    for (const [k, v] of Object.entries(obj)) {
        if (k === 'children' || k === 'type' || k === 'props') continue;
        emitted[k] = v;
    }
    if (props !== undefined) emitted.props = props;
    out.push(emitted);

    const children = obj.children;
    if (Array.isArray(children)) {
        for (let i = 0; i < children.length; i++) {
            const child = children[i];
            if (child === null || typeof child !== 'object' || Array.isArray(child)) {
                // Skip non-object children; their index is preserved in
                // the next sibling's path.
                continue;
            }
            walk(child, `${path}/children/${String(i)}`, path, depth + 1, out);
        }
    }
}
