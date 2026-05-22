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

import { isTestMode, loadConfig } from './auth.js';
import { RestClient } from './client.js';
import { ConfigError } from './errors.js';
import { createServer } from './server.js';

export { createServer, SERVER_NAME, SERVER_VERSION } from './server.js';
export { RestClient, REST_NAMESPACE_PATH, encodeElementPath } from './client.js';
export { loadConfig, isTestMode, ENV_WP_URL, ENV_BEARER, ENV_TEST_MODE } from './auth.js';
export { ConfigError, RestError, NetworkError } from './errors.js';
export { buildAllTools } from './tools/index.js';
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

    let config;
    try {
        // In test mode, allow the bearer token to be missing — we exit before
        // anyone calls a tool.
        config = loadConfig({ allowMissingToken: testMode });
    } catch (e) {
        if (e instanceof ConfigError) {
            process.stderr.write(`[yt-builder-mcp] ${e.message}\n`);
            process.exit(1);
        }
        throw e;
    }

    const client = new RestClient({
        baseUrl: config.baseUrl,
        bearerToken: config.bearerToken,
        timeoutMs: config.timeoutMs,
    });

    const { mcp, tools } = createServer({ client });

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
