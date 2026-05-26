/**
 * W8 — `appendSitesBlock(skillContent, registry)` tests.
 *
 * Coverage focuses on five load-bearing behaviours:
 *
 *  1. **Empty registry → verbatim.** When the registry carries 0 sites,
 *     the returned string MUST equal the input verbatim — no separator,
 *     no header, no trailing whitespace change.
 *
 *  2. **Single-site shape.** One site → exactly one bullet, the
 *     header reads `(1)`, the "default" suffix is present iff
 *     `is_default:true`, the label fallback `(no label)` appears when
 *     the entry has none.
 *
 *  3. **Multi-site shape + ordering.** Three sites preserve insertion
 *     order, the header reads `(3)`, only the default-flagged row
 *     carries the ` · default` suffix, non-default rows do NOT.
 *
 *  4. **Bearer redaction.** The string `bearer`, the substring
 *     `bearer_ref`, the literal token prefix `ytb_live_`, and any
 *     1Password reference prefix `op://` MUST NOT appear in the
 *     output. The defence is structural (the bullet renderer only
 *     reads {@link SiteRowT} fields which {@link SiteRegistry.listForDisplay}
 *     explicitly scrubs), but the test pins it as a contract.
 *
 *  5. **Footer text.** The two CTAs from plan §W8 Z.914-916 MUST
 *     both be present.
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    appendSitesBlock,
    loadSkillContent,
} from '../src/skill-loader.js';
import { SiteRegistry } from '../src/sites/registry.js';
import type { SiteEntryT, SitesFileT } from '../src/sites/schema.js';

const SAMPLE_SKILL: string =
    '---\nname: test-skill\n---\n\nBody of the skill, not load-bearing.\n';

const VALID_BEARER: string =
    'ytb_live_eyJraWQiOiJ0LWtleSIsInNjb3BlIjoid3JpdGUifQ.abc123_xyz-def';

function entry(overrides: Partial<SiteEntryT> = {}): SiteEntryT {
    return {
        site_id: 'wp-default',
        url: 'https://example.com',
        platform: 'auto',
        bearer: VALID_BEARER,
        is_default: false,
        ...overrides,
    };
}

function registryFor(entries: readonly SiteEntryT[]): SiteRegistry {
    const file: SitesFileT = {
        schema_version: 1,
        default_site_id: null,
        sites: [...entries],
    };
    return new SiteRegistry(file);
}

describe('appendSitesBlock — empty registry', () => {
    it('returns the skill content verbatim when registry has 0 sites', () => {
        const registry = registryFor([]);
        const out = appendSitesBlock(SAMPLE_SKILL, registry);
        expect(out).toBe(SAMPLE_SKILL);
    });

    it('does not insert a separator/header when registry has 0 sites', () => {
        const registry = registryFor([]);
        const out = appendSitesBlock(SAMPLE_SKILL, registry);
        expect(out).not.toContain('Currently configured sites');
        expect(out).not.toContain('\n---\n\n## ');
    });
});

describe('appendSitesBlock — single site', () => {
    it('appends a "(1)" header bullet + bullet + footer when registry has 1 site', () => {
        const registry = registryFor([
            entry({
                site_id: 'wp-acme',
                url: 'https://acme.com',
                platform: 'wordpress',
                label: 'Acme — Production',
                is_default: true,
            }),
        ]);
        const out = appendSitesBlock(SAMPLE_SKILL, registry);
        expect(out.startsWith(SAMPLE_SKILL)).toBe(true);
        expect(out).toContain('## Currently configured sites (1)');
        expect(out).toContain(
            '- `wp-acme` — Acme — Production · wordpress · https://acme.com · default',
        );
    });

    it('uses "(no label)" fallback when the entry has no label field', () => {
        const registry = registryFor([
            entry({
                site_id: 'wp-internal',
                url: 'https://internal.example.com',
                platform: 'wordpress',
                is_default: false,
            }),
        ]);
        const out = appendSitesBlock(SAMPLE_SKILL, registry);
        expect(out).toContain(
            '- `wp-internal` — (no label) · wordpress · https://internal.example.com',
        );
        // Non-default site MUST NOT carry the " · default" suffix.
        expect(out).not.toContain('https://internal.example.com · default');
    });
});

describe('appendSitesBlock — multi-site', () => {
    it('preserves insertion order, marks only the default-flagged row', () => {
        const registry = registryFor([
            entry({
                site_id: 'wp-acme',
                url: 'https://acme.com',
                platform: 'wordpress',
                label: 'Acme — Production',
                is_default: true,
            }),
            entry({
                site_id: 'joomla-beta',
                url: 'https://beta.example.com/joomla',
                platform: 'joomla',
                label: 'Beta — Staging',
                is_default: false,
            }),
            entry({
                site_id: 'wp-internal',
                url: 'https://internal.example.com',
                platform: 'wordpress',
                label: 'Internal staging',
                is_default: false,
            }),
        ]);
        const out = appendSitesBlock(SAMPLE_SKILL, registry);

        expect(out).toContain('## Currently configured sites (3)');

        const acmeIdx = out.indexOf('- `wp-acme`');
        const betaIdx = out.indexOf('- `joomla-beta`');
        const internalIdx = out.indexOf('- `wp-internal`');

        expect(acmeIdx).toBeGreaterThan(-1);
        expect(betaIdx).toBeGreaterThan(-1);
        expect(internalIdx).toBeGreaterThan(-1);
        // Insertion order preserved.
        expect(acmeIdx).toBeLessThan(betaIdx);
        expect(betaIdx).toBeLessThan(internalIdx);

        // Default suffix appears ONCE (only on the flagged row).
        const defaultCount = out.split(' · default').length - 1;
        expect(defaultCount).toBe(1);

        // The default row is the acme one.
        expect(out).toContain(
            '- `wp-acme` — Acme — Production · wordpress · https://acme.com · default',
        );
        // Non-default rows do NOT carry the suffix.
        expect(out).toContain(
            '- `joomla-beta` — Beta — Staging · joomla · https://beta.example.com/joomla',
        );
        expect(out).not.toContain('https://beta.example.com/joomla · default');
        expect(out).toContain(
            '- `wp-internal` — Internal staging · wordpress · https://internal.example.com',
        );
        expect(out).not.toContain('https://internal.example.com · default');
    });
});

describe('appendSitesBlock — bearer redaction', () => {
    it('never includes bearer, bearer_ref, ytb_live_, or op:// in the output', () => {
        const registry = registryFor([
            entry({
                site_id: 'wp-acme',
                url: 'https://acme.com',
                platform: 'wordpress',
                label: 'Acme',
                bearer: VALID_BEARER,
                is_default: true,
            }),
            entry({
                site_id: 'joomla-1p',
                url: 'https://1p.example.com',
                platform: 'joomla',
                label: '1Password-backed',
                // bearer_ref path — proves the bearer-source field is
                // NOT leaked even when the entry uses op://.
                bearer: undefined,
                bearer_ref: 'op://Vault/Item/credential',
                is_default: false,
            }),
        ]);
        const out = appendSitesBlock(SAMPLE_SKILL, registry);

        expect(out.includes('bearer')).toBe(false);
        expect(out.includes('bearer_ref')).toBe(false);
        expect(out.includes('ytb_live_')).toBe(false);
        expect(out.includes('op://')).toBe(false);
        // bearer_source hint from sites_list MUST NOT leak either.
        expect(out.includes('bearer_source')).toBe(false);
    });
});

describe('appendSitesBlock — footer text', () => {
    it('includes both CTAs from plan §W8 Z.914-916', () => {
        const registry = registryFor([
            entry({ site_id: 'a', url: 'https://a.example.com', platform: 'wordpress' }),
        ]);
        const out = appendSitesBlock(SAMPLE_SKILL, registry);

        expect(out).toContain(
            'Use `yootheme_builder_sites_list` to inspect this at runtime.',
        );
        expect(out).toContain(
            'Pass `site_id: "<id>"` on any tool call to target a specific site;',
        );
        expect(out).toContain('omit it to use the default.');
    });

    it('places the appendix AFTER the original skill content (separator first)', () => {
        const registry = registryFor([
            entry({ site_id: 'a', url: 'https://a.example.com', platform: 'wordpress' }),
        ]);
        const out = appendSitesBlock(SAMPLE_SKILL, registry);

        const sampleEnd = out.indexOf(SAMPLE_SKILL) + SAMPLE_SKILL.length;
        const separatorIdx = out.indexOf('\n\n---\n\n## Currently configured sites');
        expect(separatorIdx).toBeGreaterThanOrEqual(sampleEnd);
    });
});

describe('loadSkillContent — registry-aware overload', () => {
    it('returns the raw skill content when called without a registry', () => {
        const out = loadSkillContent();
        expect(out.startsWith('---\nname: yt-builder-mcp')).toBe(true);
        expect(out).not.toContain('Currently configured sites');
    });

    it('returns raw content when registry has 0 sites', () => {
        const registry = registryFor([]);
        const out = loadSkillContent(registry);
        const raw = loadSkillContent();
        expect(out).toBe(raw);
    });

    it('appends the sites block when registry has ≥1 site', () => {
        const registry = registryFor([
            entry({
                site_id: 'wp-acme',
                url: 'https://acme.com',
                platform: 'wordpress',
                label: 'Acme',
                is_default: true,
            }),
        ]);
        const out = loadSkillContent(registry);
        expect(out).toContain('Currently configured sites (1)');
        expect(out).toContain('- `wp-acme` — Acme · wordpress · https://acme.com · default');
        // Still contains the underlying skill frontmatter.
        expect(out).toContain('name: yt-builder-mcp');
    });
});
