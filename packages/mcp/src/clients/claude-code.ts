/**
 * Claude Code (Anthropic CLI) config writer.
 *
 *   All platforms: ~/.claude.json (single file)
 *
 * Schema mirrors Claude Desktop's `mcpServers` map:
 *
 *   {
 *     "mcpServers": {
 *       "<name>": { "command": "npx", "args": [...], "env": {...} }
 *     }
 *   }
 *
 * @license MIT
 */

import { existsSync } from 'node:fs';
import { join } from 'node:path';

import { userHome } from './home.js';
import { patchJsonFile, type ClientWriter, type McpServerConfig } from './index.js';

function configPath(): string {
    return join(userHome(), '.claude.json');
}

/**
 * Detection: either the user-config file already exists, or the
 * `~/.claude/` directory does (Claude Code creates it on first run
 * for skills, transcripts, etc., even before the JSON file lands).
 */
function isDetected(): boolean {
    const home = userHome();
    return existsSync(join(home, '.claude.json')) || existsSync(join(home, '.claude'));
}

export const claudeCodeClient: ClientWriter = {
    id: 'claude-code',
    label: 'Claude Code',
    configPath,
    isDetected,
    apply: async (serverName: string, config: McpServerConfig) => {
        await patchJsonFile(configPath(), (current) => {
            const servers =
                current.mcpServers !== null &&
                typeof current.mcpServers === 'object' &&
                !Array.isArray(current.mcpServers)
                    ? { ...(current.mcpServers as Record<string, unknown>) }
                    : {};
            servers[serverName] = {
                command: config.command,
                args: config.args,
                env: config.env,
            };
            return { ...current, mcpServers: servers };
        });
    },
};
