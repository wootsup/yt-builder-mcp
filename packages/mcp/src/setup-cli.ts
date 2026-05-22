#!/usr/bin/env node
/**
 * Top-level CLI dispatcher for `@wootsup/yt-builder-mcp`.
 *
 * Round-1 audit I5 fix (2026-05-22): the wizard implementation lives in
 * `setup-wizard.ts`; this file is the thin dispatcher (`runCli`) plus
 * the ESM main-guard. Both have their own deps-bag (`WizardDeps` /
 * `RunCliDeps`) so unit tests can drive them independently.
 *
 * Subcommands:
 *
 *   setup (default)   — runWizard (see `setup-wizard.ts`)
 *   install-skill     — copy the bundled skill into ~/.claude/skills/
 *                       and append a marker block to ~/AGENTS.md.
 *   --help / help     — print usage.
 *   --version / -v    — print the package version.
 *
 * Re-exports `runWizard`, `majorMinor` and the wizard public types so
 * existing callers (and tests that already import from `./setup-cli`)
 * keep working with a one-line import.
 *
 * @license MIT
 */

import { ALL_CLIENTS } from './clients/index.js';
import { installSkill as defaultInstallSkill } from './install-skill.js';
import { SERVER_VERSION } from './server.js';
import { DEFAULT_WIZARD_DEPS, runWizard as runWizardImpl } from './setup-wizard.js';
import type { WizardAnswers, WizardDeps } from './setup-wizard-types.js';

// Re-export the wizard surface so consumers that imported from
// `./setup-cli` before the I5 split keep working without changes.
export {
    DEFAULT_WIZARD_DEPS,
    majorMinor,
    runWizard,
    type AuthProbeResult,
    type HandshakeResult,
    type HealthProbeResult,
    type WizardAnswers,
    type WizardDeps,
    type WriteResult,
} from './setup-wizard.js';

const HELP_TEXT = `Usage: yt-builder-mcp <command> [flags]

Commands:
  setup            Interactive first-run wizard (default if no command)
  install-skill    Copy the bundled YOOtheme Builder skill into
                   ~/.claude/skills/ and register it in ~/AGENTS.md.
                   Note: ~/.claude/skills/ is the universal-marker path
                   recognised by Claude Desktop and other AI clients.
  --version, -v    Print the package version.
  --help, -h, help Show this message.

Setup flags (for non-interactive / CI usage):
  --non-interactive       Skip all prompts; fail-fast on missing input.
  --url <wp-url>          WordPress base URL (required with --non-interactive).
  --token <bearer>        Bearer key (required with --non-interactive).
  --client <id>           AI client id to configure; repeatable. Valid ids:
                          claude-desktop, claude-code, cursor, zed, continue,
                          cline, roo-code, codex-cli, gemini-cli.

Example (non-interactive):
  npx -y @wootsup/yt-builder-mcp setup \\
    --non-interactive \\
    --client cursor --client claude-desktop \\
    --url https://example.com \\
    --token "$YTB_TOKEN"

Pickup mode (one-click setup via wp-admin):
  --pickup <url>          Pickup URL revealed by the wp-admin "Setup wizard"
                          flow; e.g. https://example.com/wp-json/yt-builder-mcp/v1/setup/pickup
  --nonce <code>          One-shot nonce revealed alongside the pickup URL.
                          Pairs 1:1 with --pickup (both or neither).

  When --pickup + --nonce are passed, the wizard fetches the freshly-minted
  Bearer + canonical URL from the plugin in a single POST and skips the
  URL/token prompts entirely. The pickup is IP-bound, single-use, and
  expires after 5 minutes. If --url or --token are also passed they are
  ignored (a warning is printed to stderr).

Example (pickup):
  npx -y @wootsup/yt-builder-mcp setup \\
    --pickup https://example.com/wp-json/yt-builder-mcp/v1/setup/pickup \\
    --nonce "$NONCE" \\
    --client claude-desktop

Environment (when launched by an AI client):
  YTB_MCP_WP_URL         WordPress base URL (e.g. https://example.com).
  YTB_MCP_BEARER_TOKEN   Bearer key from wp-admin → "YOOtheme Builder MCP".
                         Format: ytb_(live|test)_<payload>.<signature>
  YTB_MCP_TEST_MODE=1    Skip stdio loop (used by smoke tests).

Documentation: https://github.com/wootsup/yt-builder-mcp
`;

// ── Argument parser (non-interactive flags) ─────────────────────────────

export interface ParsedSetupArgs {
    /** True when `--non-interactive` was passed. Always boolean. */
    readonly nonInteractive: boolean;
    /** WordPress base URL from `--url`, trailing slash stripped, trimmed. */
    readonly url: string;
    /** Bearer token from `--token`, trimmed. */
    readonly token: string;
    /** Client ids from repeated `--client`. */
    readonly clients: readonly string[];
    /**
     * Pickup URL from `--pickup`, trimmed. When set together with
     * `nonce`, the wizard fetches the Bearer + canonical URL from the
     * plugin in a single POST instead of prompting.
     */
    readonly pickup?: string;
    /** One-shot nonce from `--nonce`, trimmed. Pairs 1:1 with `pickup`. */
    readonly nonce?: string;
    /**
     * Human-readable validation errors. Empty array means the parsed
     * args are usable. The dispatcher prints these to stderr and exits
     * non-zero when non-empty AND `--non-interactive` was requested.
     */
    readonly errors: readonly string[];
    /**
     * Non-fatal warnings to surface on stderr (e.g. "--url ignored in
     * pickup mode"). These do NOT block the wizard from running.
     */
    readonly warnings: readonly string[];
}

/**
 * Parse the `setup` subcommand argv tail. Pure function; does no I/O.
 *
 * Accepts both `--key value` and `--key=value` styles. Unknown flags
 * are reported as errors so typos don't silently disable validation.
 */
export function parseSetupArgs(argv: readonly string[]): ParsedSetupArgs {
    let nonInteractive = false;
    let url = '';
    let token = '';
    let pickup = '';
    let nonce = '';
    const clients: string[] = [];
    const errors: string[] = [];
    const warnings: string[] = [];

    function consumeValue(name: string, inline: string | undefined, i: number): {
        value: string;
        nextIndex: number;
    } {
        if (inline !== undefined) {
            return { value: inline, nextIndex: i };
        }
        const next = argv[i + 1];
        if (next === undefined || next.startsWith('--')) {
            errors.push(`Flag ${name} requires a value.`);
            return { value: '', nextIndex: i };
        }
        return { value: next, nextIndex: i + 1 };
    }

    for (let i = 0; i < argv.length; i++) {
        const raw = argv[i]!;
        const eq = raw.indexOf('=');
        const flag = eq >= 0 ? raw.slice(0, eq) : raw;
        const inline = eq >= 0 ? raw.slice(eq + 1) : undefined;
        switch (flag) {
            case '--non-interactive': {
                nonInteractive = true;
                if (inline !== undefined) {
                    errors.push('Flag --non-interactive does not take a value.');
                }
                break;
            }
            case '--url': {
                const { value, nextIndex } = consumeValue('--url', inline, i);
                url = value.trim().replace(/\/+$/, '');
                i = nextIndex;
                break;
            }
            case '--token': {
                const { value, nextIndex } = consumeValue('--token', inline, i);
                token = value.trim();
                i = nextIndex;
                break;
            }
            case '--pickup': {
                const { value, nextIndex } = consumeValue('--pickup', inline, i);
                pickup = value.trim();
                i = nextIndex;
                break;
            }
            case '--nonce': {
                const { value, nextIndex } = consumeValue('--nonce', inline, i);
                nonce = value.trim();
                i = nextIndex;
                break;
            }
            case '--client': {
                const { value, nextIndex } = consumeValue('--client', inline, i);
                if (value !== '') clients.push(value.trim());
                i = nextIndex;
                break;
            }
            default: {
                errors.push(`Unknown setup flag: ${flag}`);
            }
        }
    }

    // Pickup mode validation (Wave C). Pickup pairs 1:1 with nonce; when
    // both are present, the wizard fetches token + URL from the plugin and
    // ignores --url/--token (with a warning so typos don't go silent).
    const pickupMode = pickup !== '' || nonce !== '';
    if (pickupMode) {
        if (pickup === '') errors.push('--pickup is required when --nonce is given.');
        if (nonce === '') errors.push('--nonce is required when --pickup is given.');
        if (url !== '') warnings.push('--url is ignored in pickup mode (the plugin returns the canonical URL).');
        if (token !== '') warnings.push('--token is ignored in pickup mode (the plugin returns the Bearer token).');
        if (clients.length === 0) {
            errors.push('--client is required with --pickup (at least one).');
        }
        const knownIds = new Set(ALL_CLIENTS.map((c) => c.id));
        for (const id of clients) {
            if (!knownIds.has(id)) {
                errors.push(`Unknown --client id "${id}". Valid: ${[...knownIds].sort().join(', ')}.`);
            }
        }
    } else if (nonInteractive) {
        if (url === '') errors.push('--url is required with --non-interactive.');
        if (token === '') errors.push('--token is required with --non-interactive.');
        if (clients.length === 0) errors.push('--client is required with --non-interactive (at least one).');
        const knownIds = new Set(ALL_CLIENTS.map((c) => c.id));
        for (const id of clients) {
            if (!knownIds.has(id)) {
                errors.push(`Unknown --client id "${id}". Valid: ${[...knownIds].sort().join(', ')}.`);
            }
        }
    }

    return { nonInteractive, url, token, pickup, nonce, clients, errors, warnings };
}

/**
 * Build a `WizardDeps` bag that answers prompts from CLI flags instead
 * of asking a human. Reuses every other dependency (probes, writes,
 * handshake) from `DEFAULT_WIZARD_DEPS` so the wizard's exit-code
 * contract (0/2/3/4/5) stays identical.
 *
 * `confirmContinue` always returns `false` — there is no human to ask,
 * so a probe failure cleanly aborts with the documented exit code.
 */
export function buildNonInteractiveDeps(
    parsed: ParsedSetupArgs,
    base: WizardDeps = DEFAULT_WIZARD_DEPS,
): WizardDeps {
    const answers: WizardAnswers = {
        wpUrl: parsed.url,
        bearer: parsed.token,
        selectedClients: parsed.clients,
    };
    return {
        ...base,
        prompt: async () => answers,
        confirmContinue: async () => false,
    };
}

/**
 * Build a `WizardDeps` bag for the Wave-C pickup flow. The wizard's
 * `prompt` step calls `base.fetchPickup(url, nonce)` once, then
 * synthesises the `WizardAnswers` from the response — the actual answers
 * (token + canonical WordPress URL) come straight from the plugin via
 * a 256-bit one-shot nonce, never through chat history / provider logs.
 *
 * On `fetchPickup` failure the prompt resolves to `null` so the wizard
 * exits cleanly with code 130 (user-cancel equivalent — we treat
 * an unrecoverable pickup as "the conversation ends here"). The
 * thrown error is logged via the `log` dep so the operator sees why.
 *
 * `confirmContinue` returns `false` (same rationale as non-interactive).
 */
export function buildPickupDeps(
    parsed: ParsedSetupArgs,
    base: WizardDeps = DEFAULT_WIZARD_DEPS,
): WizardDeps {
    const pickupUrl = parsed.pickup ?? '';
    const nonce = parsed.nonce ?? '';
    const fetchPickup = base.fetchPickup;
    const logFn = base.log;

    return {
        ...base,
        prompt: async () => {
            if (fetchPickup === undefined) {
                const msg = 'Pickup mode requested but no fetchPickup is wired in this WizardDeps bag.';
                if (logFn !== undefined) logFn(msg);
                else process.stderr.write(`${msg}\n`);
                return null;
            }
            try {
                const result = await fetchPickup(pickupUrl, nonce);
                return {
                    wpUrl: result.siteurl,
                    bearer: result.token,
                    selectedClients: parsed.clients,
                };
            } catch (e) {
                const msg = e instanceof Error ? e.message : String(e);
                if (logFn !== undefined) logFn(`Pickup failed: ${msg}`);
                else process.stderr.write(`Pickup failed: ${msg}\n`);
                return null;
            }
        },
        confirmContinue: async () => false,
    };
}

// ── runCli dispatcher ───────────────────────────────────────────────────

export interface RunCliDeps {
    /**
     * Wizard runner. The optional deps bag lets the non-interactive
     * dispatcher inject prebuilt answers; interactive callers pass
     * nothing and the wizard uses `DEFAULT_WIZARD_DEPS`.
     */
    runWizard: (deps?: WizardDeps) => Promise<number>;
    installSkill: typeof defaultInstallSkill;
    log?: (line: string) => void;
    error?: (line: string) => void;
}

export async function runCli(
    argv: readonly string[],
    deps?: Partial<RunCliDeps>,
): Promise<number> {
    const out = deps?.log ?? ((s: string) => process.stdout.write(`${s}\n`));
    const err =
        deps?.error ?? ((s: string) => process.stderr.write(`${s}\n`));
    const wizard = deps?.runWizard ?? ((wizDeps?: WizardDeps) => runWizardImpl(wizDeps));
    const install = deps?.installSkill ?? defaultInstallSkill;

    const [subcommand] = argv;

    if (subcommand === undefined || subcommand === 'setup') {
        const setupArgs = argv[0] === 'setup' ? argv.slice(1) : argv;
        const parsed = parseSetupArgs(setupArgs);

        // Surface non-fatal warnings (e.g. "--url ignored in pickup mode").
        for (const line of parsed.warnings) err(`yt-builder-mcp: warning: ${line}`);

        const pickupMode = (parsed.pickup ?? '') !== '' || (parsed.nonce ?? '') !== '';

        if (pickupMode) {
            if (parsed.errors.length > 0) {
                for (const line of parsed.errors) err(`yt-builder-mcp: ${line}`);
                err(HELP_TEXT);
                return 2;
            }
            return wizard(buildPickupDeps(parsed));
        }
        if (parsed.nonInteractive) {
            if (parsed.errors.length > 0) {
                for (const line of parsed.errors) err(`yt-builder-mcp: ${line}`);
                err(HELP_TEXT);
                return 2;
            }
            return wizard(buildNonInteractiveDeps(parsed));
        }
        if (parsed.errors.length > 0) {
            for (const line of parsed.errors) err(`yt-builder-mcp: ${line}`);
            err(HELP_TEXT);
            return 1;
        }
        return wizard();
    }

    switch (subcommand) {
        case 'install-skill':
        case 'install': {
            try {
                const result = await install();
                if (result.markerAlreadyPresent) {
                    out(
                        `yt-builder-mcp: skill refreshed at ${result.skillTargetDir} (marker already in AGENTS.md).`,
                    );
                } else {
                    out(
                        `yt-builder-mcp: skill installed at ${result.skillTargetDir}; ${result.agentsFile} updated.`,
                    );
                }
                return 0;
            } catch (e) {
                const msg = e instanceof Error ? e.message : String(e);
                err(`yt-builder-mcp: install-skill failed: ${msg}`);
                return 2;
            }
        }
        case '--version':
        case '-v': {
            out(SERVER_VERSION);
            return 0;
        }
        case 'help':
        case '--help':
        case '-h': {
            out(HELP_TEXT);
            return 0;
        }
        default: {
            err(`yt-builder-mcp: unknown command "${subcommand}".`);
            err(HELP_TEXT);
            return 1;
        }
    }
}

// ── direct CLI entry ────────────────────────────────────────────────────

const isMain =
    typeof process !== 'undefined' &&
    process.argv[1] !== undefined &&
    import.meta.url === `file://${process.argv[1]}`;

if (isMain) {
    runCli(process.argv.slice(2)).then(
        (code) => process.exit(code),
        (err: unknown) => {
            const msg = err instanceof Error ? err.message : String(err);
            process.stderr.write(`yt-builder-mcp: fatal: ${msg}\n`);
            process.exit(99);
        },
    );
}
