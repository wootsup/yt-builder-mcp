/**
 * Tests for client-config-writers.
 *
 * Uses a temp HOME directory so writes are sandboxed. Each writer's
 * `apply()` is idempotent and merges with existing config — both paths
 * are exercised.
 *
 * @license MIT
 */

import { existsSync, mkdtempSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    claudeCodeClient,
    claudeDesktopClient,
    clineClient,
    codexCliClient,
    continueClient,
    cursorClient,
    geminiCliClient,
    rooCodeClient,
    zedClient,
} from '../../src/clients/index.js';

let tmpHome: string;

beforeEach(() => {
    tmpHome = mkdtempSync(join(tmpdir(), 'ytbmcp-'));
    // The writers call `userHome()` which prefers HOME / USERPROFILE
    // over `os.homedir()` — see src/clients/home.ts for rationale.
    vi.stubEnv('HOME', tmpHome);
    vi.stubEnv('USERPROFILE', tmpHome);
    vi.stubEnv('APPDATA', join(tmpHome, 'AppData', 'Roaming'));
});

afterEach(() => {
    vi.unstubAllEnvs();
});

function readJson<T = unknown>(path: string): T {
    return JSON.parse(readFileSync(path, 'utf-8')) as T;
}

const FAKE_CONFIG = {
    command: 'npx',
    args: ['-y', '@wootsup/yt-builder-mcp'],
    env: { YTB_MCP_WP_URL: 'https://example.com', YTB_MCP_BEARER_TOKEN: 'tok' },
};

describe('claudeDesktopClient', () => {
    it('writes the config with our server entry', async () => {
        const path = claudeDesktopClient.configPath();

        await claudeDesktopClient.apply('yootheme-builder', FAKE_CONFIG);
        expect(existsSync(path)).toBe(true);

        const data = readJson<{ mcpServers: Record<string, unknown> }>(path);
        expect(data.mcpServers['yootheme-builder']).toEqual({
            command: 'npx',
            args: ['-y', '@wootsup/yt-builder-mcp'],
            env: { YTB_MCP_WP_URL: 'https://example.com', YTB_MCP_BEARER_TOKEN: 'tok' },
        });
    });

    it('merges with existing mcpServers', async () => {
        const path = claudeDesktopClient.configPath();
        mkdirSync(dirname(path), { recursive: true });
        writeFileSync(path, JSON.stringify({ mcpServers: { other: { command: 'foo' } } }));

        await claudeDesktopClient.apply('yootheme-builder', FAKE_CONFIG);
        const data = readJson<{ mcpServers: Record<string, unknown> }>(path);
        expect(data.mcpServers.other).toEqual({ command: 'foo' });
        expect(data.mcpServers['yootheme-builder']).toBeDefined();
    });

    it('overwrites the same server key on second apply (idempotent)', async () => {
        const path = claudeDesktopClient.configPath();
        await claudeDesktopClient.apply('yootheme-builder', FAKE_CONFIG);
        await claudeDesktopClient.apply('yootheme-builder', {
            ...FAKE_CONFIG,
            env: { YTB_MCP_WP_URL: 'https://example.com', YTB_MCP_BEARER_TOKEN: 'newtok' },
        });
        const data = readJson<{ mcpServers: Record<string, { env: Record<string, string> }> }>(
            path,
        );
        expect(data.mcpServers['yootheme-builder']!.env.YTB_MCP_BEARER_TOKEN).toBe('newtok');
    });
});

describe('cursorClient', () => {
    it('writes ~/.cursor/mcp.json with mcpServers map', async () => {
        await cursorClient.apply('yootheme-builder', FAKE_CONFIG);
        const path = cursorClient.configPath();
        const data = readJson<{ mcpServers: Record<string, unknown> }>(path);
        expect(data.mcpServers['yootheme-builder']).toBeDefined();
    });
});

describe('zedClient', () => {
    it('writes ~/.config/zed/settings.json with context_servers map', async () => {
        await zedClient.apply('yootheme-builder', FAKE_CONFIG);
        const path = zedClient.configPath();
        const data = readJson<{ context_servers: Record<string, { command: { path: string } }> }>(
            path,
        );
        expect(data.context_servers['yootheme-builder']!.command.path).toBe('npx');
    });
});

describe('continueClient', () => {
    it('writes ~/.continue/config.json with modelContextProtocolServers list', async () => {
        await continueClient.apply('yootheme-builder', FAKE_CONFIG);
        const path = continueClient.configPath();
        const data = readJson<{
            experimental: { modelContextProtocolServers: Array<{ name: string }> };
        }>(path);
        expect(data.experimental.modelContextProtocolServers).toHaveLength(1);
        expect(data.experimental.modelContextProtocolServers[0]!.name).toBe('yootheme-builder');
    });

    it('replaces an existing entry with the same name', async () => {
        await continueClient.apply('yootheme-builder', FAKE_CONFIG);
        await continueClient.apply('yootheme-builder', FAKE_CONFIG);
        const path = continueClient.configPath();
        const data = readJson<{
            experimental: { modelContextProtocolServers: Array<{ name: string }> };
        }>(path);
        expect(data.experimental.modelContextProtocolServers).toHaveLength(1);
    });
});

describe('clineClient', () => {
    it('has the expected id, label, and platform-specific path', () => {
        expect(clineClient.id).toBe('cline');
        expect(clineClient.label).toContain('Cline');
        const path = clineClient.configPath();
        // Path always ends with the cline_mcp_settings.json filename, regardless of platform.
        expect(path.endsWith('cline_mcp_settings.json')).toBe(true);
        // Contains the saoudrizwan.claude-dev extension namespace.
        expect(path).toContain('saoudrizwan.claude-dev');
    });

    it('writes the Cline MCP settings JSON with mcpServers map', async () => {
        await clineClient.apply('yootheme-builder', FAKE_CONFIG);
        const path = clineClient.configPath();
        expect(existsSync(path)).toBe(true);
        const data = readJson<{ mcpServers: Record<string, unknown> }>(path);
        expect(data.mcpServers['yootheme-builder']).toEqual({
            command: 'npx',
            args: ['-y', '@wootsup/yt-builder-mcp'],
            env: { YTB_MCP_WP_URL: 'https://example.com', YTB_MCP_BEARER_TOKEN: 'tok' },
        });
    });

    it('merges with existing mcpServers entries', async () => {
        const path = clineClient.configPath();
        mkdirSync(dirname(path), { recursive: true });
        writeFileSync(path, JSON.stringify({ mcpServers: { other: { command: 'foo' } } }));

        await clineClient.apply('yootheme-builder', FAKE_CONFIG);
        const data = readJson<{ mcpServers: Record<string, unknown> }>(path);
        expect(data.mcpServers.other).toEqual({ command: 'foo' });
        expect(data.mcpServers['yootheme-builder']).toBeDefined();
    });

    it('overwrites the same server key on second apply (idempotent)', async () => {
        await clineClient.apply('yootheme-builder', FAKE_CONFIG);
        await clineClient.apply('yootheme-builder', {
            ...FAKE_CONFIG,
            env: { YTB_MCP_WP_URL: 'https://example.com', YTB_MCP_BEARER_TOKEN: 'rotated' },
        });
        const path = clineClient.configPath();
        const data = readJson<{ mcpServers: Record<string, { env: Record<string, string> }> }>(
            path,
        );
        expect(data.mcpServers['yootheme-builder']!.env.YTB_MCP_BEARER_TOKEN).toBe('rotated');
    });
});

describe('rooCodeClient', () => {
    it('has the expected id, label, and platform-specific path', () => {
        expect(rooCodeClient.id).toBe('roo-code');
        expect(rooCodeClient.label).toContain('Roo');
        const path = rooCodeClient.configPath();
        expect(path.endsWith('mcp_settings.json')).toBe(true);
        // Contains the rooveterinaryinc.roo-cline extension namespace.
        expect(path.toLowerCase()).toContain('rooveterinaryinc.roo-cline');
    });

    it('writes the Roo Code MCP settings JSON with mcpServers map', async () => {
        await rooCodeClient.apply('yootheme-builder', FAKE_CONFIG);
        const path = rooCodeClient.configPath();
        expect(existsSync(path)).toBe(true);
        const data = readJson<{ mcpServers: Record<string, unknown> }>(path);
        expect(data.mcpServers['yootheme-builder']).toEqual({
            command: 'npx',
            args: ['-y', '@wootsup/yt-builder-mcp'],
            env: { YTB_MCP_WP_URL: 'https://example.com', YTB_MCP_BEARER_TOKEN: 'tok' },
        });
    });

    it('merges with existing mcpServers entries', async () => {
        const path = rooCodeClient.configPath();
        mkdirSync(dirname(path), { recursive: true });
        writeFileSync(path, JSON.stringify({ mcpServers: { other: { command: 'foo' } } }));

        await rooCodeClient.apply('yootheme-builder', FAKE_CONFIG);
        const data = readJson<{ mcpServers: Record<string, unknown> }>(path);
        expect(data.mcpServers.other).toEqual({ command: 'foo' });
        expect(data.mcpServers['yootheme-builder']).toBeDefined();
    });

    it('overwrites the same server key on second apply (idempotent)', async () => {
        await rooCodeClient.apply('yootheme-builder', FAKE_CONFIG);
        await rooCodeClient.apply('yootheme-builder', {
            ...FAKE_CONFIG,
            env: { YTB_MCP_WP_URL: 'https://example.com', YTB_MCP_BEARER_TOKEN: 'rotated' },
        });
        const path = rooCodeClient.configPath();
        const data = readJson<{ mcpServers: Record<string, { env: Record<string, string> }> }>(
            path,
        );
        expect(data.mcpServers['yootheme-builder']!.env.YTB_MCP_BEARER_TOKEN).toBe('rotated');
    });
});

describe('clineClient.isDetected', () => {
    it('returns false when neither the extension dir nor the settings file exist', () => {
        // tmpHome is freshly created; nothing under ~/Library/Application Support/Code/User
        expect(clineClient.isDetected()).toBe(false);
    });

    it('returns true once the extension globalStorage dir exists', () => {
        const path = clineClient.configPath();
        // Create the parent extension dir; isDetected should now be true
        // even before the settings file itself exists.
        mkdirSync(dirname(dirname(path)), { recursive: true });
        expect(clineClient.isDetected()).toBe(true);
    });

    it('returns true when the settings file exists directly', async () => {
        await clineClient.apply('yootheme-builder', FAKE_CONFIG);
        expect(clineClient.isDetected()).toBe(true);
    });
});

describe('rooCodeClient.isDetected', () => {
    it('returns false when neither dir nor file exist', () => {
        expect(rooCodeClient.isDetected()).toBe(false);
    });

    it('returns true once the settings file is written', async () => {
        await rooCodeClient.apply('yootheme-builder', FAKE_CONFIG);
        expect(rooCodeClient.isDetected()).toBe(true);
    });
});

describe('VS Code paths — platform-specific resolution', () => {
    afterEach(() => {
        vi.resetModules();
        vi.unmock('node:os');
    });

    it('cline resolves to exact %APPDATA%\\Code\\User\\globalStorage\\... on win32', async () => {
        vi.resetModules();
        vi.doMock('node:os', async () => {
            const actual = await vi.importActual<typeof import('node:os')>('node:os');
            return { ...actual, platform: () => 'win32' };
        });
        const { clineClient: winCline } = await import('../../src/clients/cline.js');
        const path = winCline.configPath();
        // H3 — TEST-PATH-1: exact-path equality. Previously this used 4
        // independent `toContain` assertions — a writer that resolved to
        // .../Cline-Other/saoudrizwan.claude-dev/cline_mcp_settings.json
        // would have passed despite the bogus middle segment. Exact equality
        // pins every path segment.
        const expected = join(
            tmpHome,
            'AppData',
            'Roaming',
            'Code',
            'User',
            'globalStorage',
            'saoudrizwan.claude-dev',
            'settings',
            'cline_mcp_settings.json',
        );
        expect(path).toBe(expected);
    });

    it('cline resolves to exact ~/.config/Code/User/globalStorage/... on linux', async () => {
        vi.resetModules();
        vi.doMock('node:os', async () => {
            const actual = await vi.importActual<typeof import('node:os')>('node:os');
            return { ...actual, platform: () => 'linux' };
        });
        const { clineClient: linuxCline } = await import('../../src/clients/cline.js');
        const path = linuxCline.configPath();
        const expected = join(
            tmpHome,
            '.config',
            'Code',
            'User',
            'globalStorage',
            'saoudrizwan.claude-dev',
            'settings',
            'cline_mcp_settings.json',
        );
        expect(path).toBe(expected);
    });

    it('roo-code resolves to exact %APPDATA%\\Code\\User\\globalStorage\\... on win32', async () => {
        vi.resetModules();
        vi.doMock('node:os', async () => {
            const actual = await vi.importActual<typeof import('node:os')>('node:os');
            return { ...actual, platform: () => 'win32' };
        });
        const { rooCodeClient: winRoo } = await import('../../src/clients/roo-code.js');
        const path = winRoo.configPath();
        const expected = join(
            tmpHome,
            'AppData',
            'Roaming',
            'Code',
            'User',
            'globalStorage',
            'rooveterinaryinc.roo-cline',
            'settings',
            'mcp_settings.json',
        );
        expect(path).toBe(expected);
    });

    it('roo-code resolves to exact ~/.config/Code/User/globalStorage/... on linux', async () => {
        vi.resetModules();
        vi.doMock('node:os', async () => {
            const actual = await vi.importActual<typeof import('node:os')>('node:os');
            return { ...actual, platform: () => 'linux' };
        });
        const { rooCodeClient: linuxRoo } = await import('../../src/clients/roo-code.js');
        const path = linuxRoo.configPath();
        const expected = join(
            tmpHome,
            '.config',
            'Code',
            'User',
            'globalStorage',
            'rooveterinaryinc.roo-cline',
            'settings',
            'mcp_settings.json',
        );
        expect(path).toBe(expected);
    });
});

describe('ALL_CLIENTS catalog', () => {
    it('exposes all 9 supported AI clients (Wave B added 3 in 2026-05-22)', async () => {
        const mod = await import('../../src/clients/index.js');
        const ids = mod.ALL_CLIENTS.map((c) => c.id).sort();
        expect(ids).toEqual(
            [
                'claude-code',
                'claude-desktop',
                'cline',
                'codex-cli',
                'continue',
                'cursor',
                'gemini-cli',
                'roo-code',
                'zed',
            ],
        );
    });
});
