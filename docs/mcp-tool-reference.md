# MCP Tool Reference

All 21 tools exposed by `@wootsup/yt-builder-mcp`. Each tool documents:

- **Name**: the MCP tool name (use this in your AI prompt)
- **Description**: what it does
- **Input schema**: Zod schema (TypeScript)
- **Output**: JSON shape on success

All tools return errors as `{ "error": "...", "context": {...}, "hint": "..." }`.

The tools are organised into five domains: **Health**, **Pages**, **Elements**, **Sources**, **Inspection**.

---

## Health (2 tools)

### `yootheme_builder_health`

Returns plugin version, YOOtheme version, CMS version, storage backend, and the list
of available REST endpoints. The MCP server always sends its Bearer token, so this
tool receives the **augmented** `/health` payload (the anonymous probe returns only
`{plugin_version, status}`, see the [REST reference](./rest-api-reference.md#get-health)
for the field-split). Identical contract on WordPress and Joomla; the CMS-specific
fields differ (`wp_version` vs Joomla version, storage target).

- **Annotation:** read-only
- **Input schema:** `{}`
- **Output (WordPress):**

```json
{
  "plugin_version": "1.1.0",
  "status": "ok",
  "yootheme_version": "4.5.33",
  "wp_version": "6.5.0",
  "php_version": "8.3.6",
  "storage_type": "wp_option",
  "storage_target": "yootheme",
  "yootheme_loaded": true,
  "schema_version": 1,
  "available_endpoints_count": 25,
  "available_endpoints": ["/yt-builder-mcp/v1/health", "/yt-builder-mcp/v1/pages", "..."]
}
```

On **Joomla** the shape is the same minus the WP-only fields (`wp_version`,
`storage_type`/`storage_target`), with the endpoints listed under the
`/v1/yt-builder-mcp/*` namespace served at `/api/index.php/`.

### `yootheme_builder_diagnose`

Connectivity + auth check. Returns hints when something is misconfigured.

- **Annotation:** read-only
- **Input schema:** `{}`
- **Output:**

```json
{
  "ok": true,
  "checks": {
    "reachable": true,
    "authenticated": true,
    "yootheme_loaded": true
  },
  "hints": []
}
```

---

## Pages (6 tools)

### `yootheme_builder_pages_list`

List all YOOtheme templates ("pages") on the site. Returns id, label and usage metadata. Use this first to discover template IDs.

- **Annotation:** read-only
- **Input schema:** `{}`
- **Output:** `{ pages: [...], etag: string }`

### `yootheme_builder_page_get_layout`

Get the full layout tree for one template. Heavy. Prefer `yootheme_builder_page_get_schema` for navigation.

- **Annotation:** read-only
- **Input schema:**

```ts
{
  template_id: z.string().min(1),
}
```

- **Output:** `{ template_id, layout: [...], etag }`

### `yootheme_builder_page_get_schema`

Flat schema view (nodes + paths). Preferred for navigation.

- **Annotation:** read-only
- **Input schema:** `{ template_id: z.string().min(1) }`
- **Output:** `{ template_id, nodes: [{ path, type, name? }, ...], etag }`

### `yootheme_builder_get_etag`

Get the current top-level ETag for optimistic-locking.

- **Annotation:** read-only
- **Input schema:** `{}`
- **Output:** `{ etag: string }`

### `yootheme_builder_page_save`

Re-run save-transforms + persist.

- **Annotation:** mutating
- **Input schema:**

```ts
{
  template_id: z.string().min(1),
  etag: z.string().optional(),
}
```

- **Output:** `{ saved: true, etag: string }`

### `yootheme_builder_page_publish`

Publish a template.

- **Annotation:** mutating
- **Input schema:** same as `page_save`
- **Output:** `{ published: true, etag: string }`

---

## Elements (7 tools)

### `yootheme_builder_element_list`

List elements within a template.

- **Annotation:** read-only
- **Input schema:** `{ template_id: z.string().min(1) }`
- **Output:** `{ elements: [{ path, type, name? }, ...] }`

### `yootheme_builder_element_get`

Get one element by path.

- **Annotation:** read-only
- **Input schema:**

```ts
{
  template_id: z.string().min(1),
  element_path: z.string().min(1),
}
```

- **Output:** `{ element: { type, props, children? } }`

### `yootheme_builder_element_add`

Add a new element under a parent path.

- **Annotation:** creating
- **Input schema:**

```ts
{
  template_id: z.string().min(1),
  parent_path: z.string().min(1),
  element: z.object({
    type: z.string(),
    props: z.record(z.unknown()).optional(),
    children: z.array(z.unknown()).optional(),
  }),
  position: z.enum(['append', 'prepend']).default('append'),
  etag: z.string().optional(),
}
```

- **Output:** `{ added: true, path: string, etag: string }`

### `yootheme_builder_element_update_settings`

Update settings (props) for an existing element.

- **Annotation:** mutating
- **Input schema:**

```ts
{
  template_id: z.string().min(1),
  element_path: z.string().min(1),
  props: z.record(z.unknown()),
  etag: z.string().optional(),
}
```

- **Output:** `{ updated: true, etag: string }`

### `yootheme_builder_element_move`

Move an element to a new path.

- **Annotation:** mutating
- **Input schema:**

```ts
{
  template_id: z.string().min(1),
  element_path: z.string().min(1),
  target_parent_path: z.string().min(1),
  position: z.enum(['append', 'prepend']).default('append'),
  etag: z.string().optional(),
}
```

- **Output:** `{ moved: true, new_path: string, etag: string }`

### `yootheme_builder_element_clone`

Clone an element (deep-copy, placed as a sibling).

- **Annotation:** creating
- **Input schema:**

```ts
{
  template_id: z.string().min(1),
  element_path: z.string().min(1),
  etag: z.string().optional(),
}
```

- **Output:** `{ cloned: true, path: string, etag: string }`

### `yootheme_builder_element_delete`

Delete an element.

- **Annotation:** destructive
- **Input schema:**

```ts
{
  template_id: z.string().min(1),
  element_path: z.string().min(1),
  etag: z.string().optional(),
}
```

- **Output:** `{ deleted: true, etag: string }`

---

## Sources (4 tools)

### `yootheme_builder_sources_list`

List Dynamic Sources available (e.g. API Mapper sources).

- **Annotation:** read-only
- **Input schema:** `{}`
- **Output:** `{ sources: [{ name, label, fields: [...] }, ...] }`

### `yootheme_builder_element_get_binding`

Get the current Dynamic Source binding for an element.

- **Annotation:** read-only
- **Input schema:**

```ts
{
  template_id: z.string().min(1),
  element_path: z.string().min(1),
}
```

- **Output:** `{ binding: { source_name, field_map } | null }`

### `yootheme_builder_element_bind_source`

Bind a Dynamic Source to an element.

- **Annotation:** mutating
- **Input schema:**

```ts
{
  template_id: z.string().min(1),
  element_path: z.string().min(1),
  source_name: z.string().min(1),
  field_map: z.record(z.string()).optional(),
  etag: z.string().optional(),
}
```

- **Output:** `{ bound: true, etag: string }`

### `yootheme_builder_element_unbind_source`

Remove the binding.

- **Annotation:** destructive
- **Input schema:**

```ts
{
  template_id: z.string().min(1),
  element_path: z.string().min(1),
  etag: z.string().optional(),
}
```

- **Output:** `{ unbound: true, etag: string }`

---

## Inspection (2 tools)

### `yootheme_builder_element_types_list`

List all element types YOOtheme exposes (grid, headline, image, …).

- **Annotation:** read-only
- **Input schema:** `{}`
- **Output:** `{ element_types: [{ name, label }, ...] }`

### `yootheme_builder_element_type_get_schema`

Get the JSON schema for one element type.

- **Annotation:** read-only
- **Input schema:**

```ts
{
  type_name: z.string().min(1),
}
```

- **Output:**

```json
{
  "type_name": "grid",
  "schema": {
    "props": {
      "columns": { "type": "integer", "default": 3 },
      ...
    }
  }
}
```

---

## Calling pattern

A typical multi-step workflow looks like this:

```
1. yootheme_builder_diagnose             → confirm connectivity
2. yootheme_builder_pages_list           → discover template IDs
3. yootheme_builder_page_get_schema      → navigate the page
4. yootheme_builder_get_etag             → grab ETag
5. yootheme_builder_element_add          → mutate (pass etag)
6. yootheme_builder_page_save            → persist (pass etag)
```

Pass the `etag` returned from each write into the next write to keep the
optimistic lock chain valid. If you skip this and the page changed under
you, the next write returns a `412` error and your handler should re-read
state and decide whether to retry.

---

## Error envelope

Every tool returns errors in a consistent shape:

```json
{
  "error": "HTTP 412",
  "context": { "template_id": "default", "etag_sent": "abc123" },
  "hint": "ETag mismatch. Re-read yootheme_builder_get_etag and retry."
}
```

When you see this in your AI chat, the assistant should call
`yootheme_builder_diagnose` if the error mentions auth or connectivity.

---

## Annotations

Each tool carries an MCP annotation describing its side-effects:

- **`readOnly`**: safe to call without confirmation
- **`creating`**: adds something new
- **`mutating`**: changes existing data
- **`destructive`**: irreversible

Some AI clients (notably Claude Desktop) surface these annotations in
their permission prompts. Destructive tools always ask before running.
