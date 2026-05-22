/**
 * Error-scenarios matrix helper — Wave G.8 / Plan I-4.
 *
 * Centralises the 7 canonical HTTP failure modes we expect every
 * read-tool to map cleanly into the YOOtheme-Builder error taxonomy
 * (see `src/errors/hints.ts`). Tests use `describe.each(ERROR_SCENARIOS)`
 * to enumerate "tool × code" combinations without duplicating boilerplate.
 *
 * Each scenario carries:
 *   - `status` — the HTTP status to inject via the fake fetch.
 *   - `expectedCode` — the `YtbErrorCode` the tool's `errorResult` payload
 *     must surface (via the `code` field on the RestError parent).
 *   - `expectedHint` — substring the `hint` field must contain (anchors
 *     the human-readable recovery copy).
 *   - `headers` — optional response headers (e.g. `WWW-Authenticate` for
 *     401 disambiguation).
 *
 * @license MIT
 */

import { vi } from 'vitest';

import { RestClient } from '../../src/client.js';

export interface ErrorScenario {
    readonly label: string;
    readonly status: number;
    /** Substring (case-insensitive) that must appear in the rendered hint. */
    readonly hintContains: string;
    /** Substring that must appear in the rendered error.message. */
    readonly messageContains?: string;
    /** Optional response headers. */
    readonly headers?: Record<string, string>;
    /** `true` for status=0 / fetch-rejects (NetworkError). */
    readonly isNetwork?: boolean;
}

/**
 * The canonical 7 scenarios — keep in sync with `YtbErrorCode` in
 * `src/errors/hints.ts`. Order is documentary; describe.each iterates.
 */
export const ERROR_SCENARIOS: readonly ErrorScenario[] = [
    {
        label: '401 — auth_invalid (key rejected)',
        status: 401,
        headers: { 'WWW-Authenticate': 'Bearer realm="ytb-mcp"' },
        hintContains: 'Bearer',
    },
    {
        label: '403 — forbidden (scope insufficient)',
        status: 403,
        hintContains: 'scope',
    },
    {
        label: '404 — not_found',
        status: 404,
        hintContains: 'list',
    },
    {
        label: '412 — conflict_etag',
        status: 412,
        hintContains: 'etag',
    },
    {
        label: '429 — rate_limit',
        status: 429,
        hintContains: 'rate',
    },
    {
        label: '500 — server_error',
        status: 500,
        hintContains: 'server',
    },
    {
        label: '0 — network (fetch rejects)',
        status: 0,
        isNetwork: true,
        hintContains: 'verify',
    },
] as const;

/**
 * Builds a RestClient whose `fetch` either resolves to a Response with
 * the given status (and optional headers) or rejects (network mode).
 *
 * The response body deliberately mirrors the WordPress WP_Error JSON
 * envelope so RestError extracts `code` + `message` consistently with
 * production traffic.
 */
export function makeFailingClient(scenario: ErrorScenario): RestClient {
    if (scenario.isNetwork === true) {
        return new RestClient({
            baseUrl: 'https://example.com',
            bearerToken: 't',
            fetch: vi.fn(async () => {
                throw new TypeError('fetch failed');
            }) as unknown as typeof fetch,
        });
    }
    return new RestClient({
        baseUrl: 'https://example.com',
        bearerToken: 't',
        fetch: vi.fn(async () => {
            const body = {
                code: `synthetic_${String(scenario.status)}`,
                message: `Synthetic ${String(scenario.status)} response`,
                data: { status: scenario.status },
            };
            return new Response(JSON.stringify(body), {
                status: scenario.status,
                headers: {
                    'Content-Type': 'application/json',
                    ...(scenario.headers ?? {}),
                },
            });
        }) as unknown as typeof fetch,
    });
}

/** Extract the parsed `{error,status,code,context,hint}` payload from a tool result. */
export function extractErrorPayload(
    result: { content?: Array<{ text?: string }>; isError?: boolean },
): Record<string, unknown> {
    const text = result.content?.[0]?.text ?? '';
    try {
        return JSON.parse(text) as Record<string, unknown>;
    } catch {
        return { error: text };
    }
}
