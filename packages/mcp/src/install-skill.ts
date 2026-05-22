/**
 * `install-skill` subcommand for `@wootsup/yt-builder-mcp`.
 *
 * Copies the bundled `skills/yootheme-builder/` directory into the user's
 * agent-skill folder (typically `~/.claude/skills/`) and appends a marker
 * block to `~/AGENTS.md` so other AI clients pick it up automatically.
 *
 * Idempotent: re-running overwrites existing skill files and skips the
 * AGENTS.md append when the marker is already present.
 *
 * Mirrors the pattern from `@wootsup/mcp install-skill` so users on both
 * products get the same workflow.
 *
 * @license MIT
 */

import {
    appendFileSync,
    copyFileSync,
    existsSync,
    mkdirSync,
    readdirSync,
    readFileSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const MARKER_LINE = '<!-- yt-builder-mcp:skill-installed -->';

const MARKER_BLOCK = `

## YT Builder MCP

${MARKER_LINE}
The YOOtheme Builder skill is installed at \`~/.claude/skills/yootheme-builder/\`
— the universal-marker path recognised by Claude Desktop and other AI
clients that follow the \`~/AGENTS.md\` discovery protocol.

It documents the 22 MCP tools (21 domain + 1 gateway, 10 entries in
\`tools/list\`), the 5 canonical workflows (build hero, bind dynamic
source, clone section, diagnose 401, add custom element), and the
gateway routing model. Source: https://github.com/wootsup/yt-builder-mcp.
`;

export interface InstallSkillOptions {
    /**
     * Package root that contains the bundled `skills/` dir. Defaults to
     * walking up from this file (works in both source and `dist/` layouts).
     */
    readonly pkgRoot?: string;
    /** Target dir where the `yootheme-builder/` skill folder will land. */
    readonly targetSkillsDir?: string;
    /** Target AGENTS.md file to append the marker to. */
    readonly targetAgentsFile?: string;
}

export interface InstallSkillResult {
    readonly copied: boolean;
    readonly markerAlreadyPresent: boolean;
    readonly skillTargetDir: string;
    readonly agentsFile: string;
}

function defaultPkgRoot(): string {
    // src/install-skill.ts → pkg root is one level up.
    // When running from dist/install-skill.js the layout is identical
    // (TypeScript emits `dist/install-skill.js` mirroring `src/`).
    const here = dirname(fileURLToPath(import.meta.url));
    return resolve(here, '..');
}

function copyDirRecursive(src: string, dst: string): void {
    if (!existsSync(dst)) mkdirSync(dst, { recursive: true });
    for (const entry of readdirSync(src)) {
        const sPath = join(src, entry);
        const dPath = join(dst, entry);
        const stat = statSync(sPath);
        if (stat.isDirectory()) {
            copyDirRecursive(sPath, dPath);
        } else {
            copyFileSync(sPath, dPath);
        }
    }
}

/**
 * Locate the bundled `skills/yootheme-builder` directory.
 *
 * Production layout: the skill folder is bundled INSIDE the npm package
 * (`packages/mcp/skills/yootheme-builder/`), next to `dist/`. The same
 * layout exists in the source tree (per Design-Doc §13.1) so source-mode
 * smoke tests and the published tarball use the same single path.
 *
 * Earlier drafts walked `../..` up to a monorepo-root `skills/` dir;
 * that layout was eliminated in Round-1 audit fix C1 because it produced
 * a tarball without `skills/` (skill-not-found at first run).
 */
function findSkillSource(pkgRoot: string): string {
    return join(pkgRoot, 'skills', 'yootheme-builder');
}

export async function installSkill(
    options: InstallSkillOptions = {},
): Promise<InstallSkillResult> {
    const pkgRoot = options.pkgRoot ?? defaultPkgRoot();
    const targetSkillsDir =
        options.targetSkillsDir ?? join(homedir(), '.claude', 'skills');
    const targetAgentsFile =
        options.targetAgentsFile ?? join(homedir(), 'AGENTS.md');

    const srcSkillDir = findSkillSource(pkgRoot);
    if (!existsSync(srcSkillDir)) {
        throw new Error(
            `Bundled skill not found at ${srcSkillDir}. ` +
                `Is the package installed correctly? (pkgRoot=${pkgRoot})`,
        );
    }
    if (!existsSync(targetSkillsDir)) {
        mkdirSync(targetSkillsDir, { recursive: true });
    }
    const skillTargetDir = join(targetSkillsDir, 'yootheme-builder');
    copyDirRecursive(srcSkillDir, skillTargetDir);

    let markerAlreadyPresent = false;
    if (existsSync(targetAgentsFile)) {
        const existing = readFileSync(targetAgentsFile, 'utf-8');
        if (existing.includes(MARKER_LINE)) {
            markerAlreadyPresent = true;
        } else {
            appendFileSync(targetAgentsFile, MARKER_BLOCK);
        }
    } else {
        // Create with a sensible top-line and the marker block (trimmed).
        writeFileSync(targetAgentsFile, MARKER_BLOCK.trimStart(), 'utf-8');
    }

    return {
        copied: true,
        markerAlreadyPresent,
        skillTargetDir,
        agentsFile: targetAgentsFile,
    };
}
