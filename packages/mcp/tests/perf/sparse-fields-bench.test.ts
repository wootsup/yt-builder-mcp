/**
 * Sparse-fields perf sanity check.
 *
 * NOT the full Wave G.9 token-baseline benchmark — this is a per-Wave
 * pin that asserts the ≥30% reduction target from Design §3.5 holds for
 * the canonical "list 100 elements with default vs minimal projection"
 * workflow. If it fails, sparse-fields stopped doing useful work.
 *
 * Generates a synthetic 100-row payload (realistic shape for
 * `element_list`), then measures `JSON.stringify(structuredContent.items)`
 * length with and without `fields: ['path','element_type']`. Reduction
 * must be ≥ 30%.
 *
 * @license MIT
 */

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import { describe, expect, it, vi } from 'vitest';

import type { ClientPool } from '../../src/sites/client-pool.js';
import { buildElementsTools } from '../../src/tools/elements.js';
import { makeTestPool } from '../helpers/test-pool.js';

function fakeClient(handler: () => Response): ClientPool {
    return makeTestPool({
        baseUrl: 'https://example.com',
        bearer: 't',
        fetch: vi.fn(async () => handler()) as unknown as typeof fetch,
    });
}

function jsonResponse(body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}

/**
 * Build a realistic 100-element payload. Each row carries the four
 * default columns plus three "wide" fields that simulate the props /
 * source-binding / metadata payload an actual YT template returns. The
 * realistic-payload size dominates the projection delta — without the
 * extras the reduction wouldn't be meaningful.
 */
function makeRealisticElements(count: number): Array<Record<string, unknown>> {
    return Array.from({ length: count }, (_, i) => ({
        path: `/0/children/${String(i)}`,
        element_type: 'card',
        label: `Card ${String(i)}`,
        has_binding: i % 3 === 0,
        // Wide fields the AI usually doesn't need for navigation
        props: {
            title: `Card ${String(i)} headline goes here and is reasonably long`,
            margin: 'default',
            class: 'uk-card uk-card-default uk-card-body uk-card-hover',
            text_align: 'center',
            image: `https://example.com/images/card-${String(i)}.jpg`,
        },
        source_config: {
            source_name: i % 3 === 0 ? 'wp_posts' : null,
            template: i % 3 === 0 ? '{{post.title}}' : null,
        },
        metadata: {
            created_at: '2026-05-22T10:00:00Z',
            updated_at: '2026-05-22T10:00:00Z',
            modified_by: 'admin',
        },
    }));
}

describe('sparse-fields perf bench (per-Wave G.3 sanity check)', () => {
    it('reduces structuredContent.items size by ≥30% when narrowing to default columns', async () => {
        const elements = makeRealisticElements(100);
        const tools = buildElementsTools(fakeClient(() => jsonResponse({ elements })));
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_list')!;

        // Without fields[]: full-shape items
        const full = await tool.handler({ template_id: 'home' });
        const fullBytes = JSON.stringify(full.structuredContent?.items ?? []).length;

        // With fields=['path','element_type']: narrowed items
        const sparse = await tool.handler({
            template_id: 'home',
            fields: ['path', 'element_type'],
        });
        const sparseBytes = JSON.stringify(sparse.structuredContent?.items ?? []).length;

        const reductionPct = ((fullBytes - sparseBytes) / fullBytes) * 100;

        // Per Design §3.5 target: 30% minimum on canonical workflow.
        // Log the numbers so a future regression makes the failure mode
        // obvious (we'd see "29% reduction, target 30%" rather than just
        // "false vs true").
        // eslint-disable-next-line no-console -- benchmark surface
        console.log(
            `[sparse-fields bench] full=${String(fullBytes)}B sparse=${String(sparseBytes)}B Δ=${reductionPct.toFixed(2)}%`,
        );
        expect(reductionPct).toBeGreaterThanOrEqual(30);
    });

    it('projected_fields is echoed in the sparse response', async () => {
        const elements = makeRealisticElements(5);
        const tools = buildElementsTools(fakeClient(() => jsonResponse({ elements })));
        const tool = tools.find((t) => t.name === 'yootheme_builder_element_list')!;
        const sparse = await tool.handler({
            template_id: 'home',
            fields: ['path', 'element_type'],
        });
        expect(sparse.structuredContent?.projected_fields).toEqual(['path', 'element_type']);
    });
});
