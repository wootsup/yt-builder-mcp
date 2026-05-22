=== YT Builder MCP for YOOtheme Pro (unofficial) ===
Contributors: wootsup, getimo
Tags: mcp, ai, claude, yootheme, builder, automation, model-context-protocol
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Drive your page builder via MCP. Built for YOOtheme Pro 4.0+ — connect Claude Desktop, Cursor, Continue, Zed and other AI assistants.

== Description ==

Independent third-party project. YOOtheme® is a registered trademark of YOOtheme GmbH (https://yootheme.com). YT Builder MCP is built by WootsUp (getimo productions) and is not affiliated with, endorsed by, or sponsored by YOOtheme. The integration uses YOOtheme Pro's public extension points.

**YT Builder MCP** turns your page builder into a tool your AI assistant can use directly. Once installed, you can ask Claude Desktop, Cursor, Continue, or Zed to:

* List your templates
* Add, modify, move, clone, and delete elements (grids, headlines, images, lists, gallery — anything the builder supports)
* Bind Dynamic Sources to elements (e.g. an API Mapper source)
* Save and publish pages with proper cache invalidation
* Inspect element types and JSON schemas

**Two parts, one product:**

* This **WordPress plugin** (GPLv2) exposes a Bearer-authenticated REST dialect for builder state.
* A companion **NPM package** `@wootsup/yt-builder-mcp` (MIT) runs locally next to your AI client and translates AI tool-calls into REST requests.

**Use cases:**

* AI-assisted page construction — describe a page in natural language, your AI builds it.
* Headless / automation workflows — drive your page builder from any tool that speaks MCP (Claude Desktop, Cursor, a CI worker, your own AI agent).

**21 tools, grouped by domain:**

* Health (2) — connectivity + diagnose
* Pages (6) — list, get layout/schema, save, publish, ETag
* Elements (7) — list, get, add, update, move, clone, delete
* Sources (4) — list, bind, unbind, get binding
* Inspection (2) — element types + schemas

**Security model:**

* Bearer tokens generated server-side, hashed at rest, constant-time `hash_equals()` verification.
* All writes require an `If-Match: <etag>` header — concurrent edits return `412 Precondition Failed`.
* `manage_options` capability required to generate or revoke keys.

**Compatible with:**

* YOOtheme Pro 4.0+ (v4 + v5 both supported)
* WordPress 6.0+
* PHP 8.2+

This plugin is free and open source. The companion NPM package is MIT-licensed — use it however you want.

**Companion products:**

* [API Mapper](https://wootsup.com/api-mapper) — bring any REST API into your page builder as a Dynamic Source

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload Plugin, or download from the [GitHub Releases page](https://github.com/wootsup/yt-builder-mcp/releases).
2. Activate the plugin. If YOOtheme Pro is not the active theme, you'll see a warning notice — the plugin stays inactive until YOOtheme Pro is active.
3. Go to **Tools → YT Builder MCP** and click **Generate New Key**. Copy the key.
4. On your local machine, run:
   `npx -y @wootsup/yt-builder-mcp setup`
5. The wizard asks for your site URL, your Bearer key, and which AI clients to configure. It writes the MCP server entry into each client's config file.
6. Restart your AI client. You should see 21 new tools prefixed with `yootheme_builder_`.

== Frequently Asked Questions ==

= Does this plugin work without YOOtheme Pro? =

No. The plugin reads and writes the existing layout JSON of YOOtheme Pro. Without YOOtheme Pro as the active theme, there's no layout to drive. The plugin detects this and stays inactive with a clear admin notice.

= Does this work with v4 (legacy) and v5? =

Yes. Both v4 and v5 are supported. Storage backend is `wp_option('yootheme')` JSON, which is the same shape in both versions.

= Does it work with Joomla? =

Not yet. Phase 1 ships WordPress-only. A Joomla port is planned as Wave 7 — same module structure, separate `platform-joomla` adapter.

= Is my Bearer key safe? =

The plaintext key is never stored. I hash it with HMAC-SHA256 and verify with constant-time `hash_equals()`. If you lose the key, you can revoke it in the settings page and generate a new one.

= Does the plugin call out to the internet? =

No. The plugin only serves REST requests. The MCP server (running locally next to your AI client) is what makes outbound requests — and it only talks to your own WordPress site.

= Can multiple workers / AI assistants edit the same page at the same time? =

Yes — optimistic locking handles this. Every write requires an `If-Match: <etag>` header. If two clients race, the second write fails with `412 Precondition Failed` and that client must re-read state and retry.

= Where can I report a security issue? =

Email security@wootsup.com — see SECURITY.md in the repo for details. Please don't open public issues for security topics.

= Is this free? Forever? =

The WordPress plugin is GPLv2 (free, forever). The companion NPM package is MIT (free, forever). There's no paid tier on this product — I built it because I needed it myself and it would be a waste not to share.

== Screenshots ==

Screenshots will be added in a future release (v0.2).

== Changelog ==

= 0.2.0-alpha.1 — 2026-05-22 =

* Cursor-safe gateway: `tools/list` now returns 10 entries (was 22) via the `yootheme_builder_advanced` gateway-tool — fits inside Cursor's tool-list cap.
* Sparse-fields projection on 4 list tools (`pages_list`, `element_list`, `sources_list`, `element_types_list`) — ~44% token reduction on real `element_list` responses.
* Elicitation support on 3 sites (`element_delete`, `element_unbind_source`, `element_bind_source`) with a non-elicitation fallback so older MCP hosts still work.
* 6 AI clients supported by the setup wizard: Claude Desktop, Cursor, Continue, Zed, Cline, Roo Code.
* Progress reports on 7 write tools (`element_add/update_settings/move/clone/delete`, `page_save`, `page_publish`) for long-running operations.
* `structuredContent` + `outputSchema` on 11 read tools — typed Rich-Card responses for MCP hosts that support them.
* Error sanitization: bearer-tokens and 32+ secret-shaped keys are masked at all 4 envelope-construction sites; grep-gate enforces 0 secret leaks in `dist/`.
* `health` + `diagnose` registered as direct L3 tools (always available, no gateway round-trip).
* DXT bundle (`@wootsup/yt-builder-mcp.dxt`, 0.20 MB) for one-click install in Claude Desktop.
* Goldstandard refactor: 569 vitest tests (was 62; +9.2× scale), 98.23% line coverage. 22/22 tools live-verified against `https://dev.wootsup.com/wordpress` (YOOtheme Pro 4.5.33).

= 0.1.0-alpha.1 — Pre-release =

* Initial pre-alpha release.
* WordPress plugin scaffold (PHP 8.2+, modular under `src/modules/`).
* Core modules: `core-auth`, `builder-state`, `builder-pages`, `builder-elements`, `builder-inspection`, `builder-source-binding`, `builder-cache`, `rest-bridge`.
* 248 PHPUnit tests (542 assertions), 62 vitest tests. PHPStan level 8 clean.
* 21 MCP tools (Health, Pages, Elements, Sources, Inspection).
* Setup wizard for Claude Desktop, Cursor, Continue, Zed.
* WordPress 6.0+, YOOtheme Pro 4.0+, PHP 8.2+.

== Upgrade Notice ==

= 0.2.0-alpha.1 =
Cursor compatibility: `tools/list` returns 10 entries (was 22) via gateway-tool. 6 AI clients now supported (added Cline + Roo Code). 569 vitest tests, 22/22 live-verified.

= 0.1.0-alpha.1 =
Initial release. No upgrades yet.
