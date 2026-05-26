/**
 * CLI dispatcher tests — verify `runCli()` routes to the right
 * subcommand handler for every supported argv shape.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { runCli } from '../../src/setup-cli.js';
import { SERVER_VERSION } from '../../src/server.js';

function captureStreams() {
    const out: string[] = [];
    const err: string[] = [];
    return {
        out,
        err,
        log: (s: string) => out.push(s),
        error: (s: string) => err.push(s),
    };
}

describe('runCli — default (setup) routing', () => {
    it('routes empty argv to the wizard', async () => {
        const wizard = vi.fn(async () => 0);
        const code = await runCli([], { runWizard: wizard });
        expect(code).toBe(0);
        expect(wizard).toHaveBeenCalledOnce();
    });

    it('routes "setup" subcommand to the wizard', async () => {
        const wizard = vi.fn(async () => 42);
        const code = await runCli(['setup'], { runWizard: wizard });
        expect(code).toBe(42);
        expect(wizard).toHaveBeenCalledOnce();
    });

    it('propagates the wizard exit code unchanged', async () => {
        const wizard = vi.fn(async () => 130);
        const code = await runCli(['setup'], { runWizard: wizard });
        expect(code).toBe(130);
    });
});

describe('runCli — install-skill routing', () => {
    it('routes "install-skill" to the installSkill dep and returns 0', async () => {
        const streams = captureStreams();
        const install = vi.fn(async () => ({
            copied: true,
            markerAlreadyPresent: false,
            skillTargetDir: '/home/me/.claude/skills/yt-builder-mcp',
            agentsFile: '/home/me/AGENTS.md',
        }));
        const code = await runCli(['install-skill'], {
            installSkill: install,
            log: streams.log,
            error: streams.error,
        });
        expect(code).toBe(0);
        expect(install).toHaveBeenCalledOnce();
        expect(streams.out.join('\n')).toContain('skill installed');
        expect(streams.out.join('\n')).toContain('yt-builder-mcp');
    });

    it('reports "refreshed" message when marker is already present', async () => {
        const streams = captureStreams();
        const install = vi.fn(async () => ({
            copied: true,
            markerAlreadyPresent: true,
            skillTargetDir: '/x',
            agentsFile: '/y',
        }));
        await runCli(['install-skill'], {
            installSkill: install,
            log: streams.log,
            error: streams.error,
        });
        expect(streams.out.join('\n')).toContain('refreshed');
    });

    it('returns 2 when installSkill throws', async () => {
        const streams = captureStreams();
        const install = vi.fn(async () => {
            throw new Error('cp failed');
        });
        const code = await runCli(['install-skill'], {
            installSkill: install,
            log: streams.log,
            error: streams.error,
        });
        expect(code).toBe(2);
        expect(streams.err.join('\n')).toContain('install-skill failed');
        expect(streams.err.join('\n')).toContain('cp failed');
    });

    it('accepts the "install" alias', async () => {
        const install = vi.fn(async () => ({
            copied: true,
            markerAlreadyPresent: false,
            skillTargetDir: '/x',
            agentsFile: '/y',
        }));
        const code = await runCli(['install'], {
            installSkill: install,
            log: () => {},
            error: () => {},
        });
        expect(code).toBe(0);
    });
});

describe('runCli — help / version', () => {
    it('prints help on "help"', async () => {
        const streams = captureStreams();
        const code = await runCli(['help'], { log: streams.log, error: streams.error });
        expect(code).toBe(0);
        expect(streams.out.join('\n')).toContain('Usage:');
        expect(streams.out.join('\n')).toContain('install-skill');
    });

    it('prints help on "--help" and "-h"', async () => {
        for (const flag of ['--help', '-h']) {
            const streams = captureStreams();
            const code = await runCli([flag], { log: streams.log, error: streams.error });
            expect(code).toBe(0);
            expect(streams.out.join('\n')).toContain('Usage:');
        }
    });

    it('prints the package version on "--version" and "-v"', async () => {
        for (const flag of ['--version', '-v']) {
            const streams = captureStreams();
            const code = await runCli([flag], { log: streams.log, error: streams.error });
            expect(code).toBe(0);
            expect(streams.out.join('\n').trim()).toBe(SERVER_VERSION);
        }
    });
});

describe('runCli — unknown command', () => {
    it('returns 1 and prints help to stderr', async () => {
        const streams = captureStreams();
        const code = await runCli(['bogus'], { log: streams.log, error: streams.error });
        expect(code).toBe(1);
        expect(streams.err.join('\n')).toContain('unknown command');
        expect(streams.err.join('\n')).toContain('bogus');
        expect(streams.err.join('\n')).toContain('Usage:');
    });
});
