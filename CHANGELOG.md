# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project follows [Semantic Versioning](https://semver.org/).

## [1.1.5] `Latest` - 2026-05-26

### Changed

- **Tool descriptions enriched with discovery keywords. Claude Desktop surfaces all 20 advertised tools (was 16), including pages_list, sites_list, sources_list, get_etag.** `mcp` `discoverability`
- **Template summary declares an output schema. Strict MCP hosts render structured-content cards.** `mcp`
- **Field-projection feedback (available_fields + unknown_fields) on list tools so typos are visible.** `mcp`
- **Element-pointer paths consistent across tools (all emit /children/0 style); element_path vs rel_path disambiguated.** `mcp`
- **element_type_get_schema shows a real field table (NAME | TYPE | LABEL), paginated for big types like grid (148 fields).** `mcp`
- **Paginated element listings show "N of M (page X of Y)" header + next_cursor footer.** `mcp`
- **Pages list clarifies single-call behavior (no pagination).** `mcp`
- **Joomla sources_list returns origin: "joomla" for native Joomla sources (was "wordpress").** `mcp` `joomla` `cross-platform`
- **MCP server-info version sourced from package.json (no more constant drift on initialize).** `mcp`
- **Joomla L2 articles surface is REST-only in v1.x; MCP coverage planned for v1.2.0.** `docs` `joomla`

### Fixed

- **WordPress front-end stays up after writes. The YOOtheme storage option is pinned as JSON, preventing the PHP-array corruption that 500'd every page.** `wordpress` `stability`
- **Claude Desktop accepts every tool result. Site metadata moved from structuredContent to result-level _meta; strict outputSchema validation stops returning -32602.** `mcp`
- **Joomla parity round. REST endpoints auto-bootstrap YOOtheme, /elements/:path routes correctly, ETag bumps on L2 writes, /health surfaces yootheme_version, deploy script handles the Joomla package end-to-end.** `joomla`
- **Error envelopes tightened: structured 400 for invalid pagination cursor, structured 404 for unknown root_path, host-boundary validation for missing required args.** `mcp`

## [1.1.4] - 2026-05-26

### Changed

- **Template summary tool now ships an explicit output schema — Claude Desktop, Cursor, and other strict hosts can validate responses and surface richer structured-content cards.**
- **Pages list description clarifies that all pages return in a single call (no pagination) — fewer wasted retries when an agent expects cursor-based paging.**
- **Audit harness updated to reflect the current v1.x scope (L1 templates only); the Joomla L2 article MCP surface is on the v1.2.0 roadmap.**

## [1.1.3] - 2026-05-26

### Changed

- **Tool descriptions enriched with discovery keywords. `pages_list`, `sites_list`, `sources_list`, and `get_etag` now lead with explicit verbs ("List all pages, templates...", "List all sites configured...", "Get the current ETag...") and carry keyword synonyms. Hosts that use semantic tool-search (Claude Desktop ToolSearch, etc.) should now surface these discovery tools more reliably.** `mcp` `discoverability`

## [1.1.2] - 2026-05-26

### Changed

- **`element_type_get_schema` now shows a real field table (`NAME | TYPE | LABEL`) instead of a truncated keys-only summary. The docs always said to call this before `element_add`, but the previous output gave you no type info — you had to guess prop types. Now you can see exactly what each field expects, paginated for the big types (grid has 148).** `mcp` `schema`
- **Element listings tell you which page you got. With pagination active, the header reads `N of M (page X of Y)` and the footer hints at the `next_cursor` to copy into the next call. No more silent truncation.** `mcp` `pagination`
- **Layout traversal paths are consistent across tools. `page_get_layout` flat mode now emits `/children/0` style paths matching `element_list` and `page_get_schema`. The earlier `/layout/layout/children/0` double-prefix is gone, so you can copy a path between tools without rewriting it.** `mcp` `paths`

### Fixed

- **Calling `element_type_get_schema` with neither `element_type` nor `type_name` now fails at the host boundary with a clear input-validation error. The previous behaviour bounced you back to the handler with a generic 400 — now your host can catch the typo pre-flight.** `mcp` `input-validation`
- **An invalid pagination `cursor` now returns a structured 400 with a hint to omit it for page 1. Earlier releases silently returned page 1 on garbage, which made it look like your cursor worked.** `mcp` `pagination`
- **An unknown `root_path` on `element_list` now returns a structured 404 with a recovery hint instead of an empty list. The old `0 elements` response was indistinguishable from a legitimately empty subtree.** `mcp` `errors`

## [1.1.1] - 2026-05-26

### Changed

- **List tools tell you which fields exist. Pass `fields:["foo"]` to pages_list, element_list, sources_list, or element_types_list, and the response now includes `available_fields` (every key you can pick from) and `unknown_fields` (anything you asked for that does not match an item). Previously the response was a silent `items:[{},{},…]`.** `mcp` `projection`
- **`page_get_schema` now returns `rel_path` (`/children/0`) instead of the fully-qualified `/templates/<id>/layout/...` pointer. Matches the column you already see on `element_list` and `page_get_layout`, so you can copy a path between tools without rewriting it.** `mcp` `paths`
- **Tool input description disambiguates `element_path` (the input arg used everywhere) from `rel_path` (the projection column on list output). Removes the cross-tool typo that produced -32602 input-validation errors.** `mcp` `dx`

### Fixed

- **The WordPress front-end stays up after writes. I now save the YOOtheme storage option as a JSON string and pin it with a one-shot filter, so a write can no longer corrupt the option into a PHP array that crashes every page with `json_decode(): Argument #1 must be of type string, array given`.** `wordpress` `stability`
- **Claude Desktop accepts every tool result. Site-awareness metadata moved out of `structuredContent` to the result-level `_meta`, so strict outputSchema validation stops rejecting calls with -32602 "Failed to call tool". Affected every tool that declares an outputSchema.** `mcp` `host-compat`
- **Joomla REST endpoints now load YOOtheme automatically. Sources, element-type schemas, and template parsers used to return empty results because `\YOOtheme\app` was never registered for `com_api` requests. The dispatcher now bootstraps YOOtheme once per request, transparently.** `joomla` `bootstrap`
- **Joomla element-pointer URLs resolve correctly. Two bugs combined: a route rule that produced a double-capture in the compiled regex, and a registration order that let `/elements/:path` swallow longer URLs like `/elements/:path/multi-items/inspect`. Both fixed; every element-pointer endpoint now hits the controller you expect.** `joomla` `routing`
- **Joomla ETag advances on per-article writes. The global state-revision counter now ticks on L2 article-element writes too, not just L1 template writes. `yootheme_builder_get_etag` reflects every mutation.** `joomla` `etag`
- **Joomla health endpoint surfaces `yootheme_version`. Was missing for the augmented (bearer-gated) payload, making cross-platform agents branch unnecessarily. Now matches the WordPress shape one-to-one.** `joomla` `health`
- **The deploy script handles the yt-builder-mcp Joomla package. Earlier releases shipped the WordPress fixes only; the Joomla install path was hardcoded for a different product and reported "no extensions found" on every run. Now product-aware, with per-sub-extension install diagnostics.** `joomla` `deploy`

## [1.1.0] - 2026-05-25

### Added

- ****Joomla 5/6 support.** Yesterday we shipped the 1.0.1 WordPress release. Today's 1.1.0 brings the same YT Builder MCP server to Joomla 5/6 with 1:1 feature parity. Install the `pkg_ytbmcp` package and get the same REST surface, the same admin UI, and the same L1 builder-state writer that has shipped on WordPress since 1.0.0. The package installs three sub-extensions (system plugin, webservices plugin, admin component).**
- **L2 per-article layout storage on Joomla. Write builder layouts directly to `#__content.fulltext` of individual articles with their own optimistic-lock ETags, governed solely by the Bearer scope hierarchy.**
- **Joomla admin component with 3-tab dashboard (Keys, Diagnostics, About) at 1:1 parity with the WordPress settings page: key generate / list / revoke, reveal-token notice, copy-as-markdown diagnostics, supported-clients and license table.**
- **One-click Claude Desktop install on Joomla. The same `.dxt` Desktop Extension bundle that ships on WordPress is built into and served from `media/com_ytbmcp/`, with a download CTA in the admin Keys tab.**
- **Published Joomla `update.xml` feed for in-CMS update parity with the WordPress `info.json` feed. Both platforms now self-update from `updates.wootsup.com/yt-builder-mcp/`.**
- **Rich branded post-install panel on Joomla (postflight: YOOtheme Pro, PHP, and Joomla compatibility checks plus getting-started tiles, dark-mode aware) mirroring the WordPress experience.**
- **Branded `YOOtheme Pro required` admin notice on Joomla when YT is absent. No more silently missing menu entries.**
- **Component `access.xml` plus `core.admin` / `core.manage` capability guard on the Joomla admin dashboard so the component is governed by Joomla's permission UI. Branded menu icon and component `<config>` included.**
- **Byte-stable, reproducible Joomla package ZIP. Two builds of the same source produce a byte-identical `pkg_ytbmcp_v{version}.zip`.**
- **Upgrade self-heal on Joomla. Prunes stale media on update and on a request-time sentinel so leftover files from a previous version never bleed into the new one.**
- **Standards-compliant dark-mode support for the admin UI on both platforms (`[data-bs-theme]` + Bootstrap `--bs-*` vars). Native-Joomla and native-WordPress admin look (custom brand CSS dropped, branded logo retained).**
- ****Multi-site support across both platforms.** Drive many YOOtheme sites from one MCP install. Configure each site in `~/.config/yt-builder-mcp/sites.json` (chmod 0600). Every tool accepts an optional `site_id` parameter and falls back to the default site when omitted.**
- **Five new CLI subcommands for multi-site management: `add-site`, `list-sites`, `remove-site`, `set-default`, `test-site`. Run via `npx -y @wootsup/yt-builder-mcp <subcommand>`.**
- **1Password integration for Bearer storage. Use `bearer_ref: "op://Vault/Item/credential"` instead of inline tokens. The MCP server shells out to the `op` CLI on first use per site and caches the resolved token in memory.**
- **Two new L1 tools: `sites_list` and `sites_test`. Discover configured site_ids without leaving the MCP surface; pre-flight one site without mutating anything.**
- **Site-awareness in every reply. Text responses are prefixed with `[label @ host]`; structured payloads carry `_meta.site_id`, `_meta.site_url`, and `_meta.platform` so the calling agent always knows which site answered.**
- **`YTB_MCP_SITES_FILE` user-config field in the DXT manifest. Point Claude Desktop at a multi-site registry; the legacy single-site env vars stay supported for backward compatibility.**

### Changed

- **Cross-platform interface extraction: `LayoutReaderInterface`, `LayoutWriterInterface`, and `StateRevisionInterface` are now the single contract that both the WordPress and Joomla adapters satisfy — no behavioural change for existing WordPress installations.**
- **`BearerVerifier` constructor type-widened to `KeyStoreInterface` so the same verifier can drive WordPress and Joomla key stores. Existing WordPress tests pass unchanged.**
- **3-tier encryption-key resolver on Joomla — `YTB_MCP_ENCRYPTION_KEY` constant → out-of-webroot key file → hardened fallback under `media/ytb_mcp_secure/` (deliberately non-manifest-owned so package uninstall does not orphan a preserved encrypted signing-secret). Legacy `media/com_ytbmcp/.encryption_key` is migrated verbatim on first resolve — no token break.**

### Fixed

- **Reliable admin YOOtheme Pro version detection on Joomla via `#__extensions` — no more false `YOOtheme required` notice or empty version in Diagnostics when YT is actually installed.**
- **Header CTAs and prominent wootsup.com link in the admin UI on both platforms; reveal-box native card with uniform copy buttons (WordPress + Joomla parity).**
- **WordPress custom auto-updater (Update URI + `updates.wootsup.com` `info.json`) for update parity with the Joomla feed — publish gated.**
- **Tier-3 fallback encryption key on Joomla is relocated to `media/ytb_mcp_secure/` outside the component manifest tree so package uninstall does not orphan a preserved encrypted signing-secret.**

### Security

- **L2 per-article writes on Joomla are governed solely by the Bearer scope hierarchy (the per-article `core.edit` ACL gate was removed per ADR `l2-bearer-as-authority` and is now counter-pinned by `L2BearerAuthorityPinTest`).**
- **Cache-flush scoping per ADR-002: `flushL1()` invalidates only the YT cache layer (no Joomla-side `alloptions` equivalent exists), `flushL2($articleId)` additionally cleans the `com_content` cache-group and the `page` group when `plg_system_cache` is active — no unscoped global flushes.**
- **Customer data preserved by default on Joomla uninstall — the destructive table-DROP path requires explicit opt-in via the `delete_data` plugin parameter.**
- **Bearer references via `op://...` keep secrets out of `sites.json` and out of tool-call payloads; the MCP server resolves the token via the local `op` CLI and caches it in memory only.**

## [1.0.1] - 2026-05-23

### Added

- **Clearer error messages when an element can't be found. The 404 response now tells you exactly what went wrong — whether the path was URL-encoded (use literal slashes) or missing the `templates/<id>/` prefix.**
- **Element previews in the list endpoint. Each row now includes a short text snippet (first 60 characters, HTML stripped) so you can identify the right element without a follow-up detail request.**
- **Audit-friendly element listing. Pass `?include=props` to `element_list` and you get every element's full properties in one call — no more whole-template dumps for accessibility scans or content audits.**
- **Homepage detection in the pages listing. When WordPress is set to show posts on the front page, the matching template is flagged with `is_public_homepage:true` so you can find it without checking each template manually.**
- **Update transparency. Every `update_settings` response now tells you whether it replaced or merged your changes, and lists exactly which properties were dropped when you used the default replace mode. No more surprise binding wipes.**
- **Binding write visibility. The binding endpoint now reports any body fields it ignored (for example, `raw_source.query.arguments` which isn't honoured here). The response stays 200 OK, but you see what the server didn't apply.**
- **Authoritative-source pointer on bound elements. `element_get` now points at `props.source` as the source of truth, making it easy to tell the live binding apart from the legacy denormalised cache on the same object.**
- **Filterable source listing. `/sources` accepts `?group=` (filter by origin — `wordpress`, `apimapper`, etc.) and `?kind=` (filter by GraphQL type) so you can find the one source you want in a long list.**
- **Discoverable REST surface. The authenticated `/health` response now includes the element-path format with an example pointer plus a link to the help route, so you have everything you need in your first call.**
- **Accessibility metadata in the element catalog. Every well-known element type now carries a semantic role (heading, link, image, region, list, list-item, separator, button, video, text, or none) — covers around 40 core types.**
- **Select-field discoverability. Element-type schemas now expose `enum` (the valid set of values for select / radio fields) and `default` (the value used when the field isn't set). No more trial-and-error to figure out what `title_element` or `image_alt_decorative` accept.**
- **Helpful 400 on invalid `?include=`. Pass an unrecognised include token and you get a clear error telling you what's actually accepted, instead of silent fallthrough.**
- **Cleaner skill bundling. The bundled skill folder is now named `yt-builder-mcp/` (matching the package and REST namespace). Existing installations migrate automatically on the next setup run — opt out via `removeLegacyDir:false`.**

### Changed

- **Settings page documentation updated to reflect the renamed slug and the new 1.0.1 surface. No functional changes to the admin UI flows.**

### Fixed

- **Paste-friendly element paths. The short `/children/0/...` form returned by `element_list` now works in every other endpoint too — no more manually adding the `/templates/<id>/layout` prefix.**
- **Safer pointer handling. A literal `..` segment in an element path is treated as the two-character key it actually is (per RFC-6901) — no accidental path-traversal interpretation.**
- **Clearer layout responses. `page_get_layout` now returns the canonical pointer base alongside the layout tree, so you don't accidentally double the `/layout` segment when building paths.**
- **Consistent parameter name for the schema lookup. `element_type_get_schema` accepts `element_type` (matching every other tool). The old `type_name` parameter still works for backward compatibility.**
- **Combined connectivity check. `diagnose` now returns the site URL and home URL alongside the bearer-key status — one call instead of two.**
- **Reliable enum extraction. Both YOOtheme option shapes — a flat list of slugs, or a label-keyed map — are normalised consistently to a clean list of valid values.**
- **Cleaner content previews. Rich-text HTML is stripped before truncation and multibyte text is cut on character boundaries — previews never contain stray markup or broken characters.**
- **Stricter `?include=` validation. Unknown tokens return a 400 listing the accepted set, preventing silent typos like `?include=propz` returning no projection.**
- **Populated element-type schemas. Schema responses now list every YOOtheme field for known element types — earlier builds returned an empty list in some setups.**
- **Conservative semantic-role lookups. Unknown or custom element types return no role rather than a guess — better than an incorrect assumption.**
- **Resilient release process. The pre-push security scan now refreshes its database before running, so a temporary mirror outage no longer blocks legitimate pushes.**
- **Internal cleanup: a dead-code branch in the schema enum extractor removed, the authoritative-source pointer is now only emitted when there's actually a binding to disambiguate, and the preview-length is now a named constant.**

### Security

- **Hardened cross-template pointer guard. A crafted pointer that tries to address template B from template A's request is now rejected on all 11 read and write endpoints (previously: 2 destructive endpoints only). The Sources and Multi-Items controllers now share the same defense as Elements.**
- **Read endpoints now share the same template-isolation guard as writes. Earlier builds left `get_binding` and `multi_items inspect` without the cross-template + double-prefix check, leaving a window where crafted pointers could enumerate foreign-template binding shapes.**
- **Tighter race-condition handling in `clean_implode`. The pre-write etag baseline is now captured before reading state, so concurrent edits between the read and the write are detected reliably (matches the ordering used by element write endpoints).**
- **Defense-in-depth on the template-prefix builder. The prefix used to gate cross-template access is now built via the RFC-6901-safe encoder, so future template IDs containing slashes or tildes would still be guarded correctly (current ID format wouldn't allow either, but the belt-and-braces removes the risk).**

## [1.0.0] - 2026-05-22

### Added

- **First stable release of YT Builder MCP for YOOtheme Pro (unofficial) — an independent third-party WordPress plugin paired with an npm MCP server that lets any MCP-capable AI assistant inspect, build and bind the YOOtheme Pro page builder through a secure, typed REST API.**
- **Install one plugin — that is the whole footprint on the WordPress side. The MCP server distributes itself separately via npm and the Desktop Extension bundle, so there is nothing extra to install into WordPress.**
- **Nine supported AI clients out of the box — Claude Desktop, Claude Code, Cursor, Zed, Continue, Cline (VS Code), Roo Code (VS Code), Codex CLI and Gemini CLI. The setup wizard writes the correct configuration for each one automatically.**
- **Client auto-detection — the setup wizard scans the machine for installed AI clients and offers to configure the ones it finds, so you do not have to know where each client keeps its config file.**
- **Any other MCP-capable client works too — the server speaks standard Model Context Protocol over stdio, so clients beyond the nine first-class targets can connect with a manual config entry.**
- **Sub-minute onboarding — from installing the plugin to a working AI client takes well under a minute on the happy path: generate a key in wp-admin, run one command (or drag in the bundle), done.**
- **One-click Claude Desktop install — a single .dxt Desktop Extension bundle, with every dependency already included, installs the MCP server by drag-and-drop. No Node setup, no terminal.**
- **Guided npm setup wizard — run one npx command and the wizard walks you through picking clients and pasting your key, then writes each client's config file for you.**
- **Token-free pickup setup — from the wp-admin screen you can copy a one-time pickup link instead of a raw token; the AI client redeems it and the Bearer token is never pasted into a chat.**
- **Copy-paste AI prompt — the wp-admin key screen offers a ready-made prompt you can paste straight into your AI client to drive the whole setup hands-free.**
- **Built-in connection check — the health and diagnose tools form a two-step probe (plugin reachable, then Bearer valid) so you can confirm a working setup in seconds and get a precise reason if not.**
- **Self-delivering usage skill — the MCP server ships an embedded skill and resources, so the assistant automatically learns correct tool selection, the ETag-locked write workflow, the Multi-Items binding pattern and error-code triage — no manual prompting required.**
- **The server advertises its own usage instructions over MCP, so a connecting client immediately knows the recommended workflow and golden path.**
- **Element discovery — list every element in a template as a flat index with JSON-Pointer paths, element types, labels and a has-binding flag; the recommended first call for locating the element you want to edit.**
- **Element detail — read the full object at any JSON-Pointer path, including all props, child elements and binding state.**
- **Element-type catalog — enumerate every element type registered on the site (YOOtheme built-ins plus YOOessentials / uEssentials extras), each with label, origin and a has-children flag.**
- **Element-type schema discovery — read the full prop-field schema for any element type, so the assistant knows the valid prop keys before it writes instead of guessing from examples.**
- **Element creation — add new elements under any parent with server-validated element types (unknown types are rejected with a clear error), optional initial props and nested children.**
- **Element settings update — replace an element's props wholesale, or pass a merge flag for a server-side deep-merge that overwrites only the supplied keys and preserves the rest, avoiding read-modify-write races.**
- **Element move — reparent or reorder any element, together with its whole subtree, to a new parent and index.**
- **Element clone — duplicate an element together with its entire subtree as a sibling.**
- **Element delete — remove an element and its children behind a two-step confirmation guard: the first call returns a preview and warning, the second executes.**
- **Template inventory — list all YOOtheme templates with id, label, type, recursive element count, last-modified timestamp and ETag.**
- **Full layout read — fetch the complete nested layout tree of any template, the source of truth for read-modify-write workflows; an optional flat mode returns a depth-first index instead.**
- **Structural schema — read a flat path / type / binding schema of a template for fast structural orientation.**
- **Template summary — a compact overview computed server-side in a single tree walk: element counts by type, bound-element count, maximum depth, totals and named landmark sections.**
- **Save — runs the YOOtheme builder normalization pass over a template so the layout is consistent.**
- **Publish — flushes the YOOtheme and WordPress caches and snapshots the published state, so changes go live cleanly.**
- **Source discovery — list all bindable data sources grouped by origin (WordPress, API Mapper flows, YOOessentials providers), each with a stable type.**
- **Dynamic source binding — bind a data source to any element and write the binding in YOOtheme's canonical structured format.**
- **Per-prop field mapping — define exactly which source field feeds which element prop, so dynamic content lands in the right place.**
- **Binding introspection — read an element's current binding: source name, the full field-mapping table, query arguments and directives, so the assistant sees precisely what is wired where.**
- **Source unbinding — remove a binding behind the same two-step confirmation guard used for deletes.**
- **Multi-Items binding for all 16 container types — first-class support for the YOOtheme Pro Multi-Items pattern across every container/item pair (Grid, List, Slider, Slideshow, Switcher, Gallery, Accordion, Map, Nav, Subnav, Table, Social, Buttons, Description-List, Overlay-Slider, Panel-Slider): bind one source so a single container renders N children — never N stacked containers.**
- **Multi-Items inspector — report the current binding level (none / container / item), the matching container-to-item pair, and a recommended fix when a binding sits on the wrong level.**
- **Implode-directive cleanup — a one-click tool that strips stale implode directives left behind by older container-level bindings (the cause of comma-joined values rendering in a single field).**
- **Comprehensive tool surface — 24 tools covering health and diagnostics, pages and templates, element CRUD, sources and binding, element types, and Multi-Items.**
- **Three-lane tool organization — essential everyday tools are always visible, advanced tools are reachable through a single unified gateway, and unauthenticated diagnostics work even before a key is configured. The always-visible list stays small so AI clients stay fast and responsive.**
- **Advanced-tool gateway with discovery mode — query any advanced tool for its input schema before calling it, so the assistant can self-orient without a large permanent tool list.**
- **Typed, structured results — read tools return both human-readable text and a typed structured payload validated against a declared output schema, so AI clients can consume results programmatically and reliably.**
- **Machine-readable tool annotations — every tool declares safety hints (read-only, destructive, idempotent, open-world) per the Model Context Protocol specification, so clients can reason about a call before making it.**
- **Clear, predictable error system — every failure returns a precise HTTP status and error code (invalid type or path, invalid token, insufficient scope, not found, stale ETag, missing ETag, rate limited) together with an actionable hint on how to recover.**
- **Raw REST fallback — the underlying REST API is fully usable directly with curl or any HTTP client, so the platform is not locked to a single transport.**
- **Lean runtime — the WordPress plugin ships zero third-party PHP runtime dependencies, so there is nothing to scope, conflict with, or bloat the install.**
- **Built-in caching layer — repeated reads and source fetches are cached, keeping the builder responsive and friendly to third-party API quotas (Notion, Airtable, Pexels and similar).**
- **Direct storage — state is read and written straight from YOOtheme's own layout option, with no extra database tables and no migration overhead.**
- **Token-efficient by design — the compact always-visible tool list keeps the per-request tool-definition cost low for the AI model.**
- **Sparse-field projection — pass a fields list to any list tool to return only the columns you need, narrowing every response and the tokens it costs.**
- **Pagination and scoping — element listing supports limit / cursor paging plus subtree and depth scoping, so large templates never dump thousands of nodes at once.**
- **Compact overviews — the template summary returns a small structured digest instead of a large raw layout dump, so the assistant can orient cheaply.**
- **Optimistic concurrency — every write is ETag-locked: concurrent edits never silently overwrite each other. A stale write returns a clean conflict to re-read and retry; a missing ETag is rejected before any change.**
- **Monotonic revisions — ETags carry an always-increasing revision suffix, and a no-op write does not bump the revision, so change detection stays precise.**
- **Atomic per-template locking — two simultaneous writers cannot interleave and corrupt a layout.**
- **WordPress admin screen — a dedicated Tools to YT Builder MCP page to generate, view and manage access keys.**
- **Scoped keys — issue keys at read, write or admin scope, so each AI client gets exactly the access it needs and no more.**
- **Multiple keys — run several keys side by side (for example one per client or per person) and revoke any of them individually without affecting the others.**
- **Revoking or reinstalling the plugin via the proper deploy path keeps existing keys intact — no surprise lock-outs on update.**
- **Guided golden-path workflow — the server steers the assistant through the proven sequence (health, diagnose, list, read, lock, mutate, verify) for safe, predictable edits.**
- **Confirmation prompts on supporting clients — destructive operations can ask for confirmation through the MCP elicitation channel, instead of requiring a manual retry.**
- **Open source under the MIT license — the full plugin and MCP server source is public on GitHub.**
- **Independent and transparent — an unofficial, nominative-use project, clearly labelled as not affiliated with or endorsed by YOOtheme.**
- **Platform — runs on WordPress 6.0+ with PHP 8.2+ and YOOtheme Pro 4.x.**
- **Verified quality — the 1.0.0 surface is covered by an extensive automated test suite (PHP unit and integration plus TypeScript) and was live-verified end-to-end at the MCP protocol level: all 24 tools confirmed working against a real YOOtheme Pro install.**

### Security

- **Bearer-token authentication on every REST endpoint, with a read < write < admin scope hierarchy enforced per tool.**
- **The token signing secret is encrypted at rest with AES-256-GCM, using a key derived from the WordPress AUTH_KEY, and is never stored in plaintext.**
- **Versioned key store with compare-and-swap updates, so concurrent key changes cannot corrupt the store.**
- **Per-key rate limiting on write endpoints to contain runaway or abusive automation.**
- **RFC-6750-compliant WWW-Authenticate challenge responses, so clients receive a standards-correct authentication signal.**
- **Atomic per-template state lock, so two simultaneous writers cannot interleave and corrupt a layout.**
- **Cross-template pointer guard — an edit addressed by JSON-Pointer cannot escape the boundaries of its own template.**
- **Two-step confirmation guard on every destructive operation (element delete, source unbind): no mutation happens without an explicit confirm.**
- **LLM-boundary secret sanitization — a single choke-point strips Bearer tokens, cookies and key material out of every tool result, so secrets cannot leak into an AI transcript.**
- **Structured security-event logging for authentication, scope and rate-limit events, giving a clear audit trail.**
- **Hardened pickup flow — the token-free setup URL uses 256-bit nonce entropy, a five-minute time-to-live, one-shot consumption and IP binding, making replay or guessing infeasible.**
- **Capability-checked admin surface — key management requires WordPress administrator capability, and all form actions are nonce-verified.**

---

## Version Types

- **Added** - New features
- **Changed** - Changes to existing features
- **Deprecated** - Features to be removed in future versions
- **Removed** - Removed features
- **Fixed** - Bug fixes
- **Security** - Security updates

[1.1.5]: https://github.com/wootsup/yt-builder-mcp/compare/v1.1.4...HEAD
[1.1.4]: https://github.com/wootsup/yt-builder-mcp/compare/v1.1.3...v1.1.4
[1.1.3]: https://github.com/wootsup/yt-builder-mcp/compare/v1.1.2...v1.1.3
[1.1.2]: https://github.com/wootsup/yt-builder-mcp/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/wootsup/yt-builder-mcp/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/wootsup/yt-builder-mcp/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/wootsup/yt-builder-mcp/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/wootsup/yt-builder-mcp/releases/tag/v1.0.0

