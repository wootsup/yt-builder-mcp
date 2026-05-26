# @wootsup/yt-builder-mcp — YT Builder MCP for YOOtheme Pro (unofficial)

> Drive your page builder via MCP. Built for YOOtheme Pro 4.0+ on **WordPress** and **Joomla 5/6**. Connect **Claude Desktop**, **Claude Code**, **Cursor**, **Zed**, **Continue**, **Cline**, **Roo Code**, **Codex CLI**, or **Gemini CLI** in one command.

> Independent third-party project. YOOtheme® is a registered trademark of YOOtheme GmbH
> ([yootheme.com](https://yootheme.com)). YT Builder MCP is built by WootsUp (getimo
> productions) and is not affiliated with, endorsed by, or sponsored by YOOtheme.
> The integration uses YOOtheme Pro's public extension points.

**License:** MIT

---

## Quick start

```bash
# 1. Install the host plugin for your CMS.
#    WordPress: yt-builder-mcp-*.zip plugin.
#    Joomla 5/6: pkg_ytbmcp-*.zip package (three sub-extensions in one go).
#    See https://github.com/wootsup/yt-builder-mcp/releases

# 2. Generate a Bearer key.
#    WordPress: wp-admin → Tools → "YT Builder MCP" → Bearer Keys.
#    Joomla:    Administrator → Components → "YT Builder MCP" → Bearer Keys.
#    Key format: ytb_(live|test)_<payloadB64Url>.<sigB64Url>
#    The key is shown ONCE. Copy it now; it cannot be recovered later.

# 3. Run the wizard to configure your AI client(s):
npx -y @wootsup/yt-builder-mcp setup

# 4. (Optional) Install the bundled agent skill:
npx -y @wootsup/yt-builder-mcp install-skill

# 5. Restart your AI client.
```

The wizard prompts for:

1. Your site URL (WordPress or Joomla).
2. The Bearer key you just generated.
3. Which AI client(s) to configure (multi-select).

It probes the host plugin's `/health` endpoint (`/wp-json/yt-builder-mcp/v1/health`
on WordPress, `/api/index.php/v1/yt-builder-mcp/health` on Joomla) to confirm
the plugin is reachable, then `/etag` to validate the Bearer key. After restart
you should see the new tools prefixed with `yootheme_builder_*`.

## Supported AI clients

The wizard auto-detects and configures the following clients:

| Client | Config path | Notes |
|--------|-------------|-------|
| Claude Desktop | `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) | DXT bundle also supported (see below) |
| Claude Code | `~/.claude.json` | |
| Cursor | `~/.cursor/mcp.json` | |
| Zed | `~/.config/zed/settings.json` | |
| Continue | `~/.continue/config.json` | |
| Cline | `~/Library/Application Support/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json` | VS Code extension |
| Roo Code | same VS Code globalStorage path under `rooveterinaryinc.roo-cline` | VS Code extension |
| Codex CLI | `~/.codex/config.toml` | |
| Gemini CLI | `~/.gemini/settings.json` | |

## Tool surface — gateway model

The server registers **26 domain tools + 1 gateway = 27 reachable** tools,
of which **20 are advertised in `tools/list`** (well under Cursor's ~40-tool
cap):

- **2 direct top-level tools** (always in `tools/list`):
  `yootheme_builder_health`, `yootheme_builder_diagnose`. The
  "the gateway itself might be broken" escape hatch.
- **17 essential forwarded tools** (always in `tools/list`): the most-used
  reads and writes (`pages_list`, `get_etag`, `element_list`, `element_add`,
  `element_update_settings`, `sources_list`, `element_types_list`,
  `inspect_multi_items_binding`, and the multi-site `sites_list` / `sites_test`,
  among others).
- **1 gateway tool** (`yootheme_builder_advanced`): exposes the remaining
  **7 advanced tools** behind a single entry. Call
  `yootheme_builder_advanced({ tool: "<name>" })` for discovery (returns the
  schema) or `({ tool, arguments })` to execute.

See `skills/yt-builder-mcp/SKILL.md` for the full catalog and canonical
workflows, and `docs/TOOL-CATALOG.md` for an auto-generated reference.

## Tool catalogue

| Domain      | Count | Tools                                                                                                                                                                |
|-------------|------:|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Health      |     2 | `health`, `diagnose`                                                                                                                                                |
| Sites       |     2 | `sites_list`, `sites_test`                                                                                                                                          |
| Pages       |     7 | `pages_list`, `page_get_layout`, `page_get_schema`, `template_summary`, `get_etag`, `page_save`, `page_publish`                                                     |
| Elements    |     7 | `element_list`, `element_get`, `element_add`, `element_update_settings`, `element_move`, `element_clone`, `element_delete`                                          |
| Sources     |     5 | `sources_list`, `element_get_binding`, `element_bind_source`, `element_unbind_source`, `inspect_multi_items_binding`                                                |
| Inspection  |     3 | `element_types_list`, `element_type_get_schema`, `clean_implode_directives`                                                                                         |
| Gateway     |     1 | `advanced`                                                                                                                                                          |

All tool names are prefixed with `yootheme_builder_` at the MCP server boundary.

## Subcommands

```
yt-builder-mcp setup            Interactive first-run wizard (default).
yt-builder-mcp install-skill    Install the bundled agent skill.
yt-builder-mcp --version, -v    Print package version.
yt-builder-mcp --help, -h       Show usage.
```

### `install-skill` — bundled agent skill

Copies the bundled `skills/yootheme-builder/` folder into
`~/.claude/skills/` and appends a marker block to `~/AGENTS.md` so
other AI clients pick it up automatically.

`~/.claude/skills/` is the **universal marker path** recognised by
Claude Desktop and (per the same convention as `@wootsup/mcp`) other
AI clients that follow the `~/AGENTS.md` discovery protocol. The
single location keeps the skill discoverable across every supported
client without per-client write logic.

The skill ships with the 5 canonical workflows (build hero, bind
source, clone section, diagnose 401, add custom element) plus a
23-tool auto-generated catalog appendix.

## DXT bundle — Claude Desktop one-click install

The repo includes a `manifest.json` and `scripts/build-dxt.js` that
produces a `yt-builder-mcp.dxt` archive — the
[Desktop Extension](https://github.com/anthropics/dxt) format used
by Claude Desktop for one-click MCP installs. Build it from a
source checkout:

```bash
npm run build:dxt
# → ./yt-builder-mcp.dxt
```

Drop the `.dxt` file onto Claude Desktop's Extensions screen to
install. The bundle includes the compiled `dist/`, the bundled
skill, and `manifest.json`.

## Environment variables

When launched by an AI client (or directly):

| Variable | Required? | Purpose |
|----------|-----------|---------|
| `YTB_MCP_SITE_URL` | Yes | Host CMS base URL (e.g. `https://example.com`). Works for BOTH WordPress and Joomla. Trailing slash is stripped. |
| `YTB_MCP_WP_URL` | No (deprecated) | Legacy alias for `YTB_MCP_SITE_URL`. Still honoured for older WordPress-only configurations. A non-fatal deprecation notice is written to stderr when this is used without `YTB_MCP_SITE_URL`. |
| `YTB_MCP_BEARER_TOKEN` | Yes | Bearer key from wp-admin (WordPress: Tools → "YT Builder MCP") or Administrator → Components → "YT Builder MCP" (Joomla). Format-checked client-side: must match `ytb_(live\|test)_<payload>.<sig>`. Do **not** prepend `Bearer ` — the MCP server adds it. |
| `YTB_MCP_PLATFORM` | No | Explicit platform hint: `wordpress` or `joomla`. Set to `joomla` when `YTB_MCP_SITE_URL` is an origin-only Joomla URL (no `/api/index.php/` in the path). Defaults to URL-shape auto-detection. |
| `YTB_MCP_TIMEOUT_MS` | No | REST timeout (default 15000). |
| `YTB_MCP_TEST_MODE` | No | `1` skips the stdio loop (smoke tests). |

## Manual MCP config (no wizard)

For users who don't want to run the wizard, paste this into your AI
client's MCP config file:

```json
{
  "mcpServers": {
    "yt-builder-mcp": {
      "command": "npx",
      "args": ["-y", "@wootsup/yt-builder-mcp"],
      "env": {
        "YTB_MCP_SITE_URL": "https://example.com",
        "YTB_MCP_BEARER_TOKEN": "ytb_live_…"
      }
    }
  }
}
```

For a Joomla install at an origin-only URL, also set the platform hint:

```json
"env": {
  "YTB_MCP_SITE_URL": "https://example.com/joomla",
  "YTB_MCP_BEARER_TOKEN": "ytb_live_…",
  "YTB_MCP_PLATFORM": "joomla"
}
```

## Non-interactive CI usage

For scripted / CI installs, pass the answers as flags and add
`--non-interactive` to skip every prompt. Missing required flags
exit with code `2` and a clear error to stderr:

```bash
npx -y @wootsup/yt-builder-mcp setup \
  --non-interactive \
  --client cursor --client claude-desktop \
  --url https://dev.wootsup.com/wordpress \
  --token "$YTB_TOKEN"
```

Supported flags:

| Flag | Required? | Description |
|------|-----------|-------------|
| `--non-interactive` | Yes | Opt-in to non-interactive mode (no prompts). |
| `--url <site-url>` | Yes | Site base URL (WordPress or Joomla). Trailing slash is stripped. |
| `--token <bearer>` | Yes | Bearer key from the host plugin's admin UI (do not prepend `Bearer `). |
| `--client <id>` | Yes (≥1) | Client id; repeatable. Valid ids: `claude-desktop`, `claude-code`, `cursor`, `zed`, `continue`, `cline`, `roo-code`, `codex-cli`, `gemini-cli`. |

The wizard still runs its plugin-health + auth probes and uses the
exit codes documented in the table below — so CI can branch on the
exit code to detect whether the install actually succeeded.

## Exit codes (CLI)

The CLI returns POSIX-style exit codes for scripting / CI:

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | Invalid input / unknown subcommand |
| 2 | Health probe failed and user declined to continue (or install-skill failure) |
| 3 | Auth probe failed and user declined to continue |
| 4 | Write failed; configs rolled back |
| 5 | Handshake failed; configs rolled back |
| 99 | Unhandled fatal in dispatcher |
| 130 | User cancelled (SIGINT) |

## Documentation

- [SKILL.md (bundled agent skill)](./skills/yt-builder-mcp/SKILL.md) — 5 canonical workflows, gateway model, tool catalog
- [REST API Reference](https://github.com/wootsup/yt-builder-mcp/blob/main/docs/rest-api-reference.md)
- [MCP Tool Reference](https://github.com/wootsup/yt-builder-mcp/blob/main/docs/mcp-tool-reference.md)

## Repository

https://github.com/wootsup/yt-builder-mcp
