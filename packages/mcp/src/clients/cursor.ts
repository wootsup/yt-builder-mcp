/**
 * Cursor MCP config writer.
 *
 *   Global: ~/.cursor/mcp.json
 *
 * Schema mirrors Claude Desktop's `mcpServers` map.
 *
 * @license MIT
 */

import { existsSync } from 'node:fs';
import { join } from 'node:path';

import { userHome } from './home.js';
import { patchJsonFile, type ClientWriter, type McpServerConfig } from './index.js';

function configPath(): string {
    return join(userHome(), '.cursor', 'mcp.json');
}

export const cursorClient: ClientWriter = {
    id: 'cursor',
    label: 'Cursor',
    configPath,
    isDetected: () => existsSync(join(userHome(), '.cursor')),
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
