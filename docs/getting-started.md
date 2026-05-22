# Getting Started — YOOtheme Builder MCP

This guide takes you from "I have a WordPress site with YOOtheme Pro" to "my AI assistant can drive my page builder" in under 10 minutes.

## What you need before you start

- A WordPress site with **YOOtheme Pro 4.0+** active.
- WordPress **6.0+**, PHP **8.2+**.
- An AI client that speaks MCP — **Claude Desktop**, **Claude Code**, **Cursor**, **Zed**, **Continue**, **Cline**, **Roo Code**, **Codex CLI**, or **Gemini CLI**.
- **Node.js 18.17+** on your local machine (for the `npx` setup wizard).

## Step 1 — Install the WordPress plugin

Download the latest plugin ZIP from the GitHub Releases page:
[github.com/wootsup/yootheme-builder-mcp/releases](https://github.com/wootsup/yootheme-builder-mcp/releases)

(A WordPress.org listing is planned once the plugin leaves alpha.)

In WP-Admin:

1. **Plugins → Add New → Upload Plugin**
2. Choose the ZIP, click **Install Now**.
3. Click **Activate**.

If YOOtheme Pro is not active on your site, you will see a warning notice. The plugin stays inactive until YOOtheme Pro is the active theme.

> Screenshots coming in v0.2.

## Step 2 — Generate a Bearer Key

In WP-Admin, navigate to **Tools → YT Builder MCP**.

Click **Generate New Key**. A new key appears — something like:

```
ytb_live_eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIi...
```

**Copy it now.** You only see the full key once — afterwards the dashboard shows only the prefix. Treat it like a password.

If you lose it, generate a new one. The old key will continue to work until you click **Revoke**.

## Step 2.5 — Optional: One-Click setup via the AI prompt

When you generate a key, the Reveal-Box presents three CTAs in order of recommended use:

**1. Paste this prompt into your AI assistant** *(fastest path)*

The box pre-builds an `npx ... setup --pickup <URL> --nonce <CODE> --client <id>` command. Copy it, paste it into Claude Desktop / Cursor / Claude Code / any AI client with a Bash tool, and ask the assistant to run it. The wizard fetches the token + URL from a one-shot, IP-bound, 5-minute-TTL pickup endpoint — so **your token never travels through the chat history, the AI provider's logs, or your shell history**.

**2. Or run the wizard manually** *(if pickup is unavailable)*

Open a terminal and run:
```bash
npx -y @wootsup/yootheme-builder-mcp setup
```
Paste the site URL + token when prompted.

**3. The token itself** *(for manual MCP-config editing)*

Use this if you want to wire the MCP server yourself (eg. your client uses a config format the wizard doesn't yet target).

## Step 3 — Run the setup wizard

On your local machine:

```bash
npx -y @wootsup/yootheme-builder-mcp setup
```

The wizard walks you through four questions:

### Question 1 — Bearer Key

```
? Paste your Bearer Key
> ytb_live_eyJh…
```

The wizard decodes the token's signed payload to learn which WordPress
site issued it. You will not need to type the URL by hand in the next
step.

### Question 2 — WordPress site URL

```
? WordPress site URL
> https://example.com   (pre-filled from your key)
```

Press Enter to accept, or paste a different URL if you are pointing
the wizard at a staging mirror. The wizard probes
`https://example.com/wp-json/yootheme-builder-mcp/v1/identity` to
confirm the plugin is installed, and then `/v1/health` with the key
to confirm auth. If either fails, the wizard prints a precise error
and exits without writing anything.

### Question 3 — Profile name

```
? Profile name (for switching between sites)
> default
```

Profiles let you point one wizard install at multiple WordPress sites
(e.g. `staging`, `prod`). Use `default` if you only have one site.

### Question 4 — Which AI clients?

```
? Which AI clients should I configure?
  ◯ Claude Desktop
  ◯ Claude Code
  ◯ Cursor
  ◯ Zed
  ◯ Continue
  ◯ Cline
  ◯ Roo Code
  ◯ Codex CLI
  ◯ Gemini CLI
```

Multi-select. The wizard writes the MCP server entry into each selected
client's config file:

| Client | Config path (macOS) |
|--------|---------------------|
| Claude Desktop | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| Claude Code | `~/.claude.json` |
| Cursor | `~/.cursor/mcp.json` |
| Zed | `~/.config/zed/settings.json` |
| Continue | `~/.continue/config.json` |
| Cline | `~/Library/Application Support/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json` |
| Roo Code | `~/Library/Application Support/Code/User/globalStorage/rooveterinaryinc.roo-cline/settings/mcp_settings.json` |
| Codex CLI | `~/.codex/config.toml` |
| Gemini CLI | `~/.gemini/settings.json` |

If a config file already has an `yootheme-builder-mcp` entry, the wizard **merges** it — your existing entries for other MCP servers are preserved.

## Step 4 — Restart your AI client

Quit and re-open Claude Desktop (or Cursor, etc.). The MCP server loads on startup.

You can verify the server is connected:

- **Claude Desktop:** The slash-menu shows `yootheme-builder-mcp` as a connected server with 21 tools.
- **Cursor:** Settings → MCP servers shows the entry as `running`.

## Step 5 — Your first prompt

Open a new chat and try:

> List my YOOtheme pages.

Your assistant should respond by calling `yootheme_builder_pages_list` and showing you all your templates.

Try harder things:

> Show me the layout schema of the "default" template.

> Add a new headline element to section/0 of the default template that says "Hello from Claude". Save it.

> Bind my "Pexels Search" Dynamic Source to the grid at section/2/row/0/column/0/grid.

## Common issues

| Symptom | Cause | Fix |
|---------|-------|-----|
| Wizard says `HTTP 404` | Plugin not active | Activate the plugin in WP-Admin |
| Wizard says `HTTP 401` | Bearer key wrong or revoked | Generate a new key |
| AI client doesn't show tools | Client wasn't restarted | Quit and reopen the AI client |
| `yootheme_loaded: false` in `/health` | YOOtheme Pro not active | Activate YOOtheme Pro as the theme |
| `412 Precondition Failed` on writes | Stale ETag | Re-read `yootheme_builder_get_etag` and retry |

## What next?

- Read the [MCP Tool Reference](./mcp-tool-reference.md) for all 21 tools.
- Read the [REST API Reference](./rest-api-reference.md) if you want to integrate without the NPM package.
- Read the [Tool Catalog](./TOOL-CATALOG.md) for a quick-scan overview with sparse-fields hints.
