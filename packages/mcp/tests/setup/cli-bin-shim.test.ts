/**
 * W9 — smoke tests for bin/yt-builder-mcp.js.
 *
 * Verifies that the bin shim's CLI_SUBCOMMANDS Set recognises every
 * new multi-site subcommand AND that --help dispatch returns exit 0
 * for each. We invoke the bin via `child_process.spawn` so the test
 * exercises the real argv → runCli → exit-code path that Node would
 * take in production.
 *
 * Tests skip when `dist/setup-cli.js` is missing — the bin shim does
 * `await import('../dist/setup-cli.js')` so a fresh checkout without
 * a build can't drive these.
 *
 * @license MIT
 */

import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { describe, expect, it } from 'vitest';

const HERE = dirname(fileURLToPath(import.meta.url));
const BIN_PATH = join(HERE, '..', '..', 'bin', 'yt-builder-mcp.js');
const DIST_PATH = join(HERE, '..', '..', 'dist', 'setup-cli.js');

const HAS_DIST = existsSync(DIST_PATH);

function runBin(args: readonly string[]): { code: number | null; stdout: string; stderr: string } {
    const result = spawnSync('node', [BIN_PATH, ...args], {
        encoding: 'utf-8',
        env: { ...process.env, YTB_MCP_TEST_MODE: '1' },
    });
    return {
        code: result.status,
        stdout: result.stdout,
        stderr: result.stderr,
    };
}

describe.skipIf(!HAS_DIST)('bin shim — multi-site subcommand recognition', () => {
    it('add-site --help exits 0', () => {
        const r = runBin(['add-site', '--help']);
        expect(r.code).toBe(0);
        expect(r.stdout + r.stderr).toMatch(/Usage:.*add-site/);
    });

    it('list-sites --help exits 0', () => {
        const r = runBin(['list-sites', '--help']);
        expect(r.code).toBe(0);
        expect(r.stdout + r.stderr).toMatch(/Usage:.*list-sites/);
    });

    it('remove-site --help exits 0', () => {
        const r = runBin(['remove-site', '--help']);
        expect(r.code).toBe(0);
        expect(r.stdout + r.stderr).toMatch(/Usage:.*remove-site/);
    });

    it('set-default --help exits 0', () => {
        const r = runBin(['set-default', '--help']);
        expect(r.code).toBe(0);
        expect(r.stdout + r.stderr).toMatch(/Usage:.*set-default/);
    });

    it('test-site --help exits 0', () => {
        const r = runBin(['test-site', '--help']);
        expect(r.code).toBe(0);
        expect(r.stdout + r.stderr).toMatch(/Usage:.*test-site/);
    });

    it('top-level --help mentions all 5 new subcommands', () => {
        const r = runBin(['--help']);
        expect(r.code).toBe(0);
        const all = r.stdout + r.stderr;
        expect(all).toContain('add-site');
        expect(all).toContain('list-sites');
        expect(all).toContain('remove-site');
        expect(all).toContain('set-default');
        expect(all).toContain('test-site');
    });
});
