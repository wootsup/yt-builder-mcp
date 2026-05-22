/**
 * Roo Code MCP config writer.
 *
 * Roo Code (formerly Roo Cline) is the VS Code extension
 * `rooveterinaryinc.roo-cline` — a fork of Cline. It follows the
 * same VS Code globalStorage layout but its own settings file is
 * named `mcp_settings.json` (without the legacy `cline_` prefix
 * its parent uses).
 *
 *   <vsCodeUserDir>/globalStorage/rooveterinaryinc.roo-cline/settings/mcp_settings.json
 *
 * VS Code's `<vsCodeUserDir>` is:
 *   macOS:    ~/Library/Application Support/Code/User
 *   Linux:    ~/.config/Code/User
 *   Windows:  %APPDATA%\Code\User
 *
 * Schema mirrors Claude Desktop's `mcpServers` map.
 *
 * @license MIT
 */

import { existsSync } from 'node:fs';
import { platform } from 'node:os';
import { join } from 'node:path';

import { userHome } from './home.js';
import { patchJsonFile, type ClientWriter, type McpServerConfig } from './index.js';

const EXTENSION_ID = 'rooveterinaryinc.roo-cline';
const SETTINGS_FILENAME = 'mcp_settings.json';

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

function isDetected(): boolean {
    const extDir = join(vsCodeUserDir(), 'globalStorage', EXTENSION_ID);
    return existsSync(extDir) || existsSync(configPath());
}

export const rooCodeClient: ClientWriter = {
    id: 'roo-code',
    label: 'Roo Code (VS Code)',
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
