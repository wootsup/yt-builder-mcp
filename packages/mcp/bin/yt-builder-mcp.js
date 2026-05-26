#!/usr/bin/env node
/**
 * Entry point for `npx -y @wootsup/yt-builder-mcp`.
 *
 * Dispatch tree:
 *   - `setup` / `install-skill` / `install` / `--version` / `-v` /
 *     `--help` / `-h` / `help` → forward to `runCli()` in
 *     `dist/setup-cli.js` (Wave G.7).
 *   - no subcommand + interactive TTY → print a discoverability hint
 *     (don't accidentally start MCP server).
 *   - no subcommand + piped stdin/stdout → start the MCP stdio server
 *     (this is what AI clients invoke).
 *
 * The `runCli` / MCP-server split keeps the interactive ergonomics nice
 * while letting AI clients launch the server with zero arguments.
 *
 * @license MIT
 */
import { argv, stdout, stderr, exit } from 'node:process';

const args = argv.slice(2);
const subcommand = args[0];

const CLI_SUBCOMMANDS = new Set([
    'setup',
    'install-skill',
    'install',
    // W9 multi-site CLI subcommands.
    'add-site',
    'list-sites',
    'remove-site',
    'set-default',
    'test-site',
    'help',
    '--help',
    '-h',
    '--version',
    '-v',
]);

if (subcommand !== undefined && CLI_SUBCOMMANDS.has(subcommand)) {
    try {
        const { runCli } = await import('../dist/setup-cli.js');
        const code = await runCli(args);
        exit(code);
    } catch (err) {
        const msg = err instanceof Error ? err.message : String(err);
        stderr.write(`yt-builder-mcp: fatal: ${msg}\n`);
        exit(99);
    }
} else if (
    subcommand === undefined &&
    stdout.isTTY === true &&
    process.env.YTB_MCP_TEST_MODE !== '1'
) {
    stdout.write(
        'Run `npx -y @wootsup/yt-builder-mcp setup` to configure your AI client.\n',
    );
    stdout.write(
        'Run `npx -y @wootsup/yt-builder-mcp help` for usage.\n',
    );
} else {
    // Stdin/stdout piped (or YTB_MCP_TEST_MODE=1): MCP stdio mode.
    await import('../dist/index.js');
}
