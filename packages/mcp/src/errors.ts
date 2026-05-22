/**
 * Error types for the YT Builder MCP package.
 *
 * `RestError` wraps non-2xx responses from the WP REST API with the
 * status, the canonical WP_Error `code` (if present) and the human
 * message. `ConfigError` is thrown when the server is missing
 * environment configuration.
 *
 * Wave G.6.3: `body` is routed through `sanitizeSecrets` at construction
 * time so any secret-bearing field returned by the upstream REST API
 * (e.g. WP_Error data carrying `auth_data` or `oauth_refresh_token`)
 * never lands in the LLM context — even if a future tool decides to
 * echo `error.body` somewhere.
 *
 * @license MIT
 */

import { sanitizeSecrets } from './errors/sanitize.js';

export interface RestErrorPayload {
    readonly status: number;
    readonly code?: string;
    readonly message: string;
    /** Original parsed JSON body (or null if the body was not JSON). */
    readonly body: unknown;
}

export class RestError extends Error {
    public readonly status: number;
    public readonly code?: string;
    public readonly body: unknown;

    constructor(payload: RestErrorPayload) {
        super(payload.message);
        this.name = 'RestError';
        this.status = payload.status;
        this.code = payload.code;
        // Deep-walk redaction at construction time (Wave G.6.3). The
        // input body is never mutated; sanitizeSecrets returns a fresh
        // tree with redacted secret-keys.
        this.body = sanitizeSecrets(payload.body);
    }
}

export class ConfigError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'ConfigError';
    }
}

export interface NetworkErrorPayload {
    readonly cause: unknown;
    readonly url: string;
}

export class NetworkError extends Error {
    public readonly cause: unknown;
    public readonly url: string;

    constructor(payload: NetworkErrorPayload) {
        super(`Network error contacting ${payload.url}: ${describeCause(payload.cause)}`);
        this.name = 'NetworkError';
        this.cause = payload.cause;
        this.url = payload.url;
    }
}

function describeCause(cause: unknown): string {
    if (cause instanceof Error) return cause.message;
    if (typeof cause === 'string') return cause;
    try {
        return JSON.stringify(cause);
    } catch {
        return String(cause);
    }
}
