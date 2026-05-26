#!/usr/bin/env node
/**
 * Live-verify all registered tools against a real YT Builder MCP
 * endpoint (dev.wootsup.com by default).
 *
 * Wave G.9 / Plan §13 / Design §11 Achse 7 (Feature-Verification).
 * Wave 7 (2026-05-24): platform-agnostic — accepts BOTH WordPress
 * (`/wp-json/...`) and Joomla (`/api/index.php/v1/...`) base URLs.
 * Platform is auto-detected from the URL shape; the spawned MCP server
 * does the runtime detection in its own `RestClient`.
 *
 * What it does:
 *   1. Reads Bearer token from $YTB_MCP_BEARER_TOKEN, $YTB_MCP_BEARER, or
 *      `op read "op://Claude-Secrets/<item>/credential"` (env-driven), or
 *      gracefully skips with a clear note + exit-0.
 *   2. Spawns the local MCP stdio server (`dist/index.js`) with
 *      $YTB_MCP_SITE_URL (or legacy $YTB_MCP_WP_URL) + Bearer.
 *   3. Sends JSON-RPC `initialize` + `tools/list`.
 *   4. Invokes every catalogued tool — direct when listed in
 *      tools/list, otherwise via the `yootheme_builder_advanced`
 *      gateway. Lane is derived at runtime.
 *   5. Writes a markdown report to `docs/LIVE-VERIFY-REPORT.md` that
 *      records the detected platform alongside the per-tool table.
 *
 * Env vars:
 *   YTB_MCP_SITE_URL                — canonical base URL (WP or Joomla)
 *   YTB_MCP_WP_URL                  — legacy alias, still honoured
 *   YTB_MCP_BEARER_TOKEN            — Bearer key
 *   YTB_MCP_1P_REF                  — 1Password ref for the Bearer
 *   YTB_MCP_VERIFY_TEMPLATE_ID      — template id to probe (default: home)
 *
 * Joomla examples (subdir install — REQUIRES platform hint):
 *   export YTB_MCP_SITE_URL=https://dev.wootsup.com/joomla
 *   export YTB_MCP_PLATFORM=joomla       # subdir paths like /joomla don't
 *                                          # carry a platform hint in the
 *                                          # URL pathname, so detection
 *                                          # falls back to wordpress and
 *                                          # every tool 404s — set this
 *                                          # explicitly. Origin-only Joomla
 *                                          # installs (no subdir) can skip
 *                                          # this if the wrapper is updated
 *                                          # to detect a Joomla CMS marker.
 *   export YTB_MCP_BEARER_TOKEN=ytb_live_…
 *   export YTB_MCP_VERIFY_TEMPLATE_ID=blog
 *   node ./scripts/live-verify.mjs
 *
 * Exit codes:
 *   0  all tested tools returned non-error (or token absent — skip case)
 *   1  one or more tools returned a fatal error (non-skip)
 *   2  usage / config error
 *
 * Per the user prompt: NEVER edit Plugin-PHP to enable a test. If the
 * Bearer is unavailable, document and exit-0.
 *
 * @license MIT
 */
import { spawn, spawnSync } from 'node:child_process';
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = resolve(__dirname, '..');
const DIST_ENTRY = resolve(REPO_ROOT, 'dist', 'index.js');
const REPORT_PATH = resolve(REPO_ROOT, 'docs', 'LIVE-VERIFY-REPORT.md');

// Wave 7: prefer YTB_MCP_SITE_URL (canonical); fall back to legacy
// YTB_MCP_WP_URL so existing CI/devloop configs keep working.
const SITE_URL =
    process.env.YTB_MCP_SITE_URL
    ?? process.env.YTB_MCP_WP_URL
    ?? 'https://dev.wootsup.com';
const TEMPLATE_ID = process.env.YTB_MCP_VERIFY_TEMPLATE_ID ?? 'home';

/**
 * Auto-detect host platform from the URL shape — mirrors
 * `src/platform/index.ts::detectPlatformFromUrl`.
 * Returns 'wordpress' | 'joomla' | 'wordpress' (default fallback).
 */
function detectPlatform(url) {
    if (url.includes('/wp-json/')) return 'wordpress';
    if (url.includes('/api/index.php/')) return 'joomla';
    // Origin-only URL — let the MCP server's own platform-detect or
    // identity probe make the call. For the live-verify report we mark
    // it as "wordpress (assumed)" since that is still the default.
    return 'wordpress';
}

// Allow operator to force-set the platform when the URL is origin-only
// (Joomla origin URLs like `https://example.com/joomla` do NOT contain
// the `/api/index.php/` token, so URL-only detection falls back to
// WordPress). YTB_MCP_PLATFORM=joomla disambiguates.
const PLATFORM =
    (process.env.YTB_MCP_PLATFORM ?? '').toLowerCase() === 'joomla'
        ? 'joomla'
        : (process.env.YTB_MCP_PLATFORM ?? '').toLowerCase() === 'wordpress'
            ? 'wordpress'
            : detectPlatform(SITE_URL);

// ── Tool catalogue (matches src/tools/*) ──────────────────────────────
//
// The two arrays below are just the test catalogue (tool name + sample
// args). The actual call-lane — direct vs. via the `yootheme_builder_advanced`
// gateway — is NOT taken from this partitioning; it is derived at runtime
// from the live `tools/list`: a tool advertised there is called directly,
// everything else goes through the gateway. This makes the verifier
// self-correcting when tools are promoted between L1 and L2 (audit v4:
// page_get_layout was promoted to L1 but the old hardcoded split still
// routed it through the gateway → -32602).
//
// Per-tool `sampleArgs` are intentionally minimal — read-onlys get real
// arguments where possible, write-ops get a guarded preview (confirm:false
// or the structured-error path) so we never mutate a production layout.

/** @typedef {{ name: string, kind: 'read'|'write'|'meta', sampleArgs: Record<string, unknown>, expectError?: boolean }} ToolSpec */

/** @type {ToolSpec[]} */
const SURFACE_TOOLS = [
    // Health domain (always read-only)
    { name: 'yootheme_builder_health', kind: 'meta', sampleArgs: {} },
    { name: 'yootheme_builder_diagnose', kind: 'meta', sampleArgs: {} },
    // Pages (read direct)
    { name: 'yootheme_builder_pages_list', kind: 'read', sampleArgs: {} },
    { name: 'yootheme_builder_get_etag', kind: 'read', sampleArgs: { template_id: TEMPLATE_ID } },
    // Elements essentials (L1)
    {
        name: 'yootheme_builder_element_list',
        kind: 'read',
        sampleArgs: { template_id: TEMPLATE_ID },
    },
    {
        name: 'yootheme_builder_element_add',
        kind: 'write',
        // Stale ETag → server 412 → structured error. Success here means the
        // tool plumbing routed the call to a structured-error path (NOT a
        // transport-level 500).
        sampleArgs: {
            template_id: TEMPLATE_ID,
            parent_path: '',
            element_type: 'headline',
            etag: '"stale-etag-for-verify"',
        },
        expectError: true,
    },
    {
        name: 'yootheme_builder_element_update_settings',
        kind: 'write',
        sampleArgs: {
            template_id: TEMPLATE_ID,
            element_path: '/0',
            props: {},
            etag: '"stale-etag-for-verify"',
        },
        expectError: true,
    },
    // Sources essentials (L1)
    { name: 'yootheme_builder_sources_list', kind: 'read', sampleArgs: {} },
    // Inspection essentials (L1)
    { name: 'yootheme_builder_element_types_list', kind: 'read', sampleArgs: {} },
    // W7 — sites_list reads the registry only; sites_test probes /health
    // + /etag for the `default` site (the legacy env-bridge always
    // materialises a one-site registry with site_id = "default", which is
    // what live-verify drives). sample_args must use the literal id.
    { name: 'yootheme_builder_sites_list', kind: 'meta', sampleArgs: {} },
    { name: 'yootheme_builder_sites_test', kind: 'meta', sampleArgs: { site_id: 'default' } },
    // Gateway (meta — discovery mode is always safe)
    { name: 'yootheme_builder_advanced', kind: 'meta', sampleArgs: { tool: 'yootheme_builder_page_save' } },
];

/** @type {ToolSpec[]} */
const ADVANCED_TOOLS = [
    // Pages writes
    {
        name: 'yootheme_builder_page_save',
        kind: 'write',
        // Etag intentionally stale → server returns 412 → structured error.
        sampleArgs: { template_id: TEMPLATE_ID, etag: '"stale-etag-for-verify"' },
        expectError: true,
    },
    {
        name: 'yootheme_builder_page_publish',
        kind: 'write',
        sampleArgs: { template_id: TEMPLATE_ID, etag: '"stale-etag-for-verify"' },
        expectError: true,
    },
    {
        name: 'yootheme_builder_page_get_layout',
        kind: 'read',
        sampleArgs: { template_id: TEMPLATE_ID },
    },
    {
        name: 'yootheme_builder_page_get_schema',
        kind: 'read',
        sampleArgs: { template_id: TEMPLATE_ID },
    },
    // Element advanced
    {
        name: 'yootheme_builder_element_get',
        kind: 'read',
        sampleArgs: { template_id: TEMPLATE_ID, element_path: '/0' },
        expectError: true, // path may not exist — structured error OK
    },
    {
        name: 'yootheme_builder_element_move',
        kind: 'write',
        sampleArgs: {
            template_id: TEMPLATE_ID,
            element_path: '/0',
            to_parent_path: '',
            to_index: 0,
            etag: '"stale-etag-for-verify"',
        },
        expectError: true,
    },
    {
        name: 'yootheme_builder_element_clone',
        kind: 'write',
        sampleArgs: {
            template_id: TEMPLATE_ID,
            element_path: '/0',
            etag: '"stale-etag-for-verify"',
        },
        expectError: true,
    },
    {
        name: 'yootheme_builder_element_delete',
        kind: 'write',
        sampleArgs: {
            template_id: TEMPLATE_ID,
            element_path: '/0',
            etag: '"stale-etag-for-verify"',
            // confirm omitted → preview-then-retry path (PASS — no mutation)
        },
        expectError: true,
    },
    // Source advanced
    {
        name: 'yootheme_builder_element_get_binding',
        kind: 'read',
        sampleArgs: { template_id: TEMPLATE_ID, element_path: '/0' },
        expectError: true,
    },
    {
        name: 'yootheme_builder_element_bind_source',
        kind: 'write',
        sampleArgs: {
            template_id: TEMPLATE_ID,
            element_path: '/0',
            source_name: 'wp_posts',
            etag: '"stale-etag-for-verify"',
        },
        expectError: true,
    },
    {
        name: 'yootheme_builder_element_unbind_source',
        kind: 'write',
        sampleArgs: {
            template_id: TEMPLATE_ID,
            element_path: '/0',
            etag: '"stale-etag-for-verify"',
            // confirm omitted → preview-then-retry path
        },
        expectError: true,
    },
    // Inspection advanced
    {
        name: 'yootheme_builder_element_type_get_schema',
        kind: 'read',
        sampleArgs: { type_name: 'headline' },
    },
    // L1 essentials added after the original G.9 catalogue. Listed here for
    // completeness — the call-lane is runtime-derived from tools/list.
    {
        name: 'yootheme_builder_inspect_multi_items_binding',
        kind: 'read',
        sampleArgs: { template_id: TEMPLATE_ID, element_path: '/0' },
        expectError: true, // path may not exist — structured error OK
    },
    {
        name: 'yootheme_builder_template_summary',
        kind: 'read',
        sampleArgs: { template_id: TEMPLATE_ID },
    },
];

/** Full test catalogue — lane is decided at runtime, not by this split. */
const CATALOGUE = [...SURFACE_TOOLS, ...ADVANCED_TOOLS];
const EXPECTED_TOTAL = CATALOGUE.length;

// ── Bearer token resolution ─────────────────────────────────────────
function resolveBearerToken() {
    const envToken = process.env.YTB_MCP_BEARER_TOKEN ?? process.env.YTB_MCP_BEARER;
    if (envToken && envToken.length > 8) {
        return { token: envToken, source: 'env-var $YTB_MCP_BEARER_TOKEN' };
    }
    // 1Password lookup — caller can set $YTB_MCP_1P_REF to the secret path.
    const opRef = process.env.YTB_MCP_1P_REF;
    if (opRef) {
        const r = spawnSync('op', ['read', opRef], { encoding: 'utf-8' });
        if (r.status === 0 && r.stdout.trim().length > 8) {
            return { token: r.stdout.trim(), source: `1Password: ${opRef}` };
        }
        return { token: null, source: `1Password lookup failed (ref=${opRef}, status=${String(r.status)})` };
    }
    return { token: null, source: 'NOT-CONFIGURED (set $YTB_MCP_BEARER_TOKEN or $YTB_MCP_1P_REF)' };
}

// ── MCP stdio client ────────────────────────────────────────────────
class StdioClient {
    constructor(env) {
        this.env = env;
        this.server = null;
        this.stdoutBuf = '';
        this.responses = [];
        this.nextId = 1;
    }

    start() {
        this.server = spawn('node', [DIST_ENTRY], {
            env: this.env,
            stdio: ['pipe', 'pipe', 'pipe'],
        });
        this.server.stdout.on('data', (chunk) => {
            this.stdoutBuf += chunk.toString();
            let nlIdx;
            while ((nlIdx = this.stdoutBuf.indexOf('\n')) !== -1) {
                const line = this.stdoutBuf.slice(0, nlIdx);
                this.stdoutBuf = this.stdoutBuf.slice(nlIdx + 1);
                if (line.trim()) {
                    try {
                        this.responses.push(JSON.parse(line));
                    } catch {
                        // ignore non-json lines
                    }
                }
            }
        });
        this.server.stderr.on('data', (chunk) => {
            // Round-6 A2 N-A2-003 defense-in-depth: scrub anything that
            // looks like a Bearer token shape `ytb_(live|test)_<id>.<sig>`
            // before mirroring server stderr to the operator console. Even
            // though the current code paths don't leak tokens, future server
            // changes (verbose error logs, library debug output) could
            // surface secrets via this passthrough — masking here keeps the
            // surface clean by construction.
            const scrubbed = String(chunk).replace(
                /ytb_(live|test)_[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/g,
                'ytb_$1_***REDACTED***',
            );
            process.stderr.write(`[server] ${scrubbed}`);
        });
    }

    stop() {
        if (this.server) this.server.kill();
    }

    send(method, params = {}) {
        const id = this.nextId++;
        this.server.stdin.write(JSON.stringify({ jsonrpc: '2.0', id, method, params }) + '\n');
        return id;
    }

    sendNotification(method, params = {}) {
        this.server.stdin.write(JSON.stringify({ jsonrpc: '2.0', method, params }) + '\n');
    }

    async waitFor(id, timeoutMs = 15_000) {
        const start = Date.now();
        while (Date.now() - start < timeoutMs) {
            const found = this.responses.find((r) => r.id === id);
            if (found) return found;
            await new Promise((res) => setTimeout(res, 50));
        }
        throw new Error(`timeout waiting for response id=${String(id)}`);
    }

    async initialize() {
        const initId = this.send('initialize', {
            protocolVersion: '2025-03-26',
            capabilities: {},
            clientInfo: { name: 'live-verify', version: '0.0.1' },
        });
        await this.waitFor(initId);
        this.sendNotification('notifications/initialized');
    }

    async callTool(name, args) {
        const id = this.send('tools/call', { name, arguments: args });
        return this.waitFor(id);
    }

    async listTools() {
        const id = this.send('tools/list', {});
        const r = await this.waitFor(id);
        return r.result?.tools ?? [];
    }
}

// ── Test runner ─────────────────────────────────────────────────────
function classifyToolResult(resp, spec) {
    // The MCP SDK returns either { result: { content: [...], isError? } } on
    // success, or { error: { code, message } } on JSON-RPC error. We treat:
    //   - transport error (resp.error)                 → FAIL
    //   - tool isError=true + expectError=true         → PASS (graceful)
    //   - tool isError=true + expectError=false        → FAIL
    //   - tool returns content                         → PASS
    if (resp.error) {
        return {
            status: 'FAIL',
            detail: `JSON-RPC error: ${resp.error.code} ${resp.error.message}`,
        };
    }
    const result = resp.result;
    if (!result) {
        return { status: 'FAIL', detail: 'no result field' };
    }
    if (result.isError === true) {
        if (spec.expectError) {
            return {
                status: 'PASS',
                detail: 'expected structured error (server processed request correctly)',
            };
        }
        const preview = JSON.stringify(result.content?.[0] ?? {}).slice(0, 200);
        return { status: 'FAIL', detail: `unexpected isError: ${preview}` };
    }
    const hasContent = Array.isArray(result.content) && result.content.length > 0;
    return {
        status: 'PASS',
        detail: hasContent ? 'returned content' : 'returned (no content)',
    };
}

async function runVerification(token, tokenSource) {
    // Pass BOTH env-var names to the spawned MCP server so it works
    // regardless of which release line it was built from (pre-Wave-7
    // releases only know YTB_MCP_WP_URL; Wave-7+ prefers YTB_MCP_SITE_URL
    // but still honours the legacy alias).
    // Round-6 A2 N-A2-002: only forward the YTB_MCP_PLATFORM hint when the
    // operator explicitly set it in their shell. Otherwise let the spawned
    // server's own URL-shape detection (now `URL.pathname.startsWith` —
    // Round-6 A1 polish) make the call so the auto-detect path actually
    // gets exercised end-to-end. Forwarding the derived `PLATFORM` value
    // unconditionally bypassed that detection in every live-verify run.
    const env = {
        ...process.env,
        YTB_MCP_SITE_URL: SITE_URL,
        YTB_MCP_WP_URL: SITE_URL,
        YTB_MCP_BEARER_TOKEN: token,
    };
    if (process.env.YTB_MCP_PLATFORM) {
        env.YTB_MCP_PLATFORM = process.env.YTB_MCP_PLATFORM;
    }
    const client = new StdioClient(env);
    client.start();
    try {
        await client.initialize();
        const advertised = await client.listTools();
        const advertisedNames = advertised.map((t) => t.name).sort();

        /** @type {Array<{ name: string, kind: string, lane: 'surface'|'advanced', status: 'PASS'|'FAIL'|'SKIP', detail: string }>} */
        const results = [];

        // Lane is derived from the LIVE tools/list — a tool advertised
        // there is called directly, everything else via the gateway. No
        // hardcoded surface/advanced split can go stale this way.
        const surfaceSet = new Set(advertisedNames);
        for (const spec of CATALOGUE) {
            const direct = surfaceSet.has(spec.name);
            const resp = direct
                ? await client.callTool(spec.name, spec.sampleArgs)
                : await client.callTool('yootheme_builder_advanced', {
                      tool: spec.name,
                      arguments: spec.sampleArgs,
                  });
            const { status, detail } = classifyToolResult(resp, spec);
            results.push({
                name: spec.name,
                kind: spec.kind,
                lane: direct ? 'surface' : 'advanced',
                status,
                detail,
            });
        }

        return { advertisedNames, results };
    } finally {
        client.stop();
    }
}

function renderReport({ advertisedNames, results, token, tokenSource, error }) {
    const now = new Date().toISOString();
    const lines = [];
    lines.push('# Live-Verify Report — yt-builder-mcp');
    lines.push('');
    lines.push(`- **Generated:** ${now}`);
    lines.push(`- **Platform:** ${PLATFORM}`);
    lines.push(`- **Endpoint:** ${SITE_URL}`);
    lines.push(`- **Bearer-Token source:** ${tokenSource}`);
    lines.push(`- **Bin entry:** ${DIST_ENTRY}`);
    lines.push('');
    lines.push('## Provenance');
    lines.push('');
    lines.push('This report is generated by `scripts/live-verify.mjs` (Wave G.9). It spawns the local stdio MCP server, sends JSON-RPC `initialize` + `tools/list`, then calls every catalogued tool — directly when it is advertised in `tools/list`, otherwise via the `yootheme_builder_advanced` gateway (lane derived at runtime, never hardcoded). Write-ops are invoked without `etag` so the server-side validation returns a clean structured error (NOT a 5xx) — that is the success criterion for "the tool plumbing works."');
    lines.push('');

    if (error) {
        lines.push('## Status: SKIPPED');
        lines.push('');
        lines.push(`Reason: ${error}`);
        lines.push('');
        lines.push('### How to enable next session');
        lines.push('');
        lines.push('1. **Generate a Bearer key**:');
        lines.push('   - **WordPress:** wp-admin → Tools → YT Builder MCP → Bearer Keys');
        lines.push('   - **Joomla:** Administrator → Components → YT Builder MCP → Bearer Keys');
        lines.push('2. **Store it** in env:');
        lines.push('   ```sh');
        lines.push('   # WordPress');
        lines.push('   export YTB_MCP_SITE_URL="https://example.com"');
        lines.push('   export YTB_MCP_BEARER_TOKEN="ytb_live_…"');
        lines.push('   # …or Joomla');
        lines.push('   export YTB_MCP_SITE_URL="https://example.com/joomla"');
        lines.push('   export YTB_MCP_BEARER_TOKEN="ytb_live_…"');
        lines.push('   node ./scripts/live-verify.mjs');
        lines.push('   ```');
        lines.push('   …or in 1Password:');
        lines.push('   ```sh');
        lines.push('   export YTB_MCP_1P_REF="op://Claude-Secrets/<item-id>/credential"');
        lines.push('   node ./scripts/live-verify.mjs');
        lines.push('   ```');
        lines.push('3. **Verify** — the script will append a per-tool table here.');
        lines.push('');
        return lines.join('\n');
    }

    const surface = results.filter((r) => r.lane === 'surface');
    const advanced = results.filter((r) => r.lane === 'advanced');
    const surfacePass = surface.filter((r) => r.status === 'PASS').length;
    const advancedPass = advanced.filter((r) => r.status === 'PASS').length;
    const totalPass = surfacePass + advancedPass;
    const total = results.length;

    lines.push('## Summary');
    lines.push('');
    lines.push(`| Lane | Tested | Passed | Failed |`);
    lines.push(`|------|-------:|-------:|-------:|`);
    lines.push(`| Surface (tools/list) | ${String(surface.length)} | ${String(surfacePass)} | ${String(surface.length - surfacePass)} |`);
    lines.push(`| Advanced (via gateway) | ${String(advanced.length)} | ${String(advancedPass)} | ${String(advanced.length - advancedPass)} |`);
    lines.push(`| **Total** | **${String(total)}** | **${String(totalPass)}** | **${String(total - totalPass)}** |`);
    lines.push('');
    lines.push(`- **Server-advertised tools/list count:** ${String(advertisedNames.length)}`);
    lines.push(`- **Catalogued tools verified:** ${String(EXPECTED_TOTAL)}`);
    lines.push('');

    lines.push('## Surface tools (called directly — advertised in tools/list)');
    lines.push('');
    lines.push(`| Tool | Kind | Status | Detail |`);
    lines.push(`|------|------|--------|--------|`);
    for (const r of surface) {
        lines.push(`| \`${r.name}\` | ${r.kind} | ${r.status === 'PASS' ? '✅ PASS' : '❌ FAIL'} | ${r.detail} |`);
    }
    lines.push('');

    lines.push('## Advanced tools (via `yootheme_builder_advanced` gateway)');
    lines.push('');
    lines.push(`| Tool | Kind | Status | Detail |`);
    lines.push(`|------|------|--------|--------|`);
    for (const r of advanced) {
        lines.push(`| \`${r.name}\` | ${r.kind} | ${r.status === 'PASS' ? '✅ PASS' : '❌ FAIL'} | ${r.detail} |`);
    }
    lines.push('');

    lines.push('## Server-advertised tools/list');
    lines.push('');
    lines.push('```');
    for (const n of advertisedNames) lines.push(n);
    lines.push('```');
    lines.push('');
    return lines.join('\n');
}

async function main() {
    const { token, source } = resolveBearerToken();
    if (!token) {
        process.stdout.write(`[live-verify] no Bearer token — ${source}; writing SKIP report\n`);
        const md = renderReport({
            advertisedNames: [],
            results: [],
            token: null,
            tokenSource: source,
            error: `Bearer token not available. ${source}`,
        });
        mkdirSync(dirname(REPORT_PATH), { recursive: true });
        writeFileSync(REPORT_PATH, md, 'utf-8');
        process.stdout.write(`[live-verify] SKIP report → ${REPORT_PATH}\n`);
        process.exit(0);
    }

    try {
        const { advertisedNames, results } = await runVerification(token, source);
        const md = renderReport({
            advertisedNames,
            results,
            token,
            tokenSource: source,
            error: null,
        });
        mkdirSync(dirname(REPORT_PATH), { recursive: true });
        writeFileSync(REPORT_PATH, md, 'utf-8');
        const fails = results.filter((r) => r.status === 'FAIL');
        process.stdout.write(
            `[live-verify] ${String(results.length - fails.length)}/${String(results.length)} passed → ${REPORT_PATH}\n`,
        );
        process.exit(fails.length === 0 ? 0 : 1);
    } catch (e) {
        process.stderr.write(`[live-verify] error: ${String(e)}\n`);
        const md = renderReport({
            advertisedNames: [],
            results: [],
            token,
            tokenSource: source,
            error: `Verification crashed: ${String(e)}`,
        });
        mkdirSync(dirname(REPORT_PATH), { recursive: true });
        writeFileSync(REPORT_PATH, md, 'utf-8');
        process.exit(1);
    }
}

main();
