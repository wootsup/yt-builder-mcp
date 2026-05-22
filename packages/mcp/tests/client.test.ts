/**
 * Tests for `RestClient` — verb dispatch, header injection, URL composition,
 * error mapping.
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import { encodeElementPath, REST_NAMESPACE_PATH, RestClient } from '../src/client.js';
import { NetworkError, RestError } from '../src/errors.js';

function mockFetch(
    handler: (url: string, init: RequestInit) => Response | Promise<Response>,
): typeof fetch {
    return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input.toString();
        return handler(url, init ?? {});
    }) as unknown as typeof fetch;
}

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('RestClient', () => {
    it('composes URLs with the REST namespace', async () => {
        const calls: string[] = [];
        const client = new RestClient({
            baseUrl: 'https://example.com',
            bearerToken: 'tok',
            fetch: mockFetch((url) => {
                calls.push(url);
                return jsonResponse({ ok: true });
            }),
        });
        await client.get('/health');
        expect(calls[0]).toBe(`https://example.com${REST_NAMESPACE_PATH}/health`);
    });

    it('attaches Authorization: Bearer', async () => {
        let seenHeaders: Headers | undefined;
        const client = new RestClient({
            baseUrl: 'https://example.com',
            bearerToken: 'mytoken',
            fetch: mockFetch((_url, init) => {
                seenHeaders = new Headers(init.headers);
                return jsonResponse({ ok: true });
            }),
        });
        await client.get('/etag');
        expect(seenHeaders?.get('Authorization')).toBe('Bearer mytoken');
    });

    it('sends If-Match header when etag is provided', async () => {
        let seenHeaders: Headers | undefined;
        const client = new RestClient({
            baseUrl: 'https://example.com',
            bearerToken: 't',
            fetch: mockFetch((_url, init) => {
                seenHeaders = new Headers(init.headers);
                return jsonResponse({ ok: true });
            }),
        });
        await client.post('/anything', { etag: '"abc123"' });
        expect(seenHeaders?.get('If-Match')).toBe('"abc123"');
    });

    it('encodes JSON body + Content-Type', async () => {
        let seenBody: string | undefined;
        let seenContentType: string | null = null;
        const client = new RestClient({
            baseUrl: 'https://example.com',
            bearerToken: 't',
            fetch: mockFetch((_url, init) => {
                seenContentType = new Headers(init.headers).get('Content-Type');
                seenBody = init.body as string;
                return jsonResponse({ ok: true });
            }),
        });
        await client.post('/foo', { body: { foo: 'bar' } });
        expect(seenContentType).toBe('application/json');
        expect(JSON.parse(seenBody ?? '{}')).toEqual({ foo: 'bar' });
    });

    it('throws RestError on non-2xx with status/code/message', async () => {
        const client = new RestClient({
            baseUrl: 'https://example.com',
            bearerToken: 't',
            fetch: mockFetch(() =>
                jsonResponse(
                    {
                        code: 'yootheme_builder_mcp.pages.not_found',
                        message: 'Template not found.',
                    },
                    404,
                ),
            ),
        });
        try {
            await client.get('/pages/missing/layout');
            expect.fail('should have thrown');
        } catch (e) {
            expect(e).toBeInstanceOf(RestError);
            if (e instanceof RestError) {
                expect(e.status).toBe(404);
                expect(e.code).toBe('yootheme_builder_mcp.pages.not_found');
                expect(e.message).toBe('Template not found.');
            }
        }
    });

    it('throws NetworkError on fetch failure', async () => {
        const client = new RestClient({
            baseUrl: 'https://example.com',
            bearerToken: 't',
            fetch: mockFetch(() => {
                throw new TypeError('ECONNREFUSED');
            }),
        });
        await expect(client.get('/health')).rejects.toBeInstanceOf(NetworkError);
    });

    it('verb helpers dispatch the correct HTTP method', async () => {
        const seenMethods: string[] = [];
        const client = new RestClient({
            baseUrl: 'https://example.com',
            bearerToken: 't',
            fetch: mockFetch((_url, init) => {
                seenMethods.push((init.method ?? 'GET').toUpperCase());
                return jsonResponse({});
            }),
        });
        await client.get('/a');
        await client.post('/b');
        await client.put('/c');
        await client.delete('/d');
        expect(seenMethods).toEqual(['GET', 'POST', 'PUT', 'DELETE']);
    });
});

describe('encodeElementPath', () => {
    it('returns empty string for empty/root pointer', () => {
        expect(encodeElementPath('')).toBe('');
        expect(encodeElementPath('/')).toBe('');
    });

    it('encodes each segment but leaves slashes alone', () => {
        expect(encodeElementPath('/0/children/2')).toBe('0/children/2');
    });

    it('percent-encodes reserved chars within a segment', () => {
        expect(encodeElementPath('/foo bar/baz')).toBe('foo%20bar/baz');
    });
});
