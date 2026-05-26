/**
 * Public types for the interactive setup wizard.
 *
 * Split out of `setup-wizard.ts` in the Round-1 audit I5 fix so the
 * type surface is consumable without dragging in @clack/prompts or the
 * RestClient. Tests and other modules import these types alone.
 *
 * @license MIT
 */

import type {
    DetectedClient,
    McpServerConfig,
} from './clients/index.js';

export interface WizardAnswers {
    /** Trimmed, trailing-slash-stripped WordPress base URL. */
    readonly wpUrl: string;
    /** Trimmed Bearer key. */
    readonly bearer: string;
    /** IDs of clients the user wants wired up. */
    readonly selectedClients: readonly string[];
    /**
     * Site ID (multi-site registry key). Optional for back-compat with
     * non-interactive flag paths that don't ask for it; the wizard
     * defaults missing/empty values to `'default'`. Renamed from
     * `profileName` in W9 to align with the multi-site registry's
     * `site_id` field (see `src/sites/schema.ts`).
     */
    readonly siteId?: string;
}

/**
 * Decoded payload of a Bearer key. The plugin signs `kid`, `scope`, `iss`
 * (issuer = WordPress `site_url`), and optionally `exp` into the token's
 * base64url payload section. The wizard decodes this WITHOUT verifying the
 * signature (verification requires the plugin's secret) — `iss` is used
 * purely to pre-fill the URL prompt; the canonical auth check happens
 * later when the wizard hits `/v1/health` with the token.
 */
export interface DecodedTokenPayload {
    readonly kid: string;
    readonly scope: readonly string[];
    /** Issuer — canonical WordPress site URL the key was generated on. */
    readonly iss: string;
    /** Unix expiry timestamp, if the operator opted-in to expiry. */
    readonly exp?: number;
}

/**
 * Result of the public `/v1/identity` probe — the wizard uses this to
 * (a) confirm the plugin is installed at the URL the user typed and
 * (b) cross-check the URL against the token's `iss` claim before writing
 * any AI-client config.
 */
export interface IdentityProbeResult {
    readonly ok: boolean;
    /** Canonical site URL reported by the plugin. */
    readonly siteurl?: string;
    /** Platform discriminator: 'wordpress' today, 'joomla' later. */
    readonly platform?: 'wordpress' | 'joomla';
    /** Product discriminator (defends against another MCP at the same URL). */
    readonly product?: string;
    /** Plugin version (for diagnostic display). */
    readonly pluginVersion?: string;
    /** Failure message when ok=false. */
    readonly error?: string;
}

/**
 * Result of a successful `/setup/pickup` POST — a one-shot, IP-bound,
 * 5-minute-TTL retrieval of a freshly-minted Bearer token + the
 * canonical site URL the plugin reports. The wizard treats this as
 * "answers obtained" and skips the URL/token prompts.
 *
 * Failure modes (404 expired/consumed, 403 IP-mismatch, 429 rate-limit,
 * 400 invalid) are signalled by `fetchPickup` rejecting with a thrown
 * Error — there is no partial success shape.
 */
export interface PickupResult {
    /** Bearer token in plugin format `ytb_(live|test)_<payload>.<sig>`. */
    readonly token: string;
    /** Canonical WordPress site URL reported by the plugin. */
    readonly siteurl: string;
    /** Plugin version string for diagnostic display. */
    readonly pluginVersion: string;
}

export interface HealthProbeResult {
    readonly ok: boolean;
    /** Plugin version reported by the server (if any). */
    readonly pluginVersion?: string;
    /** Error message if `ok=false`. */
    readonly error?: string;
}

export interface AuthProbeResult {
    readonly ok: boolean;
    readonly error?: string;
}

export interface HandshakeResult {
    readonly ok: boolean;
    readonly pluginVersion?: string;
    /** Mismatch warning string when plugin version != NPM package version. */
    readonly warning?: string;
    readonly error?: string;
}

export interface WriteResult {
    readonly id: string;
    readonly label: string;
    readonly ok: boolean;
    readonly error?: string;
    /** Path that was written. Empty string on failure. */
    readonly path: string;
    /** Snapshot of the file content BEFORE the write (for rollback). */
    readonly previousContent: string | null;
}

export interface WizardDeps {
    /** Collect answers from the user. Return `null` on cancel. */
    prompt: (input: {
        detected: readonly DetectedClient[];
    }) => Promise<WizardAnswers | null>;
    /** Detect installed AI clients. */
    detectClients: () => readonly DetectedClient[];
    /** Probe the plugin /health endpoint (no auth). */
    probeHealth: (wpUrl: string) => Promise<HealthProbeResult>;
    /**
     * Probe the plugin /identity endpoint (no auth) — used to verify the
     * URL belongs to a yt-builder-mcp install and cross-check it
     * against the token's `iss` claim. Optional for back-compat: when the
     * field is missing the wizard skips the identity check.
     */
    probeIdentity?: (wpUrl: string) => Promise<IdentityProbeResult>;
    /** Probe the plugin /etag endpoint (Bearer auth). */
    probeAuth: (wpUrl: string, bearer: string) => Promise<AuthProbeResult>;
    /** Confirm whether to continue when a probe fails. */
    confirmContinue: (message: string) => Promise<boolean>;
    /** Write the MCP server config for a single client. */
    writeClient: (
        id: string,
        serverName: string,
        config: McpServerConfig,
    ) => Promise<WriteResult>;
    /** Re-run the auth probe AFTER the configs were written and parse
     *  the plugin version. Used as the dist-tag handshake. */
    handshake: (
        wpUrl: string,
        bearer: string,
        packageVersion: string,
    ) => Promise<HandshakeResult>;
    /**
     * POST `{ nonce }` to the pickup URL revealed by wp-admin and resolve
     * to a `PickupResult` (token + canonical site URL + plugin version).
     *
     * Implementations MUST throw on any non-200 response (404 expired /
     * consumed, 403 IP-mismatch, 429 rate-limited, 400 malformed) so the
     * wizard's pickup branch can abort with a single error path. The
     * thrown Error's `.message` is what gets shown to the user.
     *
     * Optional for back-compat with older WizardDeps bags constructed
     * before Wave-C — when missing, callers that pass `--pickup` get a
     * clear "pickup not supported by this WizardDeps" error.
     */
    fetchPickup?: (pickupUrl: string, nonce: string) => Promise<PickupResult>;
    /** Optional sink for human-facing status lines. */
    log?: (line: string) => void;
    /**
     * W9 — invoked after both the health + auth probes have produced a
     * decision (continue / aborted) so the wizard can persist the
     * answers to the multi-site sites.json registry. Implementations
     * MUST be idempotent: the wizard calls this exactly once on a
     * successful run, but the dispatcher's overall retry policy may
     * re-run the wizard.
     *
     * On failure the implementation should throw — the wizard treats
     * the failure as a write-failure equivalent (rollback + exit 4).
     * Optional for back-compat: when missing the wizard skips the
     * sites.json write entirely (pre-W9 single-site env-var flow).
     */
    persistSite?: (answers: WizardAnswers) => Promise<void>;
}
