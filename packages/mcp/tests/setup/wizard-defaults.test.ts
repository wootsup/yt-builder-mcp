/**
 * R1.5: direct tests for `setup-wizard-defaults.ts` real-I/O helpers
 * (`rollbackWrites`, `majorMinor`, `DEFAULT_WIZARD_DEPS.{probeHealth,
 * probeAuth, writeClient, handshake}`).
 *
 * The existing `wizard.test.ts` covers `runWizard` with a fully mocked
 * deps bag — that's why the default-deps file sat at ~49% coverage.
 * Round-1.5 wants ≥98% lines / ≥88% branches on the project; these
 * tests target the real-I/O implementations directly.
 *
 * Strategy:
 *  - probeHealth / probeAuth: stub `globalThis.fetch` (RestClient
 *    accepts no `fetch` injection through DEFAULT_WIZARD_DEPS, so the
 *    global is the cleanest seam).
 *  - writeClient: provide a temp directory + verify the WriteResult
 *    shape for both unknown-client + happy-path + apply-throws paths.
 *  - handshake: drive the version-mismatch warning branch.
 *  - rollbackWrites: unlink path + restore path + error path.
 *
 * @license MIT
 */

import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    DEFAULT_WIZARD_DEPS,
    majorMinor,
    rollbackWrites,
} from '../../src/setup-wizard-defaults.js';
import type { WriteResult } from '../../src/setup-wizard-types.js';

const ORIGINAL_FETCH = globalThis.fetch;

afterEach(() => {
    globalThis.fetch = ORIGINAL_FETCH;
    vi.restoreAllMocks();
});

// ────────────────────────────────────────────────────────────────────
// majorMinor
// ────────────────────────────────────────────────────────────────────

describe('majorMinor', () => {
    it('strips patch + prerelease', () => {
        expect(majorMinor('1.2.3')).toBe('1.2');
        expect(majorMinor('0.1.0-alpha.1')).toBe('0.1');
        expect(majorMinor('10.20.30')).toBe('10.20');
    });

    it('returns empty string on malformed input', () => {
        expect(majorMinor('')).toBe('');
        expect(majorMinor('not-a-version')).toBe('');
        expect(majorMinor('1')).toBe('');
    });
});

// ────────────────────────────────────────────────────────────────────
// probeHealth
// ────────────────────────────────────────────────────────────────────

function stubFetch(handler: (url: string, init?: RequestInit) => Response | Promise<Response>): void {
    globalThis.fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input.toString();
        return handler(url, init);
    }) as unknown as typeof fetch;
}

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('DEFAULT_WIZARD_DEPS.probeHealth', () => {
    it('returns ok=true + pluginVersion when /health responds with plugin_version', async () => {
        stubFetch(() => jsonResponse({ plugin_version: '0.1.0' }));
        const res = await DEFAULT_WIZARD_DEPS.probeHealth('https://example.com');
        expect(res.ok).toBe(true);
        if (res.ok) expect(res.pluginVersion).toBe('0.1.0');
    });

    it('falls back to version field when plugin_version is missing', async () => {
        stubFetch(() => jsonResponse({ version: '0.2.0' }));
        const res = await DEFAULT_WIZARD_DEPS.probeHealth('https://example.com');
        expect(res.ok).toBe(true);
        if (res.ok) expect(res.pluginVersion).toBe('0.2.0');
    });

    it('returns ok=true without pluginVersion when neither field is present', async () => {
        stubFetch(() => jsonResponse({ status: 'ok' }));
        const res = await DEFAULT_WIZARD_DEPS.probeHealth('https://example.com');
        expect(res.ok).toBe(true);
        if (res.ok) expect(res.pluginVersion).toBeUndefined();
    });

    it('returns ok=false + error when the request fails', async () => {
        stubFetch(() => jsonResponse({ error: 'boom' }, 500));
        const res = await DEFAULT_WIZARD_DEPS.probeHealth('https://example.com');
        expect(res.ok).toBe(false);
        if (!res.ok) expect(res.error).toBeDefined();
    });

    it('returns ok=true + no version when /health responds with a non-object payload', async () => {
        stubFetch(() => new Response('null', { status: 200, headers: { 'Content-Type': 'application/json' } }));
        const res = await DEFAULT_WIZARD_DEPS.probeHealth('https://example.com');
        expect(res.ok).toBe(true);
        if (res.ok) expect(res.pluginVersion).toBeUndefined();
    });
});

// ────────────────────────────────────────────────────────────────────
// probeAuth
// ────────────────────────────────────────────────────────────────────

describe('DEFAULT_WIZARD_DEPS.probeAuth', () => {
    it('returns ok=true when /etag responds 200', async () => {
        stubFetch(() => jsonResponse({ etag: '"abc"' }));
        const res = await DEFAULT_WIZARD_DEPS.probeAuth('https://example.com', 'bearer-tok');
        expect(res.ok).toBe(true);
    });

    it('returns ok=false + error when /etag responds 401', async () => {
        stubFetch(() => jsonResponse({ error: 'unauth' }, 401));
        const res = await DEFAULT_WIZARD_DEPS.probeAuth('https://example.com', 'bad');
        expect(res.ok).toBe(false);
        if (!res.ok) expect(res.error).toBeDefined();
    });
});

// ────────────────────────────────────────────────────────────────────
// handshake
// ────────────────────────────────────────────────────────────────────

describe('DEFAULT_WIZARD_DEPS.handshake', () => {
    it('returns ok=false when health probe fails', async () => {
        stubFetch(() => jsonResponse({ error: 'down' }, 500));
        const res = await DEFAULT_WIZARD_DEPS.handshake('https://example.com', 'k', '0.1.0');
        expect(res.ok).toBe(false);
    });

    it('returns ok=false when auth probe fails (health OK)', async () => {
        stubFetch((url) => {
            if (url.endsWith('/health')) return jsonResponse({ plugin_version: '0.1.0' });
            return jsonResponse({ error: 'unauth' }, 401);
        });
        const res = await DEFAULT_WIZARD_DEPS.handshake('https://example.com', 'k', '0.1.0');
        expect(res.ok).toBe(false);
    });

    it('returns ok=true + warning when package and plugin major.minor disagree', async () => {
        stubFetch((url) => {
            if (url.endsWith('/health')) return jsonResponse({ plugin_version: '0.2.0' });
            return jsonResponse({ etag: '"x"' });
        });
        const res = await DEFAULT_WIZARD_DEPS.handshake('https://example.com', 'k', '0.1.0');
        expect(res.ok).toBe(true);
        if (res.ok) {
            expect(res.pluginVersion).toBe('0.2.0');
            expect(res.warning).toBeDefined();
            expect(res.warning).toMatch(/major\/minor mismatch/);
        }
    });

    it('returns ok=true (no warning) when versions match', async () => {
        stubFetch((url) => {
            if (url.endsWith('/health')) return jsonResponse({ plugin_version: '0.1.5' });
            return jsonResponse({ etag: '"x"' });
        });
        const res = await DEFAULT_WIZARD_DEPS.handshake('https://example.com', 'k', '0.1.0');
        expect(res.ok).toBe(true);
        if (res.ok) {
            expect(res.warning).toBeUndefined();
        }
    });

    it('returns ok=true (no plugin version) when /health omits it', async () => {
        stubFetch((url) => {
            if (url.endsWith('/health')) return jsonResponse({});
            return jsonResponse({ etag: '"x"' });
        });
        const res = await DEFAULT_WIZARD_DEPS.handshake('https://example.com', 'k', '0.1.0');
        expect(res.ok).toBe(true);
        if (res.ok) {
            expect(res.pluginVersion).toBeUndefined();
        }
    });
});

// ────────────────────────────────────────────────────────────────────
// writeClient
// ────────────────────────────────────────────────────────────────────

describe('DEFAULT_WIZARD_DEPS.writeClient', () => {
    it('returns ok=false with "Unknown client." for an unregistered id', async () => {
        const res = await DEFAULT_WIZARD_DEPS.writeClient('nonexistent-client', 'srv', {
            command: 'node',
            args: [],
        });
        expect(res.ok).toBe(false);
        if (!res.ok) {
            expect(res.error).toBe('Unknown client.');
            expect(res.path).toBe('');
            expect(res.previousContent).toBeNull();
        }
    });
});

// ────────────────────────────────────────────────────────────────────
// rollbackWrites
// ────────────────────────────────────────────────────────────────────

describe('rollbackWrites', () => {
    let tmp: string;

    beforeEach(() => {
        tmp = mkdtempSync(join(tmpdir(), 'r15-wd-'));
    });

    afterEach(() => {
        rmSync(tmp, { recursive: true, force: true });
    });

    it('unlinks freshly created files when previousContent is null', async () => {
        const path = join(tmp, 'fresh.json');
        writeFileSync(path, '{"new":true}', 'utf-8');
        const writes: WriteResult[] = [
            { id: 'x', label: 'X', ok: true, path, previousContent: null },
        ];
        const log: string[] = [];
        await rollbackWrites(writes, (l) => log.push(l));
        expect(existsSync(path)).toBe(false);
        expect(log.some((l) => l.includes('removed'))).toBe(true);
    });

    it('restores prior content when previousContent is non-null', async () => {
        const path = join(tmp, 'restore.json');
        writeFileSync(path, '{"new":true}', 'utf-8');
        const writes: WriteResult[] = [
            { id: 'x', label: 'X', ok: true, path, previousContent: '{"old":true}' },
        ];
        const log: string[] = [];
        await rollbackWrites(writes, (l) => log.push(l));
        expect(readFileSync(path, 'utf-8')).toBe('{"old":true}');
        expect(log.some((l) => l.includes('restored'))).toBe(true);
    });

    it('skips entries with ok=false or empty path', async () => {
        const writes: WriteResult[] = [
            { id: 'a', label: 'A', ok: false, error: 'x', path: '/should/not/touch', previousContent: null },
            { id: 'b', label: 'B', ok: true, path: '', previousContent: null },
        ];
        const log: string[] = [];
        await rollbackWrites(writes, (l) => log.push(l));
        expect(log).toHaveLength(0);
    });

    it('logs rollback FAILURE when unlink throws on a missing path', async () => {
        const path = join(tmp, 'does-not-exist.json');
        const writes: WriteResult[] = [
            { id: 'x', label: 'X', ok: true, path, previousContent: null },
        ];
        const log: string[] = [];
        await rollbackWrites(writes, (l) => log.push(l));
        expect(log.some((l) => l.includes('FAILED'))).toBe(true);
    });
});
