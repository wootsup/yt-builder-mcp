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
import {
    JoomlaPlatform,
    JOOMLA_REST_NAMESPACE_PATH,
    WordPressPlatform,
    detectPlatformFromUrl,
    platformForUrl,
} from '../../src/platform/index.js';

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

// ── Wave 7 — Joomla platform ────────────────────────────────────────
describe('JoomlaPlatform', () => {
    it('advertises kind="joomla"', () => {
        const platform = new JoomlaPlatform('https://example.com');
        expect(platform.kind).toBe('joomla');
    });

    it('uses /api/index.php/v1/yt-builder-mcp as its REST namespace path', () => {
        const platform = new JoomlaPlatform('https://example.com');
        expect(platform.restNamespacePath).toBe('/api/index.php/v1/yt-builder-mcp');
        expect(JOOMLA_REST_NAMESPACE_PATH).toBe('/api/index.php/v1/yt-builder-mcp');
    });

    it('preserves the provided baseUrl verbatim (no trailing-slash trim here)', () => {
        const platform = new JoomlaPlatform('https://example.com/joomla/');
        expect(platform.baseUrl).toBe('https://example.com/joomla/');
    });

    it('satisfies the Platform interface (typecheck-time seam)', () => {
        const platform: Platform = new JoomlaPlatform('https://example.com');
        const kind: PlatformKind = platform.kind;
        expect(kind === 'wordpress' || kind === 'joomla').toBe(true);
    });

    it('routes RestClient requests through the Joomla REST namespace', async () => {
        const calls: string[] = [];
        const platform = new JoomlaPlatform('https://example.com');
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
            'https://example.com/api/index.php/v1/yt-builder-mcp/health',
        );
    });
});

describe('detectPlatformFromUrl', () => {
    it('detects wordpress from URLs containing /wp-json/', () => {
        expect(detectPlatformFromUrl('https://example.com/wp-json/yt-builder-mcp/v1/health'))
            .toBe('wordpress');
    });

    it('detects joomla from URLs containing /api/index.php/', () => {
        expect(detectPlatformFromUrl('https://example.com/api/index.php/v1/yt-builder-mcp/health'))
            .toBe('joomla');
    });

    it('returns null for an origin-only URL (ambiguous — caller probes /identity)', () => {
        expect(detectPlatformFromUrl('https://example.com')).toBeNull();
        expect(detectPlatformFromUrl('https://example.com/joomla')).toBeNull();
    });

    // Round-6 A1 polish: substring `.includes('/wp-json/')` mis-detected
    // URLs that carried the marker in a query-string. Switching to
    // `URL` + `pathname.startsWith` closes that edge case.
    it('does NOT mis-detect when /wp-json/ appears only in a query string', () => {
        expect(
            detectPlatformFromUrl('https://example.com/joomla?redirect=/wp-json/x'),
        ).toBeNull();
    });

    it('does NOT mis-detect when /api/index.php/ appears only in a query string', () => {
        expect(
            detectPlatformFromUrl('https://example.com/wp?next=/api/index.php/x'),
        ).toBeNull();
    });
});

describe('platformForUrl', () => {
    it('returns JoomlaPlatform for a Joomla-shaped URL', () => {
        const p = platformForUrl('https://example.com/api/index.php/v1/yt-builder-mcp');
        expect(p.kind).toBe('joomla');
        expect(p).toBeInstanceOf(JoomlaPlatform);
    });

    it('returns WordPressPlatform for a WP-shaped URL', () => {
        const p = platformForUrl('https://example.com/wp-json/yt-builder-mcp/v1');
        expect(p.kind).toBe('wordpress');
        expect(p).toBeInstanceOf(WordPressPlatform);
    });

    it('falls back to the hint when URL is origin-only', () => {
        const p = platformForUrl('https://example.com', 'joomla');
        expect(p.kind).toBe('joomla');
    });

    it('defaults to wordpress when URL is origin-only and no hint provided', () => {
        const p = platformForUrl('https://example.com');
        expect(p.kind).toBe('wordpress');
    });
});
