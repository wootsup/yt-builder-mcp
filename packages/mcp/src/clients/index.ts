/**
 * Client-config-writers.
 *
 * Each supported AI client has its own module that knows
 *   - which config file to read/write
 *   - what JSON shape to inject under `mcpServers` (or equivalent)
 *
 * The setup wizard calls `detectAvailableClients()` to enumerate, then
 * `writeClientConfig({client, server})` once the user picks one.
 *
 * @license MIT
 */

import { existsSync } from 'node:fs';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';

import { claudeCodeClient } from './claude-code.js';
import { claudeDesktopClient } from './claude-desktop.js';
import { clineClient } from './cline.js';
import { codexCliClient } from './codex-cli.js';
import { continueClient } from './continue.js';
import { cursorClient } from './cursor.js';
import { geminiCliClient } from './gemini-cli.js';
import { rooCodeClient } from './roo-code.js';
import { zedClient } from './zed.js';

export interface McpServerConfig {
    readonly command: string;
    readonly args: readonly string[];
    readonly env: Record<string, string>;
}

export interface ClientWriter {
    readonly id: string;
    readonly label: string;
    /** Absolute path to the config file. */
    configPath: () => string;
    /** Returns true if the config file exists (client is "installed"). */
    isDetected: () => boolean;
    /** Apply the MCP server config to the file. Creates the file if missing. */
    apply: (serverName: string, config: McpServerConfig) => Promise<void>;
}

export const ALL_CLIENTS: readonly ClientWriter[] = [
    claudeDesktopClient,
    claudeCodeClient,
    cursorClient,
    zedClient,
    continueClient,
    clineClient,
    rooCodeClient,
    codexCliClient,
    geminiCliClient,
];

export interface DetectedClient {
    readonly id: string;
    readonly label: string;
    readonly configPath: string;
    readonly detected: boolean;
}

export function detectAvailableClients(): DetectedClient[] {
    return ALL_CLIENTS.map((c) => ({
        id: c.id,
        label: c.label,
        configPath: c.configPath(),
        detected: c.isDetected(),
    }));
}

export function findClient(id: string): ClientWriter | undefined {
    return ALL_CLIENTS.find((c) => c.id === id);
}

/**
 * Shared helper: read a JSON file, mutate via callback, write back.
 * Creates parent directories if missing.
 */
export async function patchJsonFile(
    path: string,
    mutator: (current: Record<string, unknown>) => Record<string, unknown>,
): Promise<void> {
    let current: Record<string, unknown> = {};
    if (existsSync(path)) {
        try {
            const raw = await readFile(path, 'utf-8');
            const parsed = raw.trim() === '' ? {} : (JSON.parse(raw) as unknown);
            if (parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)) {
                current = parsed as Record<string, unknown>;
            }
        } catch {
            current = {};
        }
    } else {
        await mkdir(dirname(path), { recursive: true });
    }

    const next = mutator(current);
    await writeFile(path, JSON.stringify(next, null, 2) + '\n', 'utf-8');
}

export {
    claudeCodeClient,
    claudeDesktopClient,
    clineClient,
    codexCliClient,
    continueClient,
    cursorClient,
    geminiCliClient,
    rooCodeClient,
    zedClient,
};
