/**
 * W10 — manifest.json multi-site user_config pin-tests.
 *
 * The DXT manifest must expose `YTB_MCP_SITES_FILE` as a user_config
 * field and must flip the legacy single-site env-vars
 * (`YTB_MCP_SITE_URL` + `YTB_MCP_BEARER_TOKEN`) to `required: false`
 * so multi-site setups can leave them empty.
 *
 * @license MIT
 */

import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PKG_ROOT = resolve(__dirname, '..');

interface UserConfigField {
    type: string;
    title: string;
    description: string;
    required?: boolean;
    sensitive?: boolean;
    default?: string | number | boolean | string[];
}

interface Manifest {
    server: {
        mcp_config: {
            env: Record<string, string>;
        };
    };
    user_config: Record<string, UserConfigField>;
}

function readManifest(): Manifest {
    return JSON.parse(
        readFileSync(resolve(PKG_ROOT, 'manifest.json'), 'utf-8'),
    ) as Manifest;
}

describe('manifest.json — W10 multi-site user_config', () => {
    it('exposes YTB_MCP_SITES_FILE as a user_config field', () => {
        const m = readManifest();
        expect(m.user_config).toHaveProperty('YTB_MCP_SITES_FILE');
        const sitesFile = m.user_config.YTB_MCP_SITES_FILE;
        expect(sitesFile?.type).toBe('string');
        expect(typeof sitesFile?.title).toBe('string');
        expect(sitesFile?.title.length).toBeGreaterThan(0);
    });

    it('SITES_FILE field is NOT required (optional — falls back to legacy env-vars)', () => {
        const m = readManifest();
        const sitesFile = m.user_config.YTB_MCP_SITES_FILE;
        expect(sitesFile?.required).not.toBe(true);
    });

    it('SITES_FILE field is NOT marked sensitive (it is a path, not a secret)', () => {
        const m = readManifest();
        const sitesFile = m.user_config.YTB_MCP_SITES_FILE;
        expect(sitesFile?.sensitive).not.toBe(true);
    });

    it('flips YTB_MCP_SITE_URL.required to false (multi-site setups leave it empty)', () => {
        const m = readManifest();
        expect(m.user_config.YTB_MCP_SITE_URL?.required).not.toBe(true);
    });

    it('flips YTB_MCP_BEARER_TOKEN.required to false (multi-site setups leave it empty) but keeps sensitive=true', () => {
        const m = readManifest();
        const bearer = m.user_config.YTB_MCP_BEARER_TOKEN;
        expect(bearer?.required).not.toBe(true);
        // Sensitivity must stay — Claude Desktop must still mask the
        // input field when the legacy single-site fallback is in use.
        expect(bearer?.sensitive).toBe(true);
    });

    it('YTB_MCP_PLATFORM stays optional (hint only)', () => {
        const m = readManifest();
        expect(m.user_config.YTB_MCP_PLATFORM?.required).not.toBe(true);
    });

    it('wires YTB_MCP_SITES_FILE through to the server env via ${user_config.YTB_MCP_SITES_FILE}', () => {
        const m = readManifest();
        const env = m.server.mcp_config.env;
        expect(env.YTB_MCP_SITES_FILE).toBe(
            '${user_config.YTB_MCP_SITES_FILE}',
        );
    });

    it('description points operators at the CLI subcommand to bootstrap sites.json', () => {
        const m = readManifest();
        const desc = m.user_config.YTB_MCP_SITES_FILE?.description ?? '';
        expect(desc).toMatch(/sites\.json/);
        // Plan §W10: description must reference the `npx -y
        // @wootsup/yt-builder-mcp add-site` bootstrap path so operators
        // know how to populate the registry from inside Claude Desktop's
        // config UI.
        expect(desc).toMatch(/npx/);
        expect(desc).toMatch(/@wootsup\/yt-builder-mcp/);
        expect(desc).toMatch(/add-site/);
    });

    it('description mentions that the legacy single-site fields are ignored when SITES_FILE is set', () => {
        const m = readManifest();
        const desc = m.user_config.YTB_MCP_SITES_FILE?.description ?? '';
        // Operators reading the field in Claude Desktop's settings UI
        // need to know which knob wins when both are set, otherwise
        // they will waste time twiddling SITE_URL while SITES_FILE
        // silently overrides.
        expect(desc).toMatch(/legacy|ignored|YTB_MCP_SITE_URL/i);
    });
});
