#!/usr/bin/env node
/**
 * Exhaustive audit sweep — drives the local stdio MCP server against
 * EVERY tool × WP + Joomla × combination matrix and validates results
 * against the strict outputSchema contract (the same contract Claude
 * Desktop enforces). Findings stream to the central audit log.
 *
 * Append-only, idempotent: re-running emits a fresh dated section.
 *
 * @license MIT
 */
import { spawn } from 'node:child_process';
import { appendFileSync, writeFileSync, readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(__dirname, '..');
const DIST = resolve(REPO, 'dist', 'index.js');
const LOG = process.env.AUDIT_LOG ||
    '/Users/getimo/Projekte/getimo/40-Plans/active/2026-05-25-06-yt-builder-mcp-exhaustive-audit.md';
const SITES = process.env.SITES_FILE || `${process.env.HOME}/.config/yt-builder-mcp/sites.json`;
const PHASE = process.env.PHASE || 'read';
const TEMPLATE = process.env.TEMPLATE_ID || 'I99YS8Ii'; // cross-platform Post template

// ── Harness ────────────────────────────────────────────────────────────────
function startServer() {
    // Stripped PATH = Claude Desktop GUI condition.
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
            } catch { /* ignore non-JSON lines */ }
        }
    });
    function send(method, params, timeoutMs = 20000) {
        return new Promise((res, rej) => {
            const id = nextId++;
            pending.set(id, res);
            const to = setTimeout(() => { pending.delete(id); rej(new Error(`timeout ${method}`)); }, timeoutMs);
            const orig = pending.get(id);
            // wrap to clear timeout
            pending.set(id, v => { clearTimeout(to); orig(v); });
            child.stdin.write(JSON.stringify({ jsonrpc:'2.0', id, method, params }) + '\n');
        });
    }
    function notify(method, params) {
        child.stdin.write(JSON.stringify({ jsonrpc:'2.0', method, params }) + '\n');
    }
    return { child, send, notify, stderrBuf };
}

// ── Strict outputSchema validation (additionalProperties:false semantics) ──
/**
 * Approximate the JSON-Schema additionalProperties:false check a real
 * MCP host performs on structuredContent. We're given a Zod-serialised
 * JSON Schema — recurse, check keys against declared `properties`, and
 * report extras.
 */
function validateStrict(schema, value, path = '$') {
    const errs = [];
    if (!schema || typeof schema !== 'object') return errs;
    const type = schema.type;
    if (type === 'object' || (schema.properties && !type)) {
        if (value === null || typeof value !== 'object' || Array.isArray(value)) {
            errs.push(`${path}: expected object, got ${value === null ? 'null' : Array.isArray(value) ? 'array' : typeof value}`);
            return errs;
        }
        const declared = Object.keys(schema.properties || {});
        const declaredSet = new Set(declared);
        const addProps = schema.additionalProperties;
        if (addProps === false) {
            for (const k of Object.keys(value)) {
                if (!declaredSet.has(k)) errs.push(`${path}.${k}: UNDECLARED key (additionalProperties:false)`);
            }
        }
        for (const k of schema.required || []) {
            if (!(k in value)) errs.push(`${path}.${k}: MISSING required key`);
        }
        for (const [k, sub] of Object.entries(schema.properties || {})) {
            if (k in value) errs.push(...validateStrict(sub, value[k], `${path}.${k}`));
        }
    } else if (type === 'array') {
        if (!Array.isArray(value)) {
            errs.push(`${path}: expected array, got ${typeof value}`);
            return errs;
        }
        if (schema.items) {
            value.forEach((v, i) => errs.push(...validateStrict(schema.items, v, `${path}[${i}]`)));
        }
    } else if (type === 'string') {
        if (typeof value !== 'string') errs.push(`${path}: expected string, got ${typeof value}`);
        else if (schema.enum && !schema.enum.includes(value)) errs.push(`${path}: value ${JSON.stringify(value)} not in enum`);
    } else if (type === 'number' || type === 'integer') {
        if (typeof value !== 'number') errs.push(`${path}: expected ${type}, got ${typeof value}`);
    } else if (type === 'boolean') {
        if (typeof value !== 'boolean') errs.push(`${path}: expected boolean, got ${typeof value}`);
    }
    // Zod often emits `anyOf` for nullable/union; tolerate.
    if (schema.anyOf && Array.isArray(schema.anyOf)) {
        const subErrs = schema.anyOf.map(s => validateStrict(s, value, path));
        if (!subErrs.some(e => e.length === 0)) {
            errs.push(`${path}: did not match any of ${schema.anyOf.length} alternatives`);
        }
    }
    return errs;
}

// ── Log helpers ───────────────────────────────────────────────────────────
const findings = [];
let findingCounter = 100; // pre-narration findings used F-001..F-008
function nextFid() {
    findingCounter += 1;
    return `F-${String(findingCounter).padStart(3, '0')}`;
}

function recordFinding({ tool, platform, args, severity, title, detail }) {
    const fid = nextFid();
    const entry = { fid, tool, platform, args: JSON.stringify(args), severity, title, detail };
    findings.push(entry);
    return entry;
}

function summary(call) {
    const { tool, platform, ok, args, errs, http } = call;
    return `- ${ok ? '✅' : '❌'} ${tool} @ ${platform} args=${JSON.stringify(args)}${errs.length ? `  → ${errs.length} schema-err(s)` : ''}${http ? ` http=${http}` : ''}`;
}

// ── Main sweep ────────────────────────────────────────────────────────────
async function main() {
    const { child, send, notify, stderrBuf } = startServer();
    try {
        await send('initialize', {
            protocolVersion: '2024-11-05',
            capabilities: {},
            clientInfo: { name: 'audit-sweep', version: '1' },
        });
        notify('notifications/initialized', {});
        const toolsResp = await send('tools/list', {});
        const tools = toolsResp.result?.tools || [];

        // Index by name.
        const byName = new Map();
        for (const t of tools) byName.set(t.name, t);

        const calls = [];

        // Build the read-side matrix.
        const platforms = ['wp-dev', 'joomla-dev'];

        // Helper: invoke one tool with args and validate.
        async function invoke(toolName, args, label) {
            const t = byName.get(toolName);
            if (!t) {
                calls.push({ tool: toolName, platform: args.site_id || 'default', ok: false, args, errs: [`tool not registered`], label });
                return null;
            }
            let resp;
            try {
                resp = await send('tools/call', { name: toolName, arguments: args });
            } catch (e) {
                calls.push({ tool: toolName, platform: args.site_id || 'default', ok: false, args, errs: [`call threw: ${e.message}`], label });
                return null;
            }
            // JSON-RPC error wrapper?
            if (resp.error) {
                calls.push({ tool: toolName, platform: args.site_id || 'default', ok: false, args, errs: [`-32xxx ${resp.error.code}: ${resp.error.message}`], label });
                return resp;
            }
            const r = resp.result || {};
            const sc = r.structuredContent;
            const meta = r._meta || {};
            const errs = [];
            // outputSchema strict check (only if tool declares one AND structuredContent is present).
            if (t.outputSchema && sc !== undefined) {
                const schemaErrs = validateStrict(t.outputSchema, sc, 'structuredContent');
                errs.push(...schemaErrs);
            }
            // Site-meta must be on result-level _meta (sites_list/sites_test are stamped; health/diagnose etc. via withSiteMeta).
            const expectsSiteMeta = toolName !== 'yootheme_builder_sites_list' && args.site_id !== undefined && !r.isError;
            if (expectsSiteMeta) {
                if (!meta.site_id) errs.push(`result._meta.site_id missing (site-meta wrapper not applied)`);
                if (sc && (sc._meta || sc.site_meta)) errs.push(`structuredContent._meta present (must be on result._meta only)`);
            }
            const ok = errs.length === 0 && !r.isError;
            calls.push({ tool: toolName, platform: args.site_id || 'default', ok, args, errs, label, isError: !!r.isError, result: r });
            return resp;
        }

        // ── Phase 1: read sweep ───────────────────────────────────────────
        // Per platform, run every read tool in a meaningful baseline call.
        for (const site of platforms) {
            // sites_list is platform-independent; only run once.
            // L1 reads:
            await invoke('yootheme_builder_health', { site_id: site }, 'read.health');
            await invoke('yootheme_builder_diagnose', { site_id: site }, 'read.diagnose');
            await invoke('yootheme_builder_get_etag', { site_id: site }, 'read.etag');
            await invoke('yootheme_builder_pages_list', { site_id: site }, 'read.pages_list.default');
            await invoke('yootheme_builder_pages_list', { site_id: site, fields: ['template_id', 'name'] }, 'read.pages_list.fields-projection');
            await invoke('yootheme_builder_pages_list', { site_id: site, limit: 5 }, 'read.pages_list.limit-edge');
            await invoke('yootheme_builder_pages_list', { site_id: site, limit: 100 }, 'read.pages_list.limit-max');
            await invoke('yootheme_builder_pages_list', { site_id: site, limit: 1, offset: 1 }, 'read.pages_list.offset');
            await invoke('yootheme_builder_page_get_layout', { site_id: site, template_id: TEMPLATE }, 'read.page_get_layout.default');
            await invoke('yootheme_builder_page_get_layout', { site_id: site, template_id: TEMPLATE, mode: 'flat' }, 'read.page_get_layout.flat');
            await invoke('yootheme_builder_page_get_layout', { site_id: site, template_id: TEMPLATE, mode: 'nested' }, 'read.page_get_layout.nested');
            await invoke('yootheme_builder_template_summary', { site_id: site, template_id: TEMPLATE }, 'read.template_summary');
            await invoke('yootheme_builder_element_list', { site_id: site, template_id: TEMPLATE }, 'read.element_list.default');
            await invoke('yootheme_builder_element_list', { site_id: site, template_id: TEMPLATE, fields: ['type', 'rel_path'] }, 'read.element_list.fields-projection');
            // element_get needs a rel_path or element_id we need to discover.
            await invoke('yootheme_builder_element_types_list', { site_id: site }, 'read.element_types_list');
            await invoke('yootheme_builder_element_type_get_schema', { site_id: site, type_name: 'headline' }, 'read.element_type_get_schema.headline');
            await invoke('yootheme_builder_element_type_get_schema', { site_id: site, type_name: 'grid' }, 'read.element_type_get_schema.grid');
            await invoke('yootheme_builder_element_type_get_schema', { site_id: site, type_name: 'image' }, 'read.element_type_get_schema.image');
            await invoke('yootheme_builder_sources_list', { site_id: site }, 'read.sources_list');
            // F-102 (2026-05-26): the canonical input arg is `element_path` across
            // the entire MCP surface (cf. shared-schemas::ELEMENT_PATH). `element_rel_path`
            // is the projection COLUMN name on element_list/page_get_schema output —
            // not an input arg. Earlier audit calls used the wrong key and triggered
            // -32602 by mistake; using the canonical key avoids that false-positive.
            await invoke('yootheme_builder_inspect_multi_items_binding', { site_id: site, template_id: TEMPLATE, element_path: '/children/0' }, 'read.inspect_multi_items.first');
            // Advanced-gateway read: page_get_schema
            await invoke('yootheme_builder_advanced', { tool: 'yootheme_builder_page_get_schema', arguments: { site_id: site, template_id: TEMPLATE } }, 'read.advanced.page_get_schema');
            // Advanced-gateway introspection (no args sub-tool, returns schema description).
            await invoke('yootheme_builder_advanced', { tool: 'yootheme_builder_page_get_schema' }, 'read.advanced.introspect');
        }

        // sites_list (platform-independent)
        await invoke('yootheme_builder_sites_list', {}, 'read.sites_list');
        await invoke('yootheme_builder_sites_list', { fields: ['url', 'platform'] }, 'read.sites_list.fields-projection');

        // sites_test (per-site, REQUIRED site_id)
        for (const site of platforms) {
            await invoke('yootheme_builder_sites_test', { site_id: site }, 'read.sites_test');
        }

        // ── Pretty-print results ─────────────────────────────────────────
        const ts = new Date().toISOString();
        let out = `\n\n## Sweep run @ ${ts} — PHASE=${PHASE} (${calls.length} calls)\n\n`;
        const byTool = new Map();
        for (const c of calls) {
            if (!byTool.has(c.tool)) byTool.set(c.tool, []);
            byTool.get(c.tool).push(c);
        }
        let okCount = 0, errCount = 0, isErrCount = 0;
        for (const c of calls) {
            if (c.ok) okCount++; else errCount++;
            if (c.isError) isErrCount++;
        }
        out += `**Summary:** OK=${okCount}  schema-fails=${errCount}  isError=${isErrCount}\n\n`;
        for (const [tool, cs] of byTool) {
            out += `### ${tool}\n`;
            for (const c of cs) {
                const tag = c.isError ? '⚠️ isError' : (c.ok ? '✅' : '❌');
                out += `- ${tag} \`${c.label}\` args=\`${JSON.stringify(c.args)}\`\n`;
                if (c.errs.length) {
                    out += c.errs.map(e => `  - ⚠️ ${e}`).join('\n') + '\n';
                }
            }
            out += '\n';
        }
        // Append to the central log so nothing is lost on compaction.
        appendFileSync(LOG, out);
        // Also write a machine-readable artefact next to the script for re-analysis.
        const artefact = resolve(REPO, 'docs', `audit-sweep-${ts.replace(/[:.]/g, '-')}.json`);
        writeFileSync(artefact, JSON.stringify({ ts, calls: calls.map(c => ({
            tool: c.tool, platform: c.platform, label: c.label, args: c.args, ok: c.ok, errs: c.errs, isError: !!c.isError,
            content_first: (c.result?.content?.[0]?.text || '').slice(0, 400),
            structuredContent_keys: c.result?.structuredContent ? Object.keys(c.result.structuredContent) : null,
            meta_keys: c.result?._meta ? Object.keys(c.result._meta) : null,
        })) }, null, 2));
        console.error(`\n[audit-sweep] wrote ${calls.length} calls to ${LOG}`);
        console.error(`[audit-sweep] artefact: ${artefact}`);
        console.error(`[audit-sweep] OK=${okCount} schema-fails=${errCount} isError=${isErrCount}`);
        if (errCount > 0) process.exitCode = 1;
    } finally {
        child.kill();
    }
}

await main();
