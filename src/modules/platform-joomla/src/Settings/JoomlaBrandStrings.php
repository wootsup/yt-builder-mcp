<?php

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Settings;

/**
 * Verbatim copy-strings for the Joomla settings surface.
 *
 * Source-of-truth: yt-builder-mcp Cookbook §6 Appendix B (2026-05-23,
 * `~/Projekte/getimo/40-Plans/active/2026-05-23-07-cookbook-ch6-dx-ux.md`,
 * lines 1039-1128).
 *
 * **Cookbook §6 verbatim rule:** these strings are load-bearing on customer
 * trust and the cold-reliability benchmark. Re-wording any of them requires
 * a fresh cold-agent benchmark run before the change can ship. The two
 * documented exceptions (Joomla-port substitutions) are:
 *   - B.7 line 7: "WordPress" → "Joomla"
 *   - B.12: "wp-admin → Tools" → "Admin → Components → YT Builder MCP"
 *
 * Wave-4 UI work consumes these constants when rendering the Joomla
 * settings page (Empty-state, Reveal-token box, Advanced section, AI-prompt,
 * Diagnostics, Revoke confirmation, Footer, Pickup error banners).
 *
 * The string-values are intentionally **not** wrapped in
 * `Text::_('JTRANS_KEY')` — Cookbook §6.10 brand convention requires
 * verbatim English copy until a translation-ready release. The matching
 * `.ini` files only define structural labels (tab headers, button captions),
 * not these brand strings.
 *
 * **Round-3 audit A6 P1-A + P2-A**: two parallel groups of constants are
 * defined for Joomla-specific surfaces:
 *
 *   - `DIAGNOSE_*` (P1-A) — diagnostics-tab and CLI-wizard troubleshooting
 *     messages that the Joomla settings UI surfaces when a Bearer or
 *     connection check fails. These have no WP twin (WP uses Site Health).
 *   - `PICKUP_ERROR_*_JOOMLA` (P2-A) — exact mirrors of the B.11 pickup
 *     errors, with the "wp-admin" substring rewritten to the canonical
 *     Joomla admin path "Admin → Components → YT Builder MCP". The
 *     un-suffixed `PICKUP_ERROR_*` constants stay verbatim so the
 *     npm-wizard parity check still passes — the npm wizard uses the
 *     originals, while the `*_JOOMLA` variants are reserved for a future
 *     server-side error render (see the per-constant docblock below).
 *
 * @internal The Joomla Dashboard templates currently render the
 *           empty-state, reveal-token, advanced-section/AI-prompt,
 *           diagnostics-intro and revoke-confirmation constants. The
 *           `PICKUP_ERROR_*_JOOMLA` variants are NOT yet rendered by any
 *           Joomla template — they are reserved for a future server-side
 *           pickup-error render; the un-suffixed originals stay reserved
 *           for the npm wizard (cross-platform).
 */
final class JoomlaBrandStrings
{
    /**
     * B.1 — Empty-state (Keys tab).
     *
     * Two paragraphs: headline (first line) + body (second + third lines).
     * Rendered inside the empty Keys table when no Bearer keys exist yet.
     */
    public const EMPTY_STATE_HEADLINE = 'No keys yet. Click "Generate Key" above to issue your first Bearer token for the MCP server.';

    public const EMPTY_STATE_BODY = "New to MCP? Read the getting started guide or install the companion NPM package next to your AI client.";

    /**
     * B.2 — Reveal-token lede.
     *
     * Two-line heading shown ONCE immediately after key-generation, above
     * the reveal-token box.
     */
    public const REVEAL_TOKEN_LEDE = "Your key is ready\nConnect Claude Desktop in two clicks. Other AI clients shown below.";

    /**
     * B.3 — Reveal-token save-warning.
     *
     * Single-line orange/amber warning row immediately above the token
     * field. The leading "⚠" character is part of the verbatim string.
     */
    public const REVEAL_TOKEN_SAVE_WARNING = '⚠ Save the token now — it will not be shown again after you leave this page.';

    /**
     * B.4 — Reveal-token primary-CTA caption.
     *
     * Caption under the "Download .dxt" / "Open Claude Desktop" CTA in the
     * reveal box (one-click happy-path for Claude Desktop users).
     */
    public const REVEAL_TOKEN_PRIMARY_CTA_CAPTION = 'Double-click the file after download. Claude asks for the two values below.';

    /**
     * B.5 — Advanced-section summary.
     *
     * `<summary>` element of the collapsed "advanced setup" `<details>`
     * disclosure in the reveal box. Click expands to the AI-prompt + manual
     * fallback panel.
     */
    public const ADVANCED_SECTION_SUMMARY = 'Using Cursor, Zed, or another AI client?';

    /**
     * B.6 — Advanced-section AI-prompt caveat.
     *
     * Italic caveat directly below the AI-prompt copy-block, explaining
     * that some clients refuse auto-run + signposting the manual fallback.
     */
    public const ADVANCED_SECTION_AI_PROMPT_CAVEAT = "Some AI clients may refuse to auto-run external scripts — that's expected, use manual setup then.";

    /**
     * B.7 — AI-prompt template (verbatim, Joomla port substitution applied).
     *
     * Documented substitution applied in line 7: "WordPress" → "Joomla"
     * (per cookbook Appendix B.7 note line 1090).
     *
     * Placeholder tokens consumed by the Wave-4 renderer:
     *   - `{pickupUrl}`  — single-use pickup-URL from PickupChannel
     *   - `{pickupNonce}` — matching nonce
     *   - `{siteUrl}`    — site base URL (configured in com_ytbmcp params)
     */
    public const AI_PROMPT_TEMPLATE = <<<'PROMPT'
Configure yt-builder-mcp on this machine.
Run this single command, then restart your AI client:

npx -y @wootsup/yt-builder-mcp setup \
  --pickup {pickupUrl} \
  --nonce {pickupNonce} \
  --client claude-desktop

After it completes, you will be connected to the Joomla at {siteUrl}.
The pickup URL expires in 5 minutes and can only be claimed once.
PROMPT;

    /**
     * B.8 — Diagnostics intro.
     *
     * Lede above the diagnostics-markdown `<textarea>` on the Diagnostics
     * tab. Operator copies the markdown into a GitHub issue.
     */
    public const DIAGNOSTICS_INTRO = "Snapshot of the plugin's runtime state. Useful when reporting an issue — copy the markdown below and paste it into the GitHub Issue.";

    /**
     * B.9 — Revoke confirmation prompt.
     *
     * Browser-side `window.confirm()` text on the Revoke button. The
     * `<label>` placeholder is filled with the key's display-label client-
     * side.
     */
    public const REVOKE_CONFIRMATION_PROMPT = "Revoke key '<label>'? Any MCP client using this key will immediately lose access. This cannot be undone.";

    /**
     * B.10 — Footer.
     *
     * Settings-page footer line. The mid-dot (·) is verbatim U+00B7.
     */
    public const FOOTER = '© WootsUp — A getimo productions company · Documentation · Report an issue · Security disclosures';

    /**
     * B.11 — Pickup error messages (npm wizard).
     *
     * These strings are surfaced by the NPM wizard (`@wootsup/yt-builder-mcp`)
     * when a pickup-claim fails. They are mirrored here as the canonical copy
     * source; the un-suffixed variants are the wizard-facing originals. No
     * Joomla template currently renders them (a server-side pickup-error
     * render is not yet wired — see the `*_JOOMLA` block below).
     */
    public const PICKUP_ERROR_404 = 'Pickup not available. The URL may have expired (5-minute TTL) or already been claimed. Generate a fresh pickup from wp-admin.';

    public const PICKUP_ERROR_403 = 'Pickup is bound to a different IP. Regenerate from wp-admin with the "different machine" option, or run this CLI from the same network as the browser session.';

    public const PICKUP_ERROR_429 = 'Rate limit hit on pickup endpoint. Wait ~60s and try again.';

    public const PICKUP_ERROR_400 = 'Pickup rejected the request as malformed[: <server-message>]. Re-generate the pickup URL from wp-admin.';

    public const PICKUP_ERROR_NETWORK = 'Could not reach the pickup URL (<msg>). Check the URL is reachable and the plugin is active.';

    /**
     * Round-3 audit A6 P2-A — Joomla-substituted variants of the B.11
     * pickup errors. Wording is byte-identical to the originals EXCEPT
     * for the "wp-admin" → "Admin → Components → YT Builder MCP"
     * substring rewrite.
     *
     * Status: currently UNUSED / reserved. No Joomla Dashboard template
     * renders these yet — they are pre-written for a future server-side
     * pickup-error render so the wording is ready when that surface lands.
     * The npm wizard keeps using the un-suffixed variants for parity.
     */
    public const PICKUP_ERROR_404_JOOMLA = 'Pickup not available. The URL may have expired (5-minute TTL) or already been claimed. Generate a fresh pickup from Admin → Components → YT Builder MCP.';

    public const PICKUP_ERROR_403_JOOMLA = 'Pickup is bound to a different IP. Regenerate from Admin → Components → YT Builder MCP with the "different machine" option, or run this CLI from the same network as the browser session.';

    public const PICKUP_ERROR_400_JOOMLA = 'Pickup rejected the request as malformed[: <server-message>]. Re-generate the pickup URL from Admin → Components → YT Builder MCP.';

    /**
     * Round-3 audit A6 P1-A — Diagnostics-tab + CLI-wizard troubleshooting
     * messages. These have no WP twin (WP uses Site Health) so they are
     * Joomla-only and don't need a parity flag.
     */
    public const DIAGNOSE_PLUGIN_UNREACHABLE = 'Plugin not reachable. Verify YTB_MCP_SITE_URL and that the yt-builder-mcp Joomla plugin is active.';

    public const DIAGNOSE_BEARER_REJECTED    = 'Bearer key rejected. Regenerate the key in Admin → Components → YT Builder MCP → Bearer Keys and re-run `yt-builder-mcp setup`.';
}
