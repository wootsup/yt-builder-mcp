/**
 * build-dxt + manifest tests — verify the static contract surface of
 * `manifest.json` and the structural invariants of `scripts/build-dxt.js`
 * without actually running the full build/zip pipeline.
 *
 * @license MIT
 */

import { existsSync, readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PKG_ROOT = resolve(__dirname, '..', '..');

interface Manifest {
    dxt_version: string;
    name: string;
    display_name: string;
    version: string;
    description: string;
    icon?: string;
    server: {
        type: string;
        entry_point: string;
        mcp_config: {
            command: string;
            args: string[];
            env: Record<string, string>;
        };
    };
    // Claude Desktop 1.8555+ strict-validator requires user_config as an
    // object map keyed by env-var name (not an array). See manifest.json.
    user_config: Record<string, {
        type: string;
        title: string;
        description: string;
        required?: boolean;
        sensitive?: boolean;
        default?: string | number | boolean | string[];
    }>;
    compatibility?: {
        claude_desktop?: string;
        platforms?: string[];
        runtimes?: { node?: string };
    };
}

interface PkgJson {
    version: string;
    name: string;
    files: string[];
}

function readManifest(): Manifest {
    return JSON.parse(
        readFileSync(resolve(PKG_ROOT, 'manifest.json'), 'utf-8'),
    ) as Manifest;
}

function readPkg(): PkgJson {
    return JSON.parse(
        readFileSync(resolve(PKG_ROOT, 'package.json'), 'utf-8'),
    ) as PkgJson;
}

describe('manifest.json — schema sanity', () => {
    it('exists and parses as JSON', () => {
        expect(existsSync(resolve(PKG_ROOT, 'manifest.json'))).toBe(true);
        expect(() => readManifest()).not.toThrow();
    });

    it('has every required top-level key', () => {
        const m = readManifest();
        for (const k of [
            'dxt_version',
            'name',
            'display_name',
            'version',
            'description',
            'server',
            'user_config',
        ] as const) {
            expect(m[k], `missing required key: ${k}`).toBeDefined();
        }
    });

    it('matches the package.json version', () => {
        const m = readManifest();
        const pkg = readPkg();
        expect(m.version).toBe(pkg.version);
        expect(m.name).toBe(pkg.name);
    });

    it('declares the canonical cross-platform user_config env vars (SITE_URL + Bearer + PLATFORM hint)', () => {
        const m = readManifest();
        const keys = Object.keys(m.user_config);
        // F-DXT (P0): canonical SITE_URL covers WordPress + Joomla.
        // Legacy YTB_MCP_WP_URL is still honored by the wrapper as a
        // deprecated alias, but it MUST NOT appear in fresh DXT manifests.
        expect(keys).toContain('YTB_MCP_SITE_URL');
        expect(keys).toContain('YTB_MCP_BEARER_TOKEN');
        expect(keys).toContain('YTB_MCP_PLATFORM');
        expect(keys, 'legacy YTB_MCP_WP_URL must not be exposed via DXT user_config').not.toContain('YTB_MCP_WP_URL');
        // Bearer must be marked as sensitive (Claude Desktop masks input).
        const bearer = m.user_config.YTB_MCP_BEARER_TOKEN;
        expect(bearer?.type).toBe('string');
        expect(bearer?.sensitive).toBe(true);
        // W10 (multi-site): SITE_URL + Bearer are now optional in the
        // manifest — multi-site setups use YTB_MCP_SITES_FILE instead
        // and leave the legacy single-site fields empty. The boot path
        // (auth.loadRegistry) enforces "at least one of (sites-file)
        // / (URL + Bearer pair) must be present" at runtime, so the
        // manifest no longer hard-flags the legacy pair as required.
        expect(bearer?.required).not.toBe(true);
        // SITE_URL is non-sensitive and optional under multi-site.
        const url = m.user_config.YTB_MCP_SITE_URL;
        expect(url?.type).toBe('string');
        expect(url?.required).not.toBe(true);
        // PLATFORM is an optional hint with a string default ("auto").
        // DXT 0.1 user_config does not support enum, so allowed values
        // are documented in the description instead.
        const platform = m.user_config.YTB_MCP_PLATFORM;
        expect(platform?.type).toBe('string');
        expect(platform?.required).not.toBe(true);
        expect(platform?.default).toBe('auto');
        // Description must document the accepted values so the Claude Desktop
        // UI surfaces them to the operator.
        expect(platform?.description).toMatch(/auto/);
        expect(platform?.description).toMatch(/wordpress/);
        expect(platform?.description).toMatch(/joomla/);
    });

    it('wires the user_config keys through to the server env via ${user_config.X}', () => {
        const m = readManifest();
        const env = m.server.mcp_config.env;
        expect(env.YTB_MCP_SITE_URL).toBe('${user_config.YTB_MCP_SITE_URL}');
        expect(env.YTB_MCP_PLATFORM).toBe('${user_config.YTB_MCP_PLATFORM}');
        expect(env.YTB_MCP_BEARER_TOKEN).toBe('${user_config.YTB_MCP_BEARER_TOKEN}');
        // F-DXT: legacy YTB_MCP_WP_URL must NOT be wired by fresh manifests.
        expect(env.YTB_MCP_WP_URL, 'legacy YTB_MCP_WP_URL must not be wired by DXT').toBeUndefined();
    });

    it('describes itself as cross-platform (WordPress + Joomla) in copy + keywords', () => {
        const m = readManifest();
        // F-DXT: customer-facing copy must mention both platforms so
        // Joomla operators recognize this DXT in the Claude Desktop store.
        const lc = (s: string) => s.toLowerCase();
        expect(lc(m.description)).toMatch(/wordpress/);
        expect(lc(m.description)).toMatch(/joomla/);
        if (typeof (m as { long_description?: string }).long_description === 'string') {
            const ld = lc((m as { long_description: string }).long_description);
            expect(ld).toMatch(/wordpress/);
            expect(ld).toMatch(/joomla/);
        }
        // Keywords must include joomla so the store-search surfaces this DXT
        // for Joomla operators.
        const keywords = ((m as unknown as { keywords?: string[] }).keywords ?? []).map((k) => k.toLowerCase());
        expect(keywords).toContain('joomla');
        expect(keywords).toContain('wordpress');
    });
});

describe('manifest.json — icon asset (Round-1 audit I11)', () => {
    it('points to an icon file that actually exists in the package root', () => {
        const m = readManifest();
        if (m.icon === undefined) {
            // Acceptable: no icon declared. Test passes by design.
            return;
        }
        const iconPath = resolve(PKG_ROOT, m.icon);
        expect(existsSync(iconPath), `manifest.icon = "${m.icon}" but file not found at ${iconPath}`).toBe(true);
    });

    it('declares the icon (and skills/) in package.json:files so npm pack ships them', () => {
        const m = readManifest();
        const pkg = readPkg();
        if (m.icon !== undefined) {
            expect(pkg.files, `package.json files[] missing "${m.icon}" (npm pack would skip it)`).toContain(m.icon);
        }
        // The bundled skill MUST be in files[] so install-skill works
        // from the published tarball (Round-1 audit C1 fix).
        expect(pkg.files, 'package.json files[] missing "skills" (install-skill would fail post-publish)').toContain('skills');
    });
});

describe('build-dxt.js — structural invariants', () => {
    const scriptPath = resolve(PKG_ROOT, 'scripts', 'build-dxt.js');

    it('exists and is executable text', () => {
        expect(existsSync(scriptPath)).toBe(true);
        const text = readFileSync(scriptPath, 'utf-8');
        expect(text.startsWith('#!/usr/bin/env node')).toBe(true);
    });

    it('references the canonical DXT artifact name', () => {
        const text = readFileSync(scriptPath, 'utf-8');
        expect(text).toContain('yt-builder-mcp.dxt');
    });

    it('enforces a grep-gate on secret-pattern strings in the staged bundle', () => {
        const text = readFileSync(scriptPath, 'utf-8');
        // Patterns the gate must actively scan for; mirrors the unit-level
        // grep-gate (tests/auth/secrets-grep-gate.test.ts).
        expect(text).toMatch(/Bearer.*\[A-Za-z0-9._-\]\{16,\}/);
        expect(text).toMatch(/KSEC-/);
        expect(text).toMatch(/Cookie/);
    });
});
