/**
 * Direct tests for `setup-prompts.ts` (defaultPrompt +
 * defaultConfirmContinue) via vi.mock on @clack/prompts.
 *
 * Wave 6.5 UX-parity refactor changed the prompt order to:
 *   1. password (bearer) → decode `iss` for URL pre-fill
 *   2. text     (WordPress URL, pre-filled when decode succeeded)
 *   3. text     (profile name)
 *   4. multiselect (AI clients)
 *
 * The mock therefore uses per-call queues so each `text()` invocation
 * resolves to its own scripted answer instead of one shared value.
 *
 * @license MIT
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { DetectedClient } from '../../src/clients/index.js';

const CANCEL = Symbol.for('clack:cancel');

interface PromptQueue {
    text: unknown[];
    password: unknown[];
    multiselect: unknown[];
    confirm: unknown[];
    // Validate-callback capture per-text-call (URL is call 0, profile is call 1)
    textValidateInputs: Map<number, string[]>;
    textValidateResults: Map<number, Array<unknown>>;
    passwordValidateInputs: string[];
    passwordValidateResults: Array<unknown>;
}

const queue: PromptQueue = {
    text: [],
    password: [],
    multiselect: [],
    confirm: [],
    textValidateInputs: new Map(),
    textValidateResults: new Map(),
    passwordValidateInputs: [],
    passwordValidateResults: [],
};

let textCallIndex = 0;
let passwordCallIndex = 0;

vi.mock('@clack/prompts', () => ({
    intro: vi.fn(),
    cancel: vi.fn(),
    isCancel: (v: unknown) => v === CANCEL,
    multiselect: vi.fn(async () => queue.multiselect.shift()),
    text: vi.fn(async (opts: { validate?: (v: string) => unknown }) => {
        const idx = textCallIndex++;
        const inputs = queue.textValidateInputs.get(idx);
        if (inputs !== undefined && opts.validate) {
            queue.textValidateResults.set(
                idx,
                inputs.map((s) => opts.validate!(s)),
            );
        }
        return queue.text.shift();
    }),
    password: vi.fn(async (opts: { validate?: (v: string) => unknown }) => {
        const idx = passwordCallIndex++;
        if (idx === 0 && queue.passwordValidateInputs.length > 0 && opts.validate) {
            queue.passwordValidateResults = queue.passwordValidateInputs.map(
                (s) => opts.validate!(s),
            );
        }
        return queue.password.shift();
    }),
    confirm: vi.fn(async () => queue.confirm.shift()),
}));

const { defaultPrompt, defaultConfirmContinue } = await import('../../src/setup-prompts.js');

const SAMPLE_DETECTED: readonly DetectedClient[] = [
    { id: 'claude-desktop', label: 'Claude Desktop', detected: true, configPath: '/path/cd' },
    { id: 'cursor', label: 'Cursor', detected: false, configPath: '/path/cursor' },
];

beforeEach(() => {
    queue.text = [];
    queue.password = [];
    queue.multiselect = [];
    queue.confirm = [];
    queue.textValidateInputs = new Map();
    queue.textValidateResults = new Map();
    queue.passwordValidateInputs = [];
    queue.passwordValidateResults = [];
    textCallIndex = 0;
    passwordCallIndex = 0;
});

// ────────────────────────────────────────────────────────────────────
// defaultPrompt — happy path + cancel branches
// ────────────────────────────────────────────────────────────────────

describe('defaultPrompt — happy path', () => {
    it('returns answers when all four prompts succeed', async () => {
        queue.password.push('a-bearer-key-that-is-long-enough');
        queue.text.push('https://example.com/'); // URL
        queue.text.push('default'); // profile-name
        queue.multiselect.push(['claude-desktop']);
        const ans = await defaultPrompt({ detected: SAMPLE_DETECTED });
        expect(ans).not.toBeNull();
        if (ans !== null) {
            expect(ans.wpUrl).toBe('https://example.com');
            expect(ans.bearer).toBe('a-bearer-key-that-is-long-enough');
            expect(ans.selectedClients).toEqual(['claude-desktop']);
            expect(ans.siteId).toBe('default');
        }
    });
});

describe('defaultPrompt — cancellation branches', () => {
    it('returns null when bearer password input is cancelled', async () => {
        queue.password.push(CANCEL);
        const ans = await defaultPrompt({ detected: SAMPLE_DETECTED });
        expect(ans).toBeNull();
    });

    it('returns null when wpUrl text input is cancelled', async () => {
        queue.password.push('a-bearer-key-that-is-long-enough');
        queue.text.push(CANCEL); // URL prompt cancelled
        const ans = await defaultPrompt({ detected: SAMPLE_DETECTED });
        expect(ans).toBeNull();
    });

    it('returns null when profile-name text input is cancelled', async () => {
        queue.password.push('a-bearer-key-that-is-long-enough');
        queue.text.push('https://example.com');
        queue.text.push(CANCEL); // profile-name prompt cancelled
        const ans = await defaultPrompt({ detected: SAMPLE_DETECTED });
        expect(ans).toBeNull();
    });

    it('returns null when multiselect is cancelled', async () => {
        queue.password.push('a-bearer-key-that-is-long-enough');
        queue.text.push('https://example.com');
        queue.text.push('default');
        queue.multiselect.push(CANCEL);
        const ans = await defaultPrompt({ detected: SAMPLE_DETECTED });
        expect(ans).toBeNull();
    });
});

// ────────────────────────────────────────────────────────────────────
// defaultPrompt — validate callback branches
// ────────────────────────────────────────────────────────────────────

describe('defaultPrompt — text validate (URL = call 0)', () => {
    it('rejects empty / non-http / unparseable URLs and accepts valid ones', async () => {
        queue.password.push('a-bearer-key-that-is-long-enough');
        queue.text.push('https://example.com');
        queue.text.push('default');
        queue.multiselect.push(['claude-desktop']);
        queue.textValidateInputs.set(0, [
            '',
            '   ',
            'ftp://wrong-scheme',
            'http://[broken-url',
            'https://valid.example.com',
        ]);
        await defaultPrompt({ detected: SAMPLE_DETECTED });
        const results = queue.textValidateResults.get(0) as Array<string | undefined>;
        expect(results[0]).toMatch(/URL is required/);
        expect(results[1]).toMatch(/URL is required/);
        expect(results[2]).toMatch(/start with http/);
        expect(results[3]).toMatch(/not a valid web address/);
        expect(results[4]).toBeUndefined();
    });
});

describe('defaultPrompt — text validate (profile-name = call 1)', () => {
    it('rejects empty / invalid-chars and accepts identifier-like names', async () => {
        queue.password.push('a-bearer-key-that-is-long-enough');
        queue.text.push('https://example.com');
        queue.text.push('default');
        queue.multiselect.push(['claude-desktop']);
        queue.textValidateInputs.set(1, [
            '',
            'has space',
            'has/slash',
            'staging',
            'staging_2',
            'staging-2',
        ]);
        await defaultPrompt({ detected: SAMPLE_DETECTED });
        const results = queue.textValidateResults.get(1) as Array<string | undefined>;
        expect(results[0]).toMatch(/Site ID is required/);
        expect(results[1]).toMatch(/letters, digits, dashes, or underscores only/);
        expect(results[2]).toMatch(/letters, digits, dashes, or underscores only/);
        expect(results[3]).toBeUndefined();
        expect(results[4]).toBeUndefined();
        expect(results[5]).toBeUndefined();
    });
});

describe('defaultPrompt — password validate (bearer)', () => {
    it('rejects empty / short bearers and accepts valid ones', async () => {
        queue.password.push('a-bearer-key-that-is-long-enough');
        queue.text.push('https://example.com');
        queue.text.push('default');
        queue.multiselect.push(['claude-desktop']);
        queue.passwordValidateInputs = ['', 'too-short', 'a-bearer-key-that-is-long-enough'];
        await defaultPrompt({ detected: SAMPLE_DETECTED });
        const results = queue.passwordValidateResults as Array<string | undefined>;
        expect(results[0]).toMatch(/Bearer key is required/);
        expect(results[1]).toMatch(/too short/);
        expect(results[2]).toBeUndefined();
    });
});

// ────────────────────────────────────────────────────────────────────
// defaultConfirmContinue
// ────────────────────────────────────────────────────────────────────

describe('defaultConfirmContinue', () => {
    it('returns true on explicit confirm', async () => {
        queue.confirm.push(true);
        expect(await defaultConfirmContinue('?')).toBe(true);
    });

    it('returns false on explicit decline', async () => {
        queue.confirm.push(false);
        expect(await defaultConfirmContinue('?')).toBe(false);
    });

    it('returns false on cancel', async () => {
        queue.confirm.push(CANCEL);
        expect(await defaultConfirmContinue('?')).toBe(false);
    });
});
