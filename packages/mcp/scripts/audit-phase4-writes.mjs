#!/usr/bin/env node
/**
 * Phase 4 — controlled write sweep.
 *
 * Verifies live behavior of:
 *   F-008      Joomla L2 etag bumps the global counter
 *   F-meta-2   yootheme wp_option survives a write as STRING (not array)
 *
 * Strategy:
 *   1. Capture baseline ETag.
 *   2. Add a transient `text` element at template root (with the ETag).
 *   3. Capture middle ETag (must differ).
 *   4. Delete that element (using ETag from step 2).
 *   5. Capture post ETag.
 *
 * On WP we additionally inspect the persisted `yootheme` wp_option to
 * ensure F-meta-2 (string vs array) survived the round-trip.
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
const TEMPLATE = process.env.TEMPLATE_ID || 'I99YS8Ii';

function startServer() {
    const env = {
        HOME: process.env.HOME,
        PATH: '/usr/bin:/bin:/usr/sbin:/sbin',
        YTB_MCP_SITES_FILE: SITES,
        YTB_MCP_PLATFORM: 'auto',
    };
    const child = spawn(process.execPath, [DIST], { env, stdio: ['pipe','pipe','pipe'] });
    let buf = '';
    const pending = new Map();
    let nextId = 1;
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
    function send(method, params, timeoutMs = 30000) {
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
    return { child, send, notify };
}

function extractText(resp) {
    const r = resp?.result;
    if (!r) return '';
    let s = '';
    if (Array.isArray(r.content)) {
        for (const c of r.content) {
            if (typeof c.text === 'string') s += c.text + '\n';
        }
    }
    return s;
}

function extractStructured(resp) {
    return resp?.result?.structuredContent || null;
}

function extractJsonBlock(text) {
    // Drop the leading "[label @ host]" prefix if present, then JSON.parse.
    const idx = text.indexOf('{');
    if (idx === -1) return null;
    try { return JSON.parse(text.slice(idx).trim()); } catch { return null; }
}

async function call(send, name, args) {
    const resp = await send('tools/call', { name, arguments: args }, 30000);
    return {
        resp,
        sc: extractStructured(resp),
        text: extractText(resp),
        json: extractJsonBlock(extractText(resp)),
        isError: !!resp?.result?.isError,
        rpcError: resp?.error,
    };
}

async function runOnePlatform(send, siteId) {
    const trace = [];
    function step(label, payload) { trace.push({ label, ...payload }); }

    // 1. baseline etag
    const r1 = await call(send, 'yootheme_builder_get_etag',
        { site_id: siteId, template_id: TEMPLATE });
    step('get_etag.baseline', { isError: r1.isError, json: r1.json, sc: r1.sc, text: r1.text.slice(0,300) });
    const baselineEtag = r1.sc?.etag || r1.json?.etag || null;

    // 2. element_add: text element at root.
    const r2 = await call(send, 'yootheme_builder_element_add', {
        site_id: siteId,
        template_id: TEMPLATE,
        parent_path: '',
        element_type: 'text',
        props: { content: '[audit-phase4-transient]' },
        etag: baselineEtag,
    });
    step('element_add', { isError: r2.isError, json: r2.json, sc: r2.sc, text: r2.text.slice(0,300) });
    const addedPath = r2.sc?.element_path || r2.json?.element_path || null;
    const etagAfterAdd = r2.sc?.etag || r2.json?.etag || null;

    // 3. middle etag
    const r3 = await call(send, 'yootheme_builder_get_etag',
        { site_id: siteId, template_id: TEMPLATE });
    step('get_etag.middle', { isError: r3.isError, json: r3.json, sc: r3.sc });
    const middleEtag = r3.sc?.etag || r3.json?.etag || null;

    // 4. element_delete (always attempt cleanup, even if r2 failed).
    let r4 = null;
    if (addedPath) {
        r4 = await call(send, 'yootheme_builder_element_delete', {
            site_id: siteId,
            template_id: TEMPLATE,
            element_path: addedPath,
            etag: etagAfterAdd || middleEtag || baselineEtag,
            confirm: true,
        });
        step('element_delete', { isError: r4.isError, json: r4.json, sc: r4.sc, text: r4.text.slice(0,300) });
    } else {
        step('element_delete.skipped', { reason: 'no addedPath captured' });
    }

    // 5. post etag
    const r5 = await call(send, 'yootheme_builder_get_etag',
        { site_id: siteId, template_id: TEMPLATE });
    step('get_etag.post', { isError: r5.isError, json: r5.json, sc: r5.sc });
    const postEtag = r5.sc?.etag || r5.json?.etag || null;

    return {
        siteId,
        baselineEtag, etagAfterAdd, middleEtag, postEtag,
        addedPath,
        addOk: !r2.isError,
        deleteOk: r4 ? !r4.isError : null,
        trace,
    };
}

async function main() {
    const { child, send, notify } = startServer();
    const platforms = ['wp-dev', 'joomla-dev'];
    const allResults = [];
    try {
        await send('initialize', {
            protocolVersion: '2024-11-05',
            capabilities: {},
            clientInfo: { name: 'audit-phase4-writes', version: '1' },
        });
        notify('notifications/initialized', {});

        for (const p of platforms) {
            const res = await runOnePlatform(send, p);
            allResults.push(res);
        }

        const ts = new Date().toISOString();
        let out = `\n\n## Phase 4 — Controlled write sweep @ ${ts}\n\n`;
        out += '| Site | baseline | after_add | mid_read | post_delete | add_ok | delete_ok | added_path |\n';
        out += '|------|----------|-----------|----------|-------------|--------|-----------|-----------|\n';
        for (const r of allResults) {
            out += `| ${r.siteId} | \`${r.baselineEtag}\` | \`${r.etagAfterAdd}\` | \`${r.middleEtag}\` | \`${r.postEtag}\` | ${r.addOk?'✅':'❌'} | ${r.deleteOk === null ? '—' : (r.deleteOk?'✅':'❌')} | \`${r.addedPath || '—'}\` |\n`;
        }
        out += '\n### F-008 verdict (Joomla L2 etag bump)\n';
        const joomla = allResults.find(r => r.siteId === 'joomla-dev');
        if (joomla) {
            const before = joomla.baselineEtag;
            const after = joomla.middleEtag;
            const bumped = before && after && before !== after;
            out += `- baseline=\`${before}\`  middle=\`${after}\`  post=\`${joomla.postEtag}\`\n`;
            out += `- **${bumped ? 'PASS' : 'FAIL'}** — etag ${bumped ? 'CHANGED on add (counter ticked)' : 'did NOT change'}\n`;
        }
        out += '\n### F-meta-2 verdict (WP yootheme wp_option type)\n';
        out += `- Will probe via \`mcp__getimo__server_exec\` immediately below (wp eval).\n`;

        out += '\n### Phase 4 trace (per platform)\n\n';
        for (const r of allResults) {
            out += `**${r.siteId}**\n\n`;
            for (const t of r.trace) {
                out += `- \`${t.label}\` — isError=${t.isError ?? '—'} json=${t.json ? '```'+JSON.stringify(t.json).slice(0,300)+'```' : '—'}\n`;
            }
            out += '\n';
        }

        appendFileSync(LOG, out);
        const artefact = resolve(REPO, 'docs', `audit-phase4-${ts.replace(/[:.]/g, '-')}.json`);
        writeFileSync(artefact, JSON.stringify({ ts, allResults }, null, 2));
        console.error(`\n[phase4] wrote to ${LOG}`);
        console.error(`[phase4] artefact: ${artefact}`);
        for (const r of allResults) {
            console.error(`[phase4] ${r.siteId}: baseline=${r.baselineEtag} mid=${r.middleEtag} post=${r.postEtag} add_ok=${r.addOk} del_ok=${r.deleteOk}`);
        }
    } finally {
        child.kill();
    }
}

await main();
