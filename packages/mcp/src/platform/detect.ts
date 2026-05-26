/**
 * Runtime platform detection — F-Platform-Detect-Probe (P0 customer fix).
 *
 * The static URL-shape heuristic in `detectPlatformFromUrl()` only works
 * when the user-supplied base URL already carries `/wp-json/` or
 * `/api/index.php/` in its path. That is RARELY the case in practice —
 * the DXT/manifest tells the user to enter the **site origin** (or a
 * subfolder install like `https://dev.example.com/joomla`), neither of
 * which contains those markers. The heuristic then silently falls back
 * to the WordPress default and the Joomla customer experiences a
 * 24/24 FAIL until they manually set `YTB_MCP_PLATFORM=joomla`.
 *
 * This module adds a *runtime probe*: lightweight unauthenticated GETs
 * against both platforms' `/health` endpoints. Whichever returns a
 * yt-builder-mcp-shaped response (a JSON body carrying `plugin_version`)
 * wins. Joomla is probed first because the negative case (probe fails)
 * is the long-tail and the positive case (probe succeeds) returns fast
 * — letting the more common WordPress install just fall through to the
 * second probe without paying for a wasted full timeout.
 *
 * The probe is fire-and-forget safe:
 *   - 3 s `AbortSignal.timeout` per request (so a totally broken host
 *     blocks ~6 s worst case, not the request's full 15 s default).
 *   - Non-200 responses are ignored (Joomla on a WP-only install
 *     answers 404; that's fine).
 *   - Non-JSON / wrong-shape 200 responses are ignored (a WP frontend
 *     without the plugin renders the homepage HTML, which `JSON.parse`
 *     refuses — handled).
 *   - Any thrown error (DNS, TLS, abort) is swallowed → try next.
 *
 * The function returns `null` when BOTH probes fail; the caller then
 * falls back to the URL-shape heuristic or the wordpress default. We do
 * NOT throw, because a missing-plugin or wrong-URL diagnosis belongs to
 * the first real authenticated request — not to silent boot-time probing.
 *
 * @license MIT
 */

import {
    JOOMLA_REST_NAMESPACE_PATH,
    WORDPRESS_REST_NAMESPACE_PATH,
    type PlatformKind,
} from './index.js';

/** Per-probe HTTP timeout. Keep tight — boot-time UX is on the line. */
const PROBE_TIMEOUT_MS = 3_000;

/**
 * Minimal fetch type so callers (and tests) can inject a stub without
 * pulling DOM types. Matches the global `fetch` contract we actually use.
 */
export type ProbeFetch = (
    input: string,
    init?: { signal?: AbortSignal },
) => Promise<Response>;

export interface DetectPlatformAtRuntimeOptions {
    /** Override the per-probe timeout (ms). Default 3000. */
    readonly timeoutMs?: number;
    /** Injectable fetch (tests). Defaults to the global `fetch`. */
    readonly fetchImpl?: ProbeFetch;
}

interface ProbeCandidate {
    readonly kind: PlatformKind;
    readonly path: string;
}

const PROBE_CANDIDATES: readonly ProbeCandidate[] = [
    // Joomla first — see module header rationale.
    { kind: 'joomla', path: `${JOOMLA_REST_NAMESPACE_PATH}/health` },
    { kind: 'wordpress', path: `${WORDPRESS_REST_NAMESPACE_PATH}/health` },
];

/**
 * Probe both platforms' health endpoints and return the first kind that
 * answers with a yt-builder-mcp-shaped JSON payload. Returns `null`
 * when neither responds usefully (so the caller can fall through to the
 * URL-shape heuristic / default).
 *
 * NB: `baseUrl` is taken verbatim except for trimming trailing slashes.
 * If `baseUrl` itself carries a path component (e.g. subfolder install
 * `https://dev.example.com/joomla`) we preserve it — that path is where
 * Joomla's `/api/index.php/...` is rooted.
 */
export async function detectPlatformAtRuntime(
    baseUrl: string,
    options: DetectPlatformAtRuntimeOptions = {},
): Promise<PlatformKind | null> {
    const timeoutMs = options.timeoutMs ?? PROBE_TIMEOUT_MS;
    const fetchImpl: ProbeFetch =
        options.fetchImpl ?? (globalThis.fetch as ProbeFetch);
    if (typeof fetchImpl !== 'function') return null;

    const trimmed = baseUrl.replace(/\/+$/, '');
    for (const { kind, path } of PROBE_CANDIDATES) {
        const matched = await probeOne(
            trimmed + path,
            timeoutMs,
            fetchImpl,
        );
        if (matched) return kind;
    }
    return null;
}

async function probeOne(
    url: string,
    timeoutMs: number,
    fetchImpl: ProbeFetch,
): Promise<boolean> {
    try {
        const signal = AbortSignal.timeout(timeoutMs);
        const r = await fetchImpl(url, { signal });
        if (!r.ok) return false;
        // Some hosts answer JSON with `text/html` Content-Type; don't
        // rely on the header. Parse defensively.
        const text = await r.text();
        let body: unknown;
        try {
            body = JSON.parse(text);
        } catch {
            return false;
        }
        return isYtbHealthShape(body);
    } catch {
        return false;
    }
}

/** Recognises a yt-builder-mcp `/health` response. */
function isYtbHealthShape(body: unknown): boolean {
    return (
        typeof body === 'object'
        && body !== null
        && 'plugin_version' in body
    );
}
