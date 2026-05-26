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
import { confirmTTY as defaultConfirmTTY } from './setup-cli-confirm.js';
import { DEFAULT_WIZARD_DEPS, runWizard as runWizardImpl } from './setup-wizard.js';
import type { WizardAnswers, WizardDeps } from './setup-wizard-types.js';
import {
    addSiteCommand,
    AddSiteError,
    DEFAULT_ADD_SITE_DEPS,
    type AddSiteArgs,
    type AddSiteDeps,
    type AddSiteResult,
} from './sites/cli/add-site.js';
import {
    DEFAULT_LIST_SITES_DEPS,
    listSitesCommand,
    type ListSitesDeps,
} from './sites/cli/list-sites.js';
import {
    DEFAULT_REMOVE_SITE_DEPS,
    removeSiteCommand,
    RemoveSiteError,
    type RemoveSiteDeps,
} from './sites/cli/remove-site.js';
import {
    DEFAULT_SET_DEFAULT_DEPS,
    setDefaultCommand,
    SetDefaultError,
    type SetDefaultDeps,
} from './sites/cli/set-default.js';
import {
    DEFAULT_TEST_SITE_DEPS,
    renderTestSiteLines,
    testSiteCommand,
    TestSiteError,
    type TestSiteDeps,
} from './sites/cli/test-site.js';
import { defaultSitesFilePath } from './sites/paths.js';

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

Multi-site commands (W9):
  add-site         Add a site to ~/.config/yt-builder-mcp/sites.json.
                   Flags: --url <url>, --token <bearer> OR --token-ref
                   <op://...>, --platform auto|wordpress|joomla,
                   --label "...", --site-id <slug>, --default, --yes.
  list-sites       Show the configured sites (no network calls).
  remove-site <id> Remove a site; auto-promotes the next as default.
                   Flag: --yes to skip the confirmation prompt.
  set-default <id> Flip is_default to the given site_id.
  test-site <id>   Probe /health (no auth) + /etag (auth) for one site.

  --version, -v    Print the package version.
  --help, -h, help Show this message.

Setup flags (for non-interactive / CI usage):
  --non-interactive       Skip all prompts; fail-fast on missing input.
  --url <site-url>        Site base URL (required with --non-interactive).
                          WordPress or Joomla — the wizard auto-detects the
                          platform from the URL shape.
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

Pickup mode (one-click setup from the admin "Setup wizard" flow):
  --pickup <url>          Pickup URL revealed by the host CMS admin (WordPress:
                          wp-admin → Tools → "YT Builder MCP"; Joomla:
                          Administrator → Components → "YT Builder MCP").
                          WordPress:  https://example.com/wp-json/yt-builder-mcp/v1/setup/pickup
                          Joomla:      https://example.com/api/index.php/v1/yt-builder-mcp/setup/pickup
  --nonce <code>          One-shot nonce revealed alongside the pickup URL.
                          Pairs 1:1 with --pickup (both or neither).

  When --pickup + --nonce are passed, the wizard fetches the freshly-minted
  Bearer + canonical URL from the plugin in a single POST and skips the
  URL/token prompts entirely. The pickup is IP-bound, single-use, and
  expires after 5 minutes. If --url or --token are also passed they are
  ignored (a warning is printed to stderr).

Example (pickup — WordPress):
  npx -y @wootsup/yt-builder-mcp setup \\
    --pickup https://example.com/wp-json/yt-builder-mcp/v1/setup/pickup \\
    --nonce "$NONCE" \\
    --client claude-desktop

Example (pickup — Joomla):
  npx -y @wootsup/yt-builder-mcp setup \\
    --pickup https://example.com/api/index.php/v1/yt-builder-mcp/setup/pickup \\
    --nonce "$NONCE" \\
    --client claude-desktop

Environment (when launched by an AI client):
  YTB_MCP_SITE_URL       Host CMS base URL (e.g. https://example.com).
                         Works for BOTH WordPress and Joomla.
  YTB_MCP_WP_URL         DEPRECATED alias for YTB_MCP_SITE_URL — still
                         honoured for pre-Wave-7 configurations.
  YTB_MCP_BEARER_TOKEN   Bearer key from:
                           - WordPress: wp-admin → Tools → "YT Builder MCP"
                           - Joomla: Administrator → Components → "YT Builder MCP"
                         Format: ytb_(live|test)_<payload>.<signature>
  YTB_MCP_PLATFORM       Optional explicit platform: 'wordpress' or 'joomla'.
                         Set this to 'joomla' when YTB_MCP_SITE_URL is an
                         origin-only Joomla URL (no /api/index.php/ in path).
                         Defaults to URL-shape auto-detection.
  YTB_MCP_TEST_MODE=1    Skip stdio loop (used by smoke tests).

Documentation: https://github.com/wootsup/yt-builder-mcp
`;

// ── Argument parser (non-interactive flags) ─────────────────────────────

export interface ParsedSetupArgs {
    /** True when `--non-interactive` was passed. Always boolean. */
    readonly nonInteractive: boolean;
    /** Site base URL from `--url` (WordPress or Joomla), trailing slash stripped, trimmed. */
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

// ── W9 multi-site arg parsers ───────────────────────────────────────────

/**
 * Parsed shape for the `add-site` subcommand. Pure-data; tests
 * exercise this without filesystem deps.
 */
export interface ParsedAddSiteArgs {
    readonly url: string;
    readonly token: string;
    readonly tokenRef: string;
    readonly platform?: 'auto' | 'wordpress' | 'joomla';
    readonly label: string;
    readonly siteId: string;
    readonly default: boolean;
    readonly yes: boolean;
    readonly sitesFile?: string;
    readonly errors: readonly string[];
}

/**
 * Parse `add-site` flags from the tail of argv. Accepts both
 * `--key value` and `--key=value` styles. Unknown flags are reported
 * as errors so typos never silently disable validation.
 */
export function parseAddSiteArgs(argv: readonly string[]): ParsedAddSiteArgs {
    let url = '';
    let token = '';
    let tokenRef = '';
    let label = '';
    let siteId = '';
    let platform: 'auto' | 'wordpress' | 'joomla' | undefined;
    let isDefault = false;
    let yes = false;
    let sitesFile: string | undefined;
    const errors: string[] = [];

    function consume(name: string, inline: string | undefined, i: number): {
        value: string;
        nextIndex: number;
    } {
        if (inline !== undefined) return { value: inline, nextIndex: i };
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
            case '--url': {
                const r = consume('--url', inline, i);
                url = r.value.trim().replace(/\/+$/, '');
                i = r.nextIndex;
                break;
            }
            case '--token': {
                const r = consume('--token', inline, i);
                token = r.value.trim();
                i = r.nextIndex;
                break;
            }
            case '--token-ref': {
                const r = consume('--token-ref', inline, i);
                tokenRef = r.value.trim();
                i = r.nextIndex;
                break;
            }
            case '--platform': {
                const r = consume('--platform', inline, i);
                const v = r.value.trim();
                if (v === 'auto' || v === 'wordpress' || v === 'joomla') {
                    platform = v;
                } else if (v.length > 0) {
                    errors.push(
                        `--platform must be one of auto, wordpress, joomla (got "${v}").`,
                    );
                }
                i = r.nextIndex;
                break;
            }
            case '--label': {
                const r = consume('--label', inline, i);
                label = r.value;
                i = r.nextIndex;
                break;
            }
            case '--site-id': {
                const r = consume('--site-id', inline, i);
                siteId = r.value.trim();
                i = r.nextIndex;
                break;
            }
            case '--default': {
                isDefault = true;
                if (inline !== undefined) {
                    errors.push('Flag --default does not take a value.');
                }
                break;
            }
            case '--yes':
            case '-y': {
                yes = true;
                if (inline !== undefined) {
                    errors.push(`Flag ${flag} does not take a value.`);
                }
                break;
            }
            case '--sites-file': {
                const r = consume('--sites-file', inline, i);
                sitesFile = r.value.trim();
                i = r.nextIndex;
                break;
            }
            case '--help':
            case '-h': {
                errors.push('HELP_REQUESTED');
                break;
            }
            default: {
                errors.push(`Unknown add-site flag: ${flag}`);
            }
        }
    }

    const parsed: ParsedAddSiteArgs = {
        url,
        token,
        tokenRef,
        label,
        siteId: siteId.length > 0 ? siteId : 'default',
        default: isDefault,
        yes,
        ...(platform !== undefined ? { platform } : {}),
        ...(sitesFile !== undefined ? { sitesFile } : {}),
        errors,
    };
    return parsed;
}

const ADD_SITE_HELP =
    `Usage: yt-builder-mcp add-site [flags]\n\n` +
    `Flags:\n` +
    `  --url <url>             (required) Site base URL (WordPress or Joomla origin).\n` +
    `  --token <bearer>        Inline bearer (mutually exclusive with --token-ref).\n` +
    `  --token-ref <op://...>  1Password Secret Reference for the bearer.\n` +
    `  --platform <kind>       auto|wordpress|joomla (default: auto).\n` +
    `  --label "<text>"        Human-readable label (≤120 chars).\n` +
    `  --site-id <slug>        Registry key (default: "default").\n` +
    `  --default               Mark this site as the default.\n` +
    `  --yes, -y               Overwrite an existing site_id without confirming.\n` +
    `  --sites-file <path>     Override sites.json location.\n\n` +
    `Notes:\n` +
    `  - The first site you add becomes the default automatically.\n` +
    `  - Use --token OR --token-ref, not both.\n` +
    `  - URL is normalised (trailing slashes stripped).\n\n` +
    `Example:\n` +
    `  yt-builder-mcp add-site --url https://acme.example.com \\\n` +
    `    --token "$YTB_TOKEN" --site-id wp-acme --label "ACME WP"\n`;

const REMOVE_SITE_HELP =
    `Usage: yt-builder-mcp remove-site <site_id> [--yes] [--sites-file <path>]\n\n` +
    `Removes the site from sites.json. If the default site is removed and at\n` +
    `least one other site remains, the next site in insertion order becomes\n` +
    `the new default. Removing the only site clears default_site_id and\n` +
    `leaves an empty registry.\n\n` +
    `Confirmation is required unless --yes is passed. In non-TTY contexts\n` +
    `(CI, piped stdin) the subcommand fails with CONFIRM_REQUIRED rather\n` +
    `than silently deleting.\n\n` +
    `Example:\n` +
    `  yt-builder-mcp remove-site wp-acme --yes\n`;

const SET_DEFAULT_HELP =
    `Usage: yt-builder-mcp set-default <site_id> [--yes] [--sites-file <path>]\n\n` +
    `Changes which site every subsequent tool call routes to by default\n` +
    `when site_id is omitted. Confirmation is required unless --yes is\n` +
    `passed — in non-TTY contexts the subcommand fails with\n` +
    `SET_DEFAULT_CONFIRM_REQUIRED rather than silently flipping.\n\n` +
    `Example:\n` +
    `  yt-builder-mcp set-default wp-beta --yes\n`;

const TEST_SITE_HELP =
    `Usage: yt-builder-mcp test-site <site_id> [--sites-file <path>]\n\n` +
    `Probes /health (unauthenticated) and /etag (authenticated) in parallel\n` +
    `against the configured Bearer. Returns plugin reachability + Bearer\n` +
    `validity in one call. Does NOT mutate the registry or the site.\n\n` +
    `Example:\n` +
    `  yt-builder-mcp test-site wp-acme\n`;

const LIST_SITES_HELP =
    `Usage: yt-builder-mcp list-sites [--sites-file <path>]\n\n` +
    `Prints the configured sites table (site_id, url, platform, label,\n` +
    `bearer_source, is_default). No network calls — pure registry read.\n\n` +
    `Example:\n` +
    `  yt-builder-mcp list-sites\n`;

/**
 * Parse a `<site_id>` positional + optional `--sites-file <path>` /
 * `--yes` tail. Used by remove-site / set-default / test-site.
 */
interface ParsedIdArgs {
    readonly siteId: string;
    readonly yes: boolean;
    readonly sitesFile?: string;
    readonly errors: readonly string[];
    readonly helpRequested: boolean;
}

function parseIdArgs(
    argv: readonly string[],
    allowYes: boolean,
): ParsedIdArgs {
    let siteId = '';
    let yes = false;
    let sitesFile: string | undefined;
    const errors: string[] = [];
    let helpRequested = false;

    for (let i = 0; i < argv.length; i++) {
        const raw = argv[i]!;
        const eq = raw.indexOf('=');
        const flag = eq >= 0 ? raw.slice(0, eq) : raw;
        const inline = eq >= 0 ? raw.slice(eq + 1) : undefined;
        if (flag === '--help' || flag === '-h') {
            helpRequested = true;
            continue;
        }
        if (flag === '--yes' || flag === '-y') {
            if (!allowYes) {
                errors.push(`Unknown flag for this subcommand: ${flag}`);
            } else {
                yes = true;
            }
            if (inline !== undefined) errors.push(`Flag ${flag} does not take a value.`);
            continue;
        }
        if (flag === '--sites-file') {
            const next = inline ?? argv[i + 1];
            if (next === undefined || next.startsWith('--')) {
                errors.push('Flag --sites-file requires a value.');
                continue;
            }
            sitesFile = next.trim();
            if (inline === undefined) i += 1;
            continue;
        }
        if (flag.startsWith('--') || flag.startsWith('-')) {
            errors.push(`Unknown flag: ${flag}`);
            continue;
        }
        if (siteId !== '') {
            errors.push(
                `Too many positional arguments; got "${siteId}" and "${flag}".`,
            );
            continue;
        }
        siteId = flag.trim();
    }

    return {
        siteId,
        yes,
        ...(sitesFile !== undefined ? { sitesFile } : {}),
        errors,
        helpRequested,
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
    /** Test injection — override the resolved sites.json path. */
    resolveSitesPath?: () => string;
    addSiteDeps?: AddSiteDeps;
    listSitesDeps?: ListSitesDeps;
    removeSiteDeps?: RemoveSiteDeps;
    setDefaultDeps?: SetDefaultDeps;
    testSiteDeps?: TestSiteDeps;
    /**
     * W12-R2A — y/N prompt for destructive subcommands (remove-site,
     * set-default). Defaults to {@link defaultConfirmTTY} which reads
     * from process.stdin via readline. Tests inject a stub.
     *
     * Note: when the operator passes `--yes`, this hook is NEVER
     * invoked — the subcommand factory short-circuits before reaching
     * the confirm step. When `--yes` is absent AND no confirm hook is
     * injectable (CI / piped stdin), the factory throws a typed
     * CONFIRM_REQUIRED / SET_DEFAULT_CONFIRM_REQUIRED error.
     */
    confirm?: (message: string) => Promise<boolean>;
    /**
     * Optional override for the post-wizard `addSiteCommand` call.
     * Tests stub this to assert the wizard wires through addSiteCommand
     * without writing to disk.
     */
    addSiteFromWizard?: (
        args: AddSiteArgs,
        path: string,
    ) => Promise<AddSiteResult>;
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
    const resolveSitesPath = deps?.resolveSitesPath ?? (() => defaultSitesFilePath());
    const addSiteDeps = deps?.addSiteDeps ?? DEFAULT_ADD_SITE_DEPS;
    const listSitesDeps = deps?.listSitesDeps ?? DEFAULT_LIST_SITES_DEPS;
    // W12-R2A: wire a TTY confirm hook into the destructive-subcommand
    // deps bags. Tests that supply `removeSiteDeps`/`setDefaultDeps`
    // directly retain full control over the confirm channel (so the
    // existing unit-tests keep working unchanged); only the default
    // dispatcher path gets the `defaultConfirmTTY` injection.
    const confirmHook = deps?.confirm ?? defaultConfirmTTY;
    const removeSiteDeps: RemoveSiteDeps = deps?.removeSiteDeps
        ?? { ...DEFAULT_REMOVE_SITE_DEPS, confirm: confirmHook };
    const setDefaultDeps: SetDefaultDeps = deps?.setDefaultDeps
        ?? { ...DEFAULT_SET_DEFAULT_DEPS, confirm: confirmHook };
    const testSiteDeps = deps?.testSiteDeps ?? DEFAULT_TEST_SITE_DEPS;
    const addSiteFromWizard = deps?.addSiteFromWizard
        ?? ((args, path) => addSiteCommand(args, path, addSiteDeps));

    const [subcommand] = argv;

    // Build a `persistSite` callback for the wizard that translates
    // the collected WizardAnswers into an `addSiteCommand` call so the
    // sites.json registry stays in sync with the env-var path. This is
    // the W9 "wizard internally invokes add-site" wiring.
    const buildPersistSite = (): ((answers: WizardAnswers) => Promise<void>) => {
        return async (answers) => {
            const siteId = (answers.siteId !== undefined && answers.siteId.length > 0)
                ? answers.siteId
                : 'default';
            const path = resolveSitesPath();
            const args: AddSiteArgs = {
                url: answers.wpUrl,
                token: answers.bearer,
                siteId,
                default: true,
                yes: true,
                platform: 'auto',
            };
            await addSiteFromWizard(args, path);
        };
    };

    if (subcommand === undefined || subcommand === 'setup') {
        const setupArgs = argv[0] === 'setup' ? argv.slice(1) : argv;
        const parsed = parseSetupArgs(setupArgs);

        // Surface non-fatal warnings (e.g. "--url ignored in pickup mode").
        for (const line of parsed.warnings) err(`yt-builder-mcp: warning: ${line}`);

        const pickupMode = (parsed.pickup ?? '') !== '' || (parsed.nonce ?? '') !== '';
        const persistSite = buildPersistSite();

        function withPersist(base: WizardDeps): WizardDeps {
            return { ...base, persistSite };
        }

        if (pickupMode) {
            if (parsed.errors.length > 0) {
                for (const line of parsed.errors) err(`yt-builder-mcp: ${line}`);
                err(HELP_TEXT);
                return 2;
            }
            return wizard(withPersist(buildPickupDeps(parsed)));
        }
        if (parsed.nonInteractive) {
            if (parsed.errors.length > 0) {
                for (const line of parsed.errors) err(`yt-builder-mcp: ${line}`);
                err(HELP_TEXT);
                return 2;
            }
            return wizard(withPersist(buildNonInteractiveDeps(parsed)));
        }
        if (parsed.errors.length > 0) {
            for (const line of parsed.errors) err(`yt-builder-mcp: ${line}`);
            err(HELP_TEXT);
            return 1;
        }
        return wizard(withPersist(DEFAULT_WIZARD_DEPS));
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
        case 'add-site': {
            const tail = argv.slice(1);
            const parsed = parseAddSiteArgs(tail);
            if (parsed.errors.some((e) => e === 'HELP_REQUESTED')) {
                out(ADD_SITE_HELP);
                return 0;
            }
            if (parsed.errors.length > 0) {
                for (const line of parsed.errors) err(`yt-builder-mcp: ${line}`);
                err(ADD_SITE_HELP);
                return 2;
            }
            const path = parsed.sitesFile ?? resolveSitesPath();
            const args: AddSiteArgs = {
                url: parsed.url,
                ...(parsed.token.length > 0 ? { token: parsed.token } : {}),
                ...(parsed.tokenRef.length > 0 ? { tokenRef: parsed.tokenRef } : {}),
                ...(parsed.platform !== undefined ? { platform: parsed.platform } : {}),
                ...(parsed.label.length > 0 ? { label: parsed.label } : {}),
                siteId: parsed.siteId,
                default: parsed.default,
                yes: parsed.yes,
            };
            try {
                const result = await addSiteCommand(args, path, addSiteDeps);
                const stateBits: string[] = [];
                if (result.becameDefault) stateBits.push('default — first site');
                else if (result.defaultRequested) stateBits.push('default');
                if (result.overwritten) stateBits.push('overwritten');
                const state = stateBits.length > 0 ? ` (${stateBits.join(', ')})` : '';
                const verb = result.overwritten ? 'updated' : 'added';
                out(`✓ Site "${result.siteId}" ${verb}${state}.`);
                out(`  Path: ${result.path}`);
                out('Restart your AI client to pick up the new site list.');
                return 0;
            } catch (e) {
                if (e instanceof AddSiteError) {
                    err(`yt-builder-mcp: add-site failed [${e.code}]: ${e.message}`);
                    return 2;
                }
                const msg = e instanceof Error ? e.message : String(e);
                err(`yt-builder-mcp: add-site failed: ${msg}`);
                return 2;
            }
        }
        case 'list-sites': {
            const tail = argv.slice(1);
            const parsed = parseIdArgs(tail, false);
            // list-sites takes no positional id; if one slipped in it's
            // a usage error (parseIdArgs allows zero or one).
            if (parsed.helpRequested) {
                out(LIST_SITES_HELP);
                return 0;
            }
            if (parsed.siteId !== '') {
                err(`yt-builder-mcp: list-sites takes no positional argument (got "${parsed.siteId}").`);
                err(LIST_SITES_HELP);
                return 2;
            }
            if (parsed.errors.length > 0) {
                for (const line of parsed.errors) err(`yt-builder-mcp: ${line}`);
                err(LIST_SITES_HELP);
                return 2;
            }
            const path = parsed.sitesFile ?? resolveSitesPath();
            try {
                const lines = await listSitesCommand(path, listSitesDeps);
                for (const line of lines) out(line);
                return 0;
            } catch (e) {
                const msg = e instanceof Error ? e.message : String(e);
                err(`yt-builder-mcp: list-sites failed: ${msg}`);
                return 2;
            }
        }
        case 'remove-site': {
            const tail = argv.slice(1);
            const parsed = parseIdArgs(tail, true);
            if (parsed.helpRequested) {
                out(REMOVE_SITE_HELP);
                return 0;
            }
            if (parsed.siteId === '') {
                err('yt-builder-mcp: remove-site requires a <site_id> argument.');
                err(REMOVE_SITE_HELP);
                return 2;
            }
            if (parsed.errors.length > 0) {
                for (const line of parsed.errors) err(`yt-builder-mcp: ${line}`);
                err(REMOVE_SITE_HELP);
                return 2;
            }
            const path = parsed.sitesFile ?? resolveSitesPath();
            try {
                const result = await removeSiteCommand(
                    parsed.siteId,
                    path,
                    { yes: parsed.yes },
                    removeSiteDeps,
                );
                if (result.cancelled) {
                    out(`Cancelled — site "${result.siteId}" not removed.`);
                    return 0;
                }
                out(`✓ Site "${result.siteId}" removed.`);
                if (result.promoted !== undefined) {
                    out(`  Default promoted: ${result.promoted}`);
                }
                if (result.nowEmpty) {
                    out('  (registry is now empty)');
                }
                out(`  Path: ${result.path}`);
                out('Restart your AI client to pick up the new site list.');
                return 0;
            } catch (e) {
                if (e instanceof RemoveSiteError) {
                    err(`yt-builder-mcp: remove-site failed [${e.code}]: ${e.message}`);
                    return 2;
                }
                const msg = e instanceof Error ? e.message : String(e);
                err(`yt-builder-mcp: remove-site failed: ${msg}`);
                return 2;
            }
        }
        case 'set-default': {
            const tail = argv.slice(1);
            // W12-R2A: set-default is a routing-destructive op (every
            // subsequent default-routed tool call lands on a different
            // site). Allow --yes so CI can flip without a prompt.
            const parsed = parseIdArgs(tail, true);
            if (parsed.helpRequested) {
                out(SET_DEFAULT_HELP);
                return 0;
            }
            if (parsed.siteId === '') {
                err('yt-builder-mcp: set-default requires a <site_id> argument.');
                err(SET_DEFAULT_HELP);
                return 2;
            }
            if (parsed.errors.length > 0) {
                for (const line of parsed.errors) err(`yt-builder-mcp: ${line}`);
                err(SET_DEFAULT_HELP);
                return 2;
            }
            const path = parsed.sitesFile ?? resolveSitesPath();
            try {
                const result = await setDefaultCommand(
                    parsed.siteId,
                    path,
                    { yes: parsed.yes },
                    setDefaultDeps,
                );
                if (result.cancelled) {
                    out(`Cancelled — default unchanged.`);
                    return 0;
                }
                const prior =
                    result.previousDefault !== null
                        ? `(was: ${result.previousDefault})`
                        : '(no previous default)';
                out(`✓ Default set to "${result.siteId}" ${prior}.`);
                out(`  Path: ${result.path}`);
                out('Restart your AI client to pick up the new site list.');
                return 0;
            } catch (e) {
                if (e instanceof SetDefaultError) {
                    err(`yt-builder-mcp: set-default failed [${e.code}]: ${e.message}`);
                    return 2;
                }
                const msg = e instanceof Error ? e.message : String(e);
                err(`yt-builder-mcp: set-default failed: ${msg}`);
                return 2;
            }
        }
        case 'test-site': {
            const tail = argv.slice(1);
            const parsed = parseIdArgs(tail, false);
            if (parsed.helpRequested) {
                out(TEST_SITE_HELP);
                return 0;
            }
            if (parsed.siteId === '') {
                err('yt-builder-mcp: test-site requires a <site_id> argument.');
                err(TEST_SITE_HELP);
                return 2;
            }
            if (parsed.errors.length > 0) {
                for (const line of parsed.errors) err(`yt-builder-mcp: ${line}`);
                err(TEST_SITE_HELP);
                return 2;
            }
            const path = parsed.sitesFile ?? resolveSitesPath();
            try {
                const result = await testSiteCommand(
                    parsed.siteId,
                    path,
                    testSiteDeps,
                );
                for (const line of renderTestSiteLines(result)) out(line);
                const exitCode =
                    result.unknownSite
                    || !result.pluginReachable
                    || !result.bearerValid
                        ? 2
                        : 0;
                return exitCode;
            } catch (e) {
                if (e instanceof TestSiteError) {
                    err(`yt-builder-mcp: test-site failed [${e.code}]: ${e.message}`);
                    return 2;
                }
                const msg = e instanceof Error ? e.message : String(e);
                err(`yt-builder-mcp: test-site failed: ${msg}`);
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
