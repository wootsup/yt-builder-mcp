/**
 * Wave H6c (v1.1.6) — pair-list truthfulness pin.
 *
 * The 16 Multi-Items container types are declared once in
 * `item-container-map.ts`. Customer-facing surfaces (CHANGELOG, in-product
 * skill) must list those 16 container types one-to-one, with no
 * non-existent entries (the historical "Slider" / "Buttons" drift) and
 * no missing entries (the historical missing "Popover").
 *
 * This pin reads the canonical list from the TS map at runtime and checks
 * every customer-facing surface against it.
 *
 * @license MIT
 */

import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

import { ITEM_CHILDREN_OF_CONTAINER } from '../../../src/tools/multi-items/item-container-map.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = resolve(__dirname, '../../../../../..');
const CHANGELOG_PATH = resolve(REPO_ROOT, 'yt-builder-mcp/CHANGELOG.md');
const SKILL_PATH = resolve(
    REPO_ROOT,
    'yt-builder-mcp/packages/mcp/skills/yt-builder-mcp/SKILL.md',
);
const RELEASES_JSON_PATH = resolve(
    REPO_ROOT,
    'server/releases/yt-builder-mcp/releases.json',
);

/**
 * Map a canonical container key (e.g. `description_list`) to its
 * customer-facing pretty label (e.g. `Description List`). Hyphenated
 * container keys (`overlay-slider`) keep their hyphen; underscored keys
 * (`description_list`) become space-separated Title Case.
 */
function prettyLabel(containerKey: string): string {
    if (containerKey.includes('-')) {
        // overlay-slider → Overlay-Slider, panel-slider → Panel-Slider
        return containerKey
            .split('-')
            .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
            .join('-');
    }
    // description_list → Description List, button → Button
    return containerKey
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

const CANONICAL_CONTAINERS = Object.keys(ITEM_CHILDREN_OF_CONTAINER);
const CANONICAL_PRETTY = CANONICAL_CONTAINERS.map(prettyLabel);

// The CHANGELOG/SKILL bullet we care about lives in the v1.0.0 entry
// (the initial-release feature list). v1.1.x entries reference Multi-Items
// but do not enumerate the 16-container pretty-list themselves.
const CANONICAL_LIST_VERSION = '1.0.0';

// The historical wrong tokens that v1.0.0 — v1.1.5 carried in
// customer-facing copy. If any of them ever reappear in a customer
// surface as part of a Multi-Items enumeration, this test breaks.
//
// Notes on regex shape:
//   - We strip code-fenced (`backtick`) occurrences first because we
//     legitimately reference the wrong tokens INSIDE backticks in the
//     v1.1.6 "we corrected this" bullet ("incorrectly listed `Slider`").
//   - "Pro Slider" is a YOOtheme element TYPE label (an entry in the
//     element_types catalog, not a Multi-Items container). We allow it.
//
// Bare "Slider" / "Buttons" / "Carousel" as prose tokens are the real
// drift signal — those are the strings that appeared in the wrong
// pair-list enumerations.
const FORBIDDEN_TOKENS = [
    // "Slider" as a standalone container type does not exist on YT-Pro
    // 4.5.33. Legitimate variants are Overlay-Slider, Panel-Slider, and
    // Slideshow. Allow "Overlay-Slider" / "Panel-Slider" / "Pro Slider".
    /(?<!(?:Overlay|Panel)-|Pro )Slider(?![A-Za-z-])/,
    // "Buttons" plural is wrong — the singular pretty form is "Button".
    /(?<![A-Za-z])Buttons(?![A-Za-z])/,
    // "Carousel" was a fictional category in the legacy SKILL.md prose.
    /(?<![A-Za-z])Carousel(?![A-Za-z])/,
];

/**
 * Strip backtick-fenced spans so the forbidden-token scan only catches
 * bare prose mentions. The v1.1.6 "we fixed this" bullet legitimately
 * references the wrong tokens inside `code` fences — we explicitly want
 * that bullet to survive the scan.
 */
function stripCodeFences(s: string): string {
    return s.replace(/`[^`\n]+`/g, '');
}

describe('Multi-Items pair-list truthfulness (Wave H6c)', () => {
    it('canonical map has exactly 16 entries (live-verified against YT-Pro 4.5.33)', () => {
        expect(CANONICAL_CONTAINERS).toHaveLength(16);
    });

    it(`CHANGELOG.md v${CANONICAL_LIST_VERSION} entry mentions every pretty container label`, () => {
        const txt = readFileSync(CHANGELOG_PATH, 'utf8');
        // Locate the version section. The CHANGELOG uses `## [1.0.0]` style
        // headings.
        const versionEsc = CANONICAL_LIST_VERSION.replace(/\./g, '\\.');
        const startRe = new RegExp(`^##\\s*\\[?${versionEsc}\\]?\\b`, 'm');
        const startMatch = startRe.exec(txt);
        expect(
            startMatch,
            `v${CANONICAL_LIST_VERSION} heading not found in CHANGELOG.md`,
        ).not.toBeNull();
        // The section ends at the next `## ` heading (or EOF).
        const remaining = txt.slice(startMatch!.index);
        const nextHeading = remaining.slice(2).search(/^##\s/m);
        const section = nextHeading > -1 ? remaining.slice(0, nextHeading + 2) : remaining;

        for (const pretty of CANONICAL_PRETTY) {
            expect(
                section.includes(pretty),
                `CHANGELOG v${CANONICAL_LIST_VERSION} entry is missing pretty container label "${pretty}"`,
            ).toBe(true);
        }
    });

    it('CHANGELOG.md prose contains none of the forbidden legacy tokens (code-fenced mentions allowed)', () => {
        const txt = stripCodeFences(readFileSync(CHANGELOG_PATH, 'utf8'));
        for (const pattern of FORBIDDEN_TOKENS) {
            const match = pattern.exec(txt);
            expect(
                match,
                `CHANGELOG.md contains forbidden legacy token /${pattern.source}/ ` +
                    `at index ${match?.index}: "${match?.[0]}". The canonical map has no such ` +
                    `container type — see item-container-map.ts.`,
            ).toBeNull();
        }
    });

    it('SKILL.md prose contains none of the forbidden legacy tokens (code-fenced + element-type labels allowed)', () => {
        const txt = stripCodeFences(readFileSync(SKILL_PATH, 'utf8'));
        for (const pattern of FORBIDDEN_TOKENS) {
            const match = pattern.exec(txt);
            expect(
                match,
                `SKILL.md contains forbidden legacy token /${pattern.source}/ ` +
                    `at index ${match?.index}: "${match?.[0]}". The canonical map has no such ` +
                    `container type — see item-container-map.ts.`,
            ).toBeNull();
        }
    });

    it(`releases.json v${CANONICAL_LIST_VERSION} entry mentions every pretty container label`, () => {
        const raw = readFileSync(RELEASES_JSON_PATH, 'utf8');
        const json = JSON.parse(raw) as {
            releases: Array<{ version: string; changelog: Record<string, unknown> }>;
        };
        const entry = json.releases.find((r) => r.version === CANONICAL_LIST_VERSION);
        expect(
            entry,
            `v${CANONICAL_LIST_VERSION} release entry not found in releases.json`,
        ).toBeDefined();
        const text = JSON.stringify(entry!.changelog);
        for (const pretty of CANONICAL_PRETTY) {
            expect(
                text.includes(pretty),
                `releases.json v${CANONICAL_LIST_VERSION} changelog is missing pretty label "${pretty}"`,
            ).toBe(true);
        }
    });
});
