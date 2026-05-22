/**
 * Default `WizardDeps` implementations — real-I/O side of the wizard
 * (FS writes, rollback, client-config dispatch).
 *
 * REST probes + handshake/version-check live in
 * `./setup-wizard-handshake.ts` (Round-2 R2-A2-IMP1 split for headroom).
 * Clack prompts live in `./setup-prompts.ts` (Round-1.5 split). The
 * wizard algorithm lives in `./setup-wizard.ts`.
 *
 * Re-exports `majorMinor` from the handshake module so existing
 * consumers (`tests/setup/wizard-defaults.test.ts`, `setup-wizard.ts`)
 * keep working through this barrel.
 *
 * @license MIT
 */

import {
    detectAvailableClients,
    findClient,
    type McpServerConfig,
} from './clients/index.js';
import { defaultConfirmContinue, defaultPrompt } from './setup-prompts.js';
import {
    defaultHandshake,
    defaultProbeAuth,
    defaultProbeHealth,
    defaultProbeIdentity,
    majorMinor,
} from './setup-wizard-handshake.js';
import type {
    PickupResult,
    WizardDeps,
    WriteResult,
} from './setup-wizard-types.js';

// Re-export for backward-compat with existing call-sites
// (`setup-wizard.ts`, `tests/setup/wizard-defaults.test.ts`).
export { majorMinor };

/**
 * Roll back a list of write-results: restore previous content (or
 * remove freshly written files). Best-effort — errors surface via
 * `logFn` so partial rollback doesn't mask the original failure.
 */
export async function rollbackWrites(
    writes: readonly WriteResult[],
    logFn: (line: string) => void,
): Promise<void> {
    const { unlink, writeFile } = await import('node:fs/promises');
    for (const w of writes) {
        if (!w.ok || w.path === '') continue;
        try {
            if (w.previousContent === null) {
                await unlink(w.path);
                logFn(`  ↩ removed ${w.path}`);
            } else {
                await writeFile(w.path, w.previousContent, 'utf-8');
                logFn(`  ↩ restored ${w.path}`);
            }
        } catch (e) {
            logFn(`  ↩ rollback FAILED for ${w.path}: ${e instanceof Error ? e.message : String(e)}`);
        }
    }
}

async function readIfExists(path: string): Promise<string | null> {
    try {
        const { readFile } = await import('node:fs/promises');
        const { existsSync } = await import('node:fs');
        return existsSync(path) ? await readFile(path, 'utf-8') : null;
    } catch {
        return null;
    }
}

async function defaultWriteClient(
    id: string,
    serverName: string,
    config: McpServerConfig,
): Promise<WriteResult> {
    const writer = findClient(id);
    if (writer === undefined) {
        return { id, label: id, ok: false, error: 'Unknown client.', path: '', previousContent: null };
    }
    const path = writer.configPath();
    const previousContent = await readIfExists(path);
    try {
        await writer.apply(serverName, config);
        return { id, label: writer.label, ok: true, path, previousContent };
    } catch (e) {
        const error = e instanceof Error ? e.message : String(e);
        return { id, label: writer.label, ok: false, error, path, previousContent };
    }
}

/**
 * Default implementation of `WizardDeps.fetchPickup` — POSTs the nonce
 * to the pickup URL revealed in wp-admin and maps the snake_case payload
 * (`site_url`, `plugin_version`) to camelCase (`siteurl`, `pluginVersion`).
 *
 * Failure modes throw with explicit, user-actionable messages:
 *   - 404 → "pickup expired or already claimed (5-minute TTL)"
 *   - 403 → "pickup bound to a different IP — regenerate from wp-admin"
 *   - 429 → "rate limit — wait ~60s and try again"
 *   - 400 → "invalid request" (echoes server's message when parseable)
 *   - network → "check URL is reachable + plugin is active"
 *
 * The function intentionally has no retry loop — a one-shot nonce that
 * fails has either been consumed or expired; the only sensible recovery
 * is for the operator to regenerate from wp-admin.
 */
export async function defaultFetchPickup(
    pickupUrl: string,
    nonce: string,
): Promise<PickupResult> {
    let response: Response;
    try {
        response = await fetch(pickupUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nonce }),
        });
    } catch (e) {
        const msg = e instanceof Error ? e.message : String(e);
        throw new Error(
            `Could not reach the pickup URL (${msg}). Check the URL is reachable and the plugin is active.`,
        );
    }

    if (response.status === 200) {
        let raw: unknown;
        try {
            raw = await response.json();
        } catch {
            throw new Error('Pickup response was not valid JSON. Re-generate the pickup URL from wp-admin.');
        }
        if (raw === null || typeof raw !== 'object') {
            throw new Error('Pickup response had an unexpected shape (not an object).');
        }
        const obj = raw as Record<string, unknown>;
        const token = typeof obj.token === 'string' ? obj.token : undefined;
        const siteurl = typeof obj.site_url === 'string' ? obj.site_url : undefined;
        const pluginVersion = typeof obj.plugin_version === 'string' ? obj.plugin_version : undefined;
        if (token === undefined || siteurl === undefined || pluginVersion === undefined) {
            throw new Error(
                'Pickup response missing required fields (token, site_url, plugin_version). Update the plugin.',
            );
        }
        return { token, siteurl, pluginVersion };
    }

    // Try to surface the server's structured message when it's there.
    let serverMessage: string | undefined;
    try {
        const body = await response.json();
        if (body !== null && typeof body === 'object') {
            const m = (body as Record<string, unknown>).message;
            if (typeof m === 'string' && m !== '') serverMessage = m;
        }
    } catch {
        // body not JSON — ignore.
    }

    if (response.status === 404) {
        throw new Error(
            'Pickup not available. The URL may have expired (5-minute TTL) or already been claimed. Generate a fresh pickup from wp-admin.',
        );
    }
    if (response.status === 403) {
        throw new Error(
            'Pickup is bound to a different IP. Regenerate from wp-admin with the "different machine" option, or run this CLI from the same network as the browser session.',
        );
    }
    if (response.status === 429) {
        throw new Error('Rate limit hit on pickup endpoint. Wait ~60s and try again.');
    }
    if (response.status === 400) {
        throw new Error(
            `Pickup rejected the request as malformed${serverMessage ? `: ${serverMessage}` : ''}. Re-generate the pickup URL from wp-admin.`,
        );
    }
    throw new Error(
        `Pickup failed with HTTP ${response.status}${serverMessage ? `: ${serverMessage}` : ''}.`,
    );
}

export const DEFAULT_WIZARD_DEPS: WizardDeps = {
    prompt: defaultPrompt,
    detectClients: () => detectAvailableClients(),
    probeHealth: defaultProbeHealth,
    probeIdentity: defaultProbeIdentity,
    probeAuth: defaultProbeAuth,
    confirmContinue: defaultConfirmContinue,
    writeClient: defaultWriteClient,
    handshake: defaultHandshake,
    fetchPickup: defaultFetchPickup,
};
