/**
 * Wizard tests — drive `runWizard()` with a fully mocked WizardDeps
 * bag so we can verify exit codes + rollback behaviour without touching
 * real subprocesses, filesystems, or the network.
 *
 * @license MIT
 */

import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { DetectedClient, McpServerConfig } from '../../src/clients/index.js';
import {
    majorMinor,
    runWizard,
    type AuthProbeResult,
    type HandshakeResult,
    type HealthProbeResult,
    type WizardAnswers,
    type WizardDeps,
    type WriteResult,
} from '../../src/setup-cli.js';

let tmpHome: string;

beforeEach(() => {
    tmpHome = mkdtempSync(join(tmpdir(), 'ytbmcp-wizard-'));
});

afterEach(() => {
    vi.unstubAllEnvs();
});

const ANSWERS_OK: WizardAnswers = {
    wpUrl: 'https://example.com',
    bearer: 'KSEC-abcdef0123456789',
    selectedClients: ['claude-desktop'],
};

const DETECTED: DetectedClient[] = [
    {
        id: 'claude-desktop',
        label: 'Claude Desktop',
        configPath: '/tmp/never-touched.json',
        detected: true,
    },
];

function makeDeps(overrides: Partial<WizardDeps> = {}): WizardDeps {
    const written: WriteResult[] = [];
    return {
        prompt: vi.fn(async () => ANSWERS_OK),
        detectClients: () => DETECTED,
        probeHealth: vi.fn(async (): Promise<HealthProbeResult> => ({ ok: true, pluginVersion: '0.1.0' })),
        probeAuth: vi.fn(async (): Promise<AuthProbeResult> => ({ ok: true })),
        confirmContinue: vi.fn(async () => false),
        writeClient: vi.fn(
            async (id: string, _server: string, _config: McpServerConfig): Promise<WriteResult> => {
                const path = join(tmpHome, `${id}-config.json`);
                writeFileSync(path, '{"mcpServers":{"yootheme-builder":{}}}', 'utf-8');
                const r: WriteResult = {
                    id,
                    label: id,
                    ok: true,
                    path,
                    previousContent: null,
                };
                written.push(r);
                return r;
            },
        ),
        handshake: vi.fn(async (): Promise<HandshakeResult> => ({ ok: true })),
        log: () => {},
        ...overrides,
    };
}

describe('runWizard — happy path', () => {
    it('returns 0 when prompt, probes, write, and handshake all succeed', async () => {
        const deps = makeDeps();
        const code = await runWizard(deps);
        expect(code).toBe(0);
        expect(deps.prompt).toHaveBeenCalledOnce();
        expect(deps.probeHealth).toHaveBeenCalledWith('https://example.com');
        expect(deps.probeAuth).toHaveBeenCalledWith('https://example.com', 'KSEC-abcdef0123456789');
        expect(deps.writeClient).toHaveBeenCalledOnce();
        expect(deps.handshake).toHaveBeenCalledOnce();
    });

    it('writes the MCP entry env with the bearer and URL', async () => {
        const captured: McpServerConfig[] = [];
        const deps = makeDeps({
            writeClient: vi.fn(
                async (id: string, _name: string, config: McpServerConfig): Promise<WriteResult> => {
                    captured.push(config);
                    return { id, label: id, ok: true, path: '/tmp/x', previousContent: null };
                },
            ),
        });
        await runWizard(deps);
        expect(captured).toHaveLength(1);
        expect(captured[0]!.env.YTB_MCP_WP_URL).toBe('https://example.com');
        expect(captured[0]!.env.YTB_MCP_BEARER_TOKEN).toBe('KSEC-abcdef0123456789');
        expect(captured[0]!.command).toBe('npx');
    });
});

describe('runWizard — cancellation', () => {
    it('returns 130 when the user cancels at the prompt', async () => {
        const deps = makeDeps({ prompt: vi.fn(async () => null) });
        const code = await runWizard(deps);
        expect(code).toBe(130);
        expect(deps.probeHealth).not.toHaveBeenCalled();
    });

    it('returns 1 when answers contain empty wpUrl', async () => {
        const deps = makeDeps({
            prompt: vi.fn(async () => ({
                ...ANSWERS_OK,
                wpUrl: '',
            })),
        });
        const code = await runWizard(deps);
        expect(code).toBe(1);
    });
});

describe('runWizard — probe failures', () => {
    it('returns 2 when health probe fails and user declines to continue', async () => {
        const deps = makeDeps({
            probeHealth: vi.fn(async () => ({ ok: false, error: 'ECONNREFUSED' })),
            confirmContinue: vi.fn(async () => false),
        });
        const code = await runWizard(deps);
        expect(code).toBe(2);
        expect(deps.writeClient).not.toHaveBeenCalled();
    });

    it('returns 3 when auth probe fails and user declines to continue', async () => {
        const deps = makeDeps({
            probeAuth: vi.fn(async () => ({ ok: false, error: '401 Unauthorized' })),
            confirmContinue: vi.fn(async () => false),
        });
        const code = await runWizard(deps);
        expect(code).toBe(3);
        expect(deps.writeClient).not.toHaveBeenCalled();
    });

    it('proceeds when health probe fails but the user opts to continue', async () => {
        const deps = makeDeps({
            probeHealth: vi.fn(async () => ({ ok: false, error: 'whatever' })),
            confirmContinue: vi.fn(async () => true),
        });
        const code = await runWizard(deps);
        expect(code).toBe(0);
        expect(deps.writeClient).toHaveBeenCalledOnce();
    });
});

describe('runWizard — write failure + rollback', () => {
    it('returns 4 and rolls back partial writes on failure', async () => {
        // Two clients selected; first write succeeds, second fails.
        const tmpFile = join(tmpHome, 'cd.json');
        writeFileSync(tmpFile, '{"mcpServers":{"yootheme-builder":{"old":true}}}', 'utf-8');
        const previousContent = readFileSync(tmpFile, 'utf-8');

        let callCount = 0;
        const deps = makeDeps({
            prompt: vi.fn(async () => ({
                ...ANSWERS_OK,
                selectedClients: ['claude-desktop', 'cursor'],
            })),
            writeClient: vi.fn(async (id: string): Promise<WriteResult> => {
                callCount += 1;
                if (callCount === 1) {
                    // First succeeds — overwrite the existing file.
                    writeFileSync(tmpFile, '{"mcpServers":{"yootheme-builder":{"new":true}}}', 'utf-8');
                    return {
                        id,
                        label: id,
                        ok: true,
                        path: tmpFile,
                        previousContent,
                    };
                }
                return {
                    id,
                    label: id,
                    ok: false,
                    error: 'EACCES',
                    path: '/tmp/no-permission.json',
                    previousContent: null,
                };
            }),
        });

        const code = await runWizard(deps);
        expect(code).toBe(4);
        // Rollback should have restored the original content of the first file.
        expect(readFileSync(tmpFile, 'utf-8')).toBe(previousContent);
    });
});

describe('runWizard — dist-tag handshake mismatch', () => {
    it('returns 0 with a warning when major.minor mismatch is detected', async () => {
        const logged: string[] = [];
        const deps = makeDeps({
            handshake: vi.fn(async (): Promise<HandshakeResult> => ({
                ok: true,
                pluginVersion: '9.9.0',
                warning: 'MCP package v0.1.0-alpha.1 (0.1.x) ↔ plugin v9.9.0 (9.9.x) — major/minor mismatch.',
            })),
            log: (line: string) => logged.push(line),
        });
        const code = await runWizard(deps);
        expect(code).toBe(0);
        expect(deps.handshake).toHaveBeenCalledOnce();
    });

    it('returns 5 when handshake hard-fails (e.g. server stopped accepting key)', async () => {
        const tmpFile = join(tmpHome, 'cd.json');
        writeFileSync(tmpFile, '{"mcpServers":{"yootheme-builder":{"old":true}}}', 'utf-8');
        const previousContent = readFileSync(tmpFile, 'utf-8');

        const deps = makeDeps({
            writeClient: vi.fn(async (id: string): Promise<WriteResult> => {
                writeFileSync(tmpFile, '{"mcpServers":{"yootheme-builder":{"new":true}}}', 'utf-8');
                return {
                    id,
                    label: id,
                    ok: true,
                    path: tmpFile,
                    previousContent,
                };
            }),
            handshake: vi.fn(async () => ({ ok: false, error: 'plugin disappeared mid-write' })),
        });
        const code = await runWizard(deps);
        expect(code).toBe(5);
        // Rollback should have restored the pre-write content.
        expect(readFileSync(tmpFile, 'utf-8')).toBe(previousContent);
    });

    it('skips handshake when auth probe failed (and user continued anyway)', async () => {
        const deps = makeDeps({
            probeAuth: vi.fn(async () => ({ ok: false, error: '401' })),
            confirmContinue: vi.fn(async () => true),
        });
        const code = await runWizard(deps);
        expect(code).toBe(0);
        expect(deps.handshake).not.toHaveBeenCalled();
    });
});

describe('majorMinor helper', () => {
    it('parses standard semver', () => {
        expect(majorMinor('1.2.3')).toBe('1.2');
        expect(majorMinor('0.1.0')).toBe('0.1');
        expect(majorMinor('10.20.30')).toBe('10.20');
    });

    it('parses pre-release suffixes', () => {
        expect(majorMinor('0.1.0-alpha.1')).toBe('0.1');
        expect(majorMinor('1.0.0-rc.5+sha.abc')).toBe('1.0');
    });

    it('returns empty string for malformed input', () => {
        expect(majorMinor('')).toBe('');
        expect(majorMinor('not-a-version')).toBe('');
        expect(majorMinor('1')).toBe('');
    });
});

describe('rollback existing-file restoration', () => {
    it('rollback unlinks a fresh write when previousContent is null', async () => {
        const tmpFile = join(tmpHome, 'created.json');
        // Simulate: file did not exist before, the write created it.
        expect(existsSync(tmpFile)).toBe(false);

        const deps = makeDeps({
            prompt: vi.fn(async () => ({
                ...ANSWERS_OK,
                selectedClients: ['claude-desktop', 'cursor'],
            })),
            writeClient: vi.fn(async (id: string): Promise<WriteResult> => {
                // First write creates the file; second fails to trigger rollback.
                if (id === 'claude-desktop') {
                    writeFileSync(tmpFile, '{}', 'utf-8');
                    return {
                        id,
                        label: id,
                        ok: true,
                        path: tmpFile,
                        previousContent: null,
                    };
                }
                return {
                    id,
                    label: id,
                    ok: false,
                    error: 'boom',
                    path: '/tmp/never.json',
                    previousContent: null,
                };
            }),
        });
        const code = await runWizard(deps);
        expect(code).toBe(4);
        // Rollback should have removed the file the wizard created.
        expect(existsSync(tmpFile)).toBe(false);
    });
});
