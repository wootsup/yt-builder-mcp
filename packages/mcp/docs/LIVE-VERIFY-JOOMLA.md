# Live-Verify on Joomla 5/6

> **Scope.** End-to-end smoke-test of every catalogued MCP tool against a
> real Joomla install with the `yt-builder-mcp` package installed.
> Counterpart to the WordPress-flavoured `LIVE-VERIFY-REPORT.md`.

The script spawns the local MCP stdio server, sends JSON-RPC
`initialize` + `tools/list`, then calls every catalogued tool — direct
when it is listed in `tools/list`, otherwise via the
`yootheme_builder_advanced` gateway. Lane is derived at runtime, never
hardcoded. Write-ops are invoked with stale ETags so the server-side
validation returns a clean structured error (NOT a 5xx).

---

## Pre-flight

1. **Deploy the Joomla package** to your test site (e.g.
   `dev.wootsup.com/joomla`). The release-system produces the Joomla
   package at `dist/joomla/pkg_ytbmcp-<version>.zip`; install it via
   **Administrator → System → Install → Upload Package File**.

2. **Enable the Web Services Authentication — Token plugin** (Joomla
   ships it disabled by default). **Administrator → System →
   Manage → Plugins**, search for `Web Services - Token`, set
   **Enabled**.

   The `yt-builder-mcp` REST surface uses its own Bearer scheme — Joomla's
   Web Services token plugin is unrelated to that and is NOT required.
   Skip step 2 if it's already on; it's listed here only because some
   Joomla installs run the Web Services API entirely disabled.

3. **Enable the `plg_system_ytbmcp` plugin** (auto-enabled by the
   installer, but verify it's green under **Plugins → Type: System →
   "System - YT Builder MCP"**).

4. **Generate a Bearer key**. **Administrator → Components → YT Builder
   MCP → Bearer Keys → New**. Copy the token *immediately* — it is shown
   ONCE.

   The token has the shape
   `ytb_live_<payloadBase64Url>.<signatureBase64Url>`.

---

## Run the verifier

```sh
# Required
export YTB_MCP_SITE_URL='https://dev.wootsup.com/joomla'
export YTB_MCP_BEARER_TOKEN='ytb_live_…'

# Strongly recommended for Joomla — disambiguates an origin-only URL
# (an origin like `https://example.com/joomla` does NOT contain the
# `/api/index.php/` token, so URL-shape auto-detection would otherwise
# fall through to the WordPress default).
export YTB_MCP_PLATFORM='joomla'

# Optional — the template-id to probe (default: 'home').
# For Joomla L2 (article-level) verification, set this to the numeric
# `#__content.id` of an article that has a YT page-builder layout.
export YTB_MCP_VERIFY_TEMPLATE_ID='123'

# Optional — 1Password ref instead of a bare YTB_MCP_BEARER_TOKEN.
# export YTB_MCP_1P_REF='op://Claude-Secrets/<item-id>/credential'

cd yt-builder-mcp/packages/mcp
pnpm build                 # compile dist/index.js
node ./scripts/live-verify.mjs
```

The script writes its report to
`yt-builder-mcp/packages/mcp/docs/LIVE-VERIFY-REPORT.md` and exits with:

| code | meaning |
|------|---------|
| 0 | all tools PASS, OR the Bearer was not available (skip report) |
| 1 | one or more tools FAIL |
| 2 | usage / config error |

The report's front-matter records `Platform: joomla` so you can keep
WordPress + Joomla baselines side-by-side.

---

## Expected outcome (Wave 7+)

| Tier | Tool count | Expected |
|------|-----------:|----------|
| L1 (parity with WP) | 24 | all PASS |
| L2 (Joomla-only — article-level) | 6 | all PASS when `YTB_MCP_VERIFY_TEMPLATE_ID` is a real article id |

The 6 L2 tools (cookbook §4.13.5) are
`articles_list`, `articles_get_layout`, `articles_save_layout`,
`article_elements_get`, `article_elements_update`,
`article_elements_delete`. They are skipped (and reported as PASS-with-
note) on WordPress where they have no counterpart; on Joomla they are
the equivalent of the WP page-level CRUD surface but scoped to a single
`com_content` article.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Every tool returns `404 Not Found` | Web Services API disabled at the router | `Administrator → System → Global Configuration → Server → "Enable Web Services API" = Yes` |
| Every tool returns `401 Unauthorized` | Bearer rejected | Re-issue under Components → YT Builder MCP → Bearer Keys; verify scope is at least `read` |
| `WWW-Authenticate: Bearer realm="yt-builder-mcp", error="invalid_token"` | Token signature mismatch | The plugin's `SigningSecret` was rotated (e.g. fresh install). Re-issue. |
| `404` on a single tool that PASSes on WordPress | The tool needs a route that has not been registered yet — check `plg_system_ytbmcp::onBeforeApiRoute()` | Open an issue with the tool name |
| Report says `Platform: wordpress` despite a Joomla URL | URL is origin-only; auto-detect fell back to WP | Set `YTB_MCP_PLATFORM=joomla` |

---

## Next steps after a green report

- Capture the report under `docs/audits/<date>-joomla-live-verify.md`
  alongside the WordPress baseline so Wave 8.5 (per-tool 1:1 parity
  verification) has a reproducible delta.
- Run the same verification after each `plg_system_ytbmcp` release to
  catch route regressions early.

---

**License:** MIT
