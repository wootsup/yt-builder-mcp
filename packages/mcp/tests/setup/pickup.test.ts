/**
 * Tests for the Wave-C pickup mode (--pickup + --nonce flags).
 *
 * The pickup mode lets the customer copy a single `npx ... setup --pickup
 * <URL> --nonce <CODE> --client <id>` command from wp-admin's Reveal-Box
 * into their AI client's Bash tool. The wizard POSTs the nonce to the
 * pickup endpoint, receives a freshly-minted Bearer + canonical WordPress
 * URL in response, and proceeds with the normal handshake → writeClient
 * sequence — without ever taking the token through the chat history.
 *
 * Test surface:
 *   - parseSetupArgs flag parsing (3 cases)
 *   - buildPickupDeps prompt-synthesis (4 cases)
 *   - runCli dispatcher routing into pickup mode (3 cases)
 *   - defaultFetchPickup HTTP-status mapping (5 cases)
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import {
    buildPickupDeps,
    parseSetupArgs,
    runCli,
    type ParsedSetupArgs,
} from '../../src/setup-cli.js';
import { defaultFetchPickup } from '../../src/setup-wizard-defaults.js';
import type {
    DetectedClient,
    McpServerConfig,
} from '../../src/clients/index.js';
import type {
    PickupResult,
    WizardAnswers,
    WizardDeps,
    WriteResult,
} from '../../src/setup-wizard-types.js';

// ── parseSetupArgs ───────────────────────────────────────────────────────

describe('parseSetupArgs — pickup mode', () => {
    it('parses --pickup + --nonce + --client into the pickup-fields', () => {
        const parsed = parseSetupArgs([
            '--pickup', 'https://example.com/wp-json/yt-builder-mcp/v1/setup/pickup',
            '--nonce', 'AbCdEf0123456789012345678901234567890123456',
            '--client', 'claude-desktop',
        ]);
        expect(parsed.pickup).toBe('https://example.com/wp-json/yt-builder-mcp/v1/setup/pickup');
        expect(parsed.nonce).toBe('AbCdEf0123456789012345678901234567890123456');
        expect(parsed.clients).toEqual(['claude-desktop']);
        expect(parsed.errors).toEqual([]);
    });

    it('rejects --pickup without --nonce (and vice versa)', () => {
        const a = parseSetupArgs(['--pickup', 'https://x', '--client', 'cursor']);
        expect(a.errors).toContain('--nonce is required when --pickup is given.');

        const b = parseSetupArgs(['--nonce', 'ABCD', '--client', 'cursor']);
        expect(b.errors).toContain('--pickup is required when --nonce is given.');
    });

    it('warns (but does NOT error) when --url or --token are passed alongside --pickup', () => {
        const parsed = parseSetupArgs([
            '--pickup', 'https://x.example.com/p',
            '--nonce', 'ABCD0123456789ABCD0123456789ABCD0123',
            '--url', 'https://manually.example.com',
            '--token', 'ytb_live_AAA.BBB',
            '--client', 'cursor',
        ]);
        expect(parsed.errors).toEqual([]);
        expect(parsed.warnings).toEqual([
            '--url is ignored in pickup mode (the plugin returns the canonical URL).',
            '--token is ignored in pickup mode (the plugin returns the Bearer token).',
        ]);
    });

    it('rejects pickup mode without --client', () => {
        const parsed = parseSetupArgs([
            '--pickup', 'https://x',
            '--nonce', 'ABCD0123456789ABCD0123456789ABCD0123',
        ]);
        expect(parsed.errors).toContain('--client is required with --pickup (at least one).');
    });

    it('rejects unknown --client id in pickup mode', () => {
        const parsed = parseSetupArgs([
            '--pickup', 'https://x',
            '--nonce', 'ABCD0123456789ABCD0123456789ABCD0123',
            '--client', 'totally-bogus',
        ]);
        expect(parsed.errors.some((e) => e.includes('Unknown --client id "totally-bogus"'))).toBe(true);
    });
});

// ── buildPickupDeps ──────────────────────────────────────────────────────

function makeStubBase(): WizardDeps {
    return {
        prompt: async () => null,
        detectClients: (): readonly DetectedClient[] => [],
        probeHealth: async () => ({ ok: true }),
        probeAuth: async () => ({ ok: true }),
        confirmContinue: async () => false,
        writeClient: async (id: string): Promise<WriteResult> => ({
            id,
            label: id,
            ok: true,
            path: `/tmp/${id}.json`,
            previousContent: null,
        }),
        handshake: async () => ({ ok: true, pluginVersion: '0.2.0-alpha.1' }),
    };
}

function makeParsed(over: Partial<ParsedSetupArgs> = {}): ParsedSetupArgs {
    return {
        nonInteractive: false,
        url: '',
        token: '',
        pickup: 'https://example.com/wp-json/yt-builder-mcp/v1/setup/pickup',
        nonce: 'ABCD0123456789ABCD0123456789ABCD0123',
        clients: ['claude-desktop'],
        errors: [],
        warnings: [],
        ...over,
    } as ParsedSetupArgs;
}

describe('buildPickupDeps', () => {
    it('synthesises WizardAnswers from fetchPickup result', async () => {
        const fetchPickup = vi.fn(async () => ({
            token: 'ytb_live_PAYLOAD.SIG',
            siteurl: 'https://canonical.example.com',
            pluginVersion: '0.2.0-alpha.1',
        }));
        const base: WizardDeps = { ...makeStubBase(), fetchPickup };
        const deps = buildPickupDeps(makeParsed(), base);

        const answers = await deps.prompt({ detected: [] });

        expect(fetchPickup).toHaveBeenCalledWith(
            'https://example.com/wp-json/yt-builder-mcp/v1/setup/pickup',
            'ABCD0123456789ABCD0123456789ABCD0123',
        );
        expect(answers).toEqual<WizardAnswers>({
            wpUrl: 'https://canonical.example.com',
            bearer: 'ytb_live_PAYLOAD.SIG',
            selectedClients: ['claude-desktop'],
        });
    });

    it('returns null on fetchPickup error so wizard exits cleanly', async () => {
        const logs: string[] = [];
        const fetchPickup = vi.fn(async (): Promise<PickupResult> => {
            throw new Error('Pickup not available. The URL may have expired.');
        });
        const base: WizardDeps = {
            ...makeStubBase(),
            fetchPickup,
            log: (line) => logs.push(line),
        };
        const deps = buildPickupDeps(makeParsed(), base);

        const answers = await deps.prompt({ detected: [] });
        expect(answers).toBeNull();
        expect(logs.some((l) => l.includes('Pickup failed'))).toBe(true);
        expect(logs.some((l) => l.includes('URL may have expired'))).toBe(true);
    });

    it('returns null when fetchPickup is missing from the base deps', async () => {
        const logs: string[] = [];
        const base: WizardDeps = {
            ...makeStubBase(),
            log: (line) => logs.push(line),
        };
        // fetchPickup deliberately omitted.
        const deps = buildPickupDeps(makeParsed(), base);

        const answers = await deps.prompt({ detected: [] });
        expect(answers).toBeNull();
        expect(logs.some((l) => l.includes('no fetchPickup'))).toBe(true);
    });

    it('confirmContinue always resolves false (no human to ask)', async () => {
        const base = { ...makeStubBase(), fetchPickup: async () => ({ token: 't', siteurl: 'u', pluginVersion: 'v' }) };
        const deps = buildPickupDeps(makeParsed(), base);
        expect(await deps.confirmContinue('does it matter?')).toBe(false);
    });
});

// ── runCli dispatcher routing ─────────────────────────────────────────────

describe('runCli — pickup-mode routing', () => {
    it('routes --pickup + --nonce through the wizard with pickup deps', async () => {
        // The dispatcher must call wizard() with a non-undefined deps bag
        // (pickup mode short-circuits the default deps path). We don't need
        // to invoke prompt() inside the mock — buildPickupDeps' contract is
        // covered by the dedicated buildPickupDeps describe block above.
        const wizard = vi.fn(async (deps?: WizardDeps) => {
            expect(deps).toBeDefined();
            // Sanity: pickup deps override `prompt` + `confirmContinue`.
            expect(typeof deps!.prompt).toBe('function');
            expect(typeof deps!.confirmContinue).toBe('function');
            return 0;
        });

        const rc = await runCli(
            [
                'setup',
                '--pickup', 'https://x.example.com/p',
                '--nonce', 'ABCD0123456789ABCD0123456789ABCD0123',
                '--client', 'claude-desktop',
            ],
            { runWizard: wizard, error: () => {} },
        );

        expect(rc).toBe(0);
        expect(wizard).toHaveBeenCalledTimes(1);
    });

    it('emits warnings to stderr when --url/--token are passed alongside --pickup', async () => {
        const stderr: string[] = [];
        const rc = await runCli(
            [
                'setup',
                '--pickup', 'https://x.example.com/p',
                '--nonce', 'ABCD0123456789ABCD0123456789ABCD0123',
                '--url', 'https://manually.example.com',
                '--client', 'claude-desktop',
            ],
            {
                runWizard: async () => 0,
                error: (s) => stderr.push(s),
            },
        );

        expect(rc).toBe(0);
        expect(stderr.some((l) => l.includes('--url is ignored in pickup mode'))).toBe(true);
    });

    it('returns exit-2 when pickup-mode validation fails', async () => {
        const stderr: string[] = [];
        const rc = await runCli(
            [
                'setup',
                '--pickup', 'https://x.example.com/p',
                // missing --nonce + missing --client → 2 errors
            ],
            {
                runWizard: async () => 0,
                error: (s) => stderr.push(s),
            },
        );

        expect(rc).toBe(2);
        expect(stderr.some((l) => l.includes('--nonce is required'))).toBe(true);
        expect(stderr.some((l) => l.includes('--client is required'))).toBe(true);
    });
});

// ── defaultFetchPickup — HTTP status mapping ─────────────────────────────

describe('defaultFetchPickup', () => {
    function mockFetch(status: number, body: unknown): void {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any -- vi typing for global fetch is awkward
        (globalThis as any).fetch = vi.fn(async () =>
            new Response(JSON.stringify(body), {
                status,
                headers: { 'Content-Type': 'application/json' },
            }),
        );
    }

    it('returns PickupResult on 200', async () => {
        mockFetch(200, {
            token: 'ytb_live_PAYLOAD.SIG',
            site_url: 'https://example.com',
            plugin_version: '0.2.0-alpha.1',
        });
        const result = await defaultFetchPickup('https://x/p', 'ABCD');
        expect(result).toEqual({
            token: 'ytb_live_PAYLOAD.SIG',
            siteurl: 'https://example.com',
            pluginVersion: '0.2.0-alpha.1',
        });
    });

    it('throws on 404 (expired/consumed) with actionable message', async () => {
        mockFetch(404, { error: 'not_found', message: 'Pickup not available.' });
        await expect(defaultFetchPickup('https://x/p', 'ABCD')).rejects.toThrow(/expired.*5-minute TTL/);
    });

    it('throws on 403 (IP mismatch) with regenerate-hint', async () => {
        mockFetch(403, { error: 'ip_mismatch', message: 'IP mismatch.' });
        await expect(defaultFetchPickup('https://x/p', 'ABCD')).rejects.toThrow(/bound to a different IP/);
    });

    it('throws on 429 (rate limited)', async () => {
        mockFetch(429, { error: 'rate_limited', message: 'Too many.', retry_after: 60 });
        await expect(defaultFetchPickup('https://x/p', 'ABCD')).rejects.toThrow(/Rate limit/);
    });

    it('throws on 400 (malformed) and surfaces server message', async () => {
        mockFetch(400, { error: 'invalid_request', message: 'Missing nonce field.' });
        await expect(defaultFetchPickup('https://x/p', 'ABCD')).rejects.toThrow(/Missing nonce field/);
    });

    it('throws on network failure with actionable hint', async () => {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any -- mock global fetch
        (globalThis as any).fetch = vi.fn(async () => {
            throw new TypeError('fetch failed');
        });
        await expect(defaultFetchPickup('https://x/p', 'ABCD')).rejects.toThrow(/Could not reach the pickup URL/);
    });
});
