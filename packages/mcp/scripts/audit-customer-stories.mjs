#!/usr/bin/env node
/**
 * Customer-story replay — Phase 6 of the exhaustive audit.
 *
 * Each story is a DETERMINISTIC tool-call chain representing a real
 * customer flow. We drive the local stdio MCP server (no LLM tokens) and
 * assert SEMANTIC properties on the chain: not just "did the call return
 * without error", but "is the output USEFUL for the next call". The
 * goal is to catch the F-201-class of bug: tool says OK while delivering
 * a useless response.
 *
 * Stories cover the 7 most-common customer flows:
 *   A. Cold Start (discovery)
 *   B. Add Element (build a section)
 *   C. Bind Source (data integration)
 *   D. Multi-Site (cross-platform op)
 *   E. Modify Bound Element (post-binding tweak)
 *   F. Error Recovery (actionable hints)
 *   G. Joomla L2 Article Flow (per-article writes)
 *
 * Append-only log + JSON artefact for triage. Re-runnable.
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
const SITES = process.env.SITES_FILE || `${process.env.HOME}/.config/yt-builder-mcp/sites.json`;
const LOG = process.env.AUDIT_LOG ||
    '/Users/getimo/Projekte/getimo/40-Plans/active/2026-05-25-06-yt-builder-mcp-exhaustive-audit.md';
const TEMPLATE = process.env.TEMPLATE_ID || 'I99YS8Ii';

function startServer() {
    const env = {
        HOME: process.env.HOME,
        PATH: '/usr/bin:/bin:/usr/sbin:/sbin',
        YTB_MCP_SITES_FILE: SITES,
    };
    const child = spawn(process.execPath, [DIST], { env, stdio: ['pipe', 'pipe', 'pipe'] });
    let buf = '';
    const pending = new Map();
    let nextId = 1;
    child.stderr.on('data', () => {});
    child.stdout.on('data', d => {
        buf += d;
        let i;
        while ((i = buf.indexOf('\n')) >= 0) {
            const line = buf.slice(0, i).trim();
            buf = buf.slice(i + 1);
            if (!line) continue;
            try {
                const msg = JSON.parse(line);
                if (msg.id && pending.has(msg.id)) { pending.get(msg.id)(msg); pending.delete(msg.id); }
            } catch { /* ignore */ }
        }
    });
    const send = (method, params, timeoutMs = 25000) =>
        new Promise((res, rej) => {
            const id = nextId++;
            pending.set(id, res);
            const to = setTimeout(() => { pending.delete(id); rej(new Error(`timeout ${method}`)); }, timeoutMs);
            const orig = pending.get(id);
            pending.set(id, v => { clearTimeout(to); orig(v); });
            child.stdin.write(JSON.stringify({ jsonrpc: '2.0', id, method, params }) + '\n');
        });
    const notify = (method, params) =>
        child.stdin.write(JSON.stringify({ jsonrpc: '2.0', method, params }) + '\n');
    return { child, send, notify };
}

const findings = [];
function finding({ story, severity, title, detail }) {
    findings.push({ story, severity, title, detail });
}
function assertSemantic(story, condition, message, detail) {
    if (!condition) {
        finding({ story, severity: '🔴', title: message, detail });
        return false;
    }
    return true;
}

async function call(send, name, args) {
    const r = await send('tools/call', { name, arguments: args });
    const text = r.result?.content?.[0]?.text || '';
    const sc = r.result?.structuredContent;
    const isError = r.result?.isError;
    const error = r.error; // protocol-level
    return { text, sc, isError, error, raw: r };
}

// ─── Stories ──────────────────────────────────────────────────────────

async function storyA(send) {
    const story = 'A. Cold Start';
    const out = [];
    const sites = await call(send, 'yootheme_builder_sites_list', {});
    out.push(['sites_list', sites.isError, sites.sc?.total]);
    assertSemantic(story, sites.sc?.total >= 1, 'sites_list returned 0 sites', sites.text.slice(0, 200));

    const d_wp = await call(send, 'yootheme_builder_diagnose', { site_id: 'wp-dev' });
    out.push(['diagnose wp', d_wp.isError]);
    assertSemantic(story, !d_wp.isError, 'diagnose wp-dev failed', d_wp.text.slice(0, 300));

    const h_jo = await call(send, 'yootheme_builder_health', { site_id: 'joomla-dev' });
    out.push(['health joomla', h_jo.isError, h_jo.sc?.yootheme_loaded]);
    assertSemantic(story, h_jo.sc?.yootheme_loaded === true, 'Joomla yootheme_loaded false on cold start', h_jo.text.slice(0, 300));

    const st = await call(send, 'yootheme_builder_sites_test', { site_id: 'joomla-dev' });
    out.push(['sites_test joomla', st.isError, st.sc?.bearer_valid]);
    assertSemantic(story, st.sc?.plugin_reachable === true && st.sc?.bearer_valid === true,
        'sites_test joomla-dev not green', st.text.slice(0, 300));

    const pl = await call(send, 'yootheme_builder_pages_list', { site_id: 'wp-dev' });
    out.push(['pages_list wp', pl.isError, pl.sc?.total]);
    assertSemantic(story, pl.sc?.total > 0, 'pages_list wp returned 0 templates', pl.text.slice(0, 200));

    const tsum = await call(send, 'yootheme_builder_template_summary', { site_id: 'wp-dev', template_id: TEMPLATE });
    out.push(['template_summary', tsum.isError, tsum.sc?.total]);
    assertSemantic(story, tsum.sc?.total > 0, 'template_summary returned 0 elements', tsum.text.slice(0, 200));

    return { story, calls: out.length, log: out };
}

async function storyB(send) {
    const story = 'B. Add Element';
    const out = [];
    // Discover element types
    const types = await call(send, 'yootheme_builder_element_types_list', { site_id: 'wp-dev' });
    out.push(['types_list', types.isError, types.sc?.total]);

    // Schema for headline — assert text contains type info, not just summary
    const schema = await call(send, 'yootheme_builder_element_type_get_schema', { site_id: 'wp-dev', element_type: 'headline' });
    out.push(['headline_schema', schema.isError, (schema.sc?.fields || []).length]);
    assertSemantic(story, (schema.sc?.fields || []).length >= 10,
        'headline schema fields[] suspiciously short', JSON.stringify(schema.sc?.fields?.slice(0, 3)));

    // F-201 check: text-leg should contain TYPE column, not just NAME truncation
    const hasTypeColumn = /NAME\s*\|\s*TYPE\s*\|\s*LABEL/i.test(schema.text)
        || /\btype\s*:\s*\w+/i.test(schema.text);
    assertSemantic(story, hasTypeColumn,
        'F-201 unresolved — headline schema text-leg has no TYPE column or per-field type info',
        schema.text.slice(0, 400));

    // Inspect layout, pick a place to add
    const layout = await call(send, 'yootheme_builder_page_get_layout', { site_id: 'wp-dev', template_id: TEMPLATE, mode: 'flat' });
    out.push(['layout_flat', layout.isError]);

    // F-208 check: flat-mode paths should be /children/X style, no /layout/layout double-prefix
    const paths = (layout.sc?.elements || []).map(e => e?.path || e?.rel_path).filter(Boolean).slice(0, 5);
    const hasDoublePrefix = paths.some(p => /\/layout\/layout\//.test(p));
    assertSemantic(story, !hasDoublePrefix,
        'F-208 unresolved — page_get_layout flat returns /layout/layout/* paths',
        paths.join(','));

    return { story, calls: out.length, log: out, sample_paths: paths };
}

async function storyC(send) {
    const story = 'C. Bind Source';
    const out = [];
    const sources = await call(send, 'yootheme_builder_sources_list', { site_id: 'wp-dev' });
    out.push(['sources_list', sources.isError, sources.sc?.total]);
    assertSemantic(story, sources.sc?.total > 0, 'sources_list wp returned 0', sources.text.slice(0, 200));

    // Inspect an existing element's binding (first element in template)
    const list = await call(send, 'yootheme_builder_element_list', { site_id: 'wp-dev', template_id: TEMPLATE, limit: 5 });
    out.push(['element_list_limit5', list.isError]);
    const firstPath = list.sc?.items?.[0]?.rel_path || list.sc?.items?.[0]?.path;
    if (firstPath) {
        const binding = await call(send, 'yootheme_builder_advanced', {
            tool: 'yootheme_builder_element_get_binding',
            arguments: { site_id: 'wp-dev', template_id: TEMPLATE, element_path: firstPath },
        });
        out.push(['get_binding', binding.isError]);
    } else {
        finding({ story, severity: '⚠️', title: 'No element to inspect binding on', detail: 'list returned empty items' });
    }
    return { story, calls: out.length, log: out };
}

async function storyD(send) {
    const story = 'D. Multi-Site';
    const out = [];
    // Run the SAME operation against both sites — cross-platform parity.
    const probes = [];
    for (const site of ['wp-dev', 'joomla-dev']) {
        const h = await call(send, 'yootheme_builder_health', { site_id: site });
        probes.push({ site, plugin_version: h.sc?.plugin_version, yootheme_loaded: h.sc?.yootheme_loaded });
    }
    out.push(['health_both', probes]);
    const sameVersion = probes[0].plugin_version === probes[1].plugin_version;
    assertSemantic(story, sameVersion, 'plugin_version mismatch across platforms', JSON.stringify(probes));
    return { story, calls: out.length, log: out, probes };
}

async function storyE(send) {
    const story = 'E. Modify Bound Element';
    const out = [];
    // Find a bound element
    const summary = await call(send, 'yootheme_builder_template_summary', { site_id: 'wp-dev', template_id: TEMPLATE });
    out.push(['summary', summary.isError, summary.sc?.bound_count]);
    if (!(summary.sc?.bound_count > 0)) {
        finding({ story, severity: 'ℹ️', title: 'No bound elements in template — story not exercised', detail: '' });
        return { story, calls: out.length, log: out, skipped: true };
    }
    // Discover bound elements by walking element_list and filtering has_binding
    const list = await call(send, 'yootheme_builder_element_list', { site_id: 'wp-dev', template_id: TEMPLATE });
    const bound = (list.sc?.items || []).find(i => i.has_binding === true);
    out.push(['find_bound', !!bound]);
    if (!bound) {
        finding({ story, severity: '⚠️', title: 'has_binding=true never set in element_list items', detail: 'F-201 cousin: list says no bound elements but summary says >0' });
    }
    return { story, calls: out.length, log: out };
}

async function storyF(send) {
    const story = 'F. Error Recovery';
    const out = [];
    // Trigger a typo, observe hint, "fix" by following the hint.
    const wrong = await call(send, 'yootheme_builder_page_get_layout',
        { site_id: 'wp-dev', template_id: 'BOGUS_TPL_ID' });
    out.push(['wrong_tpl', wrong.isError]);
    const hint = wrong.text || JSON.stringify(wrong.sc);
    const hintMentionsRecovery = /yootheme_builder_pages_list/i.test(hint);
    assertSemantic(story, hintMentionsRecovery,
        'error hint does not reference pages_list for recovery',
        hint.slice(0, 300));

    // Now drive the "fix": call pages_list to discover real IDs, then retry
    const pl = await call(send, 'yootheme_builder_pages_list', { site_id: 'wp-dev' });
    const realId = pl.sc?.items?.[0]?.id;
    if (realId) {
        const retry = await call(send, 'yootheme_builder_page_get_layout', { site_id: 'wp-dev', template_id: realId });
        out.push(['retry_real_tpl', retry.isError]);
        assertSemantic(story, !retry.isError, 'retry after recovery still errored', retry.text.slice(0, 200));
    }
    return { story, calls: out.length, log: out };
}

async function storyG(send) {
    const story = 'G. Joomla L1 Template Round-Trip';
    const out = [];

    // ── Probe v1.x L2 surface gap ─────────────────────────────────────
    // The Joomla L2 per-article endpoints exist server-side
    // (com_ytbmcp api routes) but are NOT exposed via MCP tools in
    // v1.x — scope is L1 templates only; L2 MCP surface deferred to
    // v1.2.0. The probe EXPECTS an SDK-validation error here (the
    // requested `tool` name is not in the gateway's enum). Anything
    // else (HTTP error / silent success) would be a surprise.
    const adv = await call(send, 'yootheme_builder_advanced', {
        tool: 'yootheme_builder_articles_list',
        arguments: { site_id: 'joomla-dev' },
    });
    out.push(['articles_list_probe_expected_invalid', adv.isError, !!adv.error]);
    const protocolErr = adv.error ? JSON.stringify(adv.error) : '';
    const textErr = adv.text || '';
    const looksLikeValidation =
        adv.isError ||
        !!adv.error ||
        /unknown tool|not found|invalid|enum|not in/i.test(textErr) ||
        /-32602|InvalidParams|enum/i.test(protocolErr);
    if (looksLikeValidation) {
        finding({
            story,
            severity: 'ℹ️',
            title:
                'Joomla L2 articles MCP surface not yet implemented ' +
                '(v1.x scope is L1 templates only — endpoints exist server-side, ' +
                'MCP tools deferred to v1.2.0)',
            detail: (protocolErr || textErr).slice(0, 300),
        });
    } else {
        finding({
            story,
            severity: '⚠️',
            title:
                'Unexpected: yootheme_builder_articles_list responded without ' +
                'an SDK validation error — gateway enum may have grown silently',
            detail: textErr.slice(0, 300),
        });
    }

    // ── Joomla-L1 round-trip (the actual exercised story) ─────────────
    // get_etag → pages_list → element_list. Each call is asserted for
    // useful payload, not just "not error".
    const etag = await call(send, 'yootheme_builder_get_etag', { site_id: 'joomla-dev' });
    out.push(['get_etag joomla', etag.isError, etag.sc?.etag]);
    assertSemantic(
        story,
        !etag.isError && typeof etag.sc?.etag === 'string' && etag.sc.etag.length > 0,
        'get_etag joomla-dev did not return a non-empty etag',
        etag.text.slice(0, 200),
    );

    const pages = await call(send, 'yootheme_builder_pages_list', { site_id: 'joomla-dev' });
    out.push(['pages_list joomla', pages.isError, pages.sc?.total]);
    assertSemantic(
        story,
        !pages.isError && (pages.sc?.total ?? 0) > 0,
        'pages_list joomla returned 0 templates',
        pages.text.slice(0, 200),
    );

    const tpl = pages.sc?.items?.[0]?.id || TEMPLATE;
    const list = await call(send, 'yootheme_builder_element_list', {
        site_id: 'joomla-dev',
        template_id: tpl,
        limit: 5,
    });
    out.push(['element_list joomla', list.isError, (list.sc?.items || []).length]);
    assertSemantic(
        story,
        !list.isError && Array.isArray(list.sc?.items),
        'element_list joomla did not return items[]',
        list.text.slice(0, 200),
    );

    return { story, calls: out.length, log: out };
}

// ─── Driver ───────────────────────────────────────────────────────────

async function main() {
    const { child, send, notify } = startServer();
    try {
        await send('initialize', {
            protocolVersion: '2024-11-05',
            capabilities: {},
            clientInfo: { name: 'audit-customer-stories', version: '1' },
        });
        notify('notifications/initialized', {});
        await send('tools/list', {}); // warm

        const results = [];
        for (const fn of [storyA, storyB, storyC, storyD, storyE, storyF, storyG]) {
            try {
                const r = await fn(send);
                results.push(r);
                process.stderr.write(`[story] ${r.story} — ${r.calls} calls\n`);
            } catch (e) {
                results.push({ story: fn.name, error: e.message });
                process.stderr.write(`[story] ${fn.name} threw: ${e.message}\n`);
            }
        }

        const ts = new Date().toISOString();
        let md = `\n\n## Phase 6 — Customer-Story replay @ ${ts}\n\n`;
        md += `**Stories run:** ${results.length}\n`;
        md += `**Findings surfaced:** ${findings.length}\n\n`;
        for (const r of results) {
            md += `### Story ${r.story}\n`;
            md += `- calls: ${r.calls ?? '?'}\n`;
            if (r.skipped) md += `- skipped: ${r.skipped}\n`;
            if (r.probes) md += `- probes: ${JSON.stringify(r.probes)}\n`;
            if (r.sample_paths) md += `- sample paths: ${r.sample_paths.join(', ')}\n`;
            md += '\n';
        }
        if (findings.length > 0) {
            md += `### Story-driven findings (this run)\n`;
            for (const f of findings) {
                md += `- ${f.severity} \`${f.story}\` — **${f.title}** — ${f.detail || ''}\n`;
            }
        }
        appendFileSync(LOG, md);
        const artefact = resolve(REPO, 'docs', `audit-stories-${ts.replace(/[:.]/g, '-')}.json`);
        writeFileSync(artefact, JSON.stringify({ ts, results, findings }, null, 2));
        process.stderr.write(`\n[audit-stories] log appended: ${LOG}\n`);
        process.stderr.write(`[audit-stories] artefact: ${artefact}\n`);
        process.stderr.write(`[audit-stories] story-driven findings: ${findings.length}\n`);
        if (findings.filter(f => f.severity === '🔴').length > 0) process.exitCode = 1;
    } finally {
        child.kill();
    }
}

await main();
