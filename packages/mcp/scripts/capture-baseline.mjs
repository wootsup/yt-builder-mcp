#!/usr/bin/env node
/**
 * Capture the pre-G.0 `tools/list` payload for the token-baseline harness.
 *
 * Usage (from packages/mcp/):
 *
 *   # 1. Add a worktree at the pre-G.0 commit (5895bb8b1 — the commit
 *   #    immediately before PF.1 housekeeping; G.0 is 16b0e1e2a).
 *   git worktree add /tmp/ytb-mcp-baseline 5895bb8b1
 *
 *   # 2. Install + build the baseline.
 *   cd /tmp/ytb-mcp-baseline/yt-builder-mcp/packages/mcp
 *   npm install && npm run build
 *
 *   # 3. From this (current) worktree, run the capture:
 *   node ./scripts/capture-baseline.mjs \
 *     /tmp/ytb-mcp-baseline/yt-builder-mcp/packages/mcp/dist/index.js
 *
 *   # 4. Cleanup:
 *   cd <here>
 *   git worktree remove /tmp/ytb-mcp-baseline
 *
 * The output is written to `tests/perf/baselines/tools-list-pre-g0-REAL.json`
 * and consumed by `tests/perf/token-baseline.test.ts`. The shape mirrors
 * what an MCP host receives on `tools/list` (name / description /
 * annotations / inputSchema, sorted alphabetically by `name`).
 *
 * @license MIT
 */
import { spawn } from 'node:child_process';
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DEFAULT_BASELINE_ENTRY = process.env.YTB_BASELINE_ENTRY ?? '';
const DEFAULT_OUTPUT = resolve(
    __dirname,
    '..',
    'tests',
    'perf',
    'baselines',
    'tools-list-pre-g0-REAL.json',
);

const baselineEntry = process.argv[2] || DEFAULT_BASELINE_ENTRY;
const outputPath = process.argv[3] || DEFAULT_OUTPUT;

if (!baselineEntry) {
    process.stderr.write(
        'Usage: node scripts/capture-baseline.mjs <path-to-baseline/dist/index.js> [output.json]\n' +
            'Or set YTB_BASELINE_ENTRY env var.\n',
    );
    process.exit(2);
}

const env = {
    ...process.env,
    YTB_MCP_WP_URL: 'https://example.com',
    YTB_MCP_BEARER_TOKEN: 'baseline-capture-token',
};

const server = spawn('node', [baselineEntry], {
    env,
    stdio: ['pipe', 'pipe', 'pipe'],
});

let stdoutBuf = '';
const responses = [];

server.stdout.on('data', (chunk) => {
    stdoutBuf += chunk.toString();
    let nlIdx;
    while ((nlIdx = stdoutBuf.indexOf('\n')) !== -1) {
        const line = stdoutBuf.slice(0, nlIdx);
        stdoutBuf = stdoutBuf.slice(nlIdx + 1);
        if (line.trim()) {
            try {
                responses.push(JSON.parse(line));
            } catch {
                process.stderr.write(`[capture] non-json stdout: ${line.slice(0, 200)}\n`);
            }
        }
    }
});

server.stderr.on('data', (chunk) => {
    process.stderr.write(`[server-stderr] ${chunk}`);
});

server.on('error', (e) => {
    process.stderr.write(`[capture] server error: ${String(e)}\n`);
    process.exit(1);
});

function send(req) {
    server.stdin.write(JSON.stringify(req) + '\n');
}

function waitFor(predicate, timeoutMs = 5000) {
    return new Promise((res, rej) => {
        const start = Date.now();
        const interval = setInterval(() => {
            const match = responses.find(predicate);
            if (match) {
                clearInterval(interval);
                res(match);
            } else if (Date.now() - start > timeoutMs) {
                clearInterval(interval);
                rej(new Error(`timeout (${String(timeoutMs)}ms) waiting for response`));
            }
        }, 50);
    });
}

async function main() {
    send({
        jsonrpc: '2.0',
        id: 1,
        method: 'initialize',
        params: {
            protocolVersion: '2025-03-26',
            capabilities: {},
            clientInfo: { name: 'capture-baseline', version: '0.0.1' },
        },
    });
    await waitFor((r) => r.id === 1);

    send({ jsonrpc: '2.0', method: 'notifications/initialized' });

    send({ jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} });
    const listResp = await waitFor((r) => r.id === 2);

    if (!listResp.result || !Array.isArray(listResp.result.tools)) {
        throw new Error('tools/list response malformed: ' + JSON.stringify(listResp).slice(0, 500));
    }

    const tools = listResp.result.tools;
    tools.sort((a, b) => a.name.localeCompare(b.name));

    const entries = tools.map((t) => ({
        name: t.name,
        description: t.description,
        annotations: t.annotations,
        inputSchema: t.inputSchema,
    }));

    mkdirSync(dirname(outputPath), { recursive: true });
    writeFileSync(outputPath, JSON.stringify(entries, null, 2), 'utf-8');

    const compactBytes = JSON.stringify(entries).length;
    process.stdout.write(
        `[capture] ${String(tools.length)} tools, ${String(compactBytes)} compact-bytes → ${outputPath}\n`,
    );

    server.kill();
    process.exit(0);
}

main().catch((e) => {
    process.stderr.write(`[capture] error: ${String(e)}\n`);
    server.kill();
    process.exit(1);
});
