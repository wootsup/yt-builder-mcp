/**
 * W12-R1.2 — shared site-probe helper.
 *
 * Both `sites/tools/sites-test.ts` (MCP tool surface) AND
 * `sites/cli/test-site.ts` (CLI surface) need to run the same
 * `/health` (no auth) + `/etag` (auth) parallel probe and classify
 * the outcome into a `{plugin_reachable, bearer_valid, etag_received,
 * summary}` shape. Pre-W12 the two call sites duplicated the
 * Promise.allSettled + RestError branching twice (~40 LoC drift
 * surface).
 *
 * This module is the single source of truth for that probe. The two
 * call sites map the result into their respective output envelopes
 * (toolkit detail-result for MCP, plain text lines for CLI) but never
 * re-implement the probe-routing logic.
 *
 * Failure-mode mapping (audit-aligned):
 *   - /health rejects, /etag rejects   → plugin unreachable
 *   - /health rejects, /etag 401/403   → plugin reachable (etag replied),
 *                                        bearer rejected
 *   - /health rejects, /etag other     → plugin unreachable
 *                                        (bearer_error still surfaced)
 *   - /health ok,      /etag rejects   → plugin reachable, bearer rejected
 *   - /health ok,      /etag ok        → green
 *
 * Never throws on probe failure — those land in the result fields.
 * Throws only on truly unexpected programmer errors (e.g. the client
 * arg is not a RestClient).
 *
 * @license MIT
 */

import type { RestClient } from '../client.js';
import { RestError } from '../errors.js';

/**
 * Probe outcome shape consumed by both the MCP tool and the CLI. Optional
 * HTTP status codes are surfaced when the probe yielded a `RestError`
 * (helpful for operators triaging 401 vs 404 vs 500).
 */
export interface ProbeResult {
    readonly plugin_reachable: boolean;
    readonly bearer_valid: boolean;
    readonly etag_received: boolean;
    readonly summary: string;
    readonly plugin_error?: string;
    readonly bearer_error?: string;
    readonly http_status_health?: number;
    readonly http_status_etag?: number;
}

/**
 * Run the canonical `/health` + `/etag` parallel probe against a
 * site-bound {@link RestClient}.
 *
 * `Promise.allSettled` so a thrown probe never short-circuits the
 * other — both legs always contribute to the final classification.
 *
 * The summary string is deterministic per `{plugin_reachable, bearer_valid}`
 * combination so customer-facing UX (CLI table, MCP detail-card) is
 * identical across the two surfaces.
 */
export async function probeSite(client: RestClient): Promise<ProbeResult> {
    const [healthSettled, etagSettled] = await Promise.allSettled([
        client.get('/health'),
        client.get('/etag'),
    ]);

    let pluginReachable = false;
    let bearerValid = false;
    let etagReceived = false;
    let pluginError: string | undefined;
    let bearerError: string | undefined;
    let httpStatusHealth: number | undefined;
    let httpStatusEtag: number | undefined;

    // /health leg — plain liveness probe (no auth required).
    if (healthSettled.status === 'fulfilled') {
        pluginReachable = true;
    } else {
        const e: unknown = healthSettled.reason;
        if (e instanceof RestError) {
            httpStatusHealth = e.status;
            pluginError = `HTTP ${String(e.status)}: ${e.message}`;
        } else if (e instanceof Error) {
            pluginError = e.message;
        } else {
            pluginError = String(e);
        }
    }

    // /etag leg — bearer-gated. 401/403 means the plugin DID respond
    // and the auth check fired — so we can confidently flip
    // plugin_reachable even if /health hasn't already.
    if (etagSettled.status === 'fulfilled') {
        bearerValid = true;
        etagReceived = true;
    } else {
        const e: unknown = etagSettled.reason;
        if (e instanceof RestError) {
            httpStatusEtag = e.status;
            bearerError = `HTTP ${String(e.status)}: ${e.message}`;
            if (e.status === 401 || e.status === 403) {
                pluginReachable = true;
            }
        } else if (e instanceof Error) {
            bearerError = e.message;
        } else {
            bearerError = String(e);
        }
    }

    const summary = pluginReachable && bearerValid
        ? 'OK — plugin reachable and Bearer key accepted.'
        : !pluginReachable
            ? 'FAIL — plugin not reachable. Verify the site URL and that the yt-builder-mcp plugin is active.'
            : 'FAIL — plugin reachable but Bearer key rejected. Regenerate the key or update the secret reference.';

    return {
        plugin_reachable: pluginReachable,
        bearer_valid: bearerValid,
        etag_received: etagReceived,
        summary,
        ...(pluginError !== undefined ? { plugin_error: pluginError } : {}),
        ...(bearerError !== undefined ? { bearer_error: bearerError } : {}),
        ...(httpStatusHealth !== undefined ? { http_status_health: httpStatusHealth } : {}),
        ...(httpStatusEtag !== undefined ? { http_status_etag: httpStatusEtag } : {}),
    };
}
