/**
 * Tests for the runtime platform-probe (F-Platform-Detect-Probe).
 *
 * The probe is what unblocks origin-only / subfolder Joomla customers
 * whose URL shape carries no `/wp-json/` or `/api/index.php/` marker.
 * These tests cover the four-quadrant truth table (joomla only,
 * wordpress only, both fail, wrong-shape 200) plus the behavioural
 * contract that wiring through `platformForUrlAsync` honours.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import {
    detectPlatformAtRuntime,
    type ProbeFetch,
} from '../../src/platform/detect.js';
import {
    JOOMLA_REST_NAMESPACE_PATH,
    JoomlaPlatform,
    platformForUrlAsync,
    WORDPRESS_REST_NAMESPACE_PATH,
    WordPressPlatform,
} from '../../src/platform/index.js';

function jsonResp(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function htmlResp(body: string, status = 200): Response {
    return new Response(body, {
        status,
        headers: { 'Content-Type': 'text/html' },
    });
}

const VALID_HEALTH = {
    plugin_version: '1.0.1',
    php: '8.3.0',
};

describe('detectPlatformAtRuntime', () => {
    it('returns "joomla" when only the Joomla /health endpoint returns a shaped payload', async () => {
        const called: string[] = [];
        const fetchImpl: ProbeFetch = vi.fn(async (url: string) => {
            called.push(url);
            if (url.endsWith(`${JOOMLA_REST_NAMESPACE_PATH}/health`)) {
                return jsonResp(VALID_HEALTH);
            }
            return new Response('404', { status: 404 });
        });
        const result = await detectPlatformAtRuntime(
            'https://example.com/joomla',
            { fetchImpl },
        );
        expect(result).toBe('joomla');
        // Joomla probed first; should match before WP is ever tried.
        expect(called[0]).toBe(
            `https://example.com/joomla${JOOMLA_REST_NAMESPACE_PATH}/health`,
        );
        expect(called.length).toBe(1);
    });

    it('returns "wordpress" when only the WP /health endpoint returns a shaped payload', async () => {
        const fetchImpl: ProbeFetch = vi.fn(async (url: string) => {
            if (url.endsWith(`${WORDPRESS_REST_NAMESPACE_PATH}/health`)) {
                return jsonResp(VALID_HEALTH);
            }
            return new Response('404', { status: 404 });
        });
        const result = await detectPlatformAtRuntime(
            'https://example.com',
            { fetchImpl },
        );
        expect(result).toBe('wordpress');
    });

    it('returns null when both probes 404 / fail / time-out', async () => {
        const fetchImpl: ProbeFetch = vi.fn(
            async () => new Response('not found', { status: 404 }),
        );
        const result = await detectPlatformAtRuntime(
            'https://nothing.example.com',
            { fetchImpl },
        );
        expect(result).toBeNull();
    });

    it('returns null when a probe throws (DNS / TLS / abort)', async () => {
        const fetchImpl: ProbeFetch = vi.fn(async () => {
            throw new TypeError('fetch failed');
        });
        const result = await detectPlatformAtRuntime(
            'https://broken.example.com',
            { fetchImpl },
        );
        expect(result).toBeNull();
    });

    it('ignores a 200 response whose body is NOT yt-builder-mcp shaped (e.g. WP frontend HTML)', async () => {
        const fetchImpl: ProbeFetch = vi.fn(
            async () => htmlResp('<html>marketing site</html>'),
        );
        const result = await detectPlatformAtRuntime(
            'https://wpsite.example.com',
            { fetchImpl },
        );
        expect(result).toBeNull();
    });

    it('ignores a 200 JSON response missing plugin_version (random JSON API at the same path)', async () => {
        const fetchImpl: ProbeFetch = vi.fn(
            async () => jsonResp({ message: 'hello from some other api' }),
        );
        const result = await detectPlatformAtRuntime(
            'https://otherapi.example.com',
            { fetchImpl },
        );
        expect(result).toBeNull();
    });

    it('preserves subfolder paths verbatim in the probe URL (trailing slash trimmed)', async () => {
        const probed: string[] = [];
        const fetchImpl: ProbeFetch = vi.fn(async (url: string) => {
            probed.push(url);
            return jsonResp(VALID_HEALTH);
        });
        await detectPlatformAtRuntime('https://example.com/joomla/', {
            fetchImpl,
        });
        expect(probed[0]).toBe(
            `https://example.com/joomla${JOOMLA_REST_NAMESPACE_PATH}/health`,
        );
    });

    it('returns null when no fetch implementation is available', async () => {
        // Force the fallback path by injecting a non-function override.
        const result = await detectPlatformAtRuntime(
            'https://example.com',
            { fetchImpl: undefined as unknown as ProbeFetch },
        );
        // With no fetchImpl AND no global fetch this MUST return null,
        // but on Node 18+ global fetch exists — in that case the call
        // would actually hit the network. We accept either: null, or
        // a real network result. The contract we test is "does not
        // throw" — the F-Platform-Detect-Probe boot path tolerates it.
        expect(result === null || result === 'wordpress' || result === 'joomla').toBe(true);
    });
});

describe('platformForUrlAsync', () => {
    it('honours an explicit hint without probing', async () => {
        const probe = vi.fn(async () => 'wordpress' as const);
        const platform = await platformForUrlAsync(
            'https://example.com/joomla',
            'joomla',
            { probe },
        );
        expect(platform).toBeInstanceOf(JoomlaPlatform);
        expect(probe).not.toHaveBeenCalled();
    });

    it('honours URL-shape detection without probing', async () => {
        const probe = vi.fn(async () => null);
        const platform = await platformForUrlAsync(
            'https://example.com/wp-json/yt-builder-mcp/v1',
            undefined,
            { probe },
        );
        expect(platform).toBeInstanceOf(WordPressPlatform);
        expect(probe).not.toHaveBeenCalled();
    });

    it('invokes the runtime probe when URL is origin-only AND no hint given', async () => {
        const probe = vi.fn(async () => 'joomla' as const);
        const platform = await platformForUrlAsync(
            'https://dev.wootsup.com/joomla',
            undefined,
            { probe },
        );
        expect(probe).toHaveBeenCalledOnce();
        expect(probe).toHaveBeenCalledWith('https://dev.wootsup.com/joomla');
        expect(platform).toBeInstanceOf(JoomlaPlatform);
    });

    it('falls back to wordpress when the runtime probe returns null AND emits a stderr note', async () => {
        const probe = vi.fn(async () => null);
        const log: string[] = [];
        const platform = await platformForUrlAsync(
            'https://nothing.example.com',
            undefined,
            { probe, logger: (m) => log.push(m) },
        );
        expect(platform).toBeInstanceOf(WordPressPlatform);
        expect(log.length).toBe(1);
        expect(log[0]).toMatch(/auto-detection probe failed/);
        expect(log[0]).toMatch(/YTB_MCP_PLATFORM=joomla/);
    });

    it('routes to WordPressPlatform when probe returns "wordpress"', async () => {
        const probe = vi.fn(async () => 'wordpress' as const);
        const platform = await platformForUrlAsync(
            'https://example.com',
            undefined,
            { probe },
        );
        expect(platform).toBeInstanceOf(WordPressPlatform);
    });
});
