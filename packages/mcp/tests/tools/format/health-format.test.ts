/**
 * Health format-sidecar tests (G.2.11).
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    buildHealthDetail,
    buildHealthStats,
    buildDiagnoseDetail,
} from '../../../src/tools/format/health-format.js';

describe('health-format sidecar', () => {
    it('buildHealthDetail produces ≥ 3 groups (Plugin / Host / Endpoints)', () => {
        const detail = buildHealthDetail({
            plugin_version: '0.1.0',
            yootheme_version: '4.5.33',
            wp_version: '6.7',
            php_version: '8.2',
            storage_type: 'wp_option',
            storage_target: 'yootheme',
            yootheme_loaded: true,
            available_endpoints: ['/health', '/pages', '/elements'],
        });
        expect(detail.groups.length).toBeGreaterThanOrEqual(3);
        expect(detail.groups[0]?.label).toMatch(/plugin/i);
        expect(detail.groups[1]?.label).toMatch(/host/i);
        expect(detail.groups[2]?.label).toMatch(/endpoint/i);
    });

    it('buildHealthDetail exposes plugin_version + yootheme_loaded in the Plugin group', () => {
        const detail = buildHealthDetail({
            plugin_version: '0.1.0',
            yootheme_version: null,
            wp_version: '6.7',
            php_version: '8.2',
            storage_type: 'wp_option',
            storage_target: 'yootheme',
            yootheme_loaded: false,
            available_endpoints: [],
        });
        const pluginGroup = detail.groups[0];
        const versionEntry = pluginGroup?.entries.find((e) => e.key === 'plugin_version');
        expect(versionEntry?.value).toBe('0.1.0');
        const loadedEntry = pluginGroup?.entries.find((e) => e.key === 'yootheme_loaded');
        expect(loadedEntry?.value).toBe(false);
    });

    it('buildHealthStats produces a flat stats array per Design §3.2 row 1 (Round-1 audit I1 fix)', () => {
        const stats = buildHealthStats({
            plugin_version: '0.1.0',
            yootheme_version: '4.5.33',
            wp_version: '6.7',
            php_version: '8.2',
            storage_type: 'wp_option',
            storage_target: 'yootheme',
            yootheme_loaded: true,
            available_endpoints: ['/health', '/pages', '/elements', '/sources'],
        });
        // Required keys per Design-Doc §3.2 row 1: plugin_version,
        // yt_version, wp_version, php_version, storage_type,
        // yootheme_loaded, endpoint_count.
        const keys = stats.stats.map((s) => s.key);
        expect(keys).toContain('plugin_version');
        expect(keys).toContain('yootheme_version');
        expect(keys).toContain('wp_version');
        expect(keys).toContain('php_version');
        expect(keys).toContain('storage_type');
        expect(keys).toContain('yootheme_loaded');
        expect(keys).toContain('endpoint_count');
        // Flat shape: every entry has key + label + value (no nested groups).
        for (const s of stats.stats) {
            expect(typeof s.key).toBe('string');
            expect(typeof s.label).toBe('string');
            expect(s.value).toBeDefined();
        }
        // endpoint_count is a derived metric (number, not enumerated list).
        const epc = stats.stats.find((s) => s.key === 'endpoint_count');
        expect(epc?.value).toBe(4);
    });

    it('buildHealthStats reports yootheme_loaded as boolean (not the version) for the flat metric', () => {
        const stats = buildHealthStats({
            plugin_version: '0.1.0',
            yootheme_version: null,
            wp_version: '6.7',
            php_version: '8.2',
            storage_type: 'wp_option',
            storage_target: 'yootheme',
            yootheme_loaded: false,
            available_endpoints: [],
        });
        const loaded = stats.stats.find((s) => s.key === 'yootheme_loaded');
        expect(loaded?.value).toBe(false);
        const epc = stats.stats.find((s) => s.key === 'endpoint_count');
        expect(epc?.value).toBe(0);
    });

    it('buildHealthDetail counts endpoints rather than enumerating them all', () => {
        const detail = buildHealthDetail({
            plugin_version: '0.1.0',
            yootheme_version: '4.5',
            wp_version: '6.7',
            php_version: '8.2',
            storage_type: 'wp_option',
            storage_target: 'yootheme',
            yootheme_loaded: true,
            available_endpoints: ['/a', '/b', '/c', '/d', '/e'],
        });
        const endpointGroup = detail.groups.find((g) => /endpoint/i.test(g.label));
        const countEntry = endpointGroup?.entries.find((e) => e.key === 'count');
        expect(countEntry?.value).toBe(5);
    });

    it('buildDiagnoseDetail produces 2 groups (Plugin probe / Bearer probe)', () => {
        const detail = buildDiagnoseDetail({
            plugin_reachable: true,
            plugin_version: '0.1.0',
            yootheme_loaded: true,
            yootheme_version: '4.5',
            endpoint_count: 16,
            bearer_valid: true,
        });
        expect(detail.groups).toHaveLength(2);
        expect(detail.groups[0]?.label).toMatch(/plugin/i);
        expect(detail.groups[1]?.label).toMatch(/bearer/i);
        const okEntry = detail.groups[1]?.entries.find((e) => e.key === 'bearer_valid');
        expect(okEntry?.value).toBe(true);
    });

    it('buildDiagnoseDetail surfaces probe errors when present', () => {
        const detail = buildDiagnoseDetail({
            plugin_reachable: true,
            plugin_version: '0.1.0',
            yootheme_loaded: true,
            yootheme_version: '4.5',
            endpoint_count: 16,
            bearer_valid: false,
            bearer_error: 'HTTP 401: bad key',
        });
        const errEntry = detail.groups[1]?.entries.find((e) => e.key === 'bearer_error');
        expect(errEntry?.value).toBe('HTTP 401: bad key');
    });

    it('buildDiagnoseDetail tolerates plugin-unreachable (no bearer probe data)', () => {
        const detail = buildDiagnoseDetail({
            plugin_reachable: false,
            plugin_error: 'network down',
        });
        // Plugin group present; bearer group still present but flagged as N/A.
        expect(detail.groups[0]?.entries.find((e) => e.key === 'plugin_reachable')?.value).toBe(false);
    });
});
