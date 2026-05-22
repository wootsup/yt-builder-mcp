/**
 * Claude Desktop config writer.
 *
 *   macOS:    ~/Library/Application Support/Claude/claude_desktop_config.json
 *   Windows:  %APPDATA%\Claude\claude_desktop_config.json
 *   Linux:    ~/.config/Claude/claude_desktop_config.json (unofficial)
 *
 * Schema:
 *   {
 *     "mcpServers": {
 *       "<name>": { "command": "npx", "args": [...], "env": {...} }
 *     }
 *   }
 *
 * @license MIT
 */

import { existsSync } from 'node:fs';
import { platform } from 'node:os';
import { join } from 'node:path';

import { userHome } from './home.js';
import { patchJsonFile, type ClientWriter, type McpServerConfig } from './index.js';

function configPath(): string {
    const home = userHome();
    switch (platform()) {
        case 'darwin':
            return join(home, 'Library', 'Application Support', 'Claude', 'claude_desktop_config.json');
        case 'win32': {
            const appData = process.env.APPDATA ?? join(home, 'AppData', 'Roaming');
            return join(appData, 'Claude', 'claude_desktop_config.json');
        }
        default:
            return join(home, '.config', 'Claude', 'claude_desktop_config.json');
    }
}

export const claudeDesktopClient: ClientWriter = {
    id: 'claude-desktop',
    label: 'Claude Desktop',
    configPath,
    isDetected: () => existsSync(configPath()),
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
