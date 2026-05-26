---
name: yt-builder-mcp
description: Drive the YOOtheme Pro Page Builder on WordPress or Joomla 5/6. Discover pages, inspect layouts, add/move/clone/delete elements, bind dynamic sources, diagnose 401/403 auth failures. Use when the user works with a YOOtheme Pro site via the YT Builder MCP server.
---

# YT Builder MCP for YOOtheme Pro (unofficial). Skill

> Independent third-party project. YOOtheme® is a registered trademark of YOOtheme GmbH
> ([yootheme.com](https://yootheme.com)). YT Builder MCP is built by WootsUp (getimo
> productions) and is not affiliated with, endorsed by, or sponsored by YOOtheme.
> The integration uses YOOtheme Pro's public extension points and works on both
> WordPress and Joomla 5/6.

This skill helps AI assistants drive the YOOtheme Pro Page Builder through the
`@wootsup/yt-builder-mcp` server. The server exposes 27 typed, scoped, idempotent
callable tools. 20 of them advertised as first-class entries in `tools/list`
(17 essential L1 forwards + 2 direct L3 entries + 1 gateway), and 7 advanced
tools reachable through the single `yootheme_builder_advanced` gateway. This
3-lane split keeps `tools/list` well below the ~40-tool Cursor cap while every
catalogued tool stays fully reachable.

## How to use this MCP server

The user invokes you through Claude Desktop, Cursor, Zed, Continue, Cline,
Roo Code, Claude Code, Codex CLI or any other MCP-aware AI client. Setup is
**cross-platform**. The same MCP server speaks to the same WordPress *or*
Joomla 5/6 host plugin:

1. The user installs the host plugin for their CMS:
   - **WordPress**. Install the `yt-builder-mcp` plugin (downloadable from the
     [GitHub repository](https://github.com/wootsup/yt-builder-mcp)) and generate
     a Bearer key in **wp-admin → Tools → "YT Builder MCP" → Bearer Keys**.
   - **Joomla 5/6**. Install the `yt-builder-mcp` package (downloadable from the
     [GitHub repository](https://github.com/wootsup/yt-builder-mcp)) and generate
     a Bearer key in **Components → YT Builder MCP → Bearer Keys**. The package
     installs three sub-extensions (system plugin, webservices plugin, component).
2. The user runs `npx -y @wootsup/yt-builder-mcp setup` once; the wizard probes
   the host plugin, validates the key, and writes the MCP server entry into every
   selected AI client's config file. (Wizard prompts include a platform hint;
   `auto` works for most cases. Set it to `joomla` explicitly when the site URL
   has no `/joomla` segment.)
3. The user restarts their AI client. The server is now visible.
4. The user asks for a YOOtheme task (build, audit, change, diagnose).

### Two picker entries (activate both)

Some clients (notably Claude Desktop with the `.dxt` bundle) expose **two**
entries when the user types "YT Builder MCP" into the picker:

- **`YT Builder MCP for YOOtheme Pro (unofficial)`**: the MCP **server**.
  Provides the 20 first-class tools (17 essential, 2 direct, 1 gateway).
  The `yootheme_builder_advanced` gateway routes 7 additional tools.
- **`Von YT Builder MCP for YOOtheme Pro`**: the bundled **skill** (this
  document). Gives the agent the workflow knowledge needed to drive those
  tools correctly on first try.

**Activate both for the full experience.** The MCP server alone gives the agent
typed tools but no narrative guidance; the skill alone has no tools to call.

When the user asks a YOOtheme-related question, **always start with
`yootheme_builder_health`**. It confirms the host plugin is reachable and (when
the Bearer key is valid) returns the **plugin version, YOOtheme version, WordPress
or Joomla version, PHP version, and the site_url + home_url of the connected
site**. The site URL is how you know *which* site the agent is currently driving;
surface it back to the user when relevant ("Working on `https://example.com`...").

If a tool returns `401 Unauthorized` or `403 Forbidden`, jump straight to
**Workflow 4: Diagnose 401/auth failure**. Do not retry blindly.

## Gateway routing (so you know what you can call)

The server exposes:

- **2 direct top-level tools**, always callable, always in `tools/list`:
  `yootheme_builder_health` and `yootheme_builder_diagnose`. These are
  the "the gateway itself might be broken" escape hatch.
- **17 essential forwarded tools**: common reads + the most-used writes
  (pages_list, get_etag, element_list / add / update_settings / get / move /
  clone / delete, page_get_layout, sources_list, element_types_list,
  element_type_get_schema, template_summary, inspect_multi_items_binding,
  sites_list, sites_test). Always advertised in `tools/list` so AI clients
  see them first-class.
- **7 advanced captured tools**: everything else (page_save, page_publish,
  page_get_schema, element_get_binding, element_bind_source,
  element_unbind_source, clean_implode_directives). Reachable through one
  gateway tool: `yootheme_builder_advanced({ tool: "<name>", input: { ... } })`.
- **1 gateway tool**: `yootheme_builder_advanced`.

`tools/list` therefore advertises 20 names (17 + 2 + 1). That's 17 L1
essentials + 2 L3 direct + 1 gateway. The total callable surface is 27
(20 advertised + 7 advanced reachable through the gateway). If the AI
client reports "tool not found", you are almost certainly calling an
advanced tool by its raw name. Wrap it in
`yootheme_builder_advanced({ tool, input })` instead.

## Site and frontend URLs (for deep-linking and verification)

The server surfaces the connected site's URLs in two places so you never have to
guess where the agent is pointing:

- **`yootheme_builder_health` (Bearer-authenticated) and
  `yootheme_builder_diagnose`** return `site_url` and `home_url` for the
  connected install. Call one of them when the user asks "which site are you
  on?" or before deep-linking the user back into wp-admin / Joomla administrator.
- **`yootheme_builder_pages_list`** returns per-template `frontend_url`,
  `frontend_url_template`, and `frontend_url_description` columns when the
  host plugin can resolve them. Use these when the user asks for a verification
  URL ("show me the 404 page", "give me the front-end URL of the homepage
  template"): find the matching row, return `frontend_url` (resolved) or
  `frontend_url_template` (with placeholders the user fills in).

Treat `frontend_url: null` as "host plugin could not resolve a public URL for
this template". Surface that honestly rather than fabricating one.

## Scopes (Bearer key permissions)

Every Bearer key has a scope, set at key creation time:

| Scope    | Reads | Writes | Destructive |
|----------|-------|--------|-------------|
| `read`   | ✓     | ✗      | ✗           |
| `write`  | ✓     | ✓      | ✗           |
| `admin`  | ✓     | ✓      | ✓           |

When a tool returns `{ error: 'insufficient_scope', context: { required: 'write', actual: 'read' } }`,
ask the user to regenerate the key with a higher scope **before** retrying.
Do not loop on auth errors.

> **Joomla note.** On the Joomla API surface (`com_api`), the Bearer token's
> scope is the **sole** authority. The L2 article-write `core.edit` ACL gate
> was intentionally removed (see ADR at https://github.com/wootsup/yt-builder-mcp/blob/main/docs/adr/2026-05-24-l2-bearer-as-authority.md).
> Joomla ACL still governs the admin component (`com_ytbmcp`). On WordPress,
> capabilities like `manage_options` gate the admin settings page only; the
> REST API surface is Bearer-gated.

> **Joomla L2 articles surface.** The Joomla plugin ships `/v1/articles*`
> REST endpoints for per-article custom layouts, but they are NOT exposed
> via MCP tools in v1.x. To use that surface, call the REST endpoints
> directly with the Bearer key. MCP tool coverage for L2 articles is
> planned for v1.2.0.

---

## Working with multiple sites

One MCP install can drive many YOOtheme Pro sites. You configure each site
once (URL + Bearer key + platform). The agent then targets a specific site
per tool-call via a `site_id` parameter, or falls back to the default site
when `site_id` is omitted.

### When and why

The typical case is an agency or freelancer running 5, 20, or 100+ YOOtheme
sites (WordPress and Joomla mixed). Without multi-site support you would need
one MCP install per site, one set of env vars per site, and one AI-client
restart per site you want to talk to. With multi-site:

- One DXT install in Claude Desktop, one entry in your AI client config.
- One conversation can edit elements on `acme.com` and `beta.io` back-to-back.
- Each site keeps its own Bearer key, platform, label, and 1Password reference.
- Adding a new client site does not require a new MCP install.

### How `site_id` works

Every tool accepts an optional `site_id` parameter.

- **Omit `site_id`**: the tool runs against the **default site**. This is the
  common case for single-site users (the registry has one site and it is the
  default) and for agency users who picked a "main" site for the session.
- **Pass `site_id: "wp-acme"`**: the tool runs against that specific site,
  overriding the default for that one call.

When the agent does not know which sites are available, it calls
`yootheme_builder_sites_list` first. The response lists every configured site
with its `site_id`, URL, platform, default flag, and bearer source (so the
agent can choose by label and the user can verify by URL).

To verify a specific site before doing work on it, call
`yootheme_builder_sites_test({ site_id: "wp-acme" })`. This probes `/health`
and `/etag` in parallel and returns `plugin_reachable` + `bearer_valid`
without mutating anything.

### Default-site mechanics

The default site is set automatically on first add:

- **First site you add**: becomes the default automatically. No flag needed.
- **Every subsequent site**: NOT default by default. Use `--default` on
  `add-site` to make it the new default (the old default is demoted).
- **You remove the current default**: the next site in the registry order
  is promoted to default. The registry is never left without a default
  while ≥1 site exists.

### Plain bearer vs 1Password reference

You can store the Bearer key two ways per site:

- **Plain field**: `bearer: "ytb_live_..."` in `sites.json`. Easy for dev or
  onboarding, but the secret lives on disk in plaintext.
- **1Password reference**: `bearer_ref: "op://Vault/Item/credential"` in
  `sites.json`. The plaintext token never touches disk. The MCP server shells
  out to the `op` CLI at first use per site to fetch the live token, then
  caches it in memory for that process lifetime.

**Recommendation for production sites**: use `bearer_ref`. You get rotation
without editing `sites.json`, and your 1Password audit log captures every
fetch. The `op` CLI must be installed and signed in on the machine running
the MCP server. If `op` is missing, the resolver returns a structured error
(`op CLI not found in PATH`) pointing at the install docs.

### `sites.json` location

The registry lives at `~/.config/yt-builder-mcp/sites.json` (XDG-conform).
If `XDG_CONFIG_HOME` is set, the file lives at
`$XDG_CONFIG_HOME/yt-builder-mcp/sites.json` instead. The file is created
with mode `0600` so only the current user can read it.

The CLI subcommands below are the supported way to edit `sites.json`. Direct
edits work but skip the schema-validation and atomic-write paths.

### CLI subcommands

Run these via `npx -y @wootsup/yt-builder-mcp <subcommand>`:

- **`setup`**: interactive wizard for the first site. Probes the host
  plugin, validates the Bearer key, picks the platform, writes `sites.json`,
  and writes the MCP server entry into every selected AI-client config.
- **`add-site [--url <url>] [--token <bearer> | --token-ref op://...] [--platform auto|wordpress|joomla] [--label "..."] [--default] [--site-id <slug>] [--yes]`**:
  add a new site. Flags can be passed for non-interactive use; missing flags
  trigger prompts. `--default` makes the new site the default (demoting the
  old one). `--yes` skips the confirmation prompt.
- **`list-sites`**: print every configured site as a table (site_id, URL,
  platform, default flag, bearer source).
- **`remove-site <site_id> [--yes]`**: delete a site from the registry. If
  the removed site was the default, the next site in registry order is
  auto-promoted. `--yes` skips the confirmation prompt.
- **`set-default <site_id>`**: switch the default site to `<site_id>`. The
  previous default is demoted.
- **`test-site <site_id>`**: pre-flight probe (`/health` + `/etag`) for one
  site. Returns `plugin_reachable` + `bearer_valid` and exits non-zero on
  failure. Mutates nothing.

### Restart your AI client after registry changes

After `add-site`, `remove-site`, `set-default`, or any direct edit to
`sites.json`, **restart Claude Desktop** (or your AI client). The MCP
protocol sends the `instructions` block (which carries the "Currently
configured sites" appendix) once at `initialize`. The agent will not see new
sites in its instructions until the next `initialize` cycle.

`sites_list` and the per-call `site_id` parameter both keep working
without a restart (they read the live registry on every call), but the
agent's narrative awareness of which sites exist lags by one restart.

The CLI prints a reminder line after every mutation so you do not forget.

### Site-awareness in every response

Every tool reply carries the connected site in two places:

- **Text prefix**: every text response starts with `[<label> @ <host>]` so
  the customer can see at a glance which site produced the answer
  (`[ACME Production @ acme.com] 12 templates ...`).
- **Structured metadata**: `structuredContent._meta.site_id`,
  `structuredContent._meta.site_url`, and `structuredContent._meta.platform`
  carry the same info in a machine-readable shape. Agents can use this for
  routing, logging, or follow-up calls.

If the AI-client UI hides `_meta`, the text prefix still tells the user
which site they are looking at.

### End-to-end example: bulk element update across 5 agency sites

Goal: change a hero headline on the `home` template of 5 client sites in
one conversation.

1. The agent calls `yootheme_builder_sites_list()`. It learns the 5
   site_ids: `wp-acme`, `wp-beta`, `joomla-gamma`, `wp-delta`,
   `joomla-epsilon`.
2. For each site, the agent runs the same sequence with `site_id` set:
   - `yootheme_builder_pages_list({ site_id: "wp-acme", fields: ["id", "label"] })`
   - `yootheme_builder_get_etag({ site_id: "wp-acme" })`
   - `yootheme_builder_element_update_settings({ site_id: "wp-acme", template_id: "home", element_path: "/0/children/0/children/0/children/0", props: { content: "New headline" }, merge: true, etag: "<etag>" })`
   - `yootheme_builder_advanced({ tool: "yootheme_builder_page_save", input: { site_id: "wp-acme", template_id: "home", etag: "<fresh>" } })`
   - `yootheme_builder_advanced({ tool: "yootheme_builder_page_publish", input: { site_id: "wp-acme", template_id: "home", etag: "<fresh>" } })`
3. The customer sees a stream of replies, each prefixed with the matching
   `[label @ host]`, so it is obvious which site is at which step.

If one site fails (auth error, plugin not active, network blip), the agent
isolates the failure to that one `site_id` and continues with the rest.
Run `yootheme_builder_sites_test({ site_id: "<id>" })` on the failing site
for a focused diagnosis without touching the others.

---

## Workflow 1: Build a hero section

**Goal:** Add a fresh hero section (heading + sub-heading + CTA button)
to an existing page.

**Canonical tool-call sequence (real parameter names, snake_case):**

1. `yootheme_builder_health`: confirm host plugin reachable. Note plugin
   version and `site_url` (some element types are version-gated; surface
   the site URL back to the user).
2. `yootheme_builder_pages_list({ fields: ["id", "label"] })`: find
   the target template. Returns `[{ id, label, ... }]`. If the user
   named a specific page, match on `label` (exact then fuzzy).
3. `yootheme_builder_get_etag()`: fetch the current top-level
   optimistic-lock ETag. Every write tool requires it via `etag`.
4. `yootheme_builder_element_add({ template_id: "<id>", parent_path: "", element_type: "section", props: { background: "primary" }, etag: "<etag>" })`:
   append a new section at the template root (`parent_path: ""`).
   Returns `{ path: "/0/children/N", etag: "<fresh>" }`.
5. `yootheme_builder_element_add({ template_id, parent_path: "<section-path>", element_type: "row", etag: "<fresh-etag>" })`:
   add a row inside the section. Use the etag returned by the
   previous write (etags rotate every mutation).
6. `yootheme_builder_element_add({ template_id, parent_path: "<row-path>", element_type: "headline", props: { content: "<h1 text>" }, etag })`:
   add a headline.
7. `yootheme_builder_element_add({ template_id, parent_path: "<row-path>", element_type: "text", props: { content: "<sub text>" }, etag })`:
   add a text element.
8. `yootheme_builder_element_add({ template_id, parent_path: "<row-path>", element_type: "button", props: { content: "<cta>", link: "<url>" }, etag })`:
   add the CTA button.
9. `yootheme_builder_advanced({ tool: "yootheme_builder_page_save", input: { template_id, etag } })`:
   persist the working copy (visible in YOOtheme Customizer preview).
   `page_save` is an advanced tool; call it through the gateway.
10. `yootheme_builder_advanced({ tool: "yootheme_builder_page_publish", input: { template_id, etag } })`:
    make the changes live on the front-end. Also an advanced tool.

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
  YOOtheme Customizer "Save" button. Content lives in the staging
  copy. Visitors see nothing until `page_publish`.
- **Reusing a stale etag across many writes.** Every write returns a
  fresh etag in the response. Pass THAT etag into the next write.
  Don't hold the one from the original `get_etag` call.
- **Calling page_save / page_publish by name.** Both are advanced
  (L2) tools. Call them through `yootheme_builder_advanced({ tool, input })`,
  not directly. The first sign you forgot is "tool not found".

**Worked example (tool-call snippet):**

```jsonc
// Step 4. Add the section. parent_path: "" means template root.
yootheme_builder_element_add({
  template_id: "home",
  parent_path: "",
  element_type: "section",
  props: { background: "primary" },
  etag: "abc123"   // from yootheme_builder_get_etag
})
// Response: { path: "/0/children/3", etag: "def456" }
// → next call uses etag "def456"

// Step 9. page_save is L2; call via the gateway.
yootheme_builder_advanced({
  tool: "yootheme_builder_page_save",
  input: { template_id: "home", etag: "<latest>" }
})
```

**Edge case:** YOOtheme allows nested sections (rare). If the user
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

**Canonical tool-call sequence (real parameter names, snake_case):**

1. `yootheme_builder_health`: confirm host plugin reachable.
2. `yootheme_builder_pages_list({ fields: ["id", "label"] })` and
   `yootheme_builder_page_get_layout({ template_id: "<id>", flat: false })`:
   locate the target Grid. Note its JSON-Pointer `path` (e.g.
   `/0/children/2/children/0`).
3. `yootheme_builder_element_get({ template_id, element_path })`:
   fetch the Grid's current props so you can preserve them. Binding
   sets `props.source` and leaves the rest alone.
4. `yootheme_builder_sources_list()`: enumerate available Sources.
   Each returns `{ name, label, origin, kind }`. Pick the one the
   user asked for.
5. `yootheme_builder_advanced({ tool: "yootheme_builder_element_get_binding", input: { template_id, element_path } })`
   check whether the Grid is already bound (idempotency: skip step
   7 if `source_name` already matches the user's intent).
   `element_get_binding` is an advanced (L2) tool. Call via the gateway.
6. `yootheme_builder_get_etag()`: fetch the optimistic-lock etag for
   the upcoming mutation.
7. `yootheme_builder_advanced({ tool: "yootheme_builder_element_bind_source", input: { template_id, element_path, source_name: "<name>", etag: "<etag>" } })`
   applies the binding. Returns `{ path, etag, has_binding: true }`.
   Pass `source_id: "<origin>:<name>"` as well **only** when two
   plugins register a source with the same `source_name` (the server
   surfaces the ambiguity as an elicitation prompt; if the host
   doesn't support elicitation you'll see a structured error listing
   the candidates). Also an advanced tool.
8. `yootheme_builder_advanced({ tool: "yootheme_builder_page_save", input: { template_id, etag: "<fresh>" } })`
   then `yootheme_builder_advanced({ tool: "yootheme_builder_page_publish", input: { template_id, etag: "<fresh>" } })`.

**Common pitfalls:**

- **Inventing `fieldMap`.** The bind tool's schema is just
  `template_id`, `element_path`, `source_name`, optional `source_id`,
  `etag`. Field mapping happens inside YOOtheme at render time based
  on the element's own field bindings, not via an MCP parameter.
- **Wrong parameter names.** Use `template_id` (not `pageId`),
  `element_path` (not `path`), `source_name` (not `sourceName`),
  `etag` (not `ifMatch`).
- **Source not in the list.** API Mapper sources only appear once
  they're PUBLISHED in API Mapper (not just saved). If
  `yootheme_builder_sources_list` returns no match for the name the
  user typed, send the user to API Mapper to publish it.
- **Binding non-list elements.** Only multi-item element types
  (Accordion, Button, Description List, Gallery, Grid, List, Map, Nav,
  Overlay-Slider, Panel-Slider, Popover, Slideshow, Social, Subnav,
  Switcher, Table) accept a source binding. Binding a single-item
  element like Headline returns a structured `validation` error.
- **Forgetting `etag`.** Every write requires the optimistic-lock
  etag. On `412 Precondition Failed` re-fetch via
  `yootheme_builder_get_etag` and retry.
- **Calling bind/unbind directly.** Both `element_bind_source` and
  `element_get_binding` are L2 advanced. Wrap in
  `yootheme_builder_advanced({ tool, input })`.

**Worked example (tool-call snippet):**

```jsonc
// Step 7. Bind a Posts source onto a Grid element via the gateway.
yootheme_builder_advanced({
  tool: "yootheme_builder_element_bind_source",
  input: {
    template_id: "home",
    element_path: "/0/children/2/children/0",
    source_name: "wp_posts",
    etag: "abc123"
    // source_id: "wordpress:wp_posts"   // pass ONLY when name collides
  }
})
// Response: { path: "/0/children/2/children/0", etag: "def456", has_binding: true }
// Verify (via gateway):
yootheme_builder_advanced({
  tool: "yootheme_builder_element_get_binding",
  input: { template_id: "home", element_path: "/0/children/2/children/0" }
})
// → { source_name: "wp_posts", source_config: { ... }, ... }
```

**Edge case:** A Source can render zero items at runtime (e.g. empty
search filter). The bind call still succeeds; the front-end Grid just
shows the YOOtheme "no items" placeholder. Don't treat empty render
as a binding failure. Verify by re-reading
`yootheme_builder_element_get_binding` through the gateway.

**Success criterion:** After publish, the Grid on the front-end shows
items from the Source (verify by item count and at least one
field-value spot-check). `yootheme_builder_element_get_binding`
(via gateway) returns the new `source_name`.

---

## Workflow 3: Clone & modify a section within a template

**Goal:** Duplicate a section inside the SAME template and tweak the
copy. Common variants: A/B-style hero, repeated CTA blocks, mirroring
a row layout. (Cross-template duplication is **not** supported by
`element_clone`. See "Important scope note" below.)

**Important scope note:** `yootheme_builder_element_clone` is
**sibling-only and intra-template**. Its real schema is
`{ template_id, element_path, etag }`. There is **no** `destPageId`
or `destParentPath`. The cloned element lands at the same parent,
right after the source. To move the clone elsewhere in the SAME
template, call `yootheme_builder_element_move` afterwards. To
duplicate into a DIFFERENT template, flag to the user that
cross-template clone is not currently supported and suggest a
CMS-level template duplication (in wp-admin or Joomla administrator).

**Canonical tool-call sequence (real parameter names, snake_case):**

1. `yootheme_builder_health`: confirm host plugin reachable.
2. `yootheme_builder_pages_list({ fields: ["id", "label"] })`:
   locate the template by `label`. Note its `id`.
3. `yootheme_builder_advanced({ tool: "yootheme_builder_page_get_schema", input: { template_id } })`
   returns a flat schema view (lighter than `page_get_layout`) showing every
   element path + type. Pick the JSON-Pointer path of the section
   to clone. `page_get_schema` is L2; call via the gateway.
4. `yootheme_builder_get_etag()`: fetch the optimistic-lock etag.
5. `yootheme_builder_element_clone({ template_id, element_path: "<src-path>", etag: "<etag>" })`
   clones as sibling. Returns `{ path: "<new-path>", etag: "<fresh>" }`.
   The new path is at the same parent, immediately after the source.
6. (Optional) `yootheme_builder_element_move({ template_id, element_path: "<new-path>", to_parent_path: "<other-parent>", to_index: 0, etag: "<fresh>" })`
   re-parents the clone within the same template if needed.
7. `yootheme_builder_element_update_settings({ template_id, element_path: "<final-path>", props: { ... }, etag: "<fresh>" })`
   replaces the `props` on the clone. **Existing props NOT in the
   request are removed** (update_settings is a full replace by default;
   pass `merge: true` to apply a server-side deep-merge instead).
   Read the current props first via `yootheme_builder_element_get`
   if you only want to tweak a subset.
8. `yootheme_builder_advanced({ tool: "yootheme_builder_page_save", input: { template_id, etag } })`
   then `yootheme_builder_advanced({ tool: "yootheme_builder_page_publish", input: { template_id, etag } })`.

**Common pitfalls:**

- **Inventing destination parameters.** `element_clone` does NOT
  accept `destPageId`, `destParentPath`, or any cross-template
  argument. It's sibling-only within ONE template.
- **Treating `element_update_settings` as a merge by default.** The handler
  REPLACES the entire `props` object on the element unless you pass
  `merge: true`. Read the existing shape via `yootheme_builder_element_get`
  first if you only want to tweak a subset and prefer not to use merge.
- **Clone-then-update path drift.** The clone returns a path that's
  correct at the moment of the call. If you fire off many ops in
  parallel, a concurrent edit may shift indices. Refresh via
  `get_etag` + `page_get_schema` (via gateway) between independent batches.
- **Cloning a bound element keeps the binding.** `element_clone`
  copies the entire element including `props.source`. If the user
  wanted a "data-free" copy, call
  `yootheme_builder_advanced({ tool: "yootheme_builder_element_unbind_source", input: { ... } })`
  on the new path afterwards.
- **Wrong parameter names.** Use `template_id`, `element_path`,
  `etag` (NOT `pageId`, `srcPath`, `ifMatch`).

**Worked example (tool-call snippet):**

```jsonc
// Step 5. Clone the section element as a sibling.
yootheme_builder_element_clone({
  template_id: "home",
  element_path: "/0/children/2",   // the hero section to duplicate
  etag: "abc123"
})
// Response: { path: "/0/children/3", etag: "def456" }

// Step 7. Tweak the clone (replace props entirely, or pass merge: true).
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
copy, follow up with
`yootheme_builder_advanced({ tool: "yootheme_builder_element_unbind_source", input: { ... } })`
on the new path. Verify with the gateway `element_get_binding` call.

**Success criterion:** After publish,
`yootheme_builder_advanced({ tool: "yootheme_builder_page_get_schema", input: { template_id } })`
shows the new section at the cloned path with the user's tweaks reflected in
`element_get` on that path.

---

## Workflow 4: Diagnose a 401 / 403 / auth failure

**Goal:** Recover from `401 Unauthorized` or `403 Forbidden` without
guessing, and without rotating the user's key unnecessarily.

**Canonical tool-call sequence:**

1. `yootheme_builder_diagnose` is a single probe that hits `/health` (no
   auth) and then `/etag` (Bearer auth). Returns
   `{ plugin_reachable, plugin_version, yootheme_loaded, yootheme_version,
   endpoint_count, bearer_valid, bearer_error?, site_url?, home_url?,
   summary? }`. Call this **before** any other tool when you see
   auth errors. (Takes no arguments. The schema is `{}`.)
2. **Interpret the result:**
   - `plugin_reachable: false` → the WordPress / Joomla install is down
     OR the host plugin is deactivated. Send the user to **wp-admin →
     Plugins → activate "YT Builder MCP"** (WordPress) or **Joomla
     administrator → Extensions → Plugins → enable "System - YT Builder
     MCP" and the matching webservices + component entries** (Joomla).
     Do not retry until they confirm.
   - `plugin_reachable: true, bearer_valid: false` → the Bearer key is
     wrong (typo, revoked, or wrong key for this install). The
     `bearer_error` field carries the upstream HTTP status. Send the
     user to:
     - **WordPress:** wp-admin → Tools → "YT Builder MCP" → Bearer Keys
       → copy the existing key into their MCP client config, or
       generate a new one.
     - **Joomla:** Components → YT Builder MCP → Bearer Keys → same.
   - `plugin_reachable: true, bearer_valid: true` but the original
     tool returned a 403 → the key works but the scope is too low for
     the tool's required scope (`write` for mutations, `admin` for
     destructive operations). Ask the user to regenerate the key with
     a higher scope and restart the AI client.
3. **Walk the user through key rotation if needed:**
   - WordPress: "wp-admin → Tools → YT Builder MCP → Bearer Keys."
     Joomla: "Components → YT Builder MCP → Bearer Keys."
   - "Click 'Generate New Key', pick the scope (admin for full access)."
   - "Copy the key. It's shown ONCE; you cannot recover it later."
   - "Update your AI client config: replace `YTB_MCP_BEARER_TOKEN`
     with the new key. The fastest way is to re-run
     `npx -y @wootsup/yt-builder-mcp setup`."
   - "Restart Claude / Cursor / Zed / Continue / Cline / Roo Code /
     Claude Code / Codex CLI."
   - "Confirm with `yootheme_builder_diagnose` that
     `bearer_valid: true` before retrying the original task."

**Common pitfalls:**

- **Treating 401 as a network error.** A network error has no HTTP
  status. It's a TCP/TLS / DNS failure. 401 means the server
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
  different recovery. Never collapse them into one branch.

**Worked example (tool-call snippet):**

```jsonc
// First. Never retry blindly. Call diagnose (no args).
yootheme_builder_diagnose({})
// Response shape:
// {
//   plugin_reachable: true,
//   plugin_version: "1.1.0",
//   yootheme_loaded: true,
//   yootheme_version: "5.0.22",
//   endpoint_count: 16,
//   bearer_valid: false,           // ← key is bad
//   bearer_error: "HTTP 401: invalid_token",
//   site_url: "https://example.com",
//   home_url: "https://example.com"
// }
// → diagnosis: rotate the key. Send user to Tools/Components → YT Builder MCP.
```

**Edge case:** `plugin_reachable: true` but `yootheme_loaded: false`
means the user installed the MCP host plugin but YOOtheme Pro itself
isn't active. The MCP server still answers, but every tool that
touches the YOOtheme layout returns an empty/error response. Surface
the mismatch ("YOOtheme Pro is not active on this install") instead
of retrying. On Joomla this can also surface as a "YOOtheme Pro
required" admin notice in the component dashboard.

**Success criterion:** A subsequent `yootheme_builder_diagnose`
returns `plugin_reachable: true` AND `bearer_valid: true`. The
original tool now returns a non-auth response.

---

## Workflow 5: Add a custom element type to a page

**Goal:** Inspect what element types are installed on the user's
YOOtheme install (built-ins + YOOtheme Pro + YOOessentials + child
theme + plugin-contributed elements), pick the right one, and place
an instance with a sensible default props payload.

**Canonical tool-call sequence (real parameter names, snake_case):**

1. `yootheme_builder_health`: note the YOOtheme version. Custom
   elements often require a minimum YOOtheme major.
2. `yootheme_builder_element_types_list({ fields: ["name", "label", "origin"] })`
   narrows the catalogue with sparse-fields. Returns rows like
   `{ name: "headline", label: "Headline", origin: "core", ... }`.
3. `yootheme_builder_element_type_get_schema({ type_name: "<picked>" })`
   fetches the prop schema for the chosen type. Returns the field
   definitions you can pass via `props`. Note: the parameter is
   `type_name`, not `name`.
4. `yootheme_builder_pages_list({ fields: ["id", "label"] })` and
   `yootheme_builder_page_get_layout({ template_id, flat: false })`
   locate the `parent_path` (JSON-Pointer) where the new element
   should land.
5. `yootheme_builder_get_etag()`: fetch the optimistic-lock etag.
6. `yootheme_builder_element_add({ template_id, parent_path: "<path>", element_type: "<picked-name>", props: { ... }, etag })`
   asks the server to validate `props` against the type schema and
   returns a structured `validation` error with a per-field issue
   list if anything is missing or malformed.
7. (Optional) `yootheme_builder_element_update_settings({ template_id, element_path: "<new-path>", props: { ... }, etag })`
   iterates on the props. **Note: this REPLACES `props` entirely by
   default; pass `merge: true` for a server-side deep-merge.** When
   replacing, include every key you want to keep.
8. `yootheme_builder_advanced({ tool: "yootheme_builder_page_save", input: { template_id, etag } })`
   then `yootheme_builder_advanced({ tool: "yootheme_builder_page_publish", input: { template_id, etag } })`.

**Common pitfalls:**

- **Wrong parameter name on the type-schema tool.** It's
  `type_name`, not `name`. The server's Zod schema rejects unknown
  keys.
- **Wrong parameter names on `element_add`.** Use `template_id`
  (not `pageId`), `parent_path` (not `parentPath`), `element_type`
  (not `type` / `name`), `props` (not `settings`), `etag` (not
  `ifMatch`).
- **`element_update_settings` is a full replace by default.** Any
  key NOT in the request is REMOVED from `props` unless you set
  `merge: true`. Read the existing shape via
  `yootheme_builder_element_get` first if you only want to tweak a
  subset.
- **Custom elements without a schema.** A poorly-built third-party
  element may not register a prop schema. In that case
  `yootheme_builder_element_type_get_schema` returns an empty/sparse
  schema and the server accepts arbitrary `props`. Don't assume "no
  schema = no required fields". Read the third-party element's
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
// Step 2. Narrow the catalogue with sparse-fields to save tokens.
yootheme_builder_element_types_list({
  fields: ["name", "label", "origin"]
})
// Rows: [{ name: "headline", label: "Headline", origin: "core" }, ...]

// Step 3. Fetch the schema (note: type_name, not name).
yootheme_builder_element_type_get_schema({ type_name: "headline" })
// Returns the field definitions for the headline's `props`.

// Step 6. Place the element.
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
talking. Surface this to the user so they know their theme is
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
  Elicitation-aware. Confirms via the AI client prompt before
  destroying state when `confirm` is omitted. On hosts without
  elicitation, it returns a preview-with-confirm-required response;
  call again with `confirm: true`.
- **Unbind a source**: call through the gateway:
  `yootheme_builder_advanced({ tool: "yootheme_builder_element_unbind_source", input: { template_id, element_path, etag, confirm: true } })`.
  Same elicitation flow as delete.
- **Flat schema inspection** (e.g. enumerate every element path +
  type without fetching the whole nested tree): call through the
  gateway: `yootheme_builder_advanced({ tool: "yootheme_builder_page_get_schema", input: { template_id } })`.
- **Etag-only fetch** (e.g. polling for concurrent edits): use
  `yootheme_builder_get_etag()` (takes no arguments) is cheaper than
  fetching the full layout.
- **Find a public/front-end URL for a template** (404 test page,
  homepage URL, etc.): call
  `yootheme_builder_pages_list({ fields: ["id", "label", "frontend_url", "frontend_url_template", "frontend_url_description"] })`,
  match by label, and read `frontend_url` (resolved) or
  `frontend_url_template` (with placeholders the user fills in).
- **Find out which site / install you are connected to**: call
  `yootheme_builder_health` (Bearer-authenticated payload includes
  `site_url` + `home_url`) or `yootheme_builder_diagnose`.
- **Strip legacy `implode` directives** from an element binding (audit-clean
  source props that pre-date the wrapper-source refactor): call through the
  gateway:
  `yootheme_builder_advanced({ tool: "yootheme_builder_clean_implode_directives", input: { template_id, element_path, etag } })`.
  Returns the audit log + a fresh ETag; idempotent (`cleaned_count: 0` when
  there is nothing to remove).

If the user asks for something none of the above covers (e.g. global
theme settings, menu management, media library), tell them clearly:
"This MCP server only covers the YOOtheme Page Builder surface. For
<X> you'll need the relevant CMS REST API or direct wp-admin /
Joomla administrator access." Don't fabricate tool calls.

## Appendix: Tool Catalog (auto-generated)

<!-- TOOL-CATALOG:BEGIN -->

**26 catalogued tools** plus the `yootheme_builder_advanced` gateway = **27 reachable via `tools/list`** (17 L1 + 2 L3 + 1 gateway = 20 advertised; the gateway routes to 7 additional advanced tools, bringing the total to 27 callable). Generated by `scripts/extract-tools.mjs` from the compiled `buildAllTools()` registry. Do not hand-edit this section; re-run `npm run build && node scripts/extract-tools.mjs` after changing tool definitions.

| Tool | Kind | Input keys | Description |
| --- | --- | --- | --- |
| `yootheme_builder_clean_implode_directives` | idempotent | `element_path`, `etag`, `site_id`, `template_id` | Strips `props.source.props.*.implode` directives from an element binding. Returns audit log + new ETag. Idempotent (cleaned_count: 0 when nothing to remove). Requires ETag. Operates on the default site unless site_id is provided. |
| `yootheme_builder_diagnose` | read+idempotent | `site_id` | Full diagnostic: /health + authenticated /etag probe. Returns site_url, home_url, plugin reachability, Bearer validity in one call. First call when you need to know where the site lives. For per-template URLs see pages_list. Operates on the default site unless site_id is provided. |
| `yootheme_builder_element_add` | mutating | `children`, `element_type`, `etag`, `parent_path`, `props`, `site_id`, `template_id` | Add a new element to a template. Provide `parent_path` (or "" for root), `element_type` (e.g. "headline", "text", "grid"), and optional `props` / `children`. Returns the new element's JSON-Pointer path. Requires ETag. Operates on the default site unless site_id is provided. |
| `yootheme_builder_element_bind_source` | idempotent | `bindingLevel`, `element_path`, `etag`, `field_mappings`, `site_id`, `source_id`, `source_name`, `template_id` | Binds a Builder source to an element (sets `props.source`). Use bindingLevel "item" on Multi-Items containers (grid/slideshow/switcher/…) to bind on the first *_item child instead of the container itself. Requires ETag. Operates on the default site unless site_id is provided. |
| `yootheme_builder_element_clone` | mutating | `element_path`, `etag`, `site_id`, `template_id` | Clone an element as a sibling (same parent, immediately after the source). Returns the new element's path. Requires ETag. Operates on the default site unless site_id is provided. |
| `yootheme_builder_element_delete` | destructive | `confirm`, `element_path`, `etag`, `site_id`, `template_id` | PERMANENTLY delete an element and all its children. Cannot be undone. Always ask the user to confirm first, then call again with `confirm: true`. Requires ETag. Operates on the default site unless site_id is provided. |
| `yootheme_builder_element_get` | read+idempotent | `element_path`, `site_id`, `template_id` | Get the full element object at a specific JSON-Pointer path, including props and children. Use yootheme_builder_element_list to discover paths. Operates on the default site unless site_id is provided. |
| `yootheme_builder_element_get_binding` | read+idempotent | `element_path`, `site_id`, `template_id` | Read the source binding attached to an element: the bound source name, the field-mappings (which source field feeds which element prop) and the query arguments/directives. Returns the empty object if the element is not bound. Operates on the default site unless site_id is provided. |
| `yootheme_builder_element_list` | read+idempotent | `cursor`, `depth`, `fields`, `limit`, `root_path`, `site_id`, `template_id` | List elements in a template as a flat array with JSON-Pointer paths + types. Scope with `root_path`/`depth` for a subtree, paginate with `limit`/`cursor` for large templates. `fields[]` narrows each row. Operates on the default site unless site_id is provided. |
| `yootheme_builder_element_move` | idempotent | `element_path`, `etag`, `site_id`, `template_id`, `to_index`, `to_parent_path` | Move an element to a new parent + index in the tree. Useful for reordering or reparenting (e.g. moving a card from one grid column to another). Requires ETag. Operates on the default site unless site_id is provided. |
| `yootheme_builder_element_type_get_schema` | read+idempotent | `element_type`, `site_id`, `type_name` | **Call before every `element_add` / `_update_settings`.** Unknown prop keys are silently dropped server-side, so guessing fails quietly. Returns `{name,type,label?}` field descriptors. Use `element_type`; `type_name` is DEPRECATED. Operates on the default site unless site_id is provided. |
| `yootheme_builder_element_types_list` | read+idempotent | `fields`, `site_id` | List element types registered on this site (built-ins + YOOessentials/uEssentials extras). Names feed `element_type` of element_add. Pass `fields[]` to narrow each row. Operates on the default site unless site_id is provided. |
| `yootheme_builder_element_unbind_source` | destructive | `confirm`, `element_path`, `etag`, `site_id`, `template_id` | Remove the source binding from an element. Clears `props.source`. Destructive in the sense that it may break dynamic-content rendering, so always ask the user to confirm. Requires ETag. Operates on the default site unless site_id is provided. |
| `yootheme_builder_element_update_settings` | idempotent | `element_path`, `etag`, `merge`, `props`, `site_id`, `template_id` | Update `props` on an element. Default replaces all props; pass `merge:true` for server-side deep-merge (only request keys overwritten, others survive, which avoids read-modify-write races). Requires ETag. Operates on the default site unless site_id is provided. |
| `yootheme_builder_get_etag` | read+idempotent | `site_id` | Get the current ETag (state revision) for the YOOtheme builder. Returns sha256+revision string used for optimistic locking on writes. Pass the returned value back as `etag` on any write tool (page_save, page_publish, element_add, element_update_settings, element_clone, element_move, element_delete). The server returns HTTP 412 if the ETag has changed since you read it. Keywords: get etag, current etag, state revision, optimistic lock, version stamp. Operates on the default site unless site_id is provided. |
| `yootheme_builder_health` | read+idempotent | `site_id` | Check plugin installed/reachable. Returns plugin version, YT Pro version, REST endpoints. Authenticated payload adds site_url + home_url for deep-linking. See yootheme_builder_diagnose for Bearer-validity + connectivity summary. Operates on the default site unless site_id is provided. |
| `yootheme_builder_inspect_multi_items_binding` | read+idempotent | `element_path`, `site_id`, `template_id` | Reports Multi-Items binding state: container/item pair (grid↔grid_item, slideshow↔slideshow_item, …), current binding level (none\|container\|item), and a recommended_fix when the binding sits on the container instead of the child. Operates on the default site unless site_id is provided. |
| `yootheme_builder_page_get_layout` | read+idempotent | `fields`, `flat`, `site_id`, `template_id` | Get full layout tree for one template. Default nested `{layout, etag}`. Set `flat:true` for depth-first array `{elements:[...], etag}`; combine with `fields[]` to project per-element. Operates on the default site unless site_id is provided. |
| `yootheme_builder_page_get_schema` | read+idempotent | `site_id`, `template_id` | Get the flat schema for a template: a list of nodes with their JSON-Pointer paths and element types. Best entry-point for navigation: lighter than page_get_layout, sufficient to locate elements before editing. Operates on the default site unless site_id is provided. |
| `yootheme_builder_page_publish` | idempotent | `etag`, `site_id`, `template_id` | Publish a template: persist state, flush YT + WP caches, snapshot the published-state ETag. ETag optional. When provided, 412 on conflict; when omitted, last-write-wins. Recommended for collaborative edits. Operates on the default site unless site_id is provided. |
| `yootheme_builder_page_save` | idempotent | `etag`, `site_id`, `template_id` | Re-run save-transforms and persist. ETag optional. When provided, 412 on conflict; when omitted, last-write-wins. Recommended for collaborative edits. No-op when state is byte-identical (returns `no_changes:true`, ETag unchanged). Operates on the default site unless site_id is provided. |
| `yootheme_builder_pages_list` | read+idempotent | `fields`, `site_id` | List all pages, templates, and layouts in the YOOtheme Pro builder. Returns template_id, label, type, element count, and frontend_url per row. CALL THIS FIRST to discover available template IDs before any tool that needs a template_id (page_get_layout, element_list, page_get_schema, etc.). Keywords: list pages, list templates, list layouts, discover template_id, index, available templates, what pages exist. Pass `fields:["id","label"]` to slim. Returns ALL pages in one call (no pagination, typically <50 templates per site). Operates on the default site unless site_id is provided. |
| `yootheme_builder_sites_list` | read+idempotent | `site_id` | List all sites configured in this multi-site MCP installation. Returns site_id + URL + platform (wordpress\|joomla) + default flag per row. CALL THIS FIRST when working with a fresh MCP connection to discover available site_ids before targeting one with any other tool. Read-only, no REST calls. Keywords: list sites, list connections, list installations, discover site_id, available sites, configured sites, what sites exist, multi-site index. (site_id is accepted for schema-uniformity but ignored by this tool.) |
| `yootheme_builder_sites_test` | read+idempotent | `site_id` | Verify connectivity to ONE site: probes /health (no auth) + /etag (auth) in parallel; returns plugin_reachable + bearer_valid. `site_id` is REQUIRED. Use sites_list to find IDs. |
| `yootheme_builder_sources_list` | read+idempotent | `fields`, `site_id` | List all data sources, feeds, and dynamic content sources available in the YOOtheme Pro builder. Returns name + label + origin (apimapper / wordpress / joomla / essentials) per source. CALL THIS BEFORE binding any element to a data source. Pick a source name from the list and pass it to `element_bind_source`. Keywords: list sources, list data sources, list feeds, list bindings, dynamic content, available data, what sources exist. Pass `fields[]` to narrow each row. Operates on the default site unless site_id is provided. |
| `yootheme_builder_template_summary` | read+idempotent | `site_id`, `template_id` | Token-efficient template overview: element counts by type, binding count, max nesting depth, and named landmark sections, computed server-side in one call. Use this to grasp a large template before pulling element_list or page_get_layout. Operates on the default site unless site_id is provided. |

<!-- TOOL-CATALOG:END -->
