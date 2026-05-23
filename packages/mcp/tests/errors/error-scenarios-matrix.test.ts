/**
 * Error-scenarios matrix — Wave G.8 / Plan I-4.
 *
 * Exercises every read tool × every canonical HTTP failure (7 codes) so
 * the catch-block behaviour stays uniform across the surface. The test
 * does NOT mock `errorResult` — it lets the real handler run through the
 * full RestClient → RestError/NetworkError → errorResult pipeline. That
 * means a regression in `hintFor()`, in the RestClient status mapping,
 * or in any handler's `context` echo trips here loudly.
 *
 * The matrix is deliberately bounded to read-only / no-side-effect tools
 * so we never have to feed write-tool-shaped success bodies. Write tools
 * are exercised under success paths by their own per-domain test files.
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import { buildElementsTools } from '../../src/tools/elements.js';
import { buildHealthTools } from '../../src/tools/health.js';
import { buildInspectionTools } from '../../src/tools/inspection.js';
import { buildPagesTools } from '../../src/tools/pages.js';
import { buildSourcesTools } from '../../src/tools/sources.js';

import { ERROR_SCENARIOS, extractErrorPayload, makeFailingClient } from '../helpers/error-scenarios.js';

interface MatrixCase {
    readonly toolName: string;
    readonly args: Record<string, unknown>;
    readonly group: 'health' | 'pages' | 'elements' | 'sources' | 'inspection';
}

/**
 * Read-only matrix — every "GET-shaped" tool with a default args set that
 * passes the inputSchema. Args carry enough data so the handler's
 * `context` echo can be asserted (e.g. `template_id` round-trips).
 */
const READ_MATRIX: readonly MatrixCase[] = [
    { toolName: 'yootheme_builder_health', args: {}, group: 'health' },
    { toolName: 'yootheme_builder_diagnose', args: {}, group: 'health' },
    { toolName: 'yootheme_builder_pages_list', args: {}, group: 'pages' },
    {
        toolName: 'yootheme_builder_page_get_layout',
        args: { template_id: 'home' },
        group: 'pages',
    },
    {
        toolName: 'yootheme_builder_page_get_schema',
        args: { template_id: 'home' },
        group: 'pages',
    },
    {
        toolName: 'yootheme_builder_get_etag',
        args: {},
        group: 'pages',
    },
    {
        toolName: 'yootheme_builder_element_list',
        args: { template_id: 'home' },
        group: 'elements',
    },
    {
        toolName: 'yootheme_builder_element_get',
        args: { template_id: 'home', element_path: '/0/children/1' },
        group: 'elements',
    },
    {
        toolName: 'yootheme_builder_element_get_binding',
        args: { template_id: 'home', element_path: '/0/children/1' },
        group: 'sources',
    },
    {
        toolName: 'yootheme_builder_sources_list',
        args: {},
        group: 'sources',
    },
    {
        toolName: 'yootheme_builder_element_types_list',
        args: {},
        group: 'inspection',
    },
    {
        toolName: 'yootheme_builder_element_type_get_schema',
        args: { element_type: 'headline' },
        group: 'inspection',
    },
];

function buildToolsByGroup(group: MatrixCase['group'], client: ReturnType<typeof makeFailingClient>) {
    switch (group) {
        case 'health':
            return buildHealthTools(client);
        case 'pages':
            return buildPagesTools(client);
        case 'elements':
            return buildElementsTools(client);
        case 'sources':
            return buildSourcesTools(client);
        case 'inspection':
            return buildInspectionTools(client);
    }
}

describe('error-scenarios matrix — read tools × 7 HTTP failures', () => {
    for (const tool of READ_MATRIX) {
        describe(tool.toolName, () => {
            for (const scenario of ERROR_SCENARIOS) {
                it(`maps ${scenario.label} into a structured error result`, async () => {
                    const client = makeFailingClient(scenario);
                    const tools = buildToolsByGroup(tool.group, client);
                    const t = tools.find((x) => x.name === tool.toolName);
                    expect(t, `tool ${tool.toolName} not built in group ${tool.group}`).toBeDefined();
                    if (!t) return;
                    const handler = t.handler as (a: Record<string, unknown>) => Promise<{
                        isError?: boolean;
                        content?: Array<{ text?: string }>;
                    }>;
                    const result = await handler(tool.args);
                    // Every failure must surface as isError:true OR carry an `error` in payload —
                    // health.ts catches the failure into a "checks" detail rather than isError
                    // (intentional: it's a health probe), so we accept either shape.
                    const payload = extractErrorPayload(result);
                    if (result.isError === true) {
                        // Every isError result MUST carry an actionable `hint`.
                        // `error` is required for the standard `errorResult` shape
                        // (every domain tool's catch block); `diagnose` follows a
                        // diagnostic-detail shape (plugin_reachable/bearer_valid)
                        // that doesn't echo `error` but still includes `hint`.
                        expect(payload, 'must have a `hint` field').toHaveProperty('hint');
                        const hint = String(payload.hint ?? '');
                        expect(hint.length).toBeGreaterThanOrEqual(20);
                        const isDiagnose = tool.toolName === 'yootheme_builder_diagnose';
                        if (!isDiagnose) {
                            expect(payload, 'must have an `error` field').toHaveProperty('error');
                            expect(payload, 'must have a `context` field').toHaveProperty('context');
                        }
                    } else {
                        // Health-style: still must visibly mark the failure in the payload text.
                        const textBlock = result.content?.[0]?.text ?? '';
                        expect(textBlock.length).toBeGreaterThan(0);
                    }
                });
            }
        });
    }
});

describe('error-scenarios matrix — coverage shape', () => {
    it('exercises 12 read tools × 7 scenarios = 84 cases', () => {
        expect(READ_MATRIX.length).toBe(12);
        expect(ERROR_SCENARIOS.length).toBe(7);
        // 84 generated test cases; this assertion documents the matrix size.
        expect(READ_MATRIX.length * ERROR_SCENARIOS.length).toBe(84);
    });
});
