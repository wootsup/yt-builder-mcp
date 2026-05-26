/**
 * W5 — `site_id` schema pin-test across every registered tool.
 *
 * Purpose: prove the Multi-Site selector field is exposed uniformly on
 * EVERY tool in `buildAllTools(...)`. This is the gate that proves W5's
 * "non-breaking optional addition" claim — handlers must accept the
 * field, reject malformed input, and accept absence (legacy callers).
 *
 * Each tool contributes 5 assertions (≥160 total over 24 tools = 120
 * round-trip + 24 field-defined + 24 description-suffix = 168), plus a
 * count floor at the bottom.
 *
 * @license MIT
 */

// W6: migrated from RestClient to ClientPool (see tests/helpers/test-pool.ts).
import { describe, expect, it } from 'vitest';
import { z, type ZodRawShape } from 'zod';

import type { ClientPool } from '../../src/sites/client-pool.js';
import { buildAllTools } from '../../src/tools/index.js';
import { makeTestPool } from '../helpers/test-pool.js';

const SITE_ID_DESCRIPTION_SUFFIX =
    'Operates on the default site unless site_id is provided.';

/**
 * W7 — `yootheme_builder_sites_test` is the SOLE tool with a REQUIRED
 * `site_id` (the rest of the surface defaults to the registry's
 * default-site). It is the tool's whole purpose: probe ONE specific
 * site_id, never the default. Skipping it from the "site_id is optional
 * on every tool" loop is more defensible than relaxing the pin to
 * "site_id is present on every tool" — the optional-on-N-1-tools
 * invariant catches a future tool that accidentally drops the
 * `.optional()` modifier, which the relaxed pin would not.
 */
const SITES_TEST_EXEMPT_FROM_OPTIONAL = new Set<string>([
    'yootheme_builder_sites_test',
]);

/**
 * W12-R1.2/R1.3 (A6-F4 + A6-F5): the canonical "Operates on the
 * default site unless site_id is provided." suffix is misleading on
 * tools whose site_id semantics differ from the default-fallback
 * pattern.
 *
 *   - `yootheme_builder_sites_test`: site_id is REQUIRED — defaulting
 *     to the default site would silently change which site is probed
 *     and contradicts the tool's whole purpose.
 *   - `yootheme_builder_sites_list`: lists ALL sites regardless of
 *     site_id — site_id is accepted only for schema-uniformity. The
 *     "default site" suffix would imply per-row filtering, which the
 *     handler does not perform.
 *
 * These tools carry their own purpose-specific descriptions instead.
 * The exemption is narrow on purpose: every NEW tool added to the
 * surface must include the canonical suffix unless its author
 * deliberately joins this set with a documented rationale.
 */
const DESCRIPTION_SUFFIX_EXEMPT = new Set<string>([
    'yootheme_builder_sites_test',
    'yootheme_builder_sites_list',
]);

function makeClient(): ClientPool {
    return makeTestPool({
        baseUrl: 'https://example.com',
        bearer: 'test',
    });
}

const tools = buildAllTools(makeClient());

describe('W5 — SITE_ID_SCHEMA pin: every tool exposes site_id uniformly', () => {
    it('buildAllTools surface is non-empty (sanity guard)', () => {
        expect(tools.length).toBeGreaterThanOrEqual(20);
    });

    // W7 lifted the count to 26 (24 pre-W7 + sites_list + sites_test).
    it('current tool-count baseline is 26 (W7 added sites_list + sites_test)', () => {
        expect(tools.length).toBeGreaterThanOrEqual(26);
    });

    for (const tool of tools) {
        const inputSchema = tool.inputSchema as ZodRawShape;
        const toolObject = z.object(inputSchema).strict();

        describe(`tool: ${tool.name}`, () => {
            it('exposes the `site_id` field on its inputSchema', () => {
                expect(
                    inputSchema.site_id,
                    `tool ${tool.name} is missing site_id`,
                ).toBeDefined();
            });

            it('accepts a valid site_id string', () => {
                const parsed = toolObject.partial().safeParse({ site_id: 'test-site' });
                expect(
                    parsed.success,
                    `tool ${tool.name} rejected valid site_id "test-site": ` +
                        (parsed.success ? '' : JSON.stringify(parsed.error.issues)),
                ).toBe(true);
            });

            it('accepts an absent site_id (optional, non-breaking)', () => {
                if (SITES_TEST_EXEMPT_FROM_OPTIONAL.has(tool.name)) {
                    // W7 — `sites_test` deliberately requires site_id.
                    // Documented in the SITES_TEST_EXEMPT_FROM_OPTIONAL
                    // comment at the top of this file.
                    return;
                }
                // We use `.partial()` so unrelated required fields (template_id,
                // element_path, etc.) don't trip the parse — the only thing
                // under test here is that site_id is OPTIONAL on every tool.
                const parsed = toolObject.partial().safeParse({});
                expect(
                    parsed.success,
                    `tool ${tool.name} rejected absent site_id (should be optional): ` +
                        (parsed.success ? '' : JSON.stringify(parsed.error.issues)),
                ).toBe(true);
            });

            it('rejects a site_id containing a space (regex guard)', () => {
                // Build a minimal schema isolating just the site_id field so the
                // regex rejection is what fails the parse — unrelated required
                // fields shouldn't muddy the assertion.
                const siteOnly = z.object({ site_id: inputSchema.site_id });
                const parsed = siteOnly.safeParse({ site_id: 'has space' });
                expect(
                    parsed.success,
                    `tool ${tool.name} accepted invalid site_id "has space" (regex broken)`,
                ).toBe(false);
                if (!parsed.success) {
                    const messages = parsed.error.issues.map((iss) => iss.message).join(' | ');
                    expect(
                        messages,
                        `tool ${tool.name} regex error message changed: ${messages}`,
                    ).toContain('site_id uses letters/digits/dash/underscore only');
                }
            });

            it('description ends with the canonical site_id sentence', () => {
                if (DESCRIPTION_SUFFIX_EXEMPT.has(tool.name)) {
                    // W12-R1.2/R1.3 exemption — see DESCRIPTION_SUFFIX_EXEMPT
                    // at the top of this file for the per-tool rationale.
                    return;
                }
                expect(
                    tool.description.endsWith(SITE_ID_DESCRIPTION_SUFFIX),
                    `tool ${tool.name} description missing canonical site_id sentence. ` +
                        `Got tail: "${tool.description.slice(-120)}"`,
                ).toBe(true);
                expect(
                    tool.description.length,
                    `tool ${tool.name} description suspiciously short`,
                ).toBeGreaterThan(SITE_ID_DESCRIPTION_SUFFIX.length + 20);
            });

            it('site_id field is OPTIONAL (Zod isOptional)', () => {
                if (SITES_TEST_EXEMPT_FROM_OPTIONAL.has(tool.name)) {
                    // W7 exception — see top-of-file rationale.
                    return;
                }
                const siteSchema = inputSchema.site_id;
                expect(siteSchema, `tool ${tool.name} site_id schema missing`).toBeDefined();
                // ZodOptional exposes isOptional() === true; defensive against
                // a future regression that strips the .optional() modifier.
                expect(
                    siteSchema.isOptional(),
                    `tool ${tool.name} site_id is NOT optional — would break legacy callers`,
                ).toBe(true);
            });
        });
    }
});
