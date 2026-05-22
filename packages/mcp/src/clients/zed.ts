/**
 * Zed MCP config writer.
 *
 *   Global: ~/.config/zed/settings.json  (JSONC; we treat as JSON for write)
 *
 * Schema: `context_servers` map (Zed-specific naming).
 *
 * @license MIT
 */

import { existsSync } from 'node:fs';
import { join } from 'node:path';

import { userHome } from './home.js';
import { patchJsonFile, type ClientWriter, type McpServerConfig } from './index.js';

function configPath(): string {
    return join(userHome(), '.config', 'zed', 'settings.json');
}

export const zedClient: ClientWriter = {
    id: 'zed',
    label: 'Zed',
    configPath,
    isDetected: () => existsSync(join(userHome(), '.config', 'zed')),
    apply: async (serverName: string, config: McpServerConfig) => {
        await patchJsonFile(configPath(), (current) => {
            const servers =
                current.context_servers !== null &&
                typeof current.context_servers === 'object' &&
                !Array.isArray(current.context_servers)
                    ? { ...(current.context_servers as Record<string, unknown>) }
                    : {};
            servers[serverName] = {
                command: {
                    path: config.command,
                    args: config.args,
                    env: config.env,
                },
                settings: {},
            };
            return { ...current, context_servers: servers };
        });
    },
};
