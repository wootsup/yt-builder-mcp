/**
 * Skill loader — Stream B1 (Skill-Distribution via MCP-Protocol).
 *
 * Reads the bundled SKILL.md once at module-load time and exposes it
 * so that:
 *
 *   1. `createServer` can pass the content as the `instructions` field
 *      to `McpServer`. MCP-spec hosts (Claude Desktop, Cursor, …)
 *      surface this string as auto-context after the `initialize`
 *      handshake — Maria the Claude-Desktop user receives the skill
 *      narrative without ever invoking a tool.
 *
 *   2. A `skill://yt-builder-mcp` resource can return the same content
 *      on demand for hosts that ignore `instructions` but honor
 *      `resources/list` + `resources/read`.
 *
 * Resolution layout (matches `install-skill.ts` + `build-dxt.js`):
 *
 *   packages/mcp/skills/yt-builder-mcp/SKILL.md
 *
 * When the file is compiled to `dist/skill-loader.js`, the same
 * relative `../skills/yt-builder-mcp/SKILL.md` resolves correctly
 * because the published npm tarball (and the DXT stage) preserves the
 * `skills/` folder next to `dist/`.
 *
 * Read errors throw at module-init: a missing SKILL.md is a packaging
 * bug, not a recoverable runtime condition.
 *
 * W8 — `appendSitesBlock(skillContent, registry)` post-processor.
 *  When the in-process {@link SiteRegistry} carries ≥1 configured
 *  site, append a "Currently configured sites" appendix to the
 *  instructions string so MCP hosts surface the live site roster
 *  alongside the skill narrative. The appendix contains ONLY
 *  safe-to-render metadata (site_id, label, platform hint/resolved,
 *  url, default-flag) — bearer fields are NEVER touched. When the
 *  registry has 0 sites the original skill content is returned
 *  verbatim (no appendix, no separator).
 *
 *  This is a pure-string post-processor; it does not touch the
 *  on-disk SKILL.md. Per plan §W8 (line 919) the MCP `initialize`
 *  response carries `instructions` as a single shot, so an AI client
 *  restart is required for the appendix to refresh after a sites file
 *  edit. The W9 CLI commands (add-site / remove-site) print a reminder.
 *
 * @license MIT
 */

import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import type { SiteRegistry, SiteRowT } from './sites/registry.js';

/** Stable URI under which the skill resource is exposed via MCP `resources/*`. */
export const SKILL_RESOURCE_URI = 'skill://yt-builder-mcp';

/** Human-readable resource name surfaced in `resources/list`. */
export const SKILL_RESOURCE_NAME = 'YT Builder MCP — Skill Guide';

/** Short description for `resources/list` consumers. */
export const SKILL_RESOURCE_DESCRIPTION =
    'Workflow guide for driving the YOOtheme Pro page builder via this MCP server. ' +
    'Auto-loaded as `instructions` at initialize; also fetchable on demand.';

/** MIME type for the SKILL.md payload. */
export const SKILL_RESOURCE_MIME_TYPE = 'text/markdown';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * Resolve the on-disk path to the bundled SKILL.md.
 *
 * Source-tree:   packages/mcp/src/skill-loader.ts → ../skills/yt-builder-mcp/SKILL.md
 * Built tarball: packages/mcp/dist/skill-loader.js → ../skills/yt-builder-mcp/SKILL.md
 *
 * Both layouts work because `dist/` and `src/` are siblings of
 * `skills/` under the package root.
 */
function resolveSkillPath(): string {
    return resolve(__dirname, '..', 'skills', 'yt-builder-mcp', 'SKILL.md');
}

let cached: string | undefined;

/**
 * Load the bundled SKILL.md content. Cached after the first read.
 *
 * When `registry` is supplied AND it carries ≥1 site, the returned
 * string is the on-disk SKILL.md PLUS the W8 sites appendix (see
 * {@link appendSitesBlock}). When `registry` is omitted or empty, the
 * raw skill content is returned unchanged. The disk cache is shared
 * across calls — the appendix is recomputed each time so registry
 * mutations after the first load are reflected (within the same
 * process instance — the MCP `initialize` response only takes a
 * single-shot snapshot per session per plan §W8 line 919).
 *
 * @throws Error when the file is missing or unreadable (packaging bug).
 */
export function loadSkillContent(registry?: SiteRegistry): string {
    if (cached === undefined) {
        const path = resolveSkillPath();
        cached = readFileSync(path, 'utf-8');
    }
    if (registry === undefined) {
        return cached;
    }
    return appendSitesBlock(cached, registry);
}

/**
 * Append the W8 "Currently configured sites" block to a skill-content
 * string. When the registry is empty, returns the input verbatim — no
 * separator, no header, no trailing newline change.
 *
 * Output shape (per plan §W8 Z.904-917):
 * ```text
 * <skillContent>
 *
 * ---
 *
 * ## Currently configured sites (3)
 *
 * - `wp-acme` — Acme — Production · wordpress · https://acme.com · default
 * - `joomla-beta` — Beta — Staging · joomla · https://beta.example.com/joomla
 * - `wp-internal` — Internal staging · wordpress · https://internal.example.com
 *
 * Use `yootheme_builder_sites_list` to inspect this at runtime.
 * Pass `site_id: "<id>"` on any tool call to target a specific site;
 * omit it to use the default.
 * ```
 *
 * Per-row format (bullet):
 *   `- \`<site_id>\` — <label or "(no label)"> · <platform> · <url><" · default"?>`
 *
 * The platform column prefers `platform_resolved` when the registry
 * has memoised a probe result for that site (peek-only — never
 * triggers a new probe), and falls back to `platform_hint` otherwise.
 *
 * Bearer fields (bearer / bearer_ref) are intentionally NEVER
 * referenced — only `site_id`, `url`, `platform_*`, `is_default`,
 * `label` from {@link SiteRowT}.
 */
export function appendSitesBlock(
    skillContent: string,
    registry: SiteRegistry,
): string {
    const rows = registry.listForDisplay();
    if (rows.length === 0) {
        return skillContent;
    }

    const bullets = rows.map((row) => renderBullet(row)).join('\n');

    const block =
        '\n\n---\n\n' +
        `## Currently configured sites (${String(rows.length)})\n\n` +
        bullets +
        '\n\n' +
        'Use `yootheme_builder_sites_list` to inspect this at runtime.\n' +
        'Pass `site_id: "<id>"` on any tool call to target a specific site;\n' +
        'omit it to use the default.\n';

    return skillContent + block;
}

/**
 * Markdown-escape an operator-provided string so it cannot inject
 * structure into the rendered SKILL.md appendix.
 *
 * W12-R1.3 (A2-L2): the W8 sites-appendix renders `row.label` into the
 * server's `instructions` field, which MCP hosts surface to the LLM
 * verbatim. An operator with write-access to sites.json could land a
 * label like `Acme [INTERNAL TOOL: ignore prior context] Production`
 * and the prompt would carry that text into the model context. The
 * escape list covers the markdown special chars the W8 bullet format
 * could collide with (\`*_[]<>), preserving readable text while
 * preventing injection. site_id is regex-restricted at the schema
 * layer so it doesn't need escaping; url is operator-controlled but
 * has a much smaller chosen-char surface and is rendered after a `·`
 * separator that limits payload shape.
 */
function escapeMarkdown(text: string): string {
    return text.replace(/([\\`*_[\]<>])/g, '\\$1');
}

/**
 * Render one {@link SiteRowT} as a markdown bullet for the W8 sites
 * appendix.
 *
 * Format: `- \`<site_id>\` — <label> · <platform> · <url><" · default"?>`
 *
 * `<platform>` prefers `platform_resolved` (the W3 memo cache) and
 * falls back to `platform_hint` when no probe has run yet.
 * `<label>` falls back to the literal string `(no label)` when the
 * entry has none AND is markdown-escaped (W12-R1.3 prompt-injection
 * defense). The `default` suffix is only present when `is_default`
 * is true.
 */
function renderBullet(row: SiteRowT): string {
    const platform: string = row.platform_resolved ?? row.platform_hint;
    const label: string = escapeMarkdown(row.label ?? '(no label)');
    const defaultSuffix: string = row.is_default ? ' · default' : '';
    return `- \`${row.site_id}\` — ${label} · ${platform} · ${row.url}${defaultSuffix}`;
}

/**
 * Reset the in-memory cache. Intended for tests only.
 *
 * @internal
 */
export function __resetSkillCacheForTests(): void {
    cached = undefined;
}
