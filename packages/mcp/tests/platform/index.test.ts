/**
 * Tests for the platform abstraction (Wave G.0).
 *
 * Establishes the seam between WordPress (today) and Joomla (future)
 * by validating that:
 *   - `WordPressPlatform` advertises the correct REST namespace path
 *   - `PlatformKind` is a typed union of 'wordpress' | 'joomla'
 *   - `RestClient` accepts BOTH the new `{platform, …}` form AND the
 *     legacy `{baseUrl, …}` form (backward-compat)
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { RestClient } from '../../src/client.js';
import type { Platform, PlatformKind } from '../../src/platform/index.js';
import { WordPressPlatform } from '../../src/platform/index.js';

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('WordPressPlatform', () => {
    it('constructs with the canonical REST namespace path', () => {
        const platform = new WordPressPlatform('https://example.com');
        expect(platform.kind).toBe('wordpress');
        expect(platform.baseUrl).toBe('https://example.com');
        expect(platform.restNamespacePath).toBe('/wp-json/yt-builder-mcp/v1');
    });

    it('preserves the provided baseUrl verbatim (no trailing-slash trim here)', () => {
        const platform = new WordPressPlatform('https://example.com/');
        expect(platform.baseUrl).toBe('https://example.com/');
    });

    it('satisfies the Platform interface (typecheck-time seam)', () => {
        const platform: Platform = new WordPressPlatform('https://example.com');
        // Typecheck-only assertion — exists so the union narrows correctly.
        const kind: PlatformKind = platform.kind;
        expect(kind === 'wordpress' || kind === 'joomla').toBe(true);
    });
});

describe('RestClient — dual-form constructor', () => {
    it('accepts the new {platform, bearerToken} form', async () => {
        const calls: string[] = [];
        const platform = new WordPressPlatform('https://example.com');
        const client = new RestClient({
            platform,
            bearerToken: 'tok',
            fetch: vi.fn(async (input: RequestInfo | URL) => {
                calls.push(typeof input === 'string' ? input : input.toString());
                return jsonResponse({ ok: true });
            }) as unknown as typeof fetch,
        });
        await client.get('/health');
        expect(calls[0]).toBe(
            'https://example.com/wp-json/yt-builder-mcp/v1/health',
        );
    });

    it('still accepts the legacy {baseUrl, bearerToken} form', async () => {
        const calls: string[] = [];
        const client = new RestClient({
            baseUrl: 'https://legacy.example.com',
            bearerToken: 'tok',
            fetch: vi.fn(async (input: RequestInfo | URL) => {
                calls.push(typeof input === 'string' ? input : input.toString());
                return jsonResponse({ ok: true });
            }) as unknown as typeof fetch,
        });
        await client.get('/health');
        expect(calls[0]).toBe(
            'https://legacy.example.com/wp-json/yt-builder-mcp/v1/health',
        );
    });
});
