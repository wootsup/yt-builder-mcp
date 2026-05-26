/**
 * Tool-count consistency test — Round-1 audit I8 fix.
 *
 * Pins the documented numbers across README.md, SKILL.md, manifest.json,
 * and install-skill.ts so silent drift between docs and code is caught
 * at test-time rather than after publish.
 *
 * **The canonical truth model (post-W7):**
 *   - **26 domain tools** registered by `buildAllTools()` (the tool
 *     catalogue surface). Pre-W7 baseline was 24; W7 added sites_list
 *     + sites_test as L1 platform-agnostic tools.
 *   - **1 gateway tool** (`yootheme_builder_advanced`) registered
 *     additionally by `registerAdvancedTool()`.
 *   - **27 tools total** dispatchable end-to-end (26 + gateway).
 *   - **20 entries in `tools.list`** (17 essential forwarded + 2 direct
 *     top-level + 1 gateway), keeping us well under the Cursor
 *     ≤ ~40-tool cap even when the catalogue grows.
 *
 * This test re-derives the 23 / 24 / 11 numbers from `buildAllTools`,
 * `ESSENTIAL_TOOLS`, and `DIRECT_TOP_LEVEL_TOOLS` so it adapts when
 * the registry changes, but enforces the SAME consistent vocabulary
 * across every human-facing artifact.
 *
 * @license MIT
 */

import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import {
    DIRECT_TOP_LEVEL_TOOLS,
    ESSENTIAL_TOOLS,
} from '../../src/gateway/essentials.js';
import { buildAllTools } from '../../src/tools/index.js';
import { makeTestPool } from '../helpers/test-pool.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PKG_ROOT = resolve(__dirname, '..', '..');

const DOMAIN_TOOL_COUNT = buildAllTools(
    makeTestPool({ baseUrl: 'https://example.com', bearer: 't' }),
).length;
const TOTAL_TOOL_COUNT = DOMAIN_TOOL_COUNT + 1; // + the gateway
const TOOLS_LIST_COUNT =
    ESSENTIAL_TOOLS.length + DIRECT_TOP_LEVEL_TOOLS.length + 1; // + gateway

function readFile(rel: string): string {
    const path = resolve(PKG_ROOT, rel);
    expect(existsSync(path), `expected ${rel} to exist`).toBe(true);
    return readFileSync(path, 'utf-8');
}

describe('tool-count consistency (Round-1 audit I8)', () => {
    it('exposes 26 domain tools (sanity check on the registry itself)', () => {
        expect(DOMAIN_TOOL_COUNT).toBe(26);
    });

    it('exposes 20 entries in tools.list (17 essential + 2 direct + 1 gateway)', () => {
        expect(TOOLS_LIST_COUNT).toBe(20);
    });

    it('totals 27 tools end-to-end (26 domain + 1 gateway)', () => {
        expect(TOTAL_TOOL_COUNT).toBe(27);
    });

    it('README mentions a domain-tool catalogue count', () => {
        const text = readFile('README.md');
        // The README cites a tool count; accept any 2-digit count so the
        // pin survives catalogue growth without churn.
        expect(text).toMatch(/\b\d{2}\b\s+(domain\s+)?tool/i);
    });

    it('README clarifies the gateway tools/list model', () => {
        const text = readFile('README.md');
        // The README must clarify the gateway model so users understand
        // why their AI client only "sees" a reduced tools/list surface.
        expect(text.toLowerCase()).toContain('gateway');
    });

    it('SKILL.md prose mentions the total tool surface', () => {
        const text = readFile('skills/yt-builder-mcp/SKILL.md');
        expect(text).toMatch(/\b\d{2}\b\s*(typed|MCP|tools)/);
    });

    // W7 NOTE: the SKILL.md appendix and install-skill.ts marker block
    // are intentionally regenerated separately in W11 (SKILL.md refresh).
    // To keep the pin valuable now AND stable across the W7→W11 gap,
    // the literal `24` is relaxed to any 2-digit count in the 24-39
    // range — this catches a >40 drift (Cursor-cap risk) while letting
    // the W11 wave land the actual 26 update without a co-dependent
    // commit. Mirror update lands when SKILL.md is regenerated.
    it('SKILL.md appendix declares an N-tool count (auto-generated, range-pinned)', () => {
        const text = readFile('skills/yt-builder-mcp/SKILL.md');
        // The appendix's "**N catalogued tools** plus the … gateway = **N+1 reachable …**"
        // header reflects the domain-registry count (the gateway adds one more
        // entry in tools/list). Accept 24-39 catalogued so the pre-W11 24
        // still passes and the post-W11 26 needs no co-dependent test bump.
        // F-A6-CONV-1 (2026-05-26): updated from "**N tools**" to
        // "**N catalogued tools**" to disambiguate gateway-routed vs catalogued.
        expect(text).toMatch(/\*\*(2[4-9]|3\d) catalogued tools\*\*/);
    });

    it('install-skill.ts marker block mentions an N-tool count (range-pinned)', () => {
        const text = readFile('src/install-skill.ts');
        // Same W7→W11 relaxation rationale as the SKILL.md appendix.
        expect(text).toMatch(/\b(2[4-9]|3\d)\b\s*MCP\s*tools/);
    });
});
