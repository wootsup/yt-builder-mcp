/**
 * W9 — `test-site` CLI subcommand.
 *
 * Live connectivity probe for ONE site_id. Mirrors the runtime
 * behaviour of the `yootheme_builder_sites_test` MCP tool, but from
 * the CLI so operators can pre-flight a site without launching the
 * stdio server.
 *
 * Pipeline (per plan §W9):
 *  1. Load `sites.json` via the W1 store.
 *  2. Build a {@link SiteRegistry} + {@link ClientPool} from the
 *     entries, wired to the supplied {@link SecretResolver}.
 *  3. Call `pool.resolve(siteId)` → throws structured
 *     {@link UnknownSiteError} / {@link NoDefaultSiteError} which we
 *     map to a structured CLI result + a non-zero exit code.
 *  4. Probe `/health` (no auth) + `/etag` (auth) in parallel via
 *     `Promise.allSettled` so a failure on one path never hides the
 *     other's outcome.
 *  5. Print a green/red structured result and return it for the
 *     dispatcher / tests.
 *
 * Returns a {@link TestSiteResult} (not exit codes directly) so tests
 * can assert deterministic outputs without re-implementing the
 * probe-routing logic.
 *
 * @license MIT
 */

import {
    platformForUrlAsync,
    type Platform,
    type PlatformKind,
} from '../../platform/index.js';
import { ClientPool } from '../client-pool.js';
import {
    NoDefaultSiteError,
    UnknownSiteError,
} from '../client-pool.js';
import { probeSite } from '../probe.js';
import { SiteRegistry } from '../registry.js';
import type { SitesFileT } from '../schema.js';
import {
    CompositeSecretResolver,
    type SecretResolver,
} from '../secret-resolver.js';
import { loadSitesFile } from '../store.js';

export interface TestSiteResult {
    readonly siteId: string;
    readonly siteUrl: string;
    readonly platform: PlatformKind | 'unknown';
    readonly pluginReachable: boolean;
    readonly bearerValid: boolean;
    readonly etagReceived: boolean;
    readonly summary: string;
    readonly pluginError?: string;
    readonly bearerError?: string;
    /** True when site_id was not in the registry. */
    readonly unknownSite: boolean;
    /** Available ids when unknownSite is true. */
    readonly available?: readonly string[];
}

export interface TestSiteDeps {
    readonly load: (path: string) => Promise<SitesFileT>;
    readonly secretResolver?: SecretResolver;
    /** Inject the platform probe for tests (no network). */
    readonly platformForUrlAsync?: (
        url: string,
        hint?: PlatformKind,
    ) => Promise<Platform>;
    /**
     * Inject the pool factory. Tests pass a pool that does NOT call
     * the real RestClient (they pre-stub the response shape).
     * Production wires the default ClientPool below.
     */
    readonly buildPool?: (
        registry: SiteRegistry,
        resolver: SecretResolver,
    ) => ClientPool;
    readonly log?: (line: string) => void;
}

export class TestSiteError extends Error {
    constructor(public readonly code: string, message: string) {
        super(message);
        this.name = 'TestSiteError';
    }
}

/**
 * Build the runtime result lines for stdout — keeps the live CLI path
 * + tests in sync without forcing tests to parse text.
 */
export function renderTestSiteLines(result: TestSiteResult): readonly string[] {
    if (result.unknownSite) {
        const avail = result.available ?? [];
        return [
            `FAIL — Site "${result.siteId}" not found.`,
            avail.length > 0
                ? `Available: ${avail.join(', ')}`
                : '(no sites configured)',
        ];
    }
    const lines: string[] = [
        `Site:           ${result.siteId}`,
        `URL:            ${result.siteUrl}`,
        `Platform:       ${result.platform}`,
        `Plugin reachable: ${result.pluginReachable ? 'yes' : 'NO'}`,
        `Bearer valid:     ${result.bearerValid ? 'yes' : 'NO'}`,
        `ETag received:    ${result.etagReceived ? 'yes' : 'NO'}`,
    ];
    if (result.pluginError !== undefined) {
        lines.push(`Plugin error:   ${result.pluginError}`);
    }
    if (result.bearerError !== undefined) {
        lines.push(`Bearer error:   ${result.bearerError}`);
    }
    lines.push('', result.summary);
    return lines;
}

/**
 * Probe one site and return a structured outcome. Never throws on a
 * probe failure (those are surfaced inside the result); throws ONLY
 * on file-IO failures the operator cannot recover from.
 */
export async function testSiteCommand(
    siteId: string,
    path: string,
    deps: TestSiteDeps,
): Promise<TestSiteResult> {
    const file = await deps.load(path);
    const registry = new SiteRegistry(file, {
        ...(deps.platformForUrlAsync !== undefined
            ? { platformForUrlAsync: deps.platformForUrlAsync }
            : { platformForUrlAsync }),
    });
    const resolver = deps.secretResolver ?? new CompositeSecretResolver();
    const pool = deps.buildPool !== undefined
        ? deps.buildPool(registry, resolver)
        : new ClientPool(registry, resolver);

    let client;
    let site;
    try {
        const resolution = await pool.resolve(siteId);
        client = resolution.client;
        site = resolution.site;
    } catch (e) {
        if (e instanceof UnknownSiteError) {
            const result: TestSiteResult = {
                siteId,
                siteUrl: '',
                platform: 'unknown',
                pluginReachable: false,
                bearerValid: false,
                etagReceived: false,
                summary: `FAIL — Site "${siteId}" not found.`,
                unknownSite: true,
                available: e.available,
            };
            if (deps.log !== undefined) {
                for (const line of renderTestSiteLines(result)) deps.log(line);
            }
            return result;
        }
        if (e instanceof NoDefaultSiteError) {
            throw new TestSiteError(
                'NO_DEFAULT_SITE',
                e.message,
            );
        }
        throw e;
    }

    // W12-R1.2: probe lives in sites/probe.ts — single source of truth
    // shared with the MCP tool surface so the two output channels never
    // drift on edge cases (401 with /health rejected, 500 on /etag etc.).
    const probe = await probeSite(client);

    const result: TestSiteResult = {
        siteId: site.id,
        siteUrl: site.url,
        platform: site.platform.kind,
        pluginReachable: probe.plugin_reachable,
        bearerValid: probe.bearer_valid,
        etagReceived: probe.etag_received,
        summary: probe.summary,
        ...(probe.plugin_error !== undefined ? { pluginError: probe.plugin_error } : {}),
        ...(probe.bearer_error !== undefined ? { bearerError: probe.bearer_error } : {}),
        unknownSite: false,
    };

    if (deps.log !== undefined) {
        for (const line of renderTestSiteLines(result)) deps.log(line);
    }
    return result;
}

export const DEFAULT_TEST_SITE_DEPS: TestSiteDeps = {
    load: loadSitesFile,
};
