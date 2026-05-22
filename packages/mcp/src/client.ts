/**
 * `RestClient` — thin wrapper around `fetch` that targets the
 * `yt-builder-mcp/v1` REST namespace.
 *
 * Responsibilities:
 *   - Compose final URLs (`{baseUrl}/wp-json/yt-builder-mcp/v1{path}`)
 *   - Attach `Authorization: Bearer …`
 *   - Encode JSON bodies + propagate `If-Match` etag headers
 *   - Map non-2xx into `RestError` with status / `code` / message
 *   - Map network failures into `NetworkError`
 *
 * The client deliberately keeps zero state beyond constructor config
 * so each tool can hold a reference and call `.get/.post/.put/.delete`
 * without coordinating sessions.
 *
 * @license MIT
 */

import { NetworkError, RestError } from './errors.js';
import type { Platform } from './platform/index.js';
import { WordPressPlatform, WORDPRESS_REST_NAMESPACE_PATH } from './platform/index.js';

/**
 * Canonical WordPress REST-namespace path. Re-exported for backward
 * compat — new code should reach for `Platform.restNamespacePath`.
 */
export const REST_NAMESPACE_PATH = WORDPRESS_REST_NAMESPACE_PATH;

/**
 * Legacy single-platform options shape. Kept so existing call-sites
 * continue working without touching every entry point.
 */
export interface LegacyRestClientOptions {
    readonly baseUrl: string;
    readonly bearerToken: string;
    readonly timeoutMs?: number;
    /** Injected for tests. Defaults to `globalThis.fetch`. */
    readonly fetch?: typeof fetch;
}

/**
 * Platform-aware options shape (Wave G.0+).
 * Pass an instance of `WordPressPlatform` (or any future `Platform`)
 * instead of a bare `baseUrl`.
 */
export interface PlatformRestClientOptions {
    readonly platform: Platform;
    readonly bearerToken: string;
    readonly timeoutMs?: number;
    /** Injected for tests. Defaults to `globalThis.fetch`. */
    readonly fetch?: typeof fetch;
}

/** Discriminated union: callers pick whichever shape suits them. */
export type RestClientOptions = LegacyRestClientOptions | PlatformRestClientOptions;

export interface RequestOptions {
    /** Optional ETag for optimistic-lock (`If-Match` header). */
    readonly etag?: string;
    /** JSON body. */
    readonly body?: unknown;
    /** Extra headers. */
    readonly headers?: Record<string, string>;
    /** Optional AbortSignal. */
    readonly signal?: AbortSignal;
}

export class RestClient {
    private readonly platform: Platform;
    private readonly baseUrl: string;
    private readonly bearerToken: string;
    private readonly timeoutMs: number;
    private readonly fetchImpl: typeof fetch;

    constructor(options: RestClientOptions) {
        // Dual-form: accept either a `Platform` or a legacy `baseUrl`.
        const platform: Platform = isPlatformOptions(options)
            ? options.platform
            : new WordPressPlatform(options.baseUrl);
        this.platform = platform;
        this.baseUrl = platform.baseUrl.replace(/\/+$/, '');
        this.bearerToken = options.bearerToken;
        this.timeoutMs = options.timeoutMs ?? 15_000;
        this.fetchImpl = options.fetch ?? globalThis.fetch.bind(globalThis);
    }

    /** Expose the underlying platform (used by tools that need restNamespacePath/kind). */
    getPlatform(): Platform {
        return this.platform;
    }

    get<T = unknown>(path: string, options: RequestOptions = {}): Promise<T> {
        return this.request<T>('GET', path, options);
    }

    post<T = unknown>(path: string, options: RequestOptions = {}): Promise<T> {
        return this.request<T>('POST', path, options);
    }

    put<T = unknown>(path: string, options: RequestOptions = {}): Promise<T> {
        return this.request<T>('PUT', path, options);
    }

    delete<T = unknown>(path: string, options: RequestOptions = {}): Promise<T> {
        return this.request<T>('DELETE', path, options);
    }

    async request<T = unknown>(
        method: string,
        path: string,
        options: RequestOptions = {},
    ): Promise<T> {
        const url = this.buildUrl(path);
        const headers: Record<string, string> = {
            Authorization: `Bearer ${this.bearerToken}`,
            Accept: 'application/json',
            ...(options.headers ?? {}),
        };
        if (options.etag !== undefined && options.etag !== '') {
            headers['If-Match'] = options.etag;
        }

        const init: RequestInit = { method, headers };
        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), this.timeoutMs);
        if (options.signal !== undefined) {
            options.signal.addEventListener('abort', () => controller.abort());
        }
        init.signal = controller.signal;

        let response: Response;
        try {
            response = await this.fetchImpl(url, init);
        } catch (e) {
            throw new NetworkError({ cause: e, url });
        } finally {
            clearTimeout(timeoutId);
        }

        const text = await response.text();
        const parsed = parseJsonSafe(text);

        if (!response.ok) {
            throw new RestError({
                status: response.status,
                code: extractErrorCode(parsed),
                message: extractErrorMessage(parsed, response.statusText),
                body: parsed,
            });
        }

        return parsed as T;
    }

    private buildUrl(path: string): string {
        const normalized = path.startsWith('/') ? path : `/${path}`;
        return `${this.baseUrl}${this.platform.restNamespacePath}${normalized}`;
    }
}

/** Type guard — discriminate between the two RestClientOptions shapes. */
function isPlatformOptions(opts: RestClientOptions): opts is PlatformRestClientOptions {
    return 'platform' in opts && opts.platform !== undefined;
}

function parseJsonSafe(text: string): unknown {
    if (text.trim() === '') return null;
    try {
        return JSON.parse(text);
    } catch {
        return text;
    }
}

function extractErrorCode(body: unknown): string | undefined {
    if (body !== null && typeof body === 'object' && 'code' in body) {
        const code = (body as { code: unknown }).code;
        if (typeof code === 'string') return code;
    }
    return undefined;
}

function extractErrorMessage(body: unknown, fallback: string): string {
    if (body !== null && typeof body === 'object' && 'message' in body) {
        const msg = (body as { message: unknown }).message;
        if (typeof msg === 'string' && msg !== '') return msg;
    }
    return fallback !== '' ? fallback : 'Unknown REST error';
}

/**
 * URL-encode a JSON-Pointer-style element path for use in the route.
 *
 * The plugin's regex captures everything after `/elements/`, so the
 * raw `/grid/1/columns/0` path can be passed as-is — but each segment
 * must be percent-encoded so reserved chars like `~` (used in
 * JSON-Pointer escapes) don't collide with WordPress' router.
 */
export function encodeElementPath(pointer: string): string {
    if (pointer === '' || pointer === '/') return '';
    // Strip leading slash, split, encode each segment, rejoin.
    const segments = pointer.replace(/^\//, '').split('/');
    return segments.map(encodeURIComponent).join('/');
}
