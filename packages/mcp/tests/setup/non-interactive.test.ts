/**
 * Tests for the non-interactive CLI surface of the setup wizard.
 *
 * R3-A6-I2: README:168-179 documents an exit-code contract that
 * suggests scripted use, but the wizard only had clack prompts.
 * Non-interactive mode lets CI / scripted callers pass everything
 * via flags and skip all prompts.
 *
 * Flags:
 *   --non-interactive          (required to opt-in)
 *   --client <id>              (repeatable; at least one required)
 *   --url <wp-url>             (required)
 *   --token <bearer>           (required)
 *
 * Behaviour:
 *   - missing required field → returns 2 (consistent with health-fail
 *     exit code, which is also "preflight blocked the run")
 *   - unknown --client id     → returns 2 with a clear error
 *   - all required present    → calls into `runWizard` with a deps bag
 *     whose `prompt` returns the parsed answers synchronously and whose
 *     `confirmContinue` always returns false (no human to ask)
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { parseSetupArgs, runCli } from '../../src/setup-cli.js';

describe('parseSetupArgs — flag parser', () => {
    it('returns interactive mode when no flags are passed', () => {
        const parsed = parseSetupArgs([]);
        expect(parsed.nonInteractive).toBe(false);
    });

    it('parses --non-interactive plus required fields', () => {
        const parsed = parseSetupArgs([
            '--non-interactive',
            '--url', 'https://example.com',
            '--token', 'KSEC-abcdef0123456789',
            '--client', 'cursor',
        ]);
        expect(parsed.nonInteractive).toBe(true);
        expect(parsed.url).toBe('https://example.com');
        expect(parsed.token).toBe('KSEC-abcdef0123456789');
        expect(parsed.clients).toEqual(['cursor']);
        expect(parsed.errors).toEqual([]);
    });

    it('accepts repeated --client flags', () => {
        const parsed = parseSetupArgs([
            '--non-interactive',
            '--client', 'cursor',
            '--client', 'claude-desktop',
            '--client', 'cline',
            '--url', 'https://example.com',
            '--token', 'KSEC-abcdef0123456789',
        ]);
        expect(parsed.clients).toEqual(['cursor', 'claude-desktop', 'cline']);
    });

    it('accepts --key=value style as well', () => {
        const parsed = parseSetupArgs([
            '--non-interactive',
            '--url=https://example.com',
            '--token=KSEC-abcdef0123456789',
            '--client=cursor',
        ]);
        expect(parsed.url).toBe('https://example.com');
        expect(parsed.token).toBe('KSEC-abcdef0123456789');
        expect(parsed.clients).toEqual(['cursor']);
    });

    it('records an error when --non-interactive is set but --url is missing', () => {
        const parsed = parseSetupArgs([
            '--non-interactive',
            '--token', 'KSEC-abcdef0123456789',
            '--client', 'cursor',
        ]);
        expect(parsed.errors.join('|')).toContain('--url');
    });

    it('records an error when --non-interactive is set but --token is missing', () => {
        const parsed = parseSetupArgs([
            '--non-interactive',
            '--url', 'https://example.com',
            '--client', 'cursor',
        ]);
        expect(parsed.errors.join('|')).toContain('--token');
    });

    it('records an error when --non-interactive is set but no --client is given', () => {
        const parsed = parseSetupArgs([
            '--non-interactive',
            '--url', 'https://example.com',
            '--token', 'KSEC-abcdef0123456789',
        ]);
        expect(parsed.errors.join('|')).toContain('--client');
    });

    it('records an error when an unknown --client id is passed', () => {
        const parsed = parseSetupArgs([
            '--non-interactive',
            '--url', 'https://example.com',
            '--token', 'KSEC-abcdef0123456789',
            '--client', 'bogus',
        ]);
        expect(parsed.errors.join('|')).toContain('bogus');
    });

    it('records an error when a flag value is missing', () => {
        const parsed = parseSetupArgs([
            '--non-interactive',
            '--url',
        ]);
        expect(parsed.errors.length).toBeGreaterThan(0);
    });
});

describe('runCli setup --non-interactive — wiring', () => {
    it('returns 2 and prints help when required flag is missing', async () => {
        const out: string[] = [];
        const err: string[] = [];
        const wizard = vi.fn(async () => 0);

        const code = await runCli(
            ['setup', '--non-interactive', '--url', 'https://example.com'],
            {
                runWizard: wizard,
                log: (s: string) => out.push(s),
                error: (s: string) => err.push(s),
            },
        );
        expect(code).toBe(2);
        expect(wizard).not.toHaveBeenCalled();
        expect(err.join('\n')).toContain('--token');
    });

    it('invokes runWizard with prebuilt answers when --non-interactive flags are valid', async () => {
        const wizard = vi.fn(async () => 0);
        const code = await runCli(
            [
                'setup',
                '--non-interactive',
                '--url', 'https://example.com',
                '--token', 'KSEC-abcdef0123456789',
                '--client', 'cursor',
            ],
            { runWizard: wizard, log: () => {}, error: () => {} },
        );
        expect(code).toBe(0);
        expect(wizard).toHaveBeenCalledOnce();
        // runCli should pass a deps bag in non-interactive mode; the
        // dispatcher signature accepts an optional WizardDeps.
        const [depsArg] = wizard.mock.calls[0]!;
        expect(depsArg).toBeDefined();
        expect(typeof (depsArg as { prompt: unknown }).prompt).toBe('function');
    });

    it('the prebuilt prompt resolves to the parsed answers without prompting', async () => {
        let capturedDeps: unknown = null;
        const wizard = vi.fn(async (deps?: unknown) => {
            capturedDeps = deps;
            return 0;
        });
        await runCli(
            [
                'setup',
                '--non-interactive',
                '--url', 'https://example.com/',  // trailing slash
                '--token', '  KSEC-abcdef0123456789  ',
                '--client', 'cursor',
                '--client', 'claude-desktop',
            ],
            { runWizard: wizard, log: () => {}, error: () => {} },
        );
        const deps = capturedDeps as {
            prompt: (i: { detected: never[] }) => Promise<{
                wpUrl: string;
                bearer: string;
                selectedClients: string[];
            } | null>;
            confirmContinue: (m: string) => Promise<boolean>;
        };
        const answers = await deps.prompt({ detected: [] });
        expect(answers).not.toBeNull();
        expect(answers!.wpUrl).toBe('https://example.com');
        expect(answers!.bearer).toBe('KSEC-abcdef0123456789');
        expect(answers!.selectedClients).toEqual(['cursor', 'claude-desktop']);
        // confirmContinue must return false (no human to ask) so probe
        // failures abort instead of hanging.
        expect(await deps.confirmContinue('continue?')).toBe(false);
    });
});
