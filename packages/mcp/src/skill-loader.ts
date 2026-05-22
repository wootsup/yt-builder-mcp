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
 *   packages/mcp/skills/yootheme-builder/SKILL.md
 *
 * When the file is compiled to `dist/skill-loader.js`, the same
 * relative `../skills/yootheme-builder/SKILL.md` resolves correctly
 * because the published npm tarball (and the DXT stage) preserves the
 * `skills/` folder next to `dist/`.
 *
 * Read errors throw at module-init: a missing SKILL.md is a packaging
 * bug, not a recoverable runtime condition.
 *
 * @license MIT
 */

import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

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
 * Source-tree:   packages/mcp/src/skill-loader.ts → ../skills/yootheme-builder/SKILL.md
 * Built tarball: packages/mcp/dist/skill-loader.js → ../skills/yootheme-builder/SKILL.md
 *
 * Both layouts work because `dist/` and `src/` are siblings of
 * `skills/` under the package root.
 */
function resolveSkillPath(): string {
    return resolve(__dirname, '..', 'skills', 'yootheme-builder', 'SKILL.md');
}

let cached: string | undefined;

/**
 * Load the bundled SKILL.md content. Cached after the first read.
 *
 * @throws Error when the file is missing or unreadable (packaging bug).
 */
export function loadSkillContent(): string {
    if (cached !== undefined) {
        return cached;
    }
    const path = resolveSkillPath();
    cached = readFileSync(path, 'utf-8');
    return cached;
}

/**
 * Reset the in-memory cache. Intended for tests only.
 *
 * @internal
 */
export function __resetSkillCacheForTests(): void {
    cached = undefined;
}
