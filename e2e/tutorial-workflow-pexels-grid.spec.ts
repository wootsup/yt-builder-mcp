/**
 * E2E — Tutorial workflow: add a Pexels-bound image grid to a YOOtheme page.
 *
 * Wave 5 ships this as a SKELETON only. All assertions are `.skip`'d.
 * Wave 6 (post-audit) implements them and runs the suite against
 * dev.wootsup.com under an explicit approval-gate.
 *
 * What this suite proves once enabled:
 *
 *  1. The full happy-path lifecycle:
 *     pages_list → get_etag → element_add (grid) → element_bind_source
 *     → page_save → page_publish, with each step's response feeding the next.
 *
 *  2. Optimistic-lock correctness — a second write with a stale ETag
 *     must return 412.
 *
 *  3. Cleanup — the inserted grid is removed after the test even when
 *     individual assertions fail. We do not want orphan test grids
 *     accumulating on dev.wootsup.com.
 *
 *  4. Both happy paths and the destructive-tool annotation surface
 *     (element_delete) behave as documented.
 */

import { test, expect } from '@playwright/test';

const TEMPLATE_ID = process.env.YTB_MCP_E2E_TEMPLATE_ID ?? 'default';
const SOURCE_NAME = process.env.YTB_MCP_E2E_SOURCE_NAME ?? 'pexels-search';

test.describe('Tutorial workflow — Pexels-bound image grid', () => {
    test.skip(
        true,
        'Wave 6 — implementation gated on 10/10 audit + explicit approval to run against dev.wootsup.com.',
    );

    test('lists pages and returns at least one template', async ({ request }) => {
        const res = await request.get('/wp-json/yt-builder-mcp/v1/pages');
        expect(res.ok()).toBeTruthy();
        const body = await res.json();
        expect(body.pages.length).toBeGreaterThan(0);
        expect(body.etag).toBeTruthy();
    });

    test('reads ETag, then adds a grid element, then saves', async ({ request }) => {
        // 1. Fetch ETag.
        const etagRes = await request.get('/wp-json/yt-builder-mcp/v1/etag');
        const { etag } = await etagRes.json();

        // 2. Add grid under section/0/row/0/column/0.
        const addRes = await request.post(
            `/wp-json/yt-builder-mcp/v1/pages/${TEMPLATE_ID}/elements`,
            {
                headers: { 'If-Match': etag },
                data: {
                    parent_path: 'section/0/row/0/column/0',
                    element: { type: 'grid', props: { columns: 3 } },
                    position: 'append',
                },
            },
        );
        expect(addRes.ok()).toBeTruthy();
        const { path, etag: newEtag } = await addRes.json();
        expect(path).toContain('grid');

        // 3. Save with the new ETag.
        const saveRes = await request.post(
            `/wp-json/yt-builder-mcp/v1/pages/${TEMPLATE_ID}/save`,
            { headers: { 'If-Match': newEtag } },
        );
        expect(saveRes.ok()).toBeTruthy();
    });

    test('binds the new grid to a Dynamic Source', async ({ request }) => {
        // Implementation in Wave 6.
        // Steps: list elements → find the grid path → bind_source → assert binding round-trips.
        expect(true).toBe(true);
    });

    test('stale ETag write returns 412 Precondition Failed', async ({ request }) => {
        // Implementation in Wave 6.
        // Steps: read etag → run mutation A → re-run mutation B with the OLD etag → expect 412.
        expect(true).toBe(true);
    });

    test('cleanup — deletes the test grid', async ({ request }) => {
        // Implementation in Wave 6. Runs via test.afterAll() in the real suite.
        expect(true).toBe(true);
    });
});
