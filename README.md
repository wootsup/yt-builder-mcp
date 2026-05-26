# YT Builder MCP for YOOtheme Pro (unofficial)

> **Drive your page builder via MCP.** Built for YOOtheme Pro 4.0+. Connect Claude Desktop, Claude Code, Cursor, Zed, Continue, Cline, Roo Code, Codex CLI or Gemini CLI in one command.
> Free WordPress plugin + Joomla 5/6 package + NPM package. GPLv2 + MIT.

[![Version](https://img.shields.io/badge/version-1.1.5-blue)]() [![License: GPLv2 / MIT](https://img.shields.io/badge/license-GPLv2%20%2B%20MIT-blue)]()

> Works on **WordPress** and **Joomla 5/6**. The same MCP server speaks to either
> host plugin. See the **Quickstart** sections below for each platform.

> Independent third-party project. YOOtheme® is a registered trademark of YOOtheme GmbH
> ([yootheme.com](https://yootheme.com)). YT Builder MCP is built by WootsUp (getimo
> productions) and is not affiliated with, endorsed by, or sponsored by YOOtheme.
> The integration uses YOOtheme Pro's public extension points.

---

## TL;DR

`yt-builder-mcp` exposes your page builder as a [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server. Once installed, your AI assistant can:

- **List pages**, walk element trees, inspect layouts.
- **Add, update, move, clone, delete** any element — grids, headlines, images, lists, gallery — anything the builder supports.
- **Bind Dynamic Sources** to elements (e.g. an [API Mapper](https://wootsup.com/products/api-mapper) source).
- **Save & publish** pages, with proper cache invalidation.
- **Discover element types** and their JSON schemas at runtime.

Two parts, one product:

| Part | Tech | License | Where |
|------|------|---------|-------|
| WordPress Plugin | PHP 8.2+ | GPLv2 | Install on your WP site |
| NPM Package | TypeScript + `@modelcontextprotocol/sdk` | MIT | Runs locally next to your AI client |

The plugin speaks a REST dialect (Bearer-authenticated, ETag-guarded). The NPM package runs an MCP `stdio` server and translates AI tool-calls into REST requests against the plugin.

---

## Why does this exist?

YT Builder MCP is the middle ground between clicking in the visual builder and writing themes by hand. The use case it was built for:

- **AI-assisted page construction** — describe a page in natural language, your AI assistant builds it. End customers get the power-user experience without learning the builder.
- **Headless / automation workflows** — drive your page builder from any tool that speaks MCP (Claude Desktop, Cursor, a CI worker, your own AI agent) over a stable Bearer-authenticated REST surface.

---

## Use-case examples

### Example 1 — "Add a Pexels image grid to the homepage"

```
You:   Add a 3-column image grid to the homepage and bind it to my "Pexels Search" source.

Claude:  → yootheme_builder_pages_list
         → yootheme_builder_page_get_schema (homepage)
         → yootheme_builder_element_add (grid, columns: 3)
         → yootheme_builder_sources_list  (finds "Pexels Search")
         → yootheme_builder_element_bind_source
         → yootheme_builder_page_save

         Done. The homepage now has a 3-column grid bound to Pexels Search.
```

> Screenshots coming in v0.2.

### Example 2 — "What elements are on this page?"

```
You:   Walk me through the layout of /landing-page.

Claude:  → yootheme_builder_pages_list
         → yootheme_builder_page_get_schema (landing-page)

         The page has 5 sections:
         1. Hero (section/0)  — headline + 2 buttons
         2. Features (section/1) — 4-column grid
         3. Pricing (section/2) — 3 cards
         4. FAQ (section/3) — accordion, 6 items
         5. CTA (section/4) — headline + form
```

> Screenshots coming in v0.2.

---

## Quickstart

### 1 — Install the WordPress plugin

Download the latest plugin ZIP from
[GitHub Releases](https://github.com/wootsup/yt-builder-mcp/releases),
then upload it via **WP-Admin → Plugins → Add New → Upload Plugin**.

(A WordPress.org listing is planned for a future release.)

You need:

- WordPress 6.0+
- PHP 8.2+
- YOOtheme Pro 4.0+ (active theme; v4 + v5 both supported)

### 2 — Generate a Bearer Key

In WP-Admin, go to **Tools → YT Builder MCP**.

Click **Generate New Key**. Copy it. You will paste it into the wizard in the next step.

> Screenshots coming in v0.2.

### 3 — Run the wizard

```bash
npx -y @wootsup/yt-builder-mcp setup
```

The wizard asks you for:

1. The Bearer Key you just generated
2. Your WordPress site URL — **pre-filled from the key**, just press Enter
3. A profile name (use `default` if you only have one site)
4. Which AI clients to configure (multi-select: Claude Desktop, Claude Code, Cursor, Zed, Continue, Cline, Roo Code, Codex CLI, Gemini CLI)

It probes `/wp-json/yt-builder-mcp/v1/health` to confirm the plugin is reachable, then writes the MCP server entry into each selected client's config file.

Restart your AI client. You should see new tools prefixed with `yootheme_builder_*` (20 advertised in `tools/list`, plus 7 advanced reachable through the `yootheme_builder_advanced` gateway).

### 4 — Try it

Open Claude (or any configured client) and try:

> List my pages.

You should see all your templates.

---

## Quickstart (Joomla)

The Joomla package ships the same MCP surface; only the install + key-generation
steps differ. After step 3 below, the wizard (step "Run the wizard" above) works
identically — it auto-detects Joomla from your site URL.

### 1 — Install the Joomla package

Download the latest Joomla package ZIP (`pkg_ytbmcp-*.zip`) from
[GitHub Releases](https://github.com/wootsup/yt-builder-mcp/releases),
then upload it via **Administrator → System → Install → Extensions → Upload Package File**.

You need:

- Joomla 5.x or 6.x
- PHP 8.2+
- YOOtheme Pro 4.0+ (active template; v4 + v5 both supported)

### 2 — Enable the plugins

The post-install script auto-enables them, but verify under
**System → Manage → Plugins**:

- **System – YT Builder MCP** (`plg_system_ytbmcp`) — bootstraps the extension
- **Web Services – YT Builder MCP** (`plg_webservices_ytbmcp`) — registers the REST routes

Also confirm the Joomla **Web Services** API is on under
**System → Global Configuration → Server → Web Services → Enable**.

### 3 — Generate a Bearer Key

Go to **Components → YT Builder MCP**, open the **Bearer Keys** tab, and click
**Generate Key**. Copy the token — it is shown once. (Same key flow as WordPress;
the **Diagnostics** tab shows the live YOOtheme Pro / Joomla / PHP versions and the
REST endpoint inventory.)

### 4 — Probe the REST surface

Confirm the plugin is reachable (anonymous health probe — no token needed):

```bash
curl https://example.com/api/index.php/v1/yt-builder-mcp/health
# → {"plugin_version":"…","status":"ok"}
```

Then run the wizard exactly as in the WordPress flow above (`npx -y @wootsup/yt-builder-mcp setup`)
— paste the Bearer key and your Joomla site URL; the wrapper detects the
`/api/index.php/` shape automatically (or set `YTB_MCP_PLATFORM=joomla` for an
origin-only URL).

---

## Tool catalogue

The MCP server exposes 26 typed tools plus a single `yootheme_builder_advanced`
gateway. 20 of them are advertised first-class in `tools/list` (the essentials);
the remaining 7 are reachable through the gateway, keeping the surface well
under Cursor's tool cap while every tool stays callable.

### Health (2)

| Tool | Description |
|------|-------------|
| `yootheme_builder_health` | Plugin version, YT Pro version, CMS version, storage backend, list of available REST endpoints. |
| `yootheme_builder_diagnose` | Connectivity + auth check in one call. Returns hints when things are misconfigured. |

### Sites (2, multi-site)

| Tool | Description |
|------|-------------|
| `yootheme_builder_sites_list` | List all sites configured in the MCP installation. |
| `yootheme_builder_sites_test` | Verify connectivity to one site (health + auth probes in parallel). |

### Pages (6)

| Tool | Description |
|------|-------------|
| `yootheme_builder_pages_list` | List all pages, templates, layouts. Returns `template_id`, label, type, frontend_url. |
| `yootheme_builder_page_get_layout` | Get the full nested layout tree (heavy). |
| `yootheme_builder_page_get_schema` | Get the flat schema (nodes + paths) for a template. Preferred for navigation. |
| `yootheme_builder_template_summary` | Token-efficient template overview: element counts, binding count, nesting depth. |
| `yootheme_builder_get_etag` | Get the current ETag for optimistic-lock writes. |
| `yootheme_builder_page_save` / `_publish` | Re-run save-transforms / publish a template. |

### Elements (7)

| Tool | Description |
|------|-------------|
| `yootheme_builder_element_list` | List elements within a template (paginated, sparse-fields). |
| `yootheme_builder_element_get` | Get one element by JSON-Pointer path. |
| `yootheme_builder_element_add` | Add a new element under a parent path. |
| `yootheme_builder_element_update_settings` | Update settings for an existing element (replace or deep-merge). |
| `yootheme_builder_element_move` | Move an element to a new parent + index. |
| `yootheme_builder_element_clone` | Clone an element as a sibling. |
| `yootheme_builder_element_delete` | Delete an element (requires `confirm: true`). |

### Sources & bindings (5)

| Tool | Description |
|------|-------------|
| `yootheme_builder_sources_list` | List Dynamic Sources available (e.g. API Mapper sources). |
| `yootheme_builder_element_get_binding` | Get the current Dynamic Source binding for an element. |
| `yootheme_builder_element_bind_source` | Bind a Dynamic Source to an element. |
| `yootheme_builder_element_unbind_source` | Remove the binding. |
| `yootheme_builder_inspect_multi_items_binding` | Inspect Multi-Items container/item state; flags container-level mis-bindings. |

### Inspection & maintenance (3)

| Tool | Description |
|------|-------------|
| `yootheme_builder_element_types_list` | List all element types the builder exposes (grid, headline, image, …). |
| `yootheme_builder_element_type_get_schema` | Get the JSON schema for one element type. Call before every add/update. |
| `yootheme_builder_clean_implode_directives` | Strip stale `props.source.props.*.implode` directives from a binding. |

### Gateway (1)

| Tool | Description |
|------|-------------|
| `yootheme_builder_advanced` | Single entry that routes to 7 advanced tools by name. Call `({ tool, arguments })`. |

See [`docs/mcp-tool-reference.md`](./docs/mcp-tool-reference.md) for full input/output schemas
and [`docs/TOOL-CATALOG.md`](./docs/TOOL-CATALOG.md) for an auto-generated, sparse-fields catalog.

---

## Architecture

```
┌─────────────────────────────┐
│  AI Agent                   │
│  (Claude Desktop, Cursor,   │
│   Continue, Zed)            │
└──────────────┬──────────────┘
               │ MCP stdio
               ▼
┌─────────────────────────────────────────┐
│  @wootsup/yt-builder-mcp  (NPM)         │
│  TypeScript                             │
│  @modelcontextprotocol/sdk              │
│  26 tools + 1 gateway (Zod schemas)     │
└──────────────┬──────────────────────────┘
               │ HTTPS + Bearer-token
               ▼
┌─────────────────────────────────────────┐
│  Host plugin (WordPress OR Joomla)      │
│  PHP 8.2+, GPLv2                        │
│  REST namespace: yt-builder-mcp/v1      │
│  Modules:                               │
│      core-auth   (Bearer + HMAC)        │
│      builder-state                      │
│      builder-pages                      │
│      builder-elements                   │
│      builder-inspection                 │
│      builder-source-binding             │
│      builder-cache                      │
└──────────────┬──────────────────────────┘
               │ \YOOtheme\app(\YOOtheme\Builder)
               ▼
┌─────────────────────────────────────────┐
│  YOOtheme Pro Theme                     │
│  Storage:                               │
│    WP: wp_option('yootheme') JSON       │
│    Joomla: #__extensions.custom_data    │
└─────────────────────────────────────────┘
```

**Design principles**

- **State-only.** The plugin reads and writes the existing layout JSON. It does not register new element types or define new schemas.
- **Optimistic locking.** Every write requires an `If-Match: <etag>` header — concurrent edits return `412 Precondition Failed`.
- **Bearer + HMAC.** Keys are generated server-side, hashed at rest, and verified with constant-time `hash_equals()`.
- **Bring-your-own-AI.** No SaaS lock-in. The MCP server runs locally next to your AI client.

---

## Documentation

| Document | What it covers |
|----------|----------------|
| [`docs/getting-started.md`](./docs/getting-started.md) | Step-by-step install + first prompt |
| [`docs/rest-api-reference.md`](./docs/rest-api-reference.md) | All REST endpoints with request/response schemas |
| [`docs/mcp-tool-reference.md`](./docs/mcp-tool-reference.md) | All MCP tools with Zod schemas |
| [`docs/TOOL-CATALOG.md`](./docs/TOOL-CATALOG.md) | Quick-scan catalog of all MCP tools with sparse-fields hints |
| [`docs/cross-platform-parity.md`](./docs/cross-platform-parity.md) | WordPress ↔ Joomla intentional divergences + parity decisions |
| [`CHANGELOG.md`](./CHANGELOG.md) | Version history |
| [`SECURITY.md`](./SECURITY.md) | How to report security issues |

---

## License

- **WordPress Plugin (`src/`)** — GPLv2 or later. Compatible with WordPress + YOOtheme Pro.
- **NPM Package (`packages/mcp/`)** — MIT. Use it however you want.

The dual-license is intentional. The plugin must be GPL to live in the WordPress ecosystem; the MCP server is yours to embed, fork, or republish.

---

## Current release

**v1.1.5** (2026-05-26). WordPress + Joomla 5/6. 20 advertised MCP tools plus 7 advanced behind the gateway. See [CHANGELOG.md](./CHANGELOG.md) for release history.

---

## Acknowledgments

- **YOOtheme** for building one of the most flexible Page Builders for WordPress + Joomla.
- **Anthropic** for the Model Context Protocol — the missing wire format for AI tools.
- **WootsUp customers** who funded the time to build this.

---

## Want to know more?

- [github.com/wootsup/yt-builder-mcp](https://github.com/wootsup/yt-builder-mcp) — source, releases, issue tracker
- [wootsup.com/api-mapper](https://wootsup.com/api-mapper) — companion product: bring any REST API into your page builder as a Dynamic Source

Built with care by [WootsUp](https://wootsup.com) — getimo productions.
