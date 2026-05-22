/**
 * Targeted-elicitation support (Wave G.4 — Design §3.4 / §4.18).
 *
 * Three tool sites use elicitation:
 *   - element_delete (destructive confirm)
 *   - element_unbind_source (destructive confirm)
 *   - element_bind_source (ambiguity resolution: ≥2 sources with the
 *     same `source_name` → ask the user which one)
 *
 * Architecture mirrors `~/Projekte/wootsup/packages/apimapper-mcp/src/
 * modules/apimapper/elicitation.ts` (the reference Goldstandard impl):
 *
 *   1. `toElicitationCapability(server)` — typed adapter that bridges the
 *      SDK's stricter discriminated `ElicitRequestFormParams` to the
 *      toolkit's looser `ElicitInputParams`. ONE documented cast, justified
 *      by the toolkit only ever emitting form-mode requests.
 *   2. `ElicitationCandidate` + `renderCandidateList` — a candidate is
 *      `{ id, label }`; the list renders as a readable bullet block for the
 *      mandatory non-elicitation fallback error.
 *   3. `ambiguityFallbackError` — the structured `errorResult` returned
 *      when elicitation is unavailable (unsupported client) or declined.
 *      Lists every candidate so the LLM (or operator) has an actionable
 *      recovery path: "retry with explicit param X set to one of …".
 *
 * MANDATORY non-elicitation fallback: `elicitChoice` returns `null` on a
 * client without elicitation support, on decline, and on cancel. Every
 * call site MUST then return a structured `errorResult` listing the
 * candidates with a recovery hint — never hang, never guess, never
 * silently pick.
 *
 * @license MIT
 */

import type {
    ElicitInputParams,
    McpServerWithElicitation,
} from '@getimo/mcp-toolkit';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { ElicitRequestFormParams } from '@modelcontextprotocol/sdk/types.js';

import { errorResult, type ToolResult } from './tool-builder.js';

export type { McpServerWithElicitation };

/**
 * Build the `McpServerWithElicitation` capability object the toolkit's
 * `elicitChoice` / `elicitConfirmation` consume, from the real `McpServer`.
 *
 * Why an adapter and not the bare `McpServer`:
 *   The toolkit's `McpServerWithElicitation.server.elicitInput` is typed
 *   with the loose `ElicitInputParams` (`mode: "form" | "url"`,
 *   `requestedSchema?: Record<string, unknown>`). The SDK's
 *   `Server.elicitInput` is the stricter discriminated
 *   `ElicitRequestFormParams | ElicitRequestURLParams` with a fully-typed
 *   `requestedSchema`. A real `McpServer` is therefore NOT structurally
 *   assignable to `McpServerWithElicitation` — the parameter types are
 *   contravariantly incompatible.
 *
 *   The toolkit's `elicitChoice` / `elicitConfirmation` ALWAYS call with
 *   `mode: "form"` and a `requestedSchema` whose properties are plain
 *   `{ type: "string", enum: [...] }` / `{ type: "boolean" }` shapes —
 *   i.e. always a valid `ElicitRequestFormParams` at runtime. This adapter
 *   is the single, documented interop boundary that re-narrows the loose
 *   toolkit params to the SDK's form-params type.
 */
export function toElicitationCapability(
    realServer: McpServer,
): McpServerWithElicitation {
    return {
        server: {
            elicitInput: (params: ElicitInputParams) =>
                // Cast: the toolkit only ever produces form-mode elicitation
                // requests (verified: elicitChoice + elicitConfirmation both pass
                // `mode: "form"` with a string/boolean `requestedSchema`). The
                // loose `ElicitInputParams` is asserted to the SDK's
                // `ElicitRequestFormParams` so the SDK's overloaded
                // `elicitInput` accepts it. The runtime payload is exactly a
                // valid form request — only the static type is widened on the
                // toolkit side.
                realServer.server.elicitInput(params as ElicitRequestFormParams),
        },
    };
}

/**
 * A disambiguation candidate. `id` is the value a tool needs (source ID,
 * credential ID, …); `label` is a human-readable name shown in the
 * elicitation prompt and the fallback error list.
 */
export interface ElicitationCandidate {
    readonly id: string;
    readonly label: string;
}

/**
 * Render a candidate list as a readable bullet block for the structured
 * fallback error — so a client without elicitation support still gets a
 * fully actionable recovery path.
 */
export function renderCandidateList(candidates: readonly ElicitationCandidate[]): string {
    return candidates.map((c) => `  • ${c.id} — ${c.label}`).join('\n');
}

/**
 * Build the structured `errorResult` returned when elicitation is
 * unavailable (unsupported client) or the user declined / cancelled. The
 * error names every candidate and tells the caller exactly which
 * parameter to supply on retry.
 */
export function ambiguityFallbackError(opts: {
    code: string;
    paramName: string;
    what: string;
    candidates: readonly ElicitationCandidate[];
    context: Record<string, unknown>;
}): ToolResult {
    const list = renderCandidateList(opts.candidates);
    return errorResult({
        // We piggy-back on the local errorResult shape: `error` is the
        // human-readable message, `context` echoes inputs + candidate ids,
        // `hint` is the recovery instruction with the full candidate list.
        error: new Error(`Multiple ${opts.what} match — cannot pick automatically.`),
        context: {
            ...opts.context,
            code: opts.code,
            candidates: opts.candidates.map((c) => ({ id: c.id, label: c.label })),
        },
        hint:
            `Retry with an explicit \`${opts.paramName}\` set to one of:\n${list}`,
    });
}
