/**
 * skill-md-claims tests — HCFV pin-tests for the *factual claims* in
 * SKILL.md. Distinct from skill-md-shape.test.ts (which pins structure:
 * frontmatter, workflow headers, tool catalog appendix, snake_case
 * parameter fidelity).
 *
 * This file pins claims that can silently drift from reality:
 *
 *  1. **Tool-count claims** — "20 first-class, 26 total, 17 essential + 2
 *     direct + 1 gateway, 7 advanced". Verified against the real
 *     `ESSENTIAL_TOOLS` + `DIRECT_TOP_LEVEL_TOOLS` constants in
 *     `src/gateway/essentials.ts` and the catalogue produced by
 *     `buildAllTools()`. If any of those numbers change at the source
 *     and SKILL.md is not updated, this test fails — exactly the
 *     drift-mode the customer caught on 2026-05-25.
 *
 *  2. **URL claims** — the only URLs allowed in SKILL.md are URLs that
 *     actually resolve. We pin the specific allowlist (github.com,
 *     npmjs.com, wootsup.com, yootheme.com) and forbid the fabricated
 *     `wootsup.com/products/yt-builder-mcp` path that 404'd in the
 *     2026-05-25 customer report.
 *
 *  3. **Cross-platform claims** — SKILL.md must mention both WordPress
 *     **and** Joomla in the same places, since the plugin is
 *     cross-platform since Wave 9. We pin the description, the setup
 *     block, and the diagnose workflow.
 *
 *  4. **Real admin paths** — WordPress: Tools → YT Builder MCP
 *     (verified against `SettingsPage::add_menu` → `add_submenu_page('tools.php', ...)`).
 *     Joomla: Components → YT Builder MCP (verified against
 *     `com_ytbmcp/ytbmcp.xml` `<menu link="option=com_ytbmcp">`).
 *
 *  5. **Multi-picker explanation** — the skill must explain the
 *     two-picker-entry situation (MCP server + skill pack) since the
 *     `.dxt` bundle surfaces both.
 *
 * @license MIT
 */

import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import { buildAllTools } from '../../src/tools/index.js';
import {
    DIRECT_TOP_LEVEL_TOOLS,
    ESSENTIAL_TOOLS,
} from '../../src/gateway/essentials.js';
import { makeTestPool } from '../helpers/test-pool.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const SKILL_PATH = resolve(
    __dirname,
    '..',
    '..',
    'skills',
    'yt-builder-mcp',
    'SKILL.md',
);

function readSkill(): string {
    return readFileSync(SKILL_PATH, 'utf-8');
}

function buildCatalogue(): readonly string[] {
    const tools = buildAllTools(
        makeTestPool({ baseUrl: 'https://example.com', bearer: 'x' }),
    );
    return tools.map((t) => t.name);
}

describe('SKILL.md — tool-count claims (HCFV against gateway source-of-truth)', () => {
    // Real counts derived from source. The skill text MUST reflect these
    // exactly. If anyone adds/removes an essential or direct-top-level tool
    // and forgets to update SKILL.md, this test fails.
    const ESSENTIAL_COUNT = ESSENTIAL_TOOLS.length;        // 17 at HEAD (W7 added sites_list + sites_test)
    const DIRECT_COUNT = DIRECT_TOP_LEVEL_TOOLS.length;    // 2 at HEAD
    const GATEWAY_COUNT = 1;                               // yootheme_builder_advanced
    const TOOLS_LIST_COUNT = ESSENTIAL_COUNT + DIRECT_COUNT + GATEWAY_COUNT;
    const CATALOGUE_COUNT = buildCatalogue().length;       // 26 at HEAD (W7 added 2 sites_* tools)
    const ADVANCED_COUNT = CATALOGUE_COUNT - ESSENTIAL_COUNT - DIRECT_COUNT;

    it('catalogue size equals what SKILL.md claims (catalogue count)', () => {
        const text = readSkill();
        // The skill calls out the catalogue size both in the intro and
        // in the catalog appendix header. Make sure the number it states
        // matches the actual number of tools we build.
        const pattern = new RegExp(`\\b${String(CATALOGUE_COUNT)}\\b.*?(tools|catalogued)`);
        expect(
            text,
            `SKILL.md must mention the real catalogue size ${String(CATALOGUE_COUNT)}`,
        ).toMatch(pattern);
        // And it must NOT use the historical wrong counts (21, 22).
        // 21 was an older catalogue size; 22 appeared in essentials.ts
        // doc-comments before the 1.0.1 schema promotion.
        const headerMatches = text.match(/\*\*\d+ tools?\*\*/g) ?? [];
        for (const m of headerMatches) {
            expect(m, 'SKILL.md headers must use the live catalogue count').toBe(
                `**${String(CATALOGUE_COUNT)} tools**`,
            );
        }
    });

    it('mentions the exact essential / direct / gateway / advanced split', () => {
        const text = readSkill();
        // Each number must appear at least once, in the right context.
        expect(text).toMatch(new RegExp(`${String(DIRECT_COUNT)}\\s+direct top-level tools`));
        expect(text).toMatch(new RegExp(`${String(ESSENTIAL_COUNT)}\\s+essential forwarded tools`));
        expect(text).toMatch(new RegExp(`${String(ADVANCED_COUNT)}\\s+advanced captured tools`));
        expect(text).toMatch(/1 gateway tool/);
        // And the combined tools/list math.
        expect(text).toMatch(
            new RegExp(
                `${String(TOOLS_LIST_COUNT)}\\s+names\\s+\\(${String(ESSENTIAL_COUNT)}\\s*\\+\\s*${String(DIRECT_COUNT)}\\s*\\+\\s*${String(GATEWAY_COUNT)}\\)`,
            ),
        );
    });

    it('does NOT use the legacy/incorrect "11-entry" or "8 essential" or "13 advanced" counts', () => {
        const text = readSkill();
        // These were the wrong counts in the pre-2026-05-25 version of the
        // skill. The customer flagged them; this guard makes sure they
        // cannot return.
        expect(text).not.toMatch(/11-entry\s+Gateway-Hub/);
        expect(text).not.toMatch(/8\s+essential\s+forwarded/);
        expect(text).not.toMatch(/13\s+advanced\s+captured/);
    });

    it('every L2 advanced tool is mentioned with the gateway-wrap pattern at least once', () => {
        // Compute the real advanced list from source so this stays honest.
        const catalogue = new Set(buildCatalogue());
        const surface = new Set<string>([
            ...ESSENTIAL_TOOLS,
            ...DIRECT_TOP_LEVEL_TOOLS,
        ]);
        const advanced = [...catalogue].filter((n) => !surface.has(n));
        expect(advanced.length).toBe(ADVANCED_COUNT);

        const text = readSkill();
        // For each advanced tool the skill must show it can be reached
        // through the gateway. We accept either a literal
        // `yootheme_builder_advanced({ tool: "<name>" ...` snippet OR
        // an explicit mention of the bare name alongside the word
        // "gateway" in the same paragraph.
        const missing: string[] = [];
        for (const name of advanced) {
            const gatewaySnippet = new RegExp(
                `yootheme_builder_advanced\\([\\s\\S]{0,80}?tool:\\s*"${name}"`,
            );
            if (!gatewaySnippet.test(text)) {
                missing.push(name);
            }
        }
        expect(
            missing,
            `advanced tools missing gateway-wrap example in SKILL.md: ${missing.join(', ')}`,
        ).toEqual([]);
    });
});

describe('SKILL.md — URL allowlist (HCFV — fabricated URLs forbidden)', () => {
    it('does NOT reference the dead wootsup.com/products/yt-builder-mcp path', () => {
        const text = readSkill();
        // The pre-2026-05-25 version pointed customers at
        // https://wootsup.com/products/yt-builder-mcp which 308-redirects
        // to a 404 — confirmed via curl in the rewrite session. Plugin
        // distribution lives on GitHub / npm only at this stage.
        expect(text).not.toMatch(/wootsup\.com\/products\/yt-builder-mcp/);
    });

    it('every absolute URL is on the verified allowlist', () => {
        const text = readSkill();
        const allow = [
            'https://yootheme.com',
            'https://github.com/wootsup/yt-builder-mcp',
            // (npm package URL acceptable but not currently in the skill;
            // included so future edits don't fail this guard)
            'https://www.npmjs.com/package/@wootsup/yt-builder-mcp',
            'https://wootsup.com',
            'https://example.com',  // worked-example placeholder
        ];
        const urlMatches = text.match(/https?:\/\/[^\s)`'"<>]+/g) ?? [];
        const violators: string[] = [];
        for (const url of urlMatches) {
            const cleaned = url.replace(/[.,;:!?)]+$/, '');
            const ok = allow.some((prefix) => cleaned.startsWith(prefix));
            if (!ok) violators.push(cleaned);
        }
        expect(
            violators,
            `URLs not on the verified allowlist: ${violators.join(', ')}`,
        ).toEqual([]);
    });
});

describe('SKILL.md — cross-platform parity (WordPress AND Joomla)', () => {
    it('frontmatter description names both WordPress and Joomla', () => {
        const text = readSkill();
        const fm = text.split('---')[1] ?? '';
        expect(fm).toMatch(/WordPress/);
        expect(fm).toMatch(/Joomla/);
    });

    it('setup block walks through both WordPress and Joomla key generation', () => {
        const text = readSkill();
        // WordPress path: Tools → YT Builder MCP
        expect(text).toMatch(/wp-admin\s*→\s*Tools\s*→\s*["“]?YT Builder MCP/);
        // Joomla path: Components → YT Builder MCP
        expect(text).toMatch(/Components\s*→\s*YT Builder MCP/);
    });

    it('diagnose workflow surfaces both WordPress and Joomla recovery steps', () => {
        const text = readSkill();
        const start = text.indexOf('## Workflow 4');
        const end = text.indexOf('## Workflow 5');
        expect(start).toBeGreaterThan(0);
        expect(end).toBeGreaterThan(start);
        const w4 = text.slice(start, end);
        // Plugin activation guidance must include both CMSes.
        expect(w4).toMatch(/wp-admin/);
        expect(w4).toMatch(/Joomla/);
    });
});

describe('SKILL.md — site-URL surfacing (diagnose / health / pages_list frontend_url)', () => {
    it('explains that health (authenticated) + diagnose return site_url + home_url', () => {
        const text = readSkill();
        // Two distinct phrasings must both be present somewhere in the
        // skill — the agent needs to know it can ask the server "which
        // site are you on?" via either tool.
        expect(text).toMatch(/site_url/);
        expect(text).toMatch(/home_url/);
        expect(text).toMatch(/yootheme_builder_diagnose/);
        expect(text).toMatch(/yootheme_builder_health/);
    });

    it('explains the pages_list frontend_url / frontend_url_template / frontend_url_description columns', () => {
        const text = readSkill();
        expect(text).toMatch(/frontend_url/);
        expect(text).toMatch(/frontend_url_template/);
        expect(text).toMatch(/frontend_url_description/);
    });
});

describe('SKILL.md — multi-picker explanation (DXT yields 2 entries)', () => {
    it('explains that Claude Desktop shows two picker entries (MCP server + skill) and both must be enabled', () => {
        const text = readSkill();
        // Loose match — operator wants the agent to surface this to the
        // user, so the wording must mention two entries / picker /
        // activate both.
        expect(text).toMatch(/two picker entries|two entries|two\b/i);
        expect(text).toMatch(/picker/i);
        expect(text).toMatch(/Activate both|activate both|enable both/i);
    });
});
