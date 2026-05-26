/**
 * W12-R3 (F-A3-2) — Live-verify catalogue pin.
 *
 * The `scripts/live-verify.mjs` driver carries two hand-maintained
 * tool catalogues (`SURFACE_TOOLS` + `ADVANCED_TOOLS`) that together
 * MUST cover the entire registered tool surface. If a future PR adds a
 * tool but forgets to append it to the catalogue, the live-verify
 * report silently under-tests the surface — exactly the failure mode
 * that the W7 audit caught for `sites_list` + `sites_test`.
 *
 * Strategy: parse the catalogue arrays out of the .mjs source verbatim
 * (no module import — the .mjs is a Node script, not a TS module) and
 * pin both the total count + the presence of the two W7 sites tools.
 *
 * Refactoring `live-verify.mjs` to export the arrays would be cleaner
 * but cross-cutting (the script is run as a subprocess by CI); a
 * source-grep keeps the pin scoped to the catalogue contract without
 * touching the driver.
 *
 * @license MIT
 */

import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const LIVE_VERIFY_PATH = resolve(
    __dirname,
    '..',
    '..',
    'scripts',
    'live-verify.mjs',
);

/**
 * Extract the `name: '...'` entries from a named array literal in the
 * live-verify source. Conservative regex: matches the array body until
 * the first balanced `];` at column-start (the two catalogues in the
 * file both end this way). Returns the list of tool names in
 * declaration order.
 */
function extractToolNames(source: string, arrayName: string): readonly string[] {
    const startRe = new RegExp(`const\\s+${arrayName}\\s*=\\s*\\[`);
    const startMatch = startRe.exec(source);
    if (startMatch === null) {
        throw new Error(`could not locate ${arrayName} declaration`);
    }
    const startIdx = startMatch.index + startMatch[0].length;
    // Walk forward to find the matching closing `];` at the start of a
    // line — both catalogues use a single trailing `];` newline.
    const tail = source.slice(startIdx);
    const endMatch = /\n\];/.exec(tail);
    if (endMatch === null) {
        throw new Error(`could not locate end of ${arrayName} declaration`);
    }
    const body = tail.slice(0, endMatch.index);
    // Match only the tool-entry `name:` field (key always sits at the
    // start of its line, possibly after `{` + whitespace). Excludes
    // accidental matches against `source_name:` / `type_name:` /
    // `clientInfo: { name:` and similar nested fields.
    const nameRe = /(?:^|\n)\s*(?:\{\s*)?name:\s*'(yootheme_builder_[a-z_]+)'/g;
    const names: string[] = [];
    let m: RegExpExecArray | null;
    while ((m = nameRe.exec(body)) !== null) {
        const matched = m[1];
        if (typeof matched === 'string') names.push(matched);
    }
    return names;
}

describe('W12-R3 — live-verify catalogue pin', () => {
    const source = readFileSync(LIVE_VERIFY_PATH, 'utf-8');
    const surface = extractToolNames(source, 'SURFACE_TOOLS');
    const advanced = extractToolNames(source, 'ADVANCED_TOOLS');

    it('SURFACE_TOOLS + ADVANCED_TOOLS total === 26 (matches the registered tool surface)', () => {
        // W7 raised the live-verify surface to 26 (24 + sites_list +
        // sites_test). description-length.test.ts already pins the
        // registered-tool count at 26; this pin keeps the live-verify
        // catalogue in lockstep so a future tool add can never silently
        // be omitted from CI's live-verify report.
        expect(surface.length + advanced.length).toBe(26);
    });

    it('SURFACE_TOOLS contains yootheme_builder_sites_list (W7 L1 promotion)', () => {
        expect(surface).toContain('yootheme_builder_sites_list');
    });

    it('SURFACE_TOOLS contains yootheme_builder_sites_test (W7 L1 promotion)', () => {
        expect(surface).toContain('yootheme_builder_sites_test');
    });
});
