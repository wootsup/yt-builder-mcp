/**
 * install-skill tests — exercise the file-system effects of
 * `installSkill()` against a sandboxed temp HOME.
 *
 * @license MIT
 */

import {
    existsSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { installSkill, MARKER_LINE } from '../../src/install-skill.js';

let tmpHome: string;
let pkgRoot: string;
let srcSkillDir: string;

beforeEach(() => {
    tmpHome = mkdtempSync(join(tmpdir(), 'ytbmcp-installskill-'));
    pkgRoot = mkdtempSync(join(tmpdir(), 'ytbmcp-pkgroot-'));
    srcSkillDir = join(pkgRoot, 'skills', 'yootheme-builder');
    mkdirSync(srcSkillDir, { recursive: true });
    writeFileSync(
        join(srcSkillDir, 'SKILL.md'),
        '# YOOtheme Builder skill\n\nfake skill content for tests\n',
        'utf-8',
    );
    // Add a subdir to test recursive copy.
    mkdirSync(join(srcSkillDir, 'helpers'), { recursive: true });
    writeFileSync(
        join(srcSkillDir, 'helpers', 'note.md'),
        'subdir content\n',
        'utf-8',
    );
});

afterEach(() => {
    // tmp dirs are auto-collected; nothing to do.
});

describe('installSkill', () => {
    it('copies the skill folder recursively into targetSkillsDir', async () => {
        const targetSkillsDir = join(tmpHome, '.claude', 'skills');
        const result = await installSkill({
            pkgRoot,
            targetSkillsDir,
            targetAgentsFile: join(tmpHome, 'AGENTS.md'),
        });
        expect(result.copied).toBe(true);
        expect(result.skillTargetDir).toBe(
            join(targetSkillsDir, 'yootheme-builder'),
        );
        expect(existsSync(join(targetSkillsDir, 'yootheme-builder', 'SKILL.md'))).toBe(true);
        expect(
            existsSync(join(targetSkillsDir, 'yootheme-builder', 'helpers', 'note.md')),
        ).toBe(true);
    });

    it('creates AGENTS.md with the marker block when it does not exist', async () => {
        const agentsFile = join(tmpHome, 'AGENTS.md');
        expect(existsSync(agentsFile)).toBe(false);
        const result = await installSkill({
            pkgRoot,
            targetSkillsDir: join(tmpHome, '.claude', 'skills'),
            targetAgentsFile: agentsFile,
        });
        expect(result.markerAlreadyPresent).toBe(false);
        expect(existsSync(agentsFile)).toBe(true);
        expect(readFileSync(agentsFile, 'utf-8')).toContain(MARKER_LINE);
    });

    it('appends the marker block to an existing AGENTS.md without one', async () => {
        const agentsFile = join(tmpHome, 'AGENTS.md');
        const original = '# Existing notes\n\nSome content.\n';
        writeFileSync(agentsFile, original, 'utf-8');
        const result = await installSkill({
            pkgRoot,
            targetSkillsDir: join(tmpHome, '.claude', 'skills'),
            targetAgentsFile: agentsFile,
        });
        expect(result.markerAlreadyPresent).toBe(false);
        const after = readFileSync(agentsFile, 'utf-8');
        expect(after.startsWith(original)).toBe(true);
        expect(after).toContain(MARKER_LINE);
    });

    it('skips appending when the marker is already present (idempotent)', async () => {
        const agentsFile = join(tmpHome, 'AGENTS.md');
        const existing = `# Notes\n\n${MARKER_LINE}\nalready installed\n`;
        writeFileSync(agentsFile, existing, 'utf-8');
        const result = await installSkill({
            pkgRoot,
            targetSkillsDir: join(tmpHome, '.claude', 'skills'),
            targetAgentsFile: agentsFile,
        });
        expect(result.markerAlreadyPresent).toBe(true);
        // The file should be unchanged.
        expect(readFileSync(agentsFile, 'utf-8')).toBe(existing);
    });

    it('throws a helpful error when the bundled skill is missing', async () => {
        const emptyPkgRoot = mkdtempSync(join(tmpdir(), 'ytbmcp-empty-'));
        await expect(
            installSkill({
                pkgRoot: emptyPkgRoot,
                targetSkillsDir: join(tmpHome, '.claude', 'skills'),
                targetAgentsFile: join(tmpHome, 'AGENTS.md'),
            }),
        ).rejects.toThrow(/Bundled skill not found/);
    });

    it('finds the skill at the production-tarball layout (skills/ next to dist/)', async () => {
        // Production-tarball layout: pkgRoot contains `dist/`, `bin/`,
        // `manifest.json`, AND `skills/yootheme-builder/` — NOT a monorepo
        // `../../skills/...` walk-up. Without `skills/` in `package.json:files[]`
        // the published tarball has no skill folder; this test guards
        // both the path resolution AND the bundling contract.
        const tarballRoot = mkdtempSync(join(tmpdir(), 'ytbmcp-tarball-'));
        const skillDir = join(tarballRoot, 'skills', 'yootheme-builder');
        mkdirSync(skillDir, { recursive: true });
        writeFileSync(
            join(skillDir, 'SKILL.md'),
            '# Skill\n',
            'utf-8',
        );
        // Simulate the dist layout — the file structure mirrors what
        // `npm pack` ships.
        mkdirSync(join(tarballRoot, 'dist'), { recursive: true });
        writeFileSync(join(tarballRoot, 'dist', 'index.js'), '', 'utf-8');

        const result = await installSkill({
            pkgRoot: tarballRoot,
            targetSkillsDir: join(tmpHome, '.claude', 'skills'),
            targetAgentsFile: join(tmpHome, 'AGENTS.md'),
        });
        expect(result.copied).toBe(true);
        expect(
            existsSync(join(tmpHome, '.claude', 'skills', 'yootheme-builder', 'SKILL.md')),
        ).toBe(true);
    });

    it('packages/mcp/skills/yootheme-builder/SKILL.md exists in the real source tree (bundling contract)', () => {
        // Regression guard: the skill MUST live INSIDE packages/mcp/ so it
        // gets bundled into the npm tarball. If somebody moves it back to
        // the monorepo root, the tarball ships without it and `install-skill`
        // breaks in production.
        const repoSkillPath = join(
            __dirname, // tests/setup/
            '..',      // tests/
            '..',      // packages/mcp/
            'skills',
            'yootheme-builder',
            'SKILL.md',
        );
        expect(existsSync(repoSkillPath)).toBe(true);
    });
});
