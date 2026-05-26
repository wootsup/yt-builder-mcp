#!/usr/bin/env node
/**
 * Phase 2 — Negative-path sweep.
 *
 * Drives the local stdio MCP server with INVALID inputs and asserts that
 * every error path returns a structured error envelope (NOT an
 * unexpected -32602 InputValidationError unless the case is explicitly
 * a missing-required-arg case, which is a legitimate -32602 surface).
 *
 * Each row in the matrix declares its EXPECTED outcome:
 *   - 'structured-error'  : result.isError === true, structured envelope
 *   - 'jsonrpc-32602'     : top-level JSON-RPC error with code -32602
 *
 * @license MIT
 */
import { spawn } from 'node:child_process';
import { appendFileSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(__dirname, '..');
const DIST = resolve(REPO, 'dist', 'index.js');
const LOG = process.env.AUDIT_LOG ||
    '/Users/getimo/Projekte/getimo/40-Plans/active/2026-05-25-06-yt-builder-mcp-exhaustive-audit.md';
const SITES = process.env.SITES_FILE || `${process.env.HOME}/.config/yt-builder-mcp/sites.json`;

function startServer() {
    const env = {
        HOME: process.env.HOME,
        PATH: '/usr/bin:/bin:/usr/sbin:/sbin',
        YTB_MCP_SITES_FILE: SITES,
        YTB_MCP_PLATFORM: process.env.YTB_MCP_PLATFORM || 'auto',
    };
    const child = spawn(process.execPath, [DIST], { env, stdio: ['pipe','pipe','pipe'] });
    let buf = '';
    const pending = new Map();
    let nextId = 1;
    const stderrBuf = [];
    child.stderr.on('data', d => stderrBuf.push(String(d)));
    child.stdout.on('data', d => {
        buf += d;
        let i;
        while ((i = buf.indexOf('\n')) >= 0) {
            const line = buf.slice(0, i).trim();
            buf = buf.slice(i + 1);
            if (!line) continue;
            try {
                const msg = JSON.parse(line);
                if (msg.id && pending.has(msg.id)) {
                    pending.get(msg.id)(msg);
                    pending.delete(msg.id);
                }
            } catch { /* ignore */ }
        }
    });
    function send(method, params, timeoutMs = 20000) {
        return new Promise((res, rej) => {
            const id = nextId++;
            const to = setTimeout(() => { pending.delete(id); rej(new Error(`timeout ${method}`)); }, timeoutMs);
            pending.set(id, v => { clearTimeout(to); res(v); });
            child.stdin.write(JSON.stringify({ jsonrpc:'2.0', id, method, params }) + '\n');
        });
    }
    function notify(method, params) {
        child.stdin.write(JSON.stringify({ jsonrpc:'2.0', method, params }) + '\n');
    }
    return { child, send, notify, stderrBuf };
}

// ── Probe matrix ──────────────────────────────────────────────────────────
//
// Each row:
//   { label, tool, args, expect: 'structured-error' | 'jsonrpc-32602',
//     contains?: string[], not_contains?: string[] }
//
// `contains` checks the rendered error payload (text + JSON) for hint
// keywords (e.g. "available", "sites_list", "not found").

const PROBES = [
    {
        label: 'sites_test.unknown_site_id',
        tool: 'yootheme_builder_sites_test',
        args: { site_id: 'does-not-exist' },
        expect: 'structured-error',
        contains: ['unknown'],
    },
    {
        label: 'pages_list.limit-too-high',
        tool: 'yootheme_builder_pages_list',
        args: { site_id: 'wp-dev', limit: 999 },
        expect: 'any', // we capture both paths and decide
    },
    {
        label: 'pages_list.limit-zero',
        tool: 'yootheme_builder_pages_list',
        args: { site_id: 'wp-dev', limit: 0 },
        expect: 'any',
    },
    {
        label: 'page_get_layout.unknown_template_wp',
        tool: 'yootheme_builder_page_get_layout',
        args: { site_id: 'wp-dev', template_id: 'XXXX_NO_SUCH' },
        expect: 'structured-error',
        contains: ['not_found', 'not found', 'unknown', 'pages_list'],
    },
    {
        label: 'page_get_layout.unknown_template_joomla',
        tool: 'yootheme_builder_page_get_layout',
        args: { site_id: 'joomla-dev', template_id: 'XXXX_NO_SUCH' },
        expect: 'structured-error',
        contains: ['not_found', 'not found', 'unknown', 'pages_list'],
    },
    {
        label: 'element_get.unknown_path_wp',
        tool: 'yootheme_builder_element_get',
        args: { site_id: 'wp-dev', template_id: 'I99YS8Ii', element_path: '/children/9999' },
        expect: 'structured-error',
        contains: ['not_found', 'not found', 'invalid', 'path'],
    },
    {
        label: 'element_get.unknown_path_joomla',
        tool: 'yootheme_builder_element_get',
        args: { site_id: 'joomla-dev', template_id: 'I99YS8Ii', element_path: '/children/9999' },
        expect: 'structured-error',
        contains: ['not_found', 'not found', 'invalid', 'path'],
    },
    {
        label: 'element_get.path-escape_wp',
        tool: 'yootheme_builder_element_get',
        args: { site_id: 'wp-dev', template_id: 'I99YS8Ii', element_path: '../escape' },
        expect: 'any', // validation could be schema-level (-32602) OR structured
        contains: ['path', 'invalid', 'pointer', '-32602'],
    },
    {
        label: 'element_get.path-escape_joomla',
        tool: 'yootheme_builder_element_get',
        args: { site_id: 'joomla-dev', template_id: 'I99YS8Ii', element_path: '../escape' },
        expect: 'any',
        contains: ['path', 'invalid', 'pointer', '-32602'],
    },
    {
        label: 'element_type_get_schema.bogus_wp',
        tool: 'yootheme_builder_element_type_get_schema',
        args: { site_id: 'wp-dev', type_name: 'bogusType' },
        expect: 'structured-error',
        contains: ['unknown', 'not_found', 'not found', 'type'],
    },
    {
        label: 'element_type_get_schema.bogus_joomla',
        tool: 'yootheme_builder_element_type_get_schema',
        args: { site_id: 'joomla-dev', type_name: 'bogusType' },
        expect: 'structured-error',
        contains: ['unknown', 'not_found', 'not found', 'type'],
    },
    {
        label: 'sites_test.missing_site_id',
        tool: 'yootheme_builder_sites_test',
        args: {},
        expect: 'any', // legit -32602 OR friendly structured error
        contains: ['site_id', 'required', 'missing'],
    },
];

function renderPayload(resp) {
    const r = resp?.result;
    if (!r) return '';
    let s = '';
    if (Array.isArray(r.content)) {
        for (const c of r.content) {
            if (typeof c.text === 'string') s += c.text + '\n';
        }
    }
    if (r.structuredContent) s += JSON.stringify(r.structuredContent);
    return s;
}

function classify(resp) {
    if (resp.error) {
        return { kind: 'jsonrpc-error', code: resp.error.code, message: resp.error.message };
    }
    const r = resp.result || {};
    return {
        kind: r.isError ? 'structured-error' : 'success',
        text: renderPayload(resp),
        structuredContent: r.structuredContent || null,
        _meta_keys: r._meta ? Object.keys(r._meta) : [],
    };
}

async function main() {
    const { child, send, notify, stderrBuf } = startServer();
    try {
        await send('initialize', {
            protocolVersion: '2024-11-05',
            capabilities: {},
            clientInfo: { name: 'audit-phase2-negative', version: '1' },
        });
        notify('notifications/initialized', {});

        const results = [];
        for (const probe of PROBES) {
            let resp;
            try {
                resp = await send('tools/call', { name: probe.tool, arguments: probe.args }, 20000);
            } catch (e) {
                results.push({ probe, classification: { kind: 'transport-error', message: e.message } });
                continue;
            }
            const classification = classify(resp);
            // Check `contains` against rendered payload OR JSON-RPC error message.
            const payload = (classification.text || '') + ' ' + (classification.message || '');
            const lc = payload.toLowerCase();
            const containsHits = (probe.contains || []).filter(needle => lc.includes(String(needle).toLowerCase()));
            // Validation:
            let verdict;
            if (probe.expect === 'structured-error') {
                if (classification.kind === 'structured-error') verdict = 'PASS';
                else verdict = `FAIL — expected structured-error, got ${classification.kind}`;
            } else if (probe.expect === 'jsonrpc-32602') {
                if (classification.kind === 'jsonrpc-error' && classification.code === -32602) verdict = 'PASS';
                else verdict = `FAIL — expected -32602, got ${classification.kind}${classification.code !== undefined ? ` (${classification.code})` : ''}`;
            } else { // 'any'
                if (classification.kind === 'structured-error' || classification.kind === 'jsonrpc-error') verdict = 'PASS';
                else verdict = `FAIL — expected error of any kind, got ${classification.kind}`;
            }
            results.push({ probe, classification, verdict, containsHits });
        }

        // ── Render ────────────────────────────────────────────────────────
        const ts = new Date().toISOString();
        let out = `\n\n## Phase 2 — Negative-path sweep @ ${ts}\n\n`;
        let passCount = 0, failCount = 0;
        for (const r of results) {
            if (r.verdict === 'PASS') passCount++; else failCount++;
        }
        out += `**Summary:** ${passCount}/${results.length} PASS, ${failCount} FAIL\n\n`;
        out += '| # | Probe | Tool | Args | Expected | Got | Verdict | Contains-hits |\n';
        out += '|---|-------|------|------|----------|-----|---------|--------------|\n';
        results.forEach((r, i) => {
            const argsStr = JSON.stringify(r.probe.args).replace(/\|/g, '\\|');
            const got = r.classification.kind + (r.classification.code !== undefined ? `(${r.classification.code})` : '');
            const hits = (r.containsHits || []).join(', ') || '—';
            const verdictIcon = r.verdict === 'PASS' ? '✅' : '❌';
            out += `| ${i+1} | ${r.probe.label} | ${r.probe.tool} | \`${argsStr}\` | ${r.probe.expect} | ${got} | ${verdictIcon} ${r.verdict.replace('PASS','PASS')} | ${hits} |\n`;
        });
        out += '\n### Details (rendered payload, truncated to 400 chars)\n\n';
        results.forEach((r, i) => {
            out += `**${i+1}. ${r.probe.label}** — ${r.classification.kind}\n`;
            if (r.classification.message) out += `\`\`\`\nJSON-RPC error code=${r.classification.code} message=${r.classification.message}\n\`\`\`\n`;
            if (r.classification.text) {
                const t = r.classification.text.slice(0, 400);
                out += '```\n' + t + (r.classification.text.length > 400 ? '\n…' : '') + '\n```\n';
            }
            out += '\n';
        });

        appendFileSync(LOG, out);
        const artefact = resolve(REPO, 'docs', `audit-phase2-${ts.replace(/[:.]/g, '-')}.json`);
        writeFileSync(artefact, JSON.stringify({ ts, results }, null, 2));
        console.error(`\n[phase2] wrote ${results.length} probes to ${LOG}`);
        console.error(`[phase2] artefact: ${artefact}`);
        console.error(`[phase2] PASS=${passCount} FAIL=${failCount}`);
        if (failCount > 0) process.exitCode = 1;
    } finally {
        child.kill();
    }
}

await main();
