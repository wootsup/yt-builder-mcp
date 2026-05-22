/**
 * Cline MCP config writer.
 *
 * Cline ships as the VS Code extension `saoudrizwan.claude-dev`. Per
 * Cline's docs (`docs.cline.bot` "MCP / Configuration"), the per-user
 * MCP server registry lives at
 * `<vsCodeUserDir>/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json`
 *
 * VS Code's `<vsCodeUserDir>` is:
 *   macOS:    ~/Library/Application Support/Code/User
 *   Linux:    ~/.config/Code/User
 *   Windows:  %APPDATA%\Code\User
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
import { platform } from 'node:os';
import { join } from 'node:path';

import { userHome } from './home.js';
import { patchJsonFile, type ClientWriter, type McpServerConfig } from './index.js';

const EXTENSION_ID = 'saoudrizwan.claude-dev';
const SETTINGS_FILENAME = 'cline_mcp_settings.json';

function vsCodeUserDir(): string {
    const home = userHome();
    switch (platform()) {
        case 'darwin':
            return join(home, 'Library', 'Application Support', 'Code', 'User');
        case 'win32': {
            const appData = process.env.APPDATA ?? join(home, 'AppData', 'Roaming');
            return join(appData, 'Code', 'User');
        }
        default:
            return join(home, '.config', 'Code', 'User');
    }
}

function configPath(): string {
    return join(
        vsCodeUserDir(),
        'globalStorage',
        EXTENSION_ID,
        'settings',
        SETTINGS_FILENAME,
    );
}

/**
 * Detection: prefer the most concrete signal we can get without
 * triggering false-negatives on a fresh install. The extension's
 * globalStorage directory is created on first launch — checking for
 * its existence reliably distinguishes "Cline installed" from "VS
 * Code installed without Cline". As a fallback we accept the bare
 * VS Code user dir so the wizard at least surfaces the option.
 */
function isDetected(): boolean {
    const extDir = join(vsCodeUserDir(), 'globalStorage', EXTENSION_ID);
    return existsSync(extDir) || existsSync(configPath());
}

export const clineClient: ClientWriter = {
    id: 'cline',
    label: 'Cline (VS Code)',
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
