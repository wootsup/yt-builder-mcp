/**
 * Source-binding write handlers (`element_bind_source`,
 * `element_unbind_source`).
 *
 * Split out of `./handlers.ts` to keep each file under the 200-LoC
 * budget. Wave G.4.2 adds destructive-confirm elicitation;
 * Wave G.4.3 adds ambiguity-resolution elicitation.
 *
 * @license MIT
 */

import { elicitChoice, elicitConfirmation } from '@getimo/mcp-toolkit';

import { encodeElementPath, type RestClient } from '../../client.js';
import {
    flattenSourcesPayload,
    mapSourceRow,
    type SourceRow,
} from '../format/sources-format.js';
import {
    ambiguityFallbackError,
    type ElicitationCandidate,
} from '../elicitation.js';
import {
    confirmGuard,
    errorResult,
    jsonResult,
    type ToolResult,
} from '../tool-builder.js';
import type { SourcesHandlerDeps } from './handlers.js';

// ─── element_bind_source (G.4.3 — ambiguity resolution) ─────────────

/**
 * Look up sources matching `source_name`. Returns at most one row per
 * (name + origin) tuple; cross-plugin name collisions yield multiple
 * candidates that need disambiguation.
 *
 * TODO(v0.3): cache /sources per session to avoid the round-trip on
 * every bind. The current "fetch on every bind" call is the only REST
 * round-trip introduced by ambiguity resolution and dominates bind
 * latency — see tests/perf/bind-latency.test.ts for the pin.
 */
async function fetchSourceCandidates(
    client: RestClient,
    source_name: string,
): Promise<SourceRow[]> {
    const data = await client.get<{ sources?: unknown }>('/sources');
    const payload = data.sources ?? data;
    const flat = flattenSourcesPayload(payload);
    return flat.map(mapSourceRow).filter((r) => r.name === source_name);
}

export async function handleElementBindSource(
    { client, elicitation }: SourcesHandlerDeps,
    input: {
        template_id: string;
        element_path: string;
        source_name: string;
        source_id?: string;
        etag: string;
    },
): Promise<ToolResult> {
    const { template_id, element_path, source_name, source_id, etag } = input;
    const encoded = encodeElementPath(element_path);

    // Wave G.4.3 — ambiguity resolution.
    //
    // If `source_id` is supplied, the caller has already disambiguated;
    // skip the /sources lookup entirely.
    //
    // Otherwise look up rows matching `source_name`:
    //   - 0 matches: fall through to the bind call; the REST plugin
    //     returns its own structured 404 with a precise hint (sources
    //     can be added between the lookup and the call).
    //   - 1 match: unique → bind directly.
    //   - ≥2 matches: cross-plugin collision (e.g. an apimapper flow
    //     AND a wordpress source both named "Posts"). Elicit a choice
    //     on supporting hosts; on unsupported hosts, return a
    //     structured `ambiguityFallbackError` listing every candidate
    //     so the agent can retry with `source_id`.
    let resolvedBody: { source_name: string; source_id?: string } = { source_name };
    if (source_id === undefined) {
        const candidates = await fetchSourceCandidates(client, source_name);
        if (candidates.length > 1) {
            const elicitCandidates: ElicitationCandidate[] = candidates.map((c) => ({
                id: `${c.origin}:${c.name}`,
                label: `${c.label || c.name} (${c.origin}${c.kind ? `/${c.kind}` : ''})`,
            }));
            const picked = elicitation
                ? await elicitChoice(
                      elicitation,
                      `Multiple sources named "${source_name}" exist. Pick which ` +
                          `one to bind to "${element_path}".`,
                      elicitCandidates.map((c) => c.id),
                  )
                : null;
            if (picked === null) {
                return ambiguityFallbackError({
                    code: 'source_ambiguous',
                    paramName: 'source_id',
                    what: 'sources',
                    candidates: elicitCandidates,
                    context: { template_id, element_path, source_name },
                });
            }
            resolvedBody = { source_name, source_id: picked };
        }
    } else {
        resolvedBody = { source_name, source_id };
    }

    try {
        const data = await client.put(
            `/pages/${encodeURIComponent(template_id)}/elements/${encoded}/binding`,
            { body: resolvedBody, etag },
        );
        return jsonResult(data);
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id, element_path, source_name, ...(source_id ? { source_id } : {}) },
            hint:
                'Verify source_name via yootheme_builder_sources_list. On 412 ' +
                'refresh ETag and retry.',
        });
    }
}

// ─── element_unbind_source (destructive — G.4.2) ────────────────────

export async function handleElementUnbindSource(
    { client, elicitation }: SourcesHandlerDeps,
    input: { template_id: string; element_path: string; etag: string; confirm?: boolean },
): Promise<ToolResult> {
    const { template_id, element_path, etag, confirm } = input;
    if (confirm !== true) {
        if (confirm === false || !elicitation) {
            return confirmGuard({
                operation: 'unbind source',
                details: { template_id, element_path },
            });
        }
        const accepted = await elicitConfirmation(
            elicitation,
            `Unbind the dynamic source from element "${element_path}" in template ` +
                `"${template_id}"? This may break dynamic-content rendering.`,
        );
        if (!accepted) {
            return confirmGuard({
                operation: 'unbind source',
                details: { template_id, element_path },
            });
        }
    }

    const encoded = encodeElementPath(element_path);
    try {
        const data = await client.delete(
            `/pages/${encodeURIComponent(template_id)}/elements/${encoded}/binding`,
            { etag },
        );
        return jsonResult(data);
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id, element_path },
            hint: 'On 412 refresh via yootheme_builder_get_etag and retry.',
        });
    }
}
