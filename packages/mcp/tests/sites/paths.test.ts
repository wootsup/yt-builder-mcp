/**
 * W1 — paths tests for sites/paths.ts.
 *
 * Coverage: XDG honoured, HOME fallback, HOME unset → tmpdir fallback
 * with stderr note.
 *
 * @license MIT
 */

import { tmpdir } from 'node:os';
import { join, sep } from 'node:path';
import { describe, expect, it, vi } from 'vitest';

import { defaultSitesFilePath } from '../../src/sites/paths.js';

describe('defaultSitesFilePath', () => {
    it('uses XDG_CONFIG_HOME when set (and ignores HOME)', () => {
        const out = defaultSitesFilePath({
            XDG_CONFIG_HOME: '/xdg/cfg',
            HOME: '/home/should-not-be-used',
        });
        expect(out).toBe(join('/xdg/cfg', 'yt-builder-mcp', 'sites.json'));
    });

    it('falls back to $HOME/.config when XDG_CONFIG_HOME is unset', () => {
        const out = defaultSitesFilePath({ HOME: '/home/alice' });
        expect(out).toBe(join('/home/alice', '.config', 'yt-builder-mcp', 'sites.json'));
    });

    it('treats XDG_CONFIG_HOME="" as unset', () => {
        const out = defaultSitesFilePath({ XDG_CONFIG_HOME: '', HOME: '/home/alice' });
        expect(out).toBe(join('/home/alice', '.config', 'yt-builder-mcp', 'sites.json'));
    });

    it('falls back to tmpdir() when neither var is set, with stderr note', () => {
        const log = vi.fn();
        const out = defaultSitesFilePath({}, log);
        expect(out).toBe(join(tmpdir(), 'yt-builder-mcp', 'sites.json'));
        expect(log).toHaveBeenCalledTimes(1);
        const msg = String(log.mock.calls[0]?.[0] ?? '');
        expect(msg).toContain('neither XDG_CONFIG_HOME nor HOME');
        expect(msg).toContain(tmpdir());
    });

    it('returns a path that ends with sites.json regardless of branch', () => {
        const a = defaultSitesFilePath({ XDG_CONFIG_HOME: '/x' });
        const b = defaultSitesFilePath({ HOME: '/h' });
        const c = defaultSitesFilePath({}, () => { /* swallow */ });
        for (const p of [a, b, c]) {
            expect(p.endsWith(`${sep}sites.json`)).toBe(true);
        }
    });

    it('uses process.env by default', () => {
        // Smoke: call with no args. We don't assert the specific path
        // (machine-dependent), only that something resembling a path
        // ending in sites.json comes back.
        const out = defaultSitesFilePath();
        expect(out.endsWith('sites.json')).toBe(true);
    });
});
