# Getting Started. YT Builder MCP for YOOtheme Pro (unofficial)

> Independent third-party project. YOOtheme® is a registered trademark of YOOtheme GmbH
> ([yootheme.com](https://yootheme.com)). YT Builder MCP is built by WootsUp (getimo
> productions) and is not affiliated with, endorsed by, or sponsored by YOOtheme.
> The integration uses YOOtheme Pro's public extension points.

This guide takes you from "I have a site with YOOtheme Pro" to "my AI assistant can drive my page builder" in under 10 minutes. It works for **WordPress** and **Joomla 5/6**. The MCP server speaks to either host plugin.

## What you need before you start

- A site with **YOOtheme Pro 4.0+** active.
- **WordPress 6.0+** OR **Joomla 5.x / 6.x**.
- PHP **8.2+**.
- An AI client that speaks MCP. **Claude Desktop**, **Claude Code**, **Cursor**, **Zed**, **Continue**, **Cline**, **Roo Code**, **Codex CLI**, or **Gemini CLI**.
- **Node.js 18.17+** on your local machine (for the `npx` setup wizard).

## Step 1. Install the host plugin

Download the latest release from
[github.com/wootsup/yt-builder-mcp/releases](https://github.com/wootsup/yt-builder-mcp/releases).

The release ships two artifacts:

| Platform | Artifact | Install in |
|----------|----------|------------|
| WordPress | `yt-builder-mcp-*.zip` (plugin) | **Plugins → Add New → Upload Plugin** |
| Joomla 5/6 | `pkg_ytbmcp-*.zip` (package) | **Administrator → System → Install → Extensions → Upload Package File** |

**WordPress:** upload the ZIP, click **Install Now**, then **Activate**. If YOOtheme Pro is not the active theme, you will see a warning notice and the plugin stays inactive until YOOtheme Pro is active.

**Joomla:** upload the package. It installs three sub-extensions in one go: the system plugin (`plg_system_ytbmcp`), the Web Services plugin (`plg_webservices_ytbmcp`), and the admin component (`com_ytbmcp`). The post-install script auto-enables both plugins. Verify they are enabled under **System → Manage → Plugins**, and confirm the Joomla Web Services API is on under **System → Global Configuration → Server → Web Services → Enable**.

## Step 2. Generate a Bearer Key

| Platform | Location |
|----------|----------|
| WordPress | **Tools → YT Builder MCP** → Bearer Keys tab |
| Joomla | **Components → YT Builder MCP** → Bearer Keys tab |

Click **Generate Key**. A new key appears, something like:

```
ytb_live_eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIi...
```

**Copy it now.** You only see the full key once. Afterwards the dashboard shows only the prefix. Treat it like a password. If you lose it, generate a new one. The old key keeps working until you click **Revoke**.

The same admin page has a **Diagnostics** tab that shows the live YOOtheme Pro / CMS / PHP versions and the REST endpoint inventory. Handy for sanity-checking the install.

## Step 2.5. Optional. One-Click setup via the AI prompt

When you generate a key, the Reveal-Box presents three CTAs in order of recommended use:

**1. Paste this prompt into your AI assistant** *(fastest path)*

The box pre-builds an `npx ... setup --pickup <URL> --nonce <CODE> --client <id>` command. Copy it, paste it into Claude Desktop / Cursor / Claude Code / any AI client with a Bash tool, and ask the assistant to run it. The wizard fetches the token and URL from a one-shot, IP-bound, 5-minute-TTL pickup endpoint, so **your token never travels through the chat history, the AI provider's logs, or your shell history**.

**2. Or run the wizard manually** *(if pickup is unavailable)*

Open a terminal and run:
```bash
npx -y @wootsup/yt-builder-mcp setup
```
Paste the site URL and token when prompted.

**3. The token itself** *(for manual MCP-config editing)*

Use this if you want to wire the MCP server yourself (e.g. your client uses a config format the wizard doesn't yet target).

## Step 3. Run the setup wizard

On your local machine:

```bash
npx -y @wootsup/yt-builder-mcp setup
```

The wizard walks you through four questions:

### Question 1. Bearer Key

```
? Paste your Bearer Key
> ytb_live_eyJh…
```

The wizard decodes the token's signed payload to learn which site issued it. You will not need to type the URL by hand in the next step.

### Question 2. Site URL

```
? Site URL
> https://example.com           (WordPress)
> https://example.com/joomla    (Joomla)
```

Press Enter to accept the pre-filled value, or paste a different URL if you are pointing the wizard at a staging mirror. The wrapper detects Joomla automatically when the URL contains `/api/index.php/`. For an origin-only Joomla URL, set `YTB_MCP_PLATFORM=joomla` in your MCP client config (see [`packages/mcp/README.md`](../packages/mcp/README.md#manual-mcp-config-no-wizard) for the JSON snippet).

The wizard then probes:
- WordPress: `https://example.com/wp-json/yt-builder-mcp/v1/health`
- Joomla: `https://example.com/api/index.php/v1/yt-builder-mcp/health`

If the probe fails, the wizard prints a precise error and exits without writing anything.

### Question 3. Site ID

```
? Site ID (for multi-site setups)
> default
```

Site IDs let you point one wizard install at multiple sites (e.g. `prod-wp`, `staging-joomla`). Use `default` if you only have one site.

### Question 4. Which AI clients?

```
? Which AI clients should we configure?
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

Multi-select. The wizard writes the MCP server entry into each selected client's config file:

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

If a config file already has an `yt-builder-mcp` entry, the wizard **merges** it. Your existing entries for other MCP servers are preserved.

## Step 4. Restart your AI client

Quit and re-open Claude Desktop (or Cursor, etc.). The MCP server loads on startup.

You can verify the server is connected:

- **Claude Desktop:** The slash-menu shows `yt-builder-mcp` as a connected server (20 advertised tools plus the `yootheme_builder_advanced` gateway that routes to 7 more).
- **Cursor:** Settings → MCP servers shows the entry as `running`.

## Step 5. Your first prompt

Open a new chat and try:

> List my pages.

Your assistant should respond by calling `yootheme_builder_pages_list` and showing you all your templates.

Try harder things:

> Show me the layout schema of the "default" template.

> Add a new headline element to the default template that says "Hello from Claude". Save it.

> Bind my "Pexels Search" Dynamic Source to the first grid in the default template.

Element-path shapes differ slightly between platforms. WordPress uses `section/0/row/0/...`; Joomla uses `/templates/<templateId>/layout/children/<n>/...`. Your assistant gets the right path from `yootheme_builder_page_get_schema` or `yootheme_builder_element_list`, so you can describe targets by name and the tool resolves the path.

## Common issues

| Symptom | Cause | Fix |
|---------|-------|-----|
| Wizard says `HTTP 404` | Host plugin not active (or Joomla Web Services API off) | Activate the plugin; on Joomla check **System → Global Configuration → Server → Web Services → Enable** |
| Wizard says `HTTP 401` | Bearer key wrong or revoked | Generate a new key |
| AI client doesn't show tools | Client wasn't restarted | Quit and reopen the AI client |
| `yootheme_loaded: false` in `/health` | YOOtheme Pro not active | Activate YOOtheme Pro as the theme/template |
| `412 Precondition Failed` on writes | Stale ETag | Re-read `yootheme_builder_get_etag` and retry |

## What next?

- Read the [MCP Tool Reference](./mcp-tool-reference.md) for input/output schemas.
- Read the [REST API Reference](./rest-api-reference.md) if you want to integrate without the NPM package.
- Read the [Tool Catalog](./TOOL-CATALOG.md) for a quick-scan overview with sparse-fields hints.
- Read the [Cross-Platform Parity Notes](./cross-platform-parity.md) for the deliberate WordPress ↔ Joomla divergences.
