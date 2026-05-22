# REST API Reference

All endpoints live under the `yootheme-builder-mcp/v1` namespace:

```
https://example.com/wp-json/yootheme-builder-mcp/v1/<endpoint>
```

## Authentication

All endpoints except `/health` require a **Bearer token** generated in WP-Admin → YOOtheme Builder MCP → Settings.

```
Authorization: Bearer ytbmcp_live_eyJh…
```

Keys are verified with constant-time `hash_equals()` against an HMAC-SHA256
hash stored in `wp_options`. The plaintext key is never stored.

## Optimistic locking (ETag)

Every write endpoint expects an `If-Match` header carrying the ETag the
client last read. If the server's current ETag differs, the write returns
`412 Precondition Failed` and does nothing.

```
If-Match: "abc123"
```

`If-Match: *` is accepted as a wildcard and bypasses the check
(RFC-7232 §3.1).

PUT/DELETE/save/publish writes **require** `If-Match` and return
`428 Precondition Required` if the header is missing. POST create-element
(`POST /pages/{template_id}/elements`) treats missing `If-Match` as
opt-out (proceeds without concurrency guard) — this is intentional so that
new-element drafts can be appended without a prior read round-trip.

The current ETag is returned in:

- The `ETag` response header on every read.
- The `etag` field in the response body of `/pages` and `/etag`.

## Error format

All errors follow the WordPress REST error envelope:

```json
{
  "code": "yootheme_builder_mcp.precondition_failed",
  "message": "If-Match header (abc123) does not match current resource ETag (def456).",
  "data": {
    "status": 412,
    "expected_etag": "def456"
  }
}
```

## Status codes

| Code | Meaning |
|------|---------|
| 200 | Success (read) |
| 201 | Created (write) |
| 204 | No content (delete) |
| 400 | Validation error — check the message |
| 401 | Auth failed — Bearer key wrong or missing |
| 404 | Resource (template / element / source) not found |
| 412 | If-Match ETag mismatch — re-read and retry |
| 500 | Internal error — check server logs |

---

## Endpoints

### `GET /health`

Public. Returns plugin and environment info. Used by the setup wizard to
sanity-check connectivity before the user even pastes their key.

**Auth required:** No.

**Response 200:**

```json
{
  "plugin_version": "0.1.0-alpha.1",
  "yootheme_version": "4.5.2",
  "wp_version": "6.5.0",
  "php_version": "8.2.18",
  "storage_type": "wp_option",
  "storage_target": "yootheme",
  "yootheme_loaded": true,
  "available_endpoints": [
    "/yootheme-builder-mcp/v1/health",
    "/yootheme-builder-mcp/v1/pages",
    ...
  ]
}
```

**Example:**

```bash
curl https://example.com/wp-json/yootheme-builder-mcp/v1/health
```

---

### `GET /etag`

Returns the current top-level state ETag for optimistic-lock writes.

**Auth required:** Yes.

**Response 200:**

```json
{ "etag": "abc123def456" }
```

**Example:**

```bash
curl -H "Authorization: Bearer $KEY" \
  https://example.com/wp-json/yootheme-builder-mcp/v1/etag
```

---

### `GET /pages`

List all YOOtheme templates.

**Auth required:** Yes.

**Response 200:**

```json
{
  "pages": [
    {
      "id": "default",
      "label": "Default",
      "usage": { "active": true, "post_types": ["page", "post"] }
    },
    { "id": "post-archive", "label": "Post Archive", "usage": { "active": true } }
  ],
  "etag": "abc123def456"
}
```

---

### `GET /pages/{template_id}/layout`

Get the full nested layout tree for one template. Heavy — prefer
`/schema` for navigation.

**Auth required:** Yes.

**Path params:**

- `template_id` — template ID (e.g. `default`).

**Response 200:**

```json
{
  "template_id": "default",
  "layout": [
    { "type": "section", "name": "Hero", "children": [...] }
  ],
  "etag": "abc123def456"
}
```

---

### `GET /pages/{template_id}/schema`

Flat schema view — every node + its JSON-Pointer path. Preferred for
navigation; use `/layout` only when you need the raw tree.

**Auth required:** Yes.

**Response 200:**

```json
{
  "template_id": "default",
  "nodes": [
    { "path": "section/0", "type": "section", "name": "Hero" },
    { "path": "section/0/row/0", "type": "row" },
    { "path": "section/0/row/0/column/0", "type": "column" },
    { "path": "section/0/row/0/column/0/headline", "type": "headline" }
  ],
  "etag": "abc123def456"
}
```

---

### `POST /pages/{template_id}/save`

Re-run YOOtheme save-transforms + persist.

**Auth required:** Yes. **If-Match required for safety.**

**Response 200:**

```json
{ "saved": true, "etag": "newetag789" }
```

---

### `POST /pages/{template_id}/publish`

Publish a template. Currently an alias for `save` with the `published` flag set.

**Auth required:** Yes. **If-Match required for safety.**

**Response 200:**

```json
{ "published": true, "etag": "newetag789" }
```

---

### `GET /pages/{template_id}/elements`

List elements within a template. Lighter than `/layout`.

**Auth required:** Yes.

**Response 200:**

```json
{
  "elements": [
    { "path": "section/0", "type": "section", "name": "Hero" },
    { "path": "section/0/row/0/column/0/headline", "type": "headline" }
  ]
}
```

---

### `GET /pages/{template_id}/elements/{element_path}`

Get one element by its JSON-Pointer path.

**Auth required:** Yes.

**Path params:**

- `element_path` — e.g. `section/0/row/0/column/0/grid`.

**Response 200:**

```json
{
  "element": {
    "type": "grid",
    "props": { "columns": 3, "gap": "medium" },
    "children": [...]
  }
}
```

---

### `POST /pages/{template_id}/elements`

Add a new element.

**Auth required:** Yes. **If-Match required.**

**Request body:**

```json
{
  "parent_path": "section/0/row/0/column/0",
  "element": {
    "type": "headline",
    "props": { "content": "Hello from Claude" }
  },
  "position": "append"
}
```

**Response 201:**

```json
{
  "added": true,
  "path": "section/0/row/0/column/0/headline/0",
  "etag": "newetag789"
}
```

---

### `PUT /pages/{template_id}/elements/{element_path}/settings`

Update an element's settings.

**Auth required:** Yes. **If-Match required.**

**Request body:**

```json
{ "props": { "content": "Updated headline text" } }
```

**Response 200:**

```json
{ "updated": true, "etag": "newetag789" }
```

---

### `DELETE /pages/{template_id}/elements/{element_path}`

Delete an element.

**Auth required:** Yes. **If-Match required.**

**Response 204:** (empty body, `ETag` header has new value)

---

### `POST /pages/{template_id}/elements/{element_path}/move`

Move an element to a new path.

**Auth required:** Yes. **If-Match required.**

**Request body:**

```json
{
  "target_parent_path": "section/2/row/0/column/0",
  "position": "append"
}
```

**Response 200:**

```json
{
  "moved": true,
  "new_path": "section/2/row/0/column/0/headline/0",
  "etag": "newetag789"
}
```

---

### `POST /pages/{template_id}/elements/{element_path}/clone`

Clone an element (deep-copy, placed as a sibling).

**Auth required:** Yes. **If-Match required.**

**Response 201:**

```json
{ "cloned": true, "path": "section/0/row/0/column/0/headline/1", "etag": "newetag789" }
```

---

### `GET /pages/{template_id}/elements/{element_path}/binding`

Get the Dynamic Source binding currently attached to an element.

**Auth required:** Yes.

**Response 200:**

```json
{
  "binding": {
    "source_name": "pexels-search",
    "field_map": { "image": "src.large", "alt": "alt" }
  }
}
```

Returns `{ "binding": null }` if no binding is set.

---

### `PUT /pages/{template_id}/elements/{element_path}/binding`

Bind a Dynamic Source to an element.

**Auth required:** Yes. **If-Match required.**

**Request body:**

```json
{
  "source_name": "pexels-search",
  "field_map": { "image": "src.large", "alt": "alt" }
}
```

**Response 200:**

```json
{ "bound": true, "etag": "newetag789" }
```

---

### `DELETE /pages/{template_id}/elements/{element_path}/binding`

Remove the Dynamic Source binding.

**Auth required:** Yes. **If-Match required.**

**Response 204:** (empty body)

---

### `GET /sources`

List Dynamic Sources available on the site (e.g. registered by API Mapper).

**Auth required:** Yes.

**Response 200:**

```json
{
  "sources": [
    { "name": "pexels-search", "label": "Pexels Search", "fields": ["src.large", "alt", "photographer"] },
    { "name": "wp-posts", "label": "Posts", "fields": ["title", "excerpt", "featured_image"] }
  ]
}
```

---

### `GET /element-types`

List all element types YOOtheme exposes.

**Auth required:** Yes.

**Response 200:**

```json
{
  "element_types": [
    { "name": "section", "label": "Section" },
    { "name": "row", "label": "Row" },
    { "name": "column", "label": "Column" },
    { "name": "grid", "label": "Grid" },
    { "name": "headline", "label": "Headline" }
  ]
}
```

---

### `GET /element-types/{type_name}/schema`

Get the JSON schema for one element type — what settings exist, types, defaults.

**Auth required:** Yes.

**Response 200:**

```json
{
  "type_name": "grid",
  "schema": {
    "props": {
      "columns": { "type": "integer", "default": 3, "min": 1, "max": 12 },
      "gap": { "type": "string", "enum": ["small", "medium", "large"] }
    }
  }
}
```

---

## Rate limits

The plugin does not enforce its own rate limits. WordPress's built-in REST
rate limits and any reverse-proxy / WAF limits apply.

## CORS

Endpoints are not CORS-enabled by default. The setup wizard does not need
CORS because the NPM package proxies requests server-side. If you need
browser-side calls, add a `cors` filter via the standard WordPress REST
filter pattern.
