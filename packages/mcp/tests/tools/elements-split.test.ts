/**
 * Snapshot test for the G.4.0 split of `src/tools/elements.ts` into
 * `src/tools/elements/{index, handlers, builders}.ts`.
 *
 * Goal: prove that the public API of `buildElementsTools()` — names,
 * descriptions, annotations, output-schema presence — is byte-for-byte
 * identical before and after the split. Handler bodies are NOT part of
 * the snapshot (they capture closures over the client); the existing
 * elements.test.ts already exercises every handler.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { RestClient } from '../../src/client.js';
import { buildElementsTools } from '../../src/tools/elements/index.js';

function fakeClient(): RestClient {
    return new RestClient({
        baseUrl: 'https://example.com',
        bearerToken: 't',
        fetch: vi.fn(async () => new Response('{}')) as unknown as typeof fetch,
    });
}

describe('elements-split snapshot — public surface preserved', () => {
    it('buildElementsTools produces the same 7 tools with identical metadata', () => {
        const tools = buildElementsTools(fakeClient());

        // Strip the closure-bound handler and just snapshot the
        // wire-visible metadata; output-schema is collapsed to a
        // boolean "present?" flag because Zod schemas don't serialize
        // stably.
        const wire = tools.map((t) => ({
            name: t.name,
            description: t.description,
            annotations: t.annotations,
            inputSchemaKeys: Object.keys(t.inputSchema).sort(),
            hasOutputSchema: t.outputSchema !== undefined,
        }));

        expect(wire).toMatchInlineSnapshot(`
          [
            {
              "annotations": {
                "destructiveHint": false,
                "idempotentHint": true,
                "openWorldHint": false,
                "readOnlyHint": true,
                "title": "List Elements",
              },
              "description": "List all elements in a template as a flat array with JSON-Pointer paths + element types. Best starting-point for "find the element I want to edit". Pass \`fields:["path","element_type"]\` to narrow each row.",
              "hasOutputSchema": true,
              "inputSchemaKeys": [
                "fields",
                "template_id",
              ],
              "name": "yootheme_builder_element_list",
            },
            {
              "annotations": {
                "destructiveHint": false,
                "idempotentHint": true,
                "openWorldHint": false,
                "readOnlyHint": true,
                "title": "Get Element",
              },
              "description": "Get the full element object at a specific JSON-Pointer path, including props and children. Use yootheme_builder_element_list to discover paths.",
              "hasOutputSchema": true,
              "inputSchemaKeys": [
                "element_path",
                "template_id",
              ],
              "name": "yootheme_builder_element_get",
            },
            {
              "annotations": {
                "destructiveHint": false,
                "idempotentHint": false,
                "openWorldHint": false,
                "readOnlyHint": false,
                "title": "Add Element",
              },
              "description": "Add a new element to a template. Provide \`parent_path\` (or "" for root), \`element_type\` (e.g. "headline", "text", "grid"), and optional \`props\` / \`children\`. Returns the new element's JSON-Pointer path. Requires ETag.",
              "hasOutputSchema": false,
              "inputSchemaKeys": [
                "children",
                "element_type",
                "etag",
                "parent_path",
                "props",
                "template_id",
              ],
              "name": "yootheme_builder_element_add",
            },
            {
              "annotations": {
                "destructiveHint": false,
                "idempotentHint": true,
                "openWorldHint": false,
                "readOnlyHint": false,
                "title": "Update Element Settings",
              },
              "description": "Update \`props\` on an element. Default replaces all props; pass \`merge:true\` for server-side deep-merge (only request keys overwritten, others survive — avoids read-modify-write races). Requires ETag.",
              "hasOutputSchema": false,
              "inputSchemaKeys": [
                "element_path",
                "etag",
                "merge",
                "props",
                "template_id",
              ],
              "name": "yootheme_builder_element_update_settings",
            },
            {
              "annotations": {
                "destructiveHint": false,
                "idempotentHint": true,
                "openWorldHint": false,
                "readOnlyHint": false,
                "title": "Move Element",
              },
              "description": "Move an element to a new parent + index in the tree. Useful for reordering or reparenting (e.g. moving a card from one grid column to another). Requires ETag.",
              "hasOutputSchema": false,
              "inputSchemaKeys": [
                "element_path",
                "etag",
                "template_id",
                "to_index",
                "to_parent_path",
              ],
              "name": "yootheme_builder_element_move",
            },
            {
              "annotations": {
                "destructiveHint": false,
                "idempotentHint": false,
                "openWorldHint": false,
                "readOnlyHint": false,
                "title": "Clone Element",
              },
              "description": "Clone an element as a sibling (same parent, immediately after the source). Returns the new element's path. Requires ETag.",
              "hasOutputSchema": false,
              "inputSchemaKeys": [
                "element_path",
                "etag",
                "template_id",
              ],
              "name": "yootheme_builder_element_clone",
            },
            {
              "annotations": {
                "destructiveHint": true,
                "idempotentHint": false,
                "openWorldHint": false,
                "readOnlyHint": false,
                "title": "Delete Element",
              },
              "description": "PERMANENTLY delete an element and all its children. Cannot be undone. Always ask the user to confirm first, then call again with \`confirm: true\`. Requires ETag.",
              "hasOutputSchema": false,
              "inputSchemaKeys": [
                "confirm",
                "element_path",
                "etag",
                "template_id",
              ],
              "name": "yootheme_builder_element_delete",
            },
          ]
        `);
    });

    it('legacy `src/tools/elements.js` import path still resolves (re-export shim)', async () => {
        // Existing call-sites use `from '../tools/elements.js'`; the split
        // must keep that import shape working via a re-export shim.
        const legacy = await import('../../src/tools/elements.js');
        expect(typeof legacy.buildElementsTools).toBe('function');
        expect(legacy.buildElementsTools(fakeClient())).toHaveLength(7);
    });
});
