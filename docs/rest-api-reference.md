# REST API Reference

All endpoints live under the `yt-builder-mcp/v1` namespace. The base URL differs
by platform:

```
WordPress:  https://example.com/wp-json/yt-builder-mcp/v1/<endpoint>
Joomla:     https://example.com/api/index.php/v1/yt-builder-mcp/<endpoint>
```

## Authentication

All endpoints except the anonymous `/health` probe require a **Bearer token**:

- **WordPress:** generated in WP-Admin → Tools → "YT Builder MCP" → Bearer Keys.
- **Joomla:** generated in Administrator → Components → "YT Builder MCP" → Bearer Keys.

```
Authorization: Bearer ytb_live_eyJh….<signature>
```

Keys are verified with constant-time `hash_equals()` against an HMAC-SHA256 hash.
The plaintext key is never stored (WordPress stores the hash in `wp_options`; Joomla
in `#__extensions.custom_data`). On both platforms the Bearer token's scope hierarchy
(`read` < `write` < `admin`) is the sole authority on the API surface.

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

(Joomla wraps the same `code` / `message` / `data` triple in its own API JSON
envelope, but the error contract is identical.)

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

Tiered. Used by the setup wizard to sanity-check connectivity before the user even
pastes their key. The payload is **field-split by authentication**: an anonymous
caller gets only the minimum needed to confirm "the plugin is installed at this URL",
while every host-fingerprinting field (YOOtheme/CMS/PHP versions, `yootheme_loaded`,
storage backend, endpoint inventory) is reserved for Bearer-holders. This is
identical on WordPress and Joomla.

**Auth required:** No (anonymous payload) / optional (Bearer augments the payload).

**Anonymous response 200** (no `Authorization` header):

```json
{
  "plugin_version": "1.1.5",
  "status": "ok"
}
```

**Bearer-authenticated response 200** (valid `Authorization: Bearer …`):

```json
{
  "plugin_version": "1.1.5",
  "status": "ok",
  "yootheme_version": "4.5.33",
  "yootheme_loaded": true,
  "site_url": "https://example.com",
  "home_url": "https://example.com",
  "storage_type": "wp_option",
  "storage_target": "yootheme",
  "schema_version": 1,
  "available_endpoints": ["/yt-builder-mcp/v1/health", "/yt-builder-mcp/v1/pages", "..."]
}
```

> WordPress additionally surfaces `wp_version` and `yooessentials_version`
> (when installed). Joomla returns `cms: "joomla"` + `cms_version` + `php_version`
> with the same Bearer-gated semantics. The anonymous `{plugin_version, status}`
> contract is byte-identical on both platforms.

**Example (anonymous probe):**

```bash
# WordPress
curl https://example.com/wp-json/yt-builder-mcp/v1/health
# Joomla
curl https://example.com/api/index.php/v1/yt-builder-mcp/health
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
# WordPress
curl -H "Authorization: Bearer $KEY" \
  https://example.com/wp-json/yt-builder-mcp/v1/etag
# Joomla
curl -H "Authorization: Bearer $KEY" \
  https://example.com/api/index.php/v1/yt-builder-mcp/etag
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

**Response 200:** Sources are grouped by origin (e.g. `apimapper`, `wordpress`, `joomla`, `essentials`):

```json
{
  "sources": {
    "apimapper": [
      { "name": "apiMapperFlow…", "label": "Pexels Search", "group": "WootsUp - API Mapper", "type": "…Query", "kind": "…Query" }
    ],
    "wordpress": [
      { "name": "posts", "label": "Posts", "group": "WordPress", "type": "[Post]", "kind": "[Post]" }
    ]
  }
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
