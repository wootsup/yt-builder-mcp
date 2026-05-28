<?php
/**
 * SecurityLogger — single sink for security-relevant events.
 *
 * Wave 6 Round-2 R2.9. Before this module, every security-event branch
 * either logged nothing at all (silent fail-closed) or scattered raw
 * error_log() calls with ad-hoc message formats. Both make forensics
 * impossible. This class is the one place to emit a structured line per
 * security event so an admin running `tail -f /var/log/php_errors.log`
 * sees a coherent narrative.
 *
 * Output format (single line):
 *   [yt-builder-mcp][security] <event> <json-context>
 *
 * The `<event>` slug is one of the constants defined below. Context is
 * `json_encode()`d (with JSON_UNESCAPED_SLASHES) — never throws because
 * we replace any non-encodable value with its var_export() string before
 * encoding.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Util
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Util;

final class SecurityLogger
{
    public const EVENT_BEARER_FAIL = 'bearer_fail';
    public const EVENT_SCOPE_DENY = 'scope_deny';
    public const EVENT_RATE_LIMIT = 'rate_limit';
    /**
     * Wave-1 Fix C-4. Emitted whenever a write-scope bearer trips the
     * per-kid write quota (60 writes / 60 s). Distinct from
     * `EVENT_PICKUP_RATE_LIMITED` (per-IP pickup-claim limiter) so SIEM /
     * forensic queries can filter the "compromised bearer / runaway client"
     * signal without the per-IP wizard noise.
     */
    public const EVENT_MCP_WRITE_RATE_LIMITED = 'mcp_write_rate_limited';
    public const EVENT_WRITE_FAILED = 'write_failed';
    public const EVENT_CROSS_TEMPLATE_DENY = 'cross_template_deny';
    public const EVENT_CACHE_FLUSH_FAILED = 'cache_flush_failed';
    public const EVENT_KEYSTORE_RACE = 'keystore_race';
    public const EVENT_LOCK_TIMEOUT = 'lock_timeout';
    public const EVENT_PICKUP_CLAIMED = 'pickup_claimed';
    public const EVENT_PICKUP_NOT_FOUND = 'pickup_not_found';
    public const EVENT_PICKUP_IP_MISMATCH = 'pickup_ip_mismatch';
    public const EVENT_PICKUP_RATE_LIMITED = 'pickup_rate_limited';
    /**
     * Joomla-only: emitted whenever the system plugin's onAfterInitialise
     * (priority 1) listener detects a request hitting the yt-builder-mcp
     * Web Services API surface and strips the cookie-bearing user-session
     * for the request duration. See cookbook §2.2.4 + §2.12.3 #1 +
     * §2.12.4 #3 — defends against the "logged-in admin re-uses session
     * cookie" implicit-auth class on Joomla's ApiApplication.
     */
    public const EVENT_SESSION_REVIVAL_STRIPPED = 'session_revival_stripped';

    /**
     * Joomla-only (Round-3 audit A4 P2 promotion). Emitted from
     * {@see \WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaLayoutWriter::runSaveTransforms}
     * when the YT save-transform fail-falls through to the unchanged tree
     * (cookbook §4.10.3 failure #1). Replaces the literal string previously
     * passed to SecurityLogger::log().
     */
    public const EVENT_SAVE_TRANSFORM_FALLBACK = 'save_transform_fallback';

    /**
     * Cross-platform (Round-3 audit A4 P2 promotion). Emitted from
     * {@see \WootsUp\BuilderMcp\Platform\Joomla\Rest\AbstractApiController::dispatch}
     * when a controller-level handler throws past the YT-bootstrap catch.
     * Replaces the literal 'controller_unhandled_exception' string previously
     * passed to SecurityLogger::log().
     */
    public const EVENT_CONTROLLER_UNHANDLED = 'controller_unhandled_exception';

    /**
     * Joomla-only (Round-3 audit A4 P2 promotion). Emitted from
     * {@see \WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaSigningSecret::warnNoKey}
     * when none of the 3 encryption-key tiers (PHP constant, outside-webroot
     * file, media/com_ytbmcp auto-generated file) resolved. Signals that
     * SigningSecret falls back to plaintext storage (Tier-fail mode).
     */
    public const EVENT_ENCRYPTION_KEY_MISSING = 'encryption_key_missing';

    /**
     * Joomla-only (Round-3 audit A4 P2 promotion). Emitted from
     * {@see \WootsUp\BuilderMcp\Platform\Joomla\State\JoomlaStateLock::reclaimIfStale}
     * and ::deleteLock() when their best-effort cleanup throws. Previously
     * silently swallowed; the constant surfaces lock-table-driver errors for
     * forensic triage without elevating them to user-facing failures.
     */
    public const EVENT_LOCK_RECLAIM_FAILED = 'lock_reclaim_failed';

    /**
     * Joomla-only (Round-3 audit A5 P1-3). Emitted from
     * {@see \WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaLayoutStorage::writeState}
     * BEFORE the UPDATE when the encoded payload is within ~2 MB of the
     * default MySQL MEDIUMTEXT 16 MB column limit. YT's default
     * `#__extensions.custom_data` column is MEDIUMTEXT; sufficiently large
     * Builder states would silently truncate. Operator remediation:
     *
     *     ALTER TABLE #__extensions MODIFY custom_data LONGTEXT;
     *
     * Context payload carries `bytes` + `limit` + the `remediation` string.
     */
    public const EVENT_PAYLOAD_NEAR_MEDIUMTEXT_LIMIT = 'payload_near_mediumtext_limit';

    /**
     * Joomla-only. Was emitted from L2 controllers when a per-article
     * Joomla `core.edit` ACL check denied a Bearer-write request.
     *
     * @deprecated Round-6 (2026-05-24). The L2 per-article ACL gate was
     *             removed in {@see ArticlesController} +
     *             {@see ArticleElementsController}: session-strip (ADR-001)
     *             deliberately drops Joomla user identity for every
     *             yt-builder-mcp API request, so `authorise('core.edit', ...)`
     *             was always false → the gate was structurally always-deny.
     *             Bearer write-scope is now the sole authority for L2
     *             (cookbook §2.2.4 Bearer-Deny-Invariant). The constant is
     *             retained @deprecated so the event-name namespace stays
     *             reserved should a future user-binding model (e.g. Bearer
     *             carrying a Joomla user-id claim) re-introduce per-asset
     *             ACL on top of Bearer authority.
     *             See `docs/adr/2026-05-24-l2-bearer-as-authority.md`.
     */
    public const EVENT_L2_ACL_DENIED = 'l2_acl_denied';

    /**
     * Emit a structured security-event line via error_log().
     *
     * @param string $event   One of the EVENT_* constants — never user input
     *                        (callers pass a const, not a string from a request).
     * @param array<string, mixed> $context Arbitrary structured context.
     *                        Non-encodable values are coerced to strings.
     */
    public static function log(string $event, array $context = []): void
    {
        if (!function_exists('error_log')) {
            return;
        }

        $sanitized = self::sanitizeContext($context);
        try {
            $encoded = json_encode($sanitized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $encoded = '{}';
        }

        \error_log(sprintf('[yt-builder-mcp][security] %s %s', $event, $encoded));
    }

    /**
     * Coerce any non-JSON-encodable value to a safe string so json_encode
     * never throws. Resource handles + circular references are the only
     * realistic blockers; both turn into a `var_export` snippet here.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function sanitizeContext(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
                continue;
            }
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $out[$key] = self::sanitizeContext($value);
                continue;
            }
            if (is_object($value) && method_exists($value, '__toString')) {
                $out[$key] = (string) $value;
                continue;
            }
            // Resource, closure, complex object — coerce to a debug string.
            $out[$key] = '[' . gettype($value) . ']';
        }
        return $out;
    }
}
