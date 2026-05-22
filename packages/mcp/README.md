# @wootsup/yootheme-builder-mcp

> MCP Server for YOOtheme Page Builder. Drive your YOOtheme Pro site programmatically from **Claude Desktop**, **Cursor**, **Continue**, **Zed**, **Cline**, or **Roo Code**.

**License:** MIT
**Status:** Pre-alpha — under active development.

---

## Quick start

```bash
# 1. Install the WordPress plugin (yootheme-builder-mcp).
#    See https://wootsup.com/products/yootheme-builder-mcp

# 2. Generate a Bearer key in
#    wp-admin → "YOOtheme Builder MCP" → Settings → Create Key.
#    Key format: ytb_(live|test)_<payloadB64Url>.<sigB64Url>
#    The key is shown ONCE — copy it now; it cannot be recovered later.

# 3. Run the wizard to configure your AI client(s):
npx -y @wootsup/yootheme-builder-mcp setup

# 4. (Optional) Install the bundled agent skill:
npx -y @wootsup/yootheme-builder-mcp install-skill

# 5. Restart your AI client.
```

The wizard prompts for:

1. Your WordPress site URL.
2. The Bearer key you just generated.
3. Which AI client(s) to configure (multi-select).

It probes `/wp-json/yootheme-builder-mcp/v1/health` to confirm the
plugin is reachable, `/etag` to validate the Bearer key, then writes
the MCP server entry into each selected client's config file. After
restart you should see the new tools prefixed with `yootheme_builder_*`.

## Supported AI clients (6)

The wizard auto-detects and configures the following clients:

| Client | Config path | Notes |
|--------|-------------|-------|
| Claude Desktop | `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) | DXT bundle also supported (see below) |
| Cursor | `~/.cursor/mcp.json` | |
| Continue | `~/.continue/config.json` | |
| Zed | `~/.config/zed/settings.json` | |
| Cline | `~/Library/Application Support/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json` | VS Code extension |
| Roo Code | same VS Code globalStorage path under `rooveterinaryinc.roo-cline` | VS Code extension |

## Tool surface — gateway model

The server registers **22 tools total**, but only **10 entries appear
in `tools/list`** (well under Cursor's ~40-tool cap):

- **2 direct top-level tools** (always in `tools/list`):
  `yootheme_builder_health`, `yootheme_builder_diagnose` — the
  "the gateway itself might be broken" escape hatch.
- **7 essential forwarded tools** (always in `tools/list`): the most-
  used reads and writes — `pages_list`, `get_etag`, `element_list`,
  `element_add`, `element_update_settings`, `sources_list`,
  `element_types_list`.
- **1 gateway tool** (`yootheme_builder_advanced`): exposes the
  remaining **12 advanced tools** behind a single entry. Call
  `yootheme_builder_advanced({ tool: "<name>" })` for discovery
  (returns the schema) or `({ tool, arguments })` to execute.
- **12 advanced tools** dispatched via the gateway (page_get_layout,
  page_get_schema, page_save, page_publish, element_get,
  element_move, element_clone, element_delete,
  element_get_binding, element_bind_source, element_unbind_source,
  element_type_get_schema).

Total: **21 domain tools + 1 gateway = 22**. Surface: **10 entries
in tools/list**. See `skills/yootheme-builder/SKILL.md` for the
full catalog and the 5 canonical workflows.

## Tool catalogue — 21 domain tools

| Domain     | Count | Tools                                                                                                                                                                |
|------------|------:|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Health     |     2 | `health`, `diagnose`                                                                                                                                                |
| Pages      |     6 | `pages_list`, `page_get_layout`, `page_get_schema`, `get_etag`, `page_save`, `page_publish`                                                                          |
| Elements   |     7 | `element_list`, `element_get`, `element_add`, `element_update_settings`, `element_move`, `element_clone`, `element_delete`                                          |
| Sources    |     4 | `sources_list`, `element_get_binding`, `element_bind_source`, `element_unbind_source`                                                                                |
| Inspection |     2 | `element_types_list`, `element_type_get_schema`                                                                                                                      |

All tool names are prefixed with `yootheme_builder_` at the MCP
server boundary.

## Subcommands

```
yootheme-builder-mcp setup            Interactive first-run wizard (default).
yootheme-builder-mcp install-skill    Install the bundled agent skill.
yootheme-builder-mcp --version, -v    Print package version.
yootheme-builder-mcp --help, -h       Show usage.
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
21-tool auto-generated catalog appendix.

## DXT bundle — Claude Desktop one-click install

The repo includes a `manifest.json` and `scripts/build-dxt.js` that
produces a `yootheme-builder-mcp.dxt` archive — the
[Desktop Extension](https://github.com/anthropics/dxt) format used
by Claude Desktop for one-click MCP installs. Build it from a
source checkout:

```bash
npm run build:dxt
# → ./yootheme-builder-mcp.dxt
```

Drop the `.dxt` file onto Claude Desktop's Extensions screen to
install. The bundle includes the compiled `dist/`, the bundled
skill, and `manifest.json`.

## Environment variables

When launched by an AI client (or directly):

| Variable | Required? | Purpose |
|----------|-----------|---------|
| `YTB_MCP_WP_URL` | Yes | WordPress base URL (e.g. `https://example.com`). Trailing slash is stripped. |
| `YTB_MCP_BEARER_TOKEN` | Yes | Bearer key from wp-admin. Format-checked client-side: must match `ytb_(live\|test)_<payload>.<sig>`. Do **not** prepend `Bearer ` — the MCP server adds it. |
| `YTB_MCP_TIMEOUT_MS` | No | REST timeout (default 15000). |
| `YTB_MCP_TEST_MODE` | No | `1` skips the stdio loop (smoke tests). |

## Manual MCP config (no wizard)

For users who don't want to run the wizard, paste this into your AI
client's MCP config file:

```json
{
  "mcpServers": {
    "yootheme-builder": {
      "command": "npx",
      "args": ["-y", "@wootsup/yootheme-builder-mcp"],
      "env": {
        "YTB_MCP_WP_URL": "https://example.com",
        "YTB_MCP_BEARER_TOKEN": "ytb_live_…"
      }
    }
  }
}
```

## Non-interactive CI usage

For scripted / CI installs, pass the answers as flags and add
`--non-interactive` to skip every prompt. Missing required flags
exit with code `2` and a clear error to stderr:

```bash
npx -y @wootsup/yootheme-builder-mcp setup \
  --non-interactive \
  --client cursor --client claude-desktop \
  --url https://dev.wootsup.com/wordpress \
  --token "$YTB_TOKEN"
```

Supported flags:

| Flag | Required? | Description |
|------|-----------|-------------|
| `--non-interactive` | Yes | Opt-in to non-interactive mode (no prompts). |
| `--url <wp-url>` | Yes | WordPress base URL; trailing slash is stripped. |
| `--token <bearer>` | Yes | Bearer key from wp-admin (do not prepend `Bearer `). |
| `--client <id>` | Yes (≥1) | Client id; repeatable. Valid ids: `claude-desktop`, `cursor`, `zed`, `continue`, `cline`, `roo-code`. |

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

- [SKILL.md (bundled agent skill)](./skills/yootheme-builder/SKILL.md) — 5 canonical workflows, gateway model, tool catalog
- [REST API Reference](https://github.com/wootsup/yootheme-builder-mcp/blob/main/docs/rest-api-reference.md)
- [MCP Tool Reference](https://github.com/wootsup/yootheme-builder-mcp/blob/main/docs/mcp-tool-reference.md)
- [Pipeline Studio Integration](https://github.com/wootsup/yootheme-builder-mcp/blob/main/docs/pipeline-studio-integration.md)

## Repository

https://github.com/wootsup/yootheme-builder-mcp
