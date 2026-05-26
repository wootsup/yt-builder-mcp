/**
 * Interactive setup wizard for `@wootsup/yt-builder-mcp`.
 *
 * `runWizard()` accepts a {@link WizardDeps} bag so unit tests can drive
 * a deterministic prompt → validate → probe → write → handshake sequence
 * without touching real subprocesses or filesystems.
 *
 * After the Round-1 audit I5 fix, this file is the wizard ALGORITHM only:
 *   - `WizardDeps` and its result types live in `setup-wizard-types.ts`.
 *   - Default real-I/O implementations + `rollbackWrites` + `majorMinor`
 *     live in `setup-wizard-defaults.ts`.
 *   - The CLI dispatcher + main-guard live in `setup-cli.ts`.
 *
 * @license MIT
 */

import { cancel, log, note, outro, spinner } from '@clack/prompts';

import type { McpServerConfig } from './clients/index.js';
import { SERVER_NAME, SERVER_VERSION } from './server.js';
import {
    DEFAULT_WIZARD_DEPS,
    rollbackWrites,
} from './setup-wizard-defaults.js';
import type {
    AuthProbeResult,
    HandshakeResult,
    HealthProbeResult,
    WizardAnswers,
    WizardDeps,
    WriteResult,
} from './setup-wizard-types.js';

// Re-export the public surface so `import { runWizard, WizardDeps } from
// './setup-wizard.js'` continues to work without touching the split files.
export { DEFAULT_WIZARD_DEPS, majorMinor } from './setup-wizard-defaults.js';
export type {
    AuthProbeResult,
    HandshakeResult,
    HealthProbeResult,
    WizardAnswers,
    WizardDeps,
    WriteResult,
};

// 1.0.2 Wave-R Part 2: renamed `yootheme-builder` → `yt-builder-mcp`
// to match the package slug + REST namespace + GitHub repo + skill
// folder. Pre-1.0.1 installs (alpha-tester only, per Thomas 2026-05-23)
// would land their server under the old key; no migration logic is
// shipped because there were no production installs to migrate. If
// someone re-runs setup they'll get a fresh `yt-builder-mcp` entry +
// the legacy `yootheme-builder` entry stays dormant (harmless, points
// at the same npm package).
const SERVER_KEY = 'yt-builder-mcp';

/**
 * Run the interactive setup wizard. Returns a process exit-code so the
 * outer dispatcher can `process.exit(rc)` cleanly.
 *
 * Exit codes:
 *   0  — success (configs written, handshake OK)
 *   1  — invalid input that prevents proceeding
 *   2  — health probe failed and user declined to continue
 *   3  — auth probe failed and user declined to continue
 *   4  — write failed; configs rolled back
 *   5  — handshake failed; configs rolled back
 *   130 — user cancelled (matches the SIGINT convention)
 */
export async function runWizard(
    deps: WizardDeps = DEFAULT_WIZARD_DEPS,
): Promise<number> {
    const logFn =
        deps.log ?? ((line: string) => process.stderr.write(`${line}\n`));

    // 1. Detect clients + collect answers.
    const detected = deps.detectClients();
    const answers = await deps.prompt({ detected });
    if (answers === null) return 130;

    if (answers.wpUrl === '' || answers.bearer === '') {
        logFn('Both the WordPress URL and the Bearer key are required.');
        return 1;
    }

    // 2a. Identity probe (no auth) — confirms the URL hosts our plugin
    //     and cross-checks against the URL the user entered. Optional
    //     dep for back-compat: missing field = skip (older WizardDeps
    //     bags + pre-6.5 plugins don't expose it).
    if (deps.probeIdentity !== undefined) {
        const idSpinner = spinner();
        idSpinner.start('Verifying plugin identity…');
        const identity = await deps.probeIdentity(answers.wpUrl);
        if (identity.ok) {
            const summary = identity.siteurl !== undefined
                ? `Plugin identified at ${identity.siteurl}.`
                : 'Plugin identified.';
            idSpinner.stop(summary);
            if (identity.siteurl !== undefined && identity.siteurl !== answers.wpUrl) {
                log.warn(
                    `URL mismatch: you entered ${answers.wpUrl} but the plugin reports its canonical URL as ${identity.siteurl}. ` +
                        `Using the URL you entered — fix the mismatch if you get auth errors.`,
                );
            }
        } else {
            idSpinner.stop('Identity probe failed.');
            log.warn(`Identity probe: ${identity.error ?? 'unknown'} (continuing — older plugins lack this endpoint).`);
        }
    }

    // 2b. Health probe (no auth).
    const healthSpinner = spinner();
    healthSpinner.start('Probing plugin health…');
    const health: HealthProbeResult = await deps.probeHealth(answers.wpUrl);
    if (health.ok) {
        healthSpinner.stop(
            health.pluginVersion !== undefined
                ? `Plugin reachable (v${health.pluginVersion}).`
                : 'Plugin reachable.',
        );
    } else {
        healthSpinner.stop('Plugin probe failed.');
        log.error(`Could not reach the plugin: ${health.error ?? 'unknown'}`);
        const cont = await deps.confirmContinue(
            'Continue anyway and write the config? (You can fix this later.)',
        );
        if (!cont) {
            cancel('Setup cancelled.');
            return 2;
        }
    }

    // 3. Auth probe (Bearer).
    const authSpinner = spinner();
    authSpinner.start('Verifying Bearer key…');
    const auth: AuthProbeResult = await deps.probeAuth(
        answers.wpUrl,
        answers.bearer,
    );
    const authOk = auth.ok;
    if (authOk) {
        authSpinner.stop('Bearer key accepted.');
    } else {
        authSpinner.stop('Bearer probe failed.');
        log.warn(`Auth probe failed: ${auth.error ?? 'unknown'}`);
        const cont = await deps.confirmContinue(
            'Continue anyway and write the config?',
        );
        if (!cont) {
            cancel('Setup cancelled.');
            return 3;
        }
    }

    // 4. Write configs. Track previous content for rollback.
    //
    // Wave 7 (2026-05-24): write BOTH env-var names so the config works
    // with every release line — pre-Wave-7 servers only know
    // YTB_MCP_WP_URL; Wave-7+ servers prefer YTB_MCP_SITE_URL but still
    // honour the legacy alias. The wizard intentionally writes both so a
    // wizard-rendered config never needs editing after an npm upgrade.
    const serverConfig: McpServerConfig = {
        command: 'npx',
        args: ['-y', SERVER_NAME],
        env: {
            YTB_MCP_SITE_URL: answers.wpUrl,
            YTB_MCP_WP_URL: answers.wpUrl,
            YTB_MCP_BEARER_TOKEN: answers.bearer,
        },
    };

    const writes: WriteResult[] = [];
    let writeFailed = false;
    for (const id of answers.selectedClients) {
        const result = await deps.writeClient(id, SERVER_KEY, serverConfig);
        writes.push(result);
        if (!result.ok) {
            writeFailed = true;
            break;
        }
    }

    const summary = writes
        .map((r) =>
            r.ok
                ? `  ✓ ${r.label}: configured`
                : `  ✗ ${r.label}: FAILED (${r.error ?? 'unknown'})`,
        )
        .join('\n');
    note(summary, 'Results');

    if (writeFailed) {
        log.error('Write failed — rolling back any partial writes.');
        await rollbackWrites(writes, logFn);
        return 4;
    }

    // 5. Dist-tag handshake (only when auth probe succeeded — there's
    // no point handshaking with a known-bad token).
    if (authOk) {
        const hsSpinner = spinner();
        hsSpinner.start('Verifying dist-tag handshake…');
        const hs: HandshakeResult = await deps.handshake(
            answers.wpUrl,
            answers.bearer,
            SERVER_VERSION,
        );
        if (!hs.ok) {
            hsSpinner.stop('Handshake failed.');
            log.error(
                `Handshake failed after writing configs: ${hs.error ?? 'unknown'}. Rolling back.`,
            );
            await rollbackWrites(writes, logFn);
            return 5;
        }
        if (hs.warning !== undefined) {
            hsSpinner.stop('Handshake OK (with warning).');
            log.warn(hs.warning);
        } else {
            hsSpinner.stop('Handshake OK.');
        }
    } else {
        log.warn('Skipping handshake because the Bearer probe failed.');
    }

    // W9 — persist the answers to the multi-site sites.json registry
    // when the dispatcher wired a `persistSite` callback. We do this
    // AFTER the handshake passes so the sites.json never contains a
    // site that failed the probe. Optional hook so pre-W9 callers
    // (and tests that drive the wizard without sites.json) skip the
    // write transparently.
    if (deps.persistSite !== undefined && writes.every((r) => r.ok)) {
        try {
            await deps.persistSite(answers);
        } catch (e) {
            const msg = e instanceof Error ? e.message : String(e);
            log.error(`Failed to persist site to sites.json: ${msg}`);
            // Don't rollback the client configs — the env-var path
            // still works; the sites.json file is an additive surface.
            // Operator just sees the warning + can retry with `add-site`.
        }
    }

    if (writes.every((r) => r.ok) && authOk) {
        outro('Done — restart your AI client to load the new MCP server.');
    } else {
        outro('Setup finished with warnings. See the messages above.');
    }
    return 0;
}
