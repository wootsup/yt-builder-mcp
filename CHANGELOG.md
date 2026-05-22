# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project follows [Semantic Versioning](https://semver.org/).

## [1.0.0] `Latest` - 2026-05-22

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

[1.0.0]: https://github.com/wootsup/yt-builder-mcp/compare/v1.0.0...HEAD

