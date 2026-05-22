/**
 * Continue MCP config writer.
 *
 *   Global: ~/.continue/config.json
 *
 * Schema: `experimental.modelContextProtocolServers` array of
 *         { transport: { type: 'stdio', command, args, env } } entries.
 *
 * @license MIT
 */

import { existsSync } from 'node:fs';
import { join } from 'node:path';

import { userHome } from './home.js';
import { patchJsonFile, type ClientWriter, type McpServerConfig } from './index.js';

function configPath(): string {
    return join(userHome(), '.continue', 'config.json');
}

interface ContinueServerEntry {
    name: string;
    transport: {
        type: 'stdio';
        command: string;
        args: readonly string[];
        env: Record<string, string>;
    };
}

export const continueClient: ClientWriter = {
    id: 'continue',
    label: 'Continue',
    configPath,
    isDetected: () => existsSync(join(userHome(), '.continue')),
    apply: async (serverName: string, config: McpServerConfig) => {
        await patchJsonFile(configPath(), (current) => {
            const experimental =
                current.experimental !== null &&
                typeof current.experimental === 'object' &&
                !Array.isArray(current.experimental)
                    ? { ...(current.experimental as Record<string, unknown>) }
                    : {};
            const rawList = experimental.modelContextProtocolServers;
            const list: ContinueServerEntry[] = Array.isArray(rawList)
                ? (rawList.filter(
                      (e): e is ContinueServerEntry =>
                          e !== null &&
                          typeof e === 'object' &&
                          typeof (e as ContinueServerEntry).name === 'string',
                  ) as ContinueServerEntry[])
                : [];
            const entry: ContinueServerEntry = {
                name: serverName,
                transport: {
                    type: 'stdio',
                    command: config.command,
                    args: config.args,
                    env: config.env,
                },
            };
            const filtered = list.filter((e) => e.name !== serverName);
            filtered.push(entry);
            experimental.modelContextProtocolServers = filtered;
            return { ...current, experimental };
        });
    },
};
