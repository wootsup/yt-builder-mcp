/**
 * skill-md-shape tests — pin-test the SKILL.md narrative so silent
 * regressions (truncations, missing workflows, missing tool catalog)
 * fail loudly.
 *
 * @license MIT
 */

import { existsSync, readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import { buildAllTools } from '../../src/tools/index.js';
import { RestClient } from '../../src/client.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
// SKILL.md lives INSIDE the npm package at packages/mcp/skills/yt-builder-mcp/SKILL.md
// (so it gets bundled into the tarball — Round-1 audit C1 fix). From here:
// packages/mcp/tests/skills → up 2 → packages/mcp/ → skills/yt-builder-mcp/SKILL.md.
const SKILL_PATH = resolve(
    __dirname,
    '..',
    '..',
    'skills',
    'yt-builder-mcp',
    'SKILL.md',
);

describe('SKILL.md — file presence', () => {
    it('exists at the project-root skills/ path', () => {
        expect(existsSync(SKILL_PATH)).toBe(true);
    });

    it('is non-trivial (≥350 lines as a Goldstandard floor)', () => {
        const text = readFileSync(SKILL_PATH, 'utf-8');
        const lines = text.split('\n').length;
        expect(lines).toBeGreaterThanOrEqual(350);
    });
});

describe('SKILL.md — frontmatter', () => {
    it('has a YAML frontmatter block with name + description', () => {
        const text = readFileSync(SKILL_PATH, 'utf-8');
        expect(text.startsWith('---')).toBe(true);
        const frontmatter = text.split('---')[1] ?? '';
        expect(frontmatter).toMatch(/name:\s*yt-builder-mcp/);
        expect(frontmatter).toMatch(/description:/);
    });
});

describe('SKILL.md — 5 canonical workflows', () => {
    const expectedWorkflows = [
        /## Workflow 1: Build a hero section/,
        /## Workflow 2: Bind a dynamic source to a grid/,
        // Round-1 audit C2 fix: workflow 3 retitled to reflect that
        // element_clone is sibling-only intra-template (no destPageId).
        /## Workflow 3: Clone & modify a section within a template/,
        /## Workflow 4: Diagnose a 401 \/ 403 \/ auth failure/,
        /## Workflow 5: Add a custom element type/,
    ];

    it('contains all five workflow section headers', () => {
        const text = readFileSync(SKILL_PATH, 'utf-8');
        for (const re of expectedWorkflows) {
            expect(text, `missing workflow header matching ${re.source}`).toMatch(re);
        }
    });

    it('every workflow section is at least 60 lines long (NO-COMPROMISE Goldstandard per I-7)', () => {
        const text = readFileSync(SKILL_PATH, 'utf-8');
        const sections = text.split(/^## /m);
        const workflowSections = sections.filter((s) => s.startsWith('Workflow '));
        expect(workflowSections.length).toBeGreaterThanOrEqual(5);
        for (const section of workflowSections) {
            const lines = section.split('\n').length;
            expect(lines, `workflow section too short:\n${section.slice(0, 200)}`).toBeGreaterThanOrEqual(60);
        }
    });

    it('every workflow has at least 3 pitfall bullet items (concrete + actionable)', () => {
        const text = readFileSync(SKILL_PATH, 'utf-8');
        const workflowChunks = text.split(/^## Workflow /m).slice(1);
        for (const chunk of workflowChunks) {
            // Pitfalls block: from "Common pitfalls" up to the next bold
            // subsection header (`**Worked`, `**Success`, `**Edge`, etc.)
            // or end-of-chunk.
            const start = chunk.indexOf('Common pitfalls:');
            expect(start, `missing Common pitfalls block in:\n${chunk.slice(0, 200)}`).toBeGreaterThanOrEqual(0);
            const rest = chunk.slice(start);
            const stopMatch = rest.match(/\n\*\*(Worked example|Success criterion|Edge case)/);
            const pitfallsBlock = stopMatch
                ? rest.slice(0, stopMatch.index)
                : rest;
            // Count top-level bullet items (`- **` markers)
            const bulletCount = (pitfallsBlock.match(/^- \*\*/gm) ?? []).length;
            expect(
                bulletCount,
                `workflow has only ${String(bulletCount)} pitfalls (need ≥3):\n${pitfallsBlock.slice(0, 300)}`,
            ).toBeGreaterThanOrEqual(3);
        }
    });

    it('every workflow has a Worked example subsection (concrete tool-call snippet)', () => {
        const text = readFileSync(SKILL_PATH, 'utf-8');
        const workflowChunks = text.split(/^## Workflow /m).slice(1);
        for (const chunk of workflowChunks) {
            expect(
                chunk,
                `missing Worked example subsection in:\n${chunk.slice(0, 200)}`,
            ).toMatch(/\*\*Worked example/);
        }
    });

    it('every workflow has a "Canonical tool-call sequence" and "Common pitfalls" subsection', () => {
        const text = readFileSync(SKILL_PATH, 'utf-8');
        const workflowChunks = text.split(/^## Workflow /m).slice(1);
        expect(workflowChunks).toHaveLength(5);
        for (const chunk of workflowChunks) {
            expect(chunk).toMatch(/Canonical tool-call sequence/);
            expect(chunk).toMatch(/Common pitfalls/);
            expect(chunk).toMatch(/Success criterion/);
        }
    });
});

describe('SKILL.md — parameter-name fidelity (Round-1 audit C2 guard)', () => {
    // The real tool schemas use snake_case. Earlier drafts of SKILL.md
    // used camelCase aliases (`pageId`, `parentPath`, `ifMatch`, `fieldMap`,
    // `type`, `settings`) AND invented cross-template clone params
    // (`destPageId`, `destParentPath`) that don't exist in any tool schema.
    // An LLM following the canonical sequences would have hit 400 on every
    // call. This test pins the workflows so the regression cannot return.
    //
    // Scoped to the workflows region (between "## Workflow 1" and the
    // "## When something doesn't fit" closer) to avoid false-positives
    // on the unrelated tool-catalog appendix.
    function workflowsRegion(): string {
        const text = readFileSync(SKILL_PATH, 'utf-8');
        const start = text.indexOf('## Workflow 1:');
        const end = text.indexOf('## Appendix: Tool Catalog');
        expect(start, 'expected ## Workflow 1 anchor').toBeGreaterThan(0);
        expect(end, 'expected ## Appendix anchor').toBeGreaterThan(start);
        return text.slice(start, end);
    }

    /**
     * Extract just the executable surface from a Markdown region:
     *   - fenced code blocks (```jsonc / ```ts / etc.)
     *   - inline code spans that look like a tool call
     *     (backtick-wrapped `yootheme_builder_*({…})` excerpts).
     *
     * Prose can (and must) reference forbidden names to warn against
     * them ("Use template_id, NOT pageId"). The pin-test only fails
     * when fabricated names leak into code an LLM would copy verbatim.
     */
    function executableSurface(region: string): string {
        const blocks: string[] = [];
        const fenced = region.matchAll(/```[a-z]*\n([\s\S]*?)```/g);
        for (const m of fenced) blocks.push(m[1]);
        const inlineCalls = region.matchAll(/`yootheme_builder_[a-z_]+\([^`]*\)`/g);
        for (const m of inlineCalls) blocks.push(m[0]);
        return blocks.join('\n---\n');
    }

    const FORBIDDEN_CAMEL_PARAMS = [
        /\bpageId\b/,
        /\bparentPath\b/,
        /\bsrcPath\b/,
        /\bdestPageId\b/,
        /\bdestParentPath\b/,
        /\bdestIndex\b/,
        /\bifMatch\b/,
        /\bfieldMap\b/,
        /\bsourceName\b/,
    ];

    it('uses only real snake_case parameter names in executable workflow examples', () => {
        const code = executableSurface(workflowsRegion());
        const offenders: string[] = [];
        for (const re of FORBIDDEN_CAMEL_PARAMS) {
            if (re.test(code)) offenders.push(re.source);
        }
        expect(
            offenders,
            `fabricated camelCase param(s) found in SKILL.md executable workflows: ${offenders.join(', ')}`,
        ).toEqual([]);
    });

    it('does NOT invent fieldMap on element_bind_source (real schema is source_name + optional source_id)', () => {
        const code = executableSurface(workflowsRegion());
        expect(code).not.toMatch(/fieldMap/);
        expect(code).toMatch(/element_bind_source[\s\S]*?source_name/);
    });

    it('does NOT invent cross-template params on element_clone (real schema is sibling-only intra-template)', () => {
        const code = executableSurface(workflowsRegion());
        expect(code).not.toMatch(/destPageId/);
        expect(code).not.toMatch(/destParentPath/);
        // Prose-level scope-note must call it sibling-only.
        const region = workflowsRegion();
        expect(region).toMatch(/sibling-only|sibling, intra-template|intra-template/i);
    });
});

describe('SKILL.md — tool-catalog appendix', () => {
    it('contains the sentinel markers for the auto-generated catalog', () => {
        const text = readFileSync(SKILL_PATH, 'utf-8');
        expect(text).toContain('<!-- TOOL-CATALOG:BEGIN -->');
        expect(text).toContain('<!-- TOOL-CATALOG:END -->');
    });

    it('mentions every registered tool name inside the appendix block', () => {
        const text = readFileSync(SKILL_PATH, 'utf-8');
        const begin = text.indexOf('<!-- TOOL-CATALOG:BEGIN -->');
        const end = text.indexOf('<!-- TOOL-CATALOG:END -->');
        expect(begin).toBeGreaterThan(0);
        expect(end).toBeGreaterThan(begin);
        const appendix = text.slice(begin, end);

        const tools = buildAllTools(
            new RestClient({ baseUrl: 'https://example.com', bearerToken: 'x' }),
        );
        expect(tools.length).toBeGreaterThanOrEqual(20);
        const missing: string[] = [];
        for (const t of tools) {
            if (!appendix.includes(t.name)) missing.push(t.name);
        }
        expect(missing, `tool(s) missing from SKILL.md catalog appendix: ${missing.join(', ')}`).toEqual([]);
    });
});
