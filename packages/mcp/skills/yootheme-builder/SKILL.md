---
name: yootheme-builder
description: Drive the YOOtheme Page Builder programmatically — discover pages, inspect layouts, add/move/clone/delete elements, bind dynamic sources, diagnose 401/403 auth failures. Use when the user wants to build, modify, audit, or troubleshoot a YOOtheme-powered WordPress site through the YOOtheme Builder MCP server.
---

# YOOtheme Builder MCP — Skill

This skill helps AI assistants drive the YOOtheme Page Builder through the
`@wootsup/yootheme-builder-mcp` server. The server exposes 22 typed,
scoped, idempotent tools behind a 10-entry Gateway-Hub (so it stays
inside the 80-tool Cursor cap even when the catalogue grows).

## How to use this MCP server

The user invokes you through Claude Desktop, Cursor, Zed, Continue, or
any other MCP-aware AI client. Setup looks like this:

1. The user installs the WordPress plugin
   (`https://wootsup.com/products/yootheme-builder-mcp`) and generates
   a Bearer key in **wp-admin → "YOOtheme Builder MCP" → Settings**.
2. The user runs `npx -y @wootsup/yootheme-builder-mcp setup` once;
   the wizard probes the plugin, validates the key, and writes the
   MCP server entry into every selected AI client's config file.
3. The user restarts their AI client. The server is now visible.
4. The user asks for a YOOtheme task (build, audit, change, diagnose).

When the user asks a YOOtheme-related question, **always start with
`yootheme_builder_health`** — it confirms the plugin is reachable and
returns the plugin/YOOtheme/WordPress/PHP versions you need to know
about before reading or writing layout state.

If a tool returns `401 Unauthorized` or `403 Forbidden`, jump straight
to **Workflow 4: Diagnose 401/auth failure**. Do not retry blindly.

## Gateway routing (so you know what you can call)

The server exposes:

- **2 direct top-level tools** — always callable, always in `tools/list`:
  `yootheme_builder_health` and `yootheme_builder_diagnose`. These are
  the "the gateway itself might be broken" escape hatch.
- **7 essential forwarded tools** — common reads + the most-used writes
  (page list / get / save / publish, element list / add / update). Also
  always in `tools/list` so AI clients see them first-class.
- **12 advanced captured tools** — everything else (move, clone, delete,
  schema introspection, source binding). Reachable through one gateway
  tool: `yootheme_builder_advanced({ tool: "<name>", input: { ... } })`.
- **1 gateway tool** — `yootheme_builder_advanced`.

If the AI client reports "tool not found", you are almost certainly
calling an advanced tool by its raw name. Wrap it in
`yootheme_builder_advanced({ tool, input })` instead.

## Scopes (Bearer key permissions)

Every Bearer key has a scope, set at key creation time:

| Scope    | Reads | Writes | Destructive |
|----------|-------|--------|-------------|
| `read`   | ✓     | ✗      | ✗           |
| `write`  | ✓     | ✓      | ✗           |
| `admin`  | ✓     | ✓      | ✓           |

When a tool returns `{ error: 'insufficient_scope', context: { required: 'write', actual: 'read' } }`,
ask the user to regenerate the key with a higher scope **before**
retrying. Do not loop on auth errors.

---

## Workflow 1: Build a hero section

**Goal:** Add a fresh hero section (heading + sub-heading + CTA button)
to an existing page.

**Canonical tool-call sequence (real parameter names — snake_case):**

1. `yootheme_builder_health` — confirm plugin reachable; note plugin
   version (some element types are version-gated).
2. `yootheme_builder_pages_list({ fields: ["id", "label"] })` — find
   the target template. Returns `[{ id, label, ... }]`. If the user
   named a specific page, match on `label` (exact then fuzzy).
3. `yootheme_builder_get_etag()` — fetch the current top-level
   optimistic-lock ETag. Every write tool requires it via `etag`.
4. `yootheme_builder_element_add({ template_id: "<id>", parent_path: "", element_type: "section", props: { background: "primary" }, etag: "<etag>" })`
   — append a new section at the template root (`parent_path: ""`).
   Returns `{ path: "/0/children/N", etag: "<fresh>" }`.
5. `yootheme_builder_element_add({ template_id, parent_path: "<section-path>", element_type: "row", etag: "<fresh-etag>" })`
   — add a row inside the section. Use the etag returned by the
   previous write — etags rotate every mutation.
6. `yootheme_builder_element_add({ template_id, parent_path: "<row-path>", element_type: "headline", props: { content: "<h1 text>" }, etag })`
   — add a headline.
7. `yootheme_builder_element_add({ template_id, parent_path: "<row-path>", element_type: "text", props: { content: "<sub text>" }, etag })`
   — add a text element.
8. `yootheme_builder_element_add({ template_id, parent_path: "<row-path>", element_type: "button", props: { content: "<cta>", link: "<url>" }, etag })`
   — add the CTA button.
9. `yootheme_builder_page_save({ template_id, etag })` — persist the
   working copy (visible in YOOtheme Customizer preview).
10. `yootheme_builder_page_publish({ template_id, etag })` — make the
    changes live on the front-end.

**Common pitfalls:**

- **Wrong parameter names.** Every tool uses snake_case. Use
  `template_id` (not `pageId`), `parent_path` (not `parentPath`),
  `element_type` (not `type`), `props` (not `settings`), `etag`
  (not `ifMatch`). The MCP server rejects unknown keys with a
  Zod-validation error.
- **Forgetting `etag`.** Every write tool needs the latest etag. The
  shared schema marks it required (min length 1). On `412 Precondition
  Failed` re-fetch via `yootheme_builder_get_etag` and retry.
- **Adding non-row elements directly to a section.** Sections expect a
  row in between. The server returns a structured error with a
  human-readable hint when you skip the row.
- **Saving without publishing.** `page_save` is the equivalent of the
  YOOtheme Customizer "Save" button — content lives in the staging
  copy. Visitors see nothing until `page_publish`.
- **Reusing a stale etag across many writes.** Every write returns a
  fresh etag in the response. Pass THAT etag into the next write —
  don't hold the one from the original `get_etag` call.

**Worked example (tool-call snippet):**

```jsonc
// Step 4 — add the section. parent_path: "" means template root.
yootheme_builder_element_add({
  template_id: "home",
  parent_path: "",
  element_type: "section",
  props: { background: "primary" },
  etag: "abc123"   // from yootheme_builder_get_etag
})
// Response: { path: "/0/children/3", etag: "def456" }
// → next call uses etag "def456"
```

**Edge case:** YOOtheme allows nested sections (rare) — if the user
asks for a "card grid inside a hero", you still need the
`section → row → column → grid` hierarchy, even when the parent
section sits inside another section.

**Success criterion:** After `page_publish`, navigating to the page
URL on the front-end shows the new hero section above the previous
content. Re-reading the layout via
`yootheme_builder_page_get_layout({ template_id })` shows the new
section as the last child of the template root.

---

## Workflow 2: Bind a dynamic source to a grid

**Goal:** Wire an existing Grid (or other multi-item element) to a
Source from API Mapper or the built-in YOOtheme Sources system so it
renders dynamic items.

**Canonical tool-call sequence (real parameter names — snake_case):**

1. `yootheme_builder_health` — confirm plugin reachable.
2. `yootheme_builder_pages_list({ fields: ["id", "label"] })` and
   `yootheme_builder_page_get_layout({ template_id: "<id>", flat: false })`
   — locate the target Grid. Note its JSON-Pointer `path` (e.g.
   `/0/children/2/children/0`).
3. `yootheme_builder_element_get({ template_id, element_path })` —
   fetch the Grid's current props so you can preserve them; binding
   sets `props.source` and leaves the rest alone.
4. `yootheme_builder_sources_list()` — enumerate available Sources.
   Each returns `{ name, label, origin, kind }`. Pick the one the
   user asked for.
5. `yootheme_builder_element_get_binding({ template_id, element_path })`
   — check whether the Grid is already bound (idempotency: skip step
   7 if `source_name` already matches the user's intent).
6. `yootheme_builder_get_etag()` — fetch the optimistic-lock etag for
   the upcoming mutation.
7. `yootheme_builder_element_bind_source({ template_id, element_path, source_name: "<name>", etag: "<etag>" })`
   — apply the binding. Returns `{ path, etag, has_binding: true }`.
   Pass `source_id: "<origin>:<name>"` as well **only** when two
   plugins register a source with the same `source_name` (the server
   surfaces the ambiguity as an elicitation prompt; if the host
   doesn't support elicitation you'll see a structured error listing
   the candidates).
8. `yootheme_builder_page_save({ template_id, etag: "<fresh>" })`
   then `yootheme_builder_page_publish({ template_id, etag: "<fresh>" })`.

**Common pitfalls:**

- **Inventing `fieldMap`.** The bind tool's schema is just
  `template_id`, `element_path`, `source_name`, optional `source_id`,
  `etag`. Field mapping happens inside YOOtheme at render time based
  on the element's own field bindings — not via an MCP parameter.
- **Wrong parameter names.** Use `template_id` (not `pageId`),
  `element_path` (not `path`), `source_name` (not `sourceName`),
  `etag` (not `ifMatch`).
- **Source not in the list.** API Mapper sources only appear once
  they're PUBLISHED in API Mapper (not just saved). If
  `yootheme_builder_sources_list` returns no match for the name the
  user typed, send the user to API Mapper to publish it.
- **Binding non-list elements.** Only multi-item element types (Grid,
  List, Switcher, Slider, Slideshow, Carousel, Map) accept a source
  binding. Binding a single-item element like Headline returns a
  structured `validation` error.
- **Forgetting `etag`.** Every write requires the optimistic-lock
  etag. On `412 Precondition Failed` re-fetch via
  `yootheme_builder_get_etag` and retry.

**Worked example (tool-call snippet):**

```jsonc
// Step 7 — bind a Posts source onto a Grid element.
yootheme_builder_element_bind_source({
  template_id: "home",
  element_path: "/0/children/2/children/0",
  source_name: "wp_posts",
  etag: "abc123"
  // source_id: "wordpress:wp_posts"   // pass ONLY when name collides
})
// Response: { path: "/0/children/2/children/0", etag: "def456", has_binding: true }
// Verify:
yootheme_builder_element_get_binding({
  template_id: "home",
  element_path: "/0/children/2/children/0"
})
// → { source_name: "wp_posts", source_config: { ... }, ... }
```

**Edge case:** A Source can render zero items at runtime (e.g. empty
search filter). The bind call still succeeds; the front-end Grid just
shows the YOOtheme "no items" placeholder. Don't treat empty render
as a binding failure — verify by re-reading
`yootheme_builder_element_get_binding`.

**Success criterion:** After publish, the Grid on the front-end shows
items from the Source (verify by item count and at least one
field-value spot-check). `yootheme_builder_element_get_binding`
returns the new `source_name`.

---

## Workflow 3: Clone & modify a section within a template

**Goal:** Duplicate a section inside the SAME template and tweak the
copy. Common variants: A/B-style hero, repeated CTA blocks, mirroring
a row layout. (Cross-template duplication is **not** supported by
`element_clone` — see "Important scope note" below.)

**Important scope note:** `yootheme_builder_element_clone` is
**sibling-only and intra-template**. Its real schema is
`{ template_id, element_path, etag }` — there is **no** `destPageId`
or `destParentPath`. The cloned element lands at the same parent,
right after the source. To move the clone elsewhere in the SAME
template, call `yootheme_builder_element_move` afterwards. To
duplicate into a DIFFERENT template, flag to the user that
cross-template clone is not currently supported and suggest a
WordPress-level template duplication.

**Canonical tool-call sequence (real parameter names — snake_case):**

1. `yootheme_builder_health` — confirm plugin reachable.
2. `yootheme_builder_pages_list({ fields: ["id", "label"] })` —
   locate the template by `label`. Note its `id`.
3. `yootheme_builder_page_get_schema({ template_id })` — flat schema
   view (lighter than `page_get_layout`) showing every element path
   + type. Pick the JSON-Pointer path of the section to clone.
4. `yootheme_builder_get_etag()` — fetch the optimistic-lock etag.
5. `yootheme_builder_element_clone({ template_id, element_path: "<src-path>", etag: "<etag>" })`
   — clone as sibling. Returns `{ path: "<new-path>", etag: "<fresh>" }`.
   The new path is at the same parent, immediately after the source.
6. (Optional) `yootheme_builder_element_move({ template_id, element_path: "<new-path>", to_parent_path: "<other-parent>", to_index: 0, etag: "<fresh>" })`
   — re-parent the clone within the same template if needed.
7. `yootheme_builder_element_update_settings({ template_id, element_path: "<final-path>", props: { ... }, etag: "<fresh>" })`
   — replace the `props` on the clone. **Existing props NOT in the
   request are removed** (update_settings is a full replace, not a
   merge). Read the current props first via
   `yootheme_builder_element_get` if you only want to tweak a subset.
8. `yootheme_builder_page_save({ template_id, etag })` then
   `yootheme_builder_page_publish({ template_id, etag })`.

**Common pitfalls:**

- **Inventing destination parameters.** `element_clone` does NOT
  accept `destPageId`, `destParentPath`, or any cross-template
  argument. It's sibling-only within ONE template.
- **Treating `element_update_settings` as a merge.** The handler
  REPLACES the entire `props` object on the element; any key you
  don't include is removed. Use `element_get` first if you need to
  preserve siblings of the field you're changing.
- **Clone-then-update path drift.** The clone returns a path that's
  correct at the moment of the call. If you fire off many ops in
  parallel, a concurrent edit may shift indices — refresh via
  `get_etag` + `page_get_schema` between independent batches.
- **Cloning a bound element keeps the binding.** `element_clone`
  copies the entire element including `props.source`. If the user
  wanted a "data-free" copy, call
  `yootheme_builder_element_unbind_source` on the new path
  afterwards.
- **Wrong parameter names.** Use `template_id`, `element_path`,
  `etag` (NOT `pageId`, `srcPath`, `ifMatch`).

**Worked example (tool-call snippet):**

```jsonc
// Step 5 — clone the section element as a sibling.
yootheme_builder_element_clone({
  template_id: "home",
  element_path: "/0/children/2",   // the hero section to duplicate
  etag: "abc123"
})
// Response: { path: "/0/children/3", etag: "def456" }

// Step 7 — tweak the clone (replace props entirely).
// First read the current shape so you can preserve siblings:
const current = yootheme_builder_element_get({
  template_id: "home",
  element_path: "/0/children/3",
});
yootheme_builder_element_update_settings({
  template_id: "home",
  element_path: "/0/children/3",
  props: { ...current.props, background: "secondary" },
  etag: "def456"
})
```

**Edge case:** When cloning a Grid with a source binding, the binding
is preserved (same `source_name`). If the user wants a "data-free"
copy, follow up with `yootheme_builder_element_unbind_source` on the
new path. Verify with `yootheme_builder_element_get_binding`.

**Success criterion:** After publish,
`yootheme_builder_page_get_schema({ template_id })` shows the new
section at the cloned path with the user's tweaks reflected in
`element_get` on that path.

---

## Workflow 4: Diagnose a 401 / 403 / auth failure

**Goal:** Recover from `401 Unauthorized` or `403 Forbidden` without
guessing — and without rotating the user's key unnecessarily.

**Canonical tool-call sequence:**

1. `yootheme_builder_diagnose` — single probe that hits `/health` (no
   auth) and then `/etag` (Bearer auth). Returns
   `{ plugin_reachable, plugin_version, yootheme_loaded, yootheme_version,
   endpoint_count, bearer_valid, bearer_error?, summary? }`. Call this
   **before** any other tool when you see auth errors. (Takes no
   arguments — the schema is `{}`.)
2. **Interpret the result:**
   - `plugin_reachable: false` → the WordPress install is down OR the
     plugin is deactivated. Send the user to wp-admin → Plugins →
     activate "YOOtheme Builder MCP". Do not retry until they confirm.
   - `plugin_reachable: true, bearer_valid: false` → the Bearer key is
     wrong (typo, revoked, or wrong key for this install). The
     `bearer_error` field carries the upstream HTTP status. Send the
     user to wp-admin → "YOOtheme Builder MCP" → Settings → either
     copy the existing key into their MCP client config, or generate
     a new one. Then they must restart the AI client.
   - `plugin_reachable: true, bearer_valid: true` but the original
     tool returned a 403 → the key works but the scope is too low for
     the tool's required scope (`write` for mutations, `admin` for
     destructive operations). Ask the user to regenerate the key with
     a higher scope and restart the AI client.
3. **Walk the user through key rotation if needed:**
   - "Go to wp-admin → YOOtheme Builder MCP → Settings."
   - "Click 'Create Key', pick the scope (admin for full access)."
   - "Copy the key — it's shown ONCE; you cannot recover it later."
   - "Update your AI client config: replace `YTB_MCP_BEARER_TOKEN`
     with the new key. The fastest way is to re-run
     `npx -y @wootsup/yootheme-builder-mcp setup`."
   - "Restart Claude / Cursor / Zed / Continue / Cline / Roo Code."
   - "Confirm with `yootheme_builder_diagnose` that
     `bearer_valid: true` before retrying the original task."

**Common pitfalls:**

- **Treating 401 as a network error.** A network error has no HTTP
  status — it's a TCP/TLS / DNS failure. 401 means the server
  responded "I do not accept this key", which is fundamentally a
  config problem.
- **Stripping the `Bearer ` prefix.** The MCP server adds it
  automatically when reading `YTB_MCP_BEARER_TOKEN`. If the user
  pasted the prefix into the env var, the value sent will be
  `Bearer Bearer ytb_live_…` and every request 401s.
- **Trying every tool to "see which ones work".** Don't. One
  `yootheme_builder_diagnose` call tells you whether the failure is
  reachability, auth, or scope.
- **Confusing 401 with 403.** 401 = "I don't recognise this key"
  (rotate). 403 = "I recognise the key but it lacks the required
  scope" (regenerate with higher scope). Different error codes,
  different recovery — never collapse them into one branch.

**Worked example (tool-call snippet):**

```jsonc
// First — never retry blindly. Call diagnose (no args).
yootheme_builder_diagnose({})
// Response shape:
// {
//   plugin_reachable: true,
//   plugin_version: "0.1.0-alpha.1",
//   yootheme_loaded: true,
//   yootheme_version: "5.0.22",
//   endpoint_count: 16,
//   bearer_valid: false,           // ← key is bad
//   bearer_error: "HTTP 401: invalid_token"
// }
// → diagnosis: rotate the key. Send user to wp-admin → Settings.
```

**Edge case:** `plugin_reachable: true` but `yootheme_loaded: false`
— the user installed the MCP plugin but YOOtheme itself isn't
active. The MCP server still answers, but every tool that touches
the YOOtheme layout returns an empty/error response. Surface the
mismatch ("YOOtheme is not active on this install") instead of
retrying.

**Success criterion:** A subsequent `yootheme_builder_diagnose`
returns `plugin_reachable: true` AND `bearer_valid: true`. The
original tool now returns a non-auth response.

---

## Workflow 5: Add a custom element type to a page

**Goal:** Inspect what element types are installed on the user's
YOOtheme install (built-ins + YOOtheme Pro + YOOessentials + child
theme + plugin-contributed elements), pick the right one, and place
an instance with a sensible default props payload.

**Canonical tool-call sequence (real parameter names — snake_case):**

1. `yootheme_builder_health` — note the YOOtheme version; custom
   elements often require a minimum YOOtheme major.
2. `yootheme_builder_element_types_list({ fields: ["name", "label", "origin"] })`
   — narrow the catalogue with sparse-fields. Returns rows like
   `{ name: "headline", label: "Headline", origin: "core", ... }`.
3. `yootheme_builder_element_type_get_schema({ type_name: "<picked>" })`
   — fetch the prop schema for the chosen type. Returns the field
   definitions you can pass via `props`. Note: the parameter is
   `type_name`, not `name`.
4. `yootheme_builder_pages_list({ fields: ["id", "label"] })` and
   `yootheme_builder_page_get_layout({ template_id, flat: false })`
   — locate the `parent_path` (JSON-Pointer) where the new element
   should land.
5. `yootheme_builder_get_etag()` — fetch the optimistic-lock etag.
6. `yootheme_builder_element_add({ template_id, parent_path: "<path>", element_type: "<picked-name>", props: { ... }, etag })`
   — the server validates `props` against the type schema and
   returns a structured `validation` error with a per-field issue
   list if anything is missing or malformed.
7. (Optional) `yootheme_builder_element_update_settings({ template_id, element_path: "<new-path>", props: { ... }, etag })`
   — iterate on the props. **Note: this REPLACES `props` entirely**
   — include every key you want to keep.
8. `yootheme_builder_page_save({ template_id, etag })` then
   `yootheme_builder_page_publish({ template_id, etag })`.

**Common pitfalls:**

- **Wrong parameter name on the type-schema tool.** It's
  `type_name`, not `name`. The server's Zod schema rejects unknown
  keys.
- **Wrong parameter names on `element_add`.** Use `template_id`
  (not `pageId`), `parent_path` (not `parentPath`), `element_type`
  (not `type` / `name`), `props` (not `settings`), `etag` (not
  `ifMatch`).
- **`element_update_settings` is a full replace, not a merge.** Any
  key NOT in the request is REMOVED from `props`. Read the existing
  shape via `yootheme_builder_element_get` first if you only want
  to tweak a subset.
- **Custom elements without a schema.** A poorly-built third-party
  element may not register a prop schema. In that case
  `yootheme_builder_element_type_get_schema` returns an empty/sparse
  schema and the server accepts arbitrary `props`. Don't assume "no
  schema = no required fields" — read the third-party element's
  docs.
- **Type name vs. label confusion.** The `name` field on the
  catalogue row is the machine identifier (e.g. `pro_slider`); the
  `label` is the human display string ("Pro Slider"). Always pass
  the `name` (as `element_type` / `type_name`).
- **Pro-only types on a Free install.** YOOtheme Free does not
  register `pro_*` element types. Surface that to the user instead
  of retrying with a different filter.

**Worked example (tool-call snippet):**

```jsonc
// Step 2 — narrow the catalogue with sparse-fields to save tokens.
yootheme_builder_element_types_list({
  fields: ["name", "label", "origin"]
})
// Rows: [{ name: "headline", label: "Headline", origin: "core" }, ...]

// Step 3 — fetch the schema (note: type_name, not name).
yootheme_builder_element_type_get_schema({ type_name: "headline" })
// Returns the field definitions for the headline's `props`.

// Step 6 — place the element.
yootheme_builder_element_add({
  template_id: "home",
  parent_path: "/0/children/2",     // row inside section
  element_type: "headline",
  props: { content: "Welcome", tag: "h1" },
  etag: "abc123"
})
// Response: { path: "/0/children/2/children/0", etag: "def456" }
```

**Edge case:** A child theme can override a built-in element's
schema in PHP. The `origin` field will read `child_theme` instead
of `core`. If you see surprising required keys, that's the override
talking — surface this to the user so they know their theme is
customising element defaults.

**Success criterion:** After publish, the front-end shows the new
element rendered with its default props. A re-read of the layout
shows the element under `parent_path` with the chosen `element_type`
and the props payload you passed.

---

## When something doesn't fit one of these 5 workflows

- **Move an element** (intra-template reorder/reparent): use
  `yootheme_builder_element_move({ template_id, element_path,
  to_parent_path, to_index, etag })`. Reorders or reparents without
  re-creating.
- **Delete an element**: use `yootheme_builder_element_delete({
  template_id, element_path, etag, confirm: true })`.
  Elicitation-aware — confirms via the AI client prompt before
  destroying state when `confirm` is omitted. On hosts without
  elicitation, it returns a preview-with-confirm-required response;
  call again with `confirm: true`.
- **Unbind a source**: use `yootheme_builder_element_unbind_source({
  template_id, element_path, etag, confirm: true })`. Same
  elicitation flow as delete.
- **Flat schema inspection** (e.g. enumerate every element path +
  type without fetching the whole nested tree): use
  `yootheme_builder_page_get_schema({ template_id })`.
- **Etag-only fetch** (e.g. polling for concurrent edits): use
  `yootheme_builder_get_etag()` (takes no arguments) — cheaper than
  fetching the full layout.

If the user asks for something none of the above covers (e.g. global
theme settings, menu management, media library), tell them clearly:
"This MCP server only covers the YOOtheme Page Builder surface. For
<X> you'll need <YOOtheme MCP / WP REST / direct wp-admin>." Don't
fabricate tool calls.

## Appendix: Tool Catalog (auto-generated)

<!-- TOOL-CATALOG:BEGIN -->

**21 tools** — generated by `scripts/extract-tools.mjs` from the compiled `buildAllTools()` registry. Do not hand-edit this section; re-run `npm run build && node scripts/extract-tools.mjs` after changing tool definitions.

| Tool | Kind | Input keys | Description |
| --- | --- | --- | --- |
| `yootheme_builder_diagnose` | read+openWorld | _(none)_ | Run a full diagnostic: hit /health (no auth), then attempt an authenticated call (/etag) to confirm the Bearer key is valid. Use when health passes but tools return 401/403. |
| `yootheme_builder_element_add` | openWorld | `children`, `element_type`, `etag`, `parent_path`, `props`, `template_id` | Add a new element to a template. Provide `parent_path` (or "" for root), `element_type` (e.g. "headline", "text", "grid"), and optional `props` / `children`. Returns the new element's JSON-Pointer path. Requires ETag. |
| `yootheme_builder_element_bind_source` | idempotent+openWorld | `element_path`, `etag`, `source_id`, `source_name`, `template_id` | Bind a Builder source to an element (sets `props.source`). Pass source_name from sources_list; pass source_id to disambiguate cross-plugin name collisions. Requires ETag. |
| `yootheme_builder_element_clone` | openWorld | `element_path`, `etag`, `template_id` | Clone an element as a sibling (same parent, immediately after the source). Returns the new element's path. Requires ETag. |
| `yootheme_builder_element_delete` | destructive+openWorld | `confirm`, `element_path`, `etag`, `template_id` | PERMANENTLY delete an element and all its children. Cannot be undone. Always ask the user to confirm first, then call again with `confirm: true`. Requires ETag. |
| `yootheme_builder_element_get` | read+openWorld | `element_path`, `template_id` | Get the full element object at a specific JSON-Pointer path, including props and children. Use yootheme_builder_element_list to discover paths. |
| `yootheme_builder_element_get_binding` | read+openWorld | `element_path`, `template_id` | Read the source binding (and source_config/source_args/etc.) attached to an element. Returns the empty object if the element is not bound. |
| `yootheme_builder_element_list` | read+openWorld | `fields`, `template_id` | List all elements in a template as a flat array with JSON-Pointer paths + element types. Best starting-point for "find the element I want to edit". Pass `fields:["path","element_type"]` to narrow each row. |
| `yootheme_builder_element_move` | idempotent+openWorld | `element_path`, `etag`, `template_id`, `to_index`, `to_parent_path` | Move an element to a new parent + index in the tree. Useful for reordering or reparenting (e.g. moving a card from one grid column to another). Requires ETag. |
| `yootheme_builder_element_type_get_schema` | read+openWorld | `type_name` | Get the prop/field schema for a single element type. Use the result to discover valid keys for `props` when calling yootheme_builder_element_add or _update_settings. |
| `yootheme_builder_element_types_list` | read+openWorld | `fields` | List element types registered on this site (built-ins + YOOessentials/uEssentials extras). Names feed `element_type` of element_add. Pass `fields[]` to narrow each row. |
| `yootheme_builder_element_unbind_source` | destructive+openWorld | `confirm`, `element_path`, `etag`, `template_id` | Remove the source binding from an element. Clears `props.source`. Destructive in the sense that it may break dynamic-content rendering — always ask the user to confirm. Requires ETag. |
| `yootheme_builder_element_update_settings` | idempotent+openWorld | `element_path`, `etag`, `props`, `template_id` | Replace the `props` on an element. Use this for any setting change — title, margins, classes, sources, etc. Requires ETag. Existing props NOT in the request are removed. |
| `yootheme_builder_get_etag` | read+openWorld | _(none)_ | Get the current top-level state ETag. Pass this back via the `etag` parameter on any write tool to prevent overwriting concurrent edits. |
| `yootheme_builder_health` | read+openWorld | _(none)_ | Check that the YOOtheme Builder MCP plugin is installed and reachable. Returns plugin version, YOOtheme Pro version (if loaded), and the list of available REST endpoints. Unauthenticated probe — call this first when troubleshooting connectivity. |
| `yootheme_builder_page_get_layout` | read+openWorld | `fields`, `flat`, `template_id` | Get full layout tree for one template. Default nested `{layout, etag}`. Set `flat:true` for depth-first array `{elements:[...], etag}`; combine with `fields[]` to project per-element. |
| `yootheme_builder_page_get_schema` | read+openWorld | `template_id` | Get the flat schema for a template — a list of nodes with their JSON-Pointer paths and element types. Best entry-point for navigation: lighter than page_get_layout, sufficient to locate elements before editing. |
| `yootheme_builder_page_publish` | openWorld | `etag`, `template_id` | Publish a template. Currently behaves as save + sets `published: true` in the response. Requires ETag. |
| `yootheme_builder_page_save` | idempotent+openWorld | `etag`, `template_id` | Re-run save-transforms on a template and persist. Useful after a series of low-level writes to trigger the Builder normalization pass. Requires ETag. |
| `yootheme_builder_pages_list` | read+openWorld | `fields` | List all YOOtheme templates ("pages") on the site. Returns id, label and usage metadata for each. Use this first to discover template IDs. Pass `fields:["id","label"]` to project per-item to a smaller shape. |
| `yootheme_builder_sources_list` | read+openWorld | `fields` | List Builder sources grouped by origin (apimapper/wordpress/essentials). Returns name+label per source — pick one for `element_bind_source`. Pass `fields[]` to narrow each row. |

<!-- TOOL-CATALOG:END -->
