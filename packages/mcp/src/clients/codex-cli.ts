/**
 * OpenAI Codex CLI config writer.
 *
 *   All platforms: ~/.codex/config.toml
 *
 * TOML schema:
 *
 *   [mcp_servers.<name>]
 *   command = "npx"
 *   args = ["-y", "@wootsup/yt-builder-mcp"]
 *
 *   [mcp_servers.<name>.env]
 *   KEY = "value"
 *
 * No TOML library is currently in this package's dependency closure
 * (see `pnpm list` at build-time). To avoid pulling a new dep for one
 * tiny use-case, this adapter uses a minimal serializer + an
 * "append-or-replace section" regex strategy:
 *
 *   - To write: emit the full `[mcp_servers.<name>] ...` block and
 *     any sub-tables (currently just `.env`) as a contiguous chunk.
 *   - To merge with an existing file: regex-delete any prior chunk
 *     for the same server name (top-level table + its sub-tables),
 *     then append the new chunk.
 *
 * Constraints (deliberately narrow — we own ALL writes via this
 * adapter, so we never have to round-trip arbitrary user TOML):
 *
 *   - string keys only
 *   - values: string, string[]
 *   - sub-tables: one level deep (just `.env`)
 *   - no inline tables, no datetime, no float, no bool
 *
 * If a customer hand-edits the file with richer TOML, those edits
 * are preserved verbatim because we only touch our own
 * `[mcp_servers.<name>]` sections by name.
 *
 * @license MIT
 */

import { existsSync } from 'node:fs';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';

import { userHome } from './home.js';
import type { ClientWriter, McpServerConfig } from './index.js';

function configPath(): string {
    return join(userHome(), '.codex', 'config.toml');
}

/**
 * Escape a string for use as a TOML basic-string value (between
 * double quotes). Handles backslash, double-quote, and control
 * characters via TOML's standard escape sequences.
 */
function escapeTomlString(input: string): string {
    let out = '';
    for (const ch of input) {
        const code = ch.codePointAt(0) ?? 0;
        if (ch === '\\') out += '\\\\';
        else if (ch === '"') out += '\\"';
        else if (ch === '\b') out += '\\b';
        else if (ch === '\t') out += '\\t';
        else if (ch === '\n') out += '\\n';
        else if (ch === '\f') out += '\\f';
        else if (ch === '\r') out += '\\r';
        else if (code < 0x20)
            out += '\\u' + code.toString(16).padStart(4, '0').toUpperCase();
        else out += ch;
    }
    return out;
}

/**
 * Bare key validity per TOML spec: ASCII letters, digits, underscore,
 * dash. Anything else gets quoted.
 */
function tomlKey(key: string): string {
    return /^[A-Za-z0-9_-]+$/.test(key) ? key : `"${escapeTomlString(key)}"`;
}

function tomlStringValue(value: string): string {
    return `"${escapeTomlString(value)}"`;
}

function tomlStringArray(values: readonly string[]): string {
    return '[' + values.map((v) => tomlStringValue(v)).join(', ') + ']';
}

/**
 * Render the `[mcp_servers.<name>]` block plus a `[mcp_servers.<name>.env]`
 * sub-table when the env map is non-empty. Always ends with a trailing
 * blank line so successive sections are visually separated.
 */
function renderServerBlock(serverName: string, config: McpServerConfig): string {
    const nameKey = tomlKey(serverName);
    const lines: string[] = [];
    lines.push(`[mcp_servers.${nameKey}]`);
    lines.push(`command = ${tomlStringValue(config.command)}`);
    lines.push(`args = ${tomlStringArray(config.args)}`);
    const envKeys = Object.keys(config.env);
    if (envKeys.length === 0) {
        lines.push('');
        return lines.join('\n') + '\n';
    }
    lines.push('');
    lines.push(`[mcp_servers.${nameKey}.env]`);
    for (const key of envKeys) {
        const v = config.env[key];
        if (typeof v !== 'string') continue;
        lines.push(`${tomlKey(key)} = ${tomlStringValue(v)}`);
    }
    lines.push('');
    return lines.join('\n') + '\n';
}

/**
 * Strip any prior `[mcp_servers.<name>]` (and its `[mcp_servers.<name>.<sub>]`
 * children) from an existing TOML body. We anchor on the literal table
 * header and consume up to the next top-level `[...]` header or EOF.
 *
 * Regex notes:
 *   - `^` with `m` flag so the table header must start a line
 *   - `[\s\S]*?` lazy so we stop at the next header
 *   - `(?=\n\[[^.])` lookahead: next `[xxx]` that is NOT a sub-table
 *     of our removed section (sub-tables start with `[mcp_servers.<name>.`,
 *     containing a dot — so we want the next `[` followed by a non-`m`
 *     OR more conservatively, any `[` that doesn't start a sub-table).
 *
 * To keep this readable we just delete `[mcp_servers.<name>]` and any
 * `[mcp_servers.<name>.*]` blocks individually.
 */
function stripExistingSection(body: string, serverName: string): string {
    // Build matchers for both bare and quoted forms of the server key.
    const bare = /^[A-Za-z0-9_-]+$/.test(serverName) ? serverName : null;
    const quoted = `"${escapeTomlString(serverName)}"`;
    const keyAlternatives = bare ? [bare, quoted] : [quoted];

    let result = body;
    for (const k of keyAlternatives) {
        // Escape regex specials in the key (quoted form contains backslashes
        // for embedded quotes — escape those for the regex itself).
        const kEsc = k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        // Match `[mcp_servers.<key>]` or `[mcp_servers.<key>.<sub>]` and
        // everything up to the next top-level table header or EOF.
        // A top-level header is `[mcp_servers.X]` (X != <key>) or `[anything]`
        // that isn't our sub-table. To keep this simple we stop at the next
        // line starting with `[` that isn't a sub-table of our key.
        const re = new RegExp(
            `(^|\\n)\\[mcp_servers\\.${kEsc}(?:\\.[^\\]]+)?\\][^\\[]*?(?=\\n\\[|$)`,
            'g',
        );
        result = result.replace(re, (match, leading: string) => leading);
    }
    // Collapse 3+ consecutive newlines to 2 so deletions don't leave gaps.
    result = result.replace(/\n{3,}/g, '\n\n');
    return result;
}

/**
 * Detection: the `~/.codex/` directory exists. Codex CLI creates it
 * during first-run auth (`codex login`), even before the user has
 * configured any MCP servers.
 */
function isDetected(): boolean {
    return existsSync(join(userHome(), '.codex'));
}

export const codexCliClient: ClientWriter = {
    id: 'codex-cli',
    label: 'Codex CLI',
    configPath,
    isDetected,
    apply: async (serverName: string, config: McpServerConfig) => {
        const path = configPath();
        let existing = '';
        if (existsSync(path)) {
            try {
                existing = await readFile(path, 'utf-8');
            } catch {
                existing = '';
            }
        } else {
            await mkdir(dirname(path), { recursive: true });
        }

        const stripped = stripExistingSection(existing, serverName);
        // Ensure a clean separator before our new section if there is
        // pre-existing content that doesn't already end with a blank line.
        let prefix = stripped;
        if (prefix.length > 0 && !prefix.endsWith('\n\n')) {
            prefix = prefix.endsWith('\n') ? prefix + '\n' : prefix + '\n\n';
        }
        const block = renderServerBlock(serverName, config);
        const next = prefix + block;
        await writeFile(path, next, 'utf-8');
    },
};
