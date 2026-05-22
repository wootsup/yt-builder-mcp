/**
 * Page-tool WRITE handlers — `page_save`, `page_publish`. Both implement
 * the 3-phase progress contract (PHASE_SEND → PHASE_SERVER → PHASE_CONFIRM).
 * PHASE_SERVER is emitted BEFORE the await so the synthetic intermediate
 * fires even when the REST call ultimately errors — the dispatch IS the
 * boundary, not the response.
 *
 * Split out of `src/tools/pages.ts` (Round-2 R2-A2-CRIT1).
 *
 * @license MIT
 */

import type { RestClient } from '../../client.js';
import { PHASE_CONFIRM, PHASE_SEND, PHASE_SERVER } from '../progress-phases.js';
import {
    createProgressReporter,
    errorResult,
    jsonResult,
    type HandlerExtra,
    type ToolResult,
} from '../tool-builder.js';

interface SavePublishInput {
    template_id: string;
    etag?: string;
}

// ─── page_save ──────────────────────────────────────────────────────

export async function handlePageSave(
    client: RestClient,
    { template_id, etag }: SavePublishInput,
    extra?: HandlerExtra,
): Promise<ToolResult> {
    const progress = extra ? createProgressReporter(extra) : null;
    await progress?.report(0, 3, PHASE_SEND);
    await progress?.report(1, 3, PHASE_SERVER);
    try {
        const data = await client.post(
            `/pages/${encodeURIComponent(template_id)}/save`,
            { etag },
        );
        await progress?.report(2, 3, PHASE_CONFIRM);
        return jsonResult(data);
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id, etag },
            hint:
                'On 412 (precondition failed) refresh via yootheme_builder_get_etag ' +
                'and retry — someone else edited the page.',
        });
    }
}

// ─── page_publish ───────────────────────────────────────────────────

export async function handlePagePublish(
    client: RestClient,
    { template_id, etag }: SavePublishInput,
    extra?: HandlerExtra,
): Promise<ToolResult> {
    const progress = extra ? createProgressReporter(extra) : null;
    await progress?.report(0, 3, PHASE_SEND);
    await progress?.report(1, 3, PHASE_SERVER);
    try {
        const data = await client.post(
            `/pages/${encodeURIComponent(template_id)}/publish`,
            { etag },
        );
        await progress?.report(2, 3, PHASE_CONFIRM);
        return jsonResult(data);
    } catch (e) {
        return errorResult({
            error: e,
            context: { template_id, etag },
            hint:
                'On 412 (precondition failed) refresh via yootheme_builder_get_etag ' +
                'and retry.',
        });
    }
}
