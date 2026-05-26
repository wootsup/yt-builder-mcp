/**
 * MCP server entrypoint (stdio transport).
 *
 * Reads config from environment variables (see `auth.ts`), spins up a
 * `McpServer` with all tools registered, connects to stdio, and lets
 * the host (Claude Desktop / Cursor / Zed / …) drive.
 *
 * `YTB_MCP_TEST_MODE=1` short-circuits: prints the tool registry and
 * exits 0. Used by the smoke test and by `release.php` to validate
 * that the bundled `dist/` is loadable on the target Node version.
 *
 * @license MIT
 */

import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';

import { isTestMode, loadRegistry } from './auth.js';
import { ConfigError } from './errors.js';
import { ClientPool } from './sites/client-pool.js';
import { SiteRegistry } from './sites/registry.js';
import { defaultSecretResolver } from './sites/secret-resolver.js';
import { createServer } from './server.js';

export { createServer, SERVER_NAME, SERVER_VERSION } from './server.js';
export { RestClient, REST_NAMESPACE_PATH, encodeElementPath } from './client.js';
export {
    loadConfig,
    loadRegistry,
    isTestMode,
    ENV_SITE_URL,
    ENV_WP_URL,
    ENV_BEARER,
    ENV_SITES_FILE,
    ENV_TEST_MODE,
} from './auth.js';
export { ConfigError, RestError, NetworkError } from './errors.js';
export { buildAllTools } from './tools/index.js';
// W6 — multi-site primitives exported for dist consumers (scripts,
// embedded uses). The `ClientPool` is the new wiring contract.
export { ClientPool, NoDefaultSiteError, UnknownSiteError } from './sites/client-pool.js';
export { SiteRegistry } from './sites/registry.js';
export { synthesiseFromEnv } from './sites/env-bridge.js';
export { defaultSecretResolver, PlainSecretResolver } from './sites/secret-resolver.js';
export {
    ESSENTIAL_TOOLS,
    DIRECT_TOP_LEVEL_TOOLS,
    isEssential,
    isDirectTopLevel,
} from './gateway/essentials.js';
export {
    CapturingServer,
    type AdvancedToolEntry,
    type CapturedToolHandler,
    type ToolRegistrar,
} from './gateway/capturing-server.js';
export { registerAdvancedTool } from './gateway/advanced-tool.js';
export {
    collectAllRegisteredTools,
    findTool,
    type CollectedTool,
} from './gateway/test-support.js';

async function main(): Promise<void> {
    const testMode = isTestMode();

    // W10 — multi-site bootstrap is now centralised in
    // `auth.loadRegistry()`. The canonical resolution order is:
    //   1. YTB_MCP_SITES_FILE → loadSitesFile + SiteRegistry
    //   2. env-bridge (YTB_MCP_SITE_URL + YTB_MCP_BEARER_TOKEN)
    //   3. ConfigError("No sites configured.")
    //
    // The single exception lives here: `--test-mode` smoke probes
    // (release.php, CI tool-list dumps) must boot even when neither
    // path is configured. We honor loadRegistry first, then trap the
    // ConfigError in test-mode and synthesise an empty registry so the
    // tool list still prints. Tools will refuse to run, which is
    // expected — `--test-mode` never invokes them.
    let registry: SiteRegistry;
    try {
        registry = await loadRegistry();
    } catch (e) {
        // Never crash the transport over a config problem. A DXT host
        // (Claude Desktop) auto-starts the server the moment the
        // extension is installed — before any Site URL / Bearer /
        // sites-file is entered — and also restarts it after every config
        // edit. Exiting here surfaced a scary "Server disconnected" toast
        // for two common cases: (1) no config at all (ConfigError), and
        // (2) a half-finished or malformed sites.json (schema validation,
        // e.g. a leftover PASTE_… placeholder bearer). Both now boot with
        // an empty registry: the server connects, advertises its tools,
        // `sites_list` shows "(no sites)", and any tool needing a site
        // returns a friendly NoDefaultSiteError at call-time. The real
        // reason is written to stderr (visible in the host's MCP logs).
        // In `--test-mode` this also lets the print-tools probe boot with
        // zero or broken config.
        const msg = e instanceof Error ? e.message : String(e);
        const kind = e instanceof ConfigError
            ? 'starting unconfigured'
            : 'config problem, starting with no sites';
        process.stderr.write(`[yt-builder-mcp] ${kind} — ${msg}\n`);
        registry = new SiteRegistry({
            schema_version: 1,
            default_site_id: null,
            sites: [],
        });
    }

    const secretResolver = defaultSecretResolver();
    const pool = new ClientPool(registry, secretResolver);

    const { mcp, tools } = createServer({ pool });

    if (testMode) {
        // Print a one-line per tool summary to stderr (stdout is reserved
        // for the JSON-RPC frame in real runs) and exit cleanly.
        process.stderr.write(
            `[yt-builder-mcp] test mode — ${String(tools.length)} tools registered:\n`,
        );
        for (const tool of tools) {
            process.stderr.write(`  - ${tool.name}\n`);
        }
        process.exit(0);
    }

    const transport = new StdioServerTransport();
    await mcp.connect(transport);
}

/**
 * Auto-run unless explicitly disabled. Tests set `YTB_MCP_NO_AUTORUN=1`
 * to import the module without firing up the stdio server.
 *
 * The bin dispatcher (`bin/yt-builder-mcp.js`) imports
 * `dist/index.js` dynamically and expects `main()` to take over — so by
 * default we run on import.
 */
if (process.env.YTB_MCP_NO_AUTORUN !== '1') {
    main().catch((e: unknown) => {
        const message = e instanceof Error ? e.message : String(e);
        process.stderr.write(`[yt-builder-mcp] fatal: ${message}\n`);
        process.exit(1);
    });
}

export { main };
