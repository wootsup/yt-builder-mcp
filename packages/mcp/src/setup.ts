/**
 * Backwards-compat entry point for `npx -y @wootsup/yt-builder-mcp setup`.
 *
 * The Wave G.7 refactor moved the wizard implementation into
 * {@link runWizard} (with dependency injection so it's unit-testable)
 * and the subcommand dispatcher into {@link runCli}, both in
 * `./setup-cli.ts`. This file remains so the published `bin/`
 * shim that does `import('../dist/setup.js')` still works after a
 * partial deploy where only the published tarball is updated; it
 * simply forwards to `runWizard()`.
 *
 * `YTB_MCP_TEST_MODE=1` short-circuits the wizard for smoke tests.
 *
 * @license MIT
 */

import { runWizard } from './setup-cli.js';

const TEST_MODE = process.env.YTB_MCP_TEST_MODE === '1';

async function run(): Promise<void> {
    if (TEST_MODE) {
        process.stderr.write('[yt-builder-mcp setup] Test mode — exiting after banner.\n');
        return;
    }
    const code = await runWizard();
    if (code !== 0) {
        process.exit(code);
    }
}

run().catch((e: unknown) => {
    const message = e instanceof Error ? e.message : String(e);
    process.stderr.write(`[yt-builder-mcp setup] fatal: ${message}\n`);
    process.exit(1);
});
