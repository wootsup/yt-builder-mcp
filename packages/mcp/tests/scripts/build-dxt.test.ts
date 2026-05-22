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
    user_config: Array<{
        key: string;
        label: string;
        type: string;
        required: boolean;
        description: string;
    }>;
    compatibility?: {
        node?: string;
        platforms?: string[];
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

    it('declares both required user_config env vars (URL + Bearer)', () => {
        const m = readManifest();
        const keys = m.user_config.map((c) => c.key);
        expect(keys).toContain('YTB_MCP_WP_URL');
        expect(keys).toContain('YTB_MCP_BEARER_TOKEN');
        // Bearer must be marked as secret (so Claude Desktop masks input).
        const bearer = m.user_config.find((c) => c.key === 'YTB_MCP_BEARER_TOKEN');
        expect(bearer?.type).toBe('secret');
        expect(bearer?.required).toBe(true);
    });

    it('wires the user_config keys through to the server env via ${user_config.X}', () => {
        const m = readManifest();
        const env = m.server.mcp_config.env;
        expect(env.YTB_MCP_WP_URL).toBe('${user_config.YTB_MCP_WP_URL}');
        expect(env.YTB_MCP_BEARER_TOKEN).toBe('${user_config.YTB_MCP_BEARER_TOKEN}');
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
