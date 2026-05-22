/**
 * Element-tool write handlers (add/update/move/clone/delete).
 *
 * Wave G.4.0 split: read handlers + shared types in `./handlers.ts`.
 * Wave G.4.1: `handleElementDelete` consults `deps.elicitation` when
 * `confirm` is undefined — falls back to the explicit preview when
 * elicitation is unavailable.
 * Wave G.5: every handler emits 2-phase client-side progress
 * (`PHASE_SEND` → `PHASE_CONFIRM`) when the caller supplied a
 * `progressToken` — see `../progress-phases.ts` for the contract.
 *
 * @license MIT
 */

import { elicitConfirmation } from '@getimo/mcp-toolkit';

import { encodeElementPath } from '../../client.js';
import { PHASE_CONFIRM, PHASE_SEND } from '../progress-phases.js';
import {
    confirmGuard,
    createProgressReporter,
    errorResult,
    jsonResult,
    type HandlerExtra,
    type ToolResult,
} from '../tool-builder.js';
import type { ElementsHandlerDeps } from './handlers.js';

// ─── element_add ─────────────────────────────────────────────────────

export async function handleElementAdd(
    { client }: ElementsHandlerDeps,
    input: {
        template_id: string;
        parent_path: string;
        element_type: string;
        props?: Record<string, unknown>;
        children?: Record<string, unknown>[];
        etag: string;
    },
    extra?: HandlerExtra,
): Promise<ToolResult> {
    const { template_id, etag, ...body } = input;
    const progress = extra ? createProgressReporter(extra) : null;
    await progress?.report(0, 2, PHASE_SEND);
    try {
        const data = await client.post(
            `/pages/${encodeURIComponent(template_id)}/elements`,
            { body, etag },
        );
        await progress?.report(2, 2, PHASE_CONFIRM);
        return jsonResult(data);
    } catch (e) {
        return errorResult({
            error: e,
            context: input,
            hint:
                'On 412 refresh via yootheme_builder_get_etag and retry. On 400 ' +
                'check `element_type` is valid via yootheme_builder_element_types_list.',
        });
    }
}

// ─── element_update_settings ─────────────────────────────────────────

export async function handleElementUpdateSettings(
    { client }: ElementsHandlerDeps,
    input: {
        template_id: string;
        element_path: string;
        props: Record<string, unknown>;
        etag: string;
    },
    extra?: HandlerExtra,
): Promise<ToolResult> {
    const { template_id, element_path, props, etag } = input;
    const encoded = encodeElementPath(element_path);
    const progress = extra ? createProgressReporter(extra) : null;
    await progress?.report(0, 2, PHASE_SEND);
    try {
        const data = await client.put(
            `/pages/${encodeURIComponent(template_id)}/elements/${encoded}/settings`,
            { body: { props }, etag },
        );
        await progress?.report(2, 2, PHASE_CONFIRM);
        return jsonResult(data);
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id, element_path },
            hint:
                'On 412 refresh via yootheme_builder_get_etag and retry. To merge ' +
                'props instead of replacing, fetch via yootheme_builder_element_get ' +
                'first and pass the merged result.',
        });
    }
}

// ─── element_move ────────────────────────────────────────────────────

export async function handleElementMove(
    { client }: ElementsHandlerDeps,
    input: {
        template_id: string;
        element_path: string;
        to_parent_path: string;
        to_index: number;
        etag: string;
    },
    extra?: HandlerExtra,
): Promise<ToolResult> {
    const { template_id, element_path, to_parent_path, to_index, etag } = input;
    const encoded = encodeElementPath(element_path);
    const progress = extra ? createProgressReporter(extra) : null;
    await progress?.report(0, 2, PHASE_SEND);
    try {
        const data = await client.post(
            `/pages/${encodeURIComponent(template_id)}/elements/${encoded}/move`,
            { body: { to_parent_path, to_index }, etag },
        );
        await progress?.report(2, 2, PHASE_CONFIRM);
        return jsonResult(data);
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id, element_path, to_parent_path, to_index },
            hint:
                'Verify destination parent accepts the element type. On 412 refresh ' +
                'ETag and retry.',
        });
    }
}

// ─── element_clone ───────────────────────────────────────────────────

export async function handleElementClone(
    { client }: ElementsHandlerDeps,
    input: { template_id: string; element_path: string; etag: string },
    extra?: HandlerExtra,
): Promise<ToolResult> {
    const { template_id, element_path, etag } = input;
    const encoded = encodeElementPath(element_path);
    const progress = extra ? createProgressReporter(extra) : null;
    await progress?.report(0, 2, PHASE_SEND);
    try {
        const data = await client.post(
            `/pages/${encodeURIComponent(template_id)}/elements/${encoded}/clone`,
            { etag },
        );
        await progress?.report(2, 2, PHASE_CONFIRM);
        return jsonResult(data);
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id, element_path },
            hint: 'On 412 refresh via yootheme_builder_get_etag and retry.',
        });
    }
}

// ─── element_delete (destructive — elicitation + confirm-guard) ─────
//
// Wave G.4.1 — destructive confirm via MCP elicitation. Progress
// reports (Wave G.5) only start AFTER the confirm gate clears.

async function resolveDeleteConfirm(
    elicitation: ElementsHandlerDeps['elicitation'],
    template_id: string,
    element_path: string,
    confirm: boolean | undefined,
): Promise<ToolResult | null> {
    if (confirm === true) return null;
    if (confirm === false || !elicitation) {
        return confirmGuard({
            operation: 'delete element',
            details: { template_id, element_path },
        });
    }
    const accepted = await elicitConfirmation(
        elicitation,
        `Permanently delete element at "${element_path}" in template ` +
            `"${template_id}"? This cannot be undone.`,
    );
    return accepted
        ? null
        : confirmGuard({
              operation: 'delete element',
              details: { template_id, element_path },
          });
}

export async function handleElementDelete(
    { client, elicitation }: ElementsHandlerDeps,
    input: { template_id: string; element_path: string; etag: string; confirm?: boolean },
    extra?: HandlerExtra,
): Promise<ToolResult> {
    const { template_id, element_path, etag, confirm } = input;
    const blocked = await resolveDeleteConfirm(elicitation, template_id, element_path, confirm);
    if (blocked) return blocked;

    const encoded = encodeElementPath(element_path);
    const progress = extra ? createProgressReporter(extra) : null;
    await progress?.report(0, 2, PHASE_SEND);
    try {
        const data = await client.delete(
            `/pages/${encodeURIComponent(template_id)}/elements/${encoded}`,
            { etag },
        );
        await progress?.report(2, 2, PHASE_CONFIRM);
        return jsonResult(data);
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id, element_path },
            hint:
                'On 412 refresh via yootheme_builder_get_etag and retry. On 404 the ' +
                'element may already be gone — verify with yootheme_builder_element_list.',
        });
    }
}
