/**
 * Description-length pin — Wave G.6.6.
 *
 * Token-efficiency budget: every tool description must stay at or
 * under 250 chars so `tools/list` remains compact across all 23
 * registered tools.
 *
 * @license MIT
 */

import { describe, expect, test } from 'vitest';

import { WordPressPlatform } from '../../src/platform/index.js';
import { RestClient } from '../../src/client.js';
import { buildAllTools } from '../../src/tools/index.js';

const MAX_DESCRIPTION_LENGTH = 250;

function makeStubClient(): RestClient {
    return new RestClient({
        platform: new WordPressPlatform('https://stub.example'),
        bearerToken: 'stub',
        fetch: (async () =>
            new Response('{}', { status: 200 })) as unknown as typeof fetch,
    });
}

describe('Tool description-length pin', () => {
    const tools = buildAllTools(makeStubClient());

    test('every registered tool description ≤ 250 chars', () => {
        const overBudget = tools
            .map((t) => ({
                name: t.name,
                length: t.description.length,
            }))
            .filter((t) => t.length > MAX_DESCRIPTION_LENGTH);
        if (overBudget.length > 0) {
            // eslint-disable-next-line no-console -- diagnostic on failure
            console.error('Description budget exceeded:', overBudget);
        }
        expect(overBudget).toEqual([]);
    });

    test('every description is non-empty', () => {
        const empty = tools.filter((t) => t.description.trim() === '');
        expect(empty).toEqual([]);
    });

    test('tool count matches expected registered surface', () => {
        // 2 health + 6 pages + 7 elements + 4 sources + 2 multi-items + 2 inspection = 23
        expect(tools.length).toBe(24);
    });
});
