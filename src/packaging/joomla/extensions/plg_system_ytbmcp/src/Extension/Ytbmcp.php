<?php
/**
 * plg_system_ytbmcp main subscriber class.
 *
 * Handles these Joomla events:
 *  - onAfterInitialise → a SINGLE bound method that runs, in order:
 *                        (1) SESSION-REVIVAL DEFENSE (cookbook §2.2.4 +
 *                            §2.12.3 #1 + §2.12.4 #3) FIRST, so a cookie-
 *                            bearing admin cannot trigger implicit auth on
 *                            the yt-builder-mcp Web Services API surface;
 *                        (2) the composer-autoloader load + schema-version
 *                            seed + upgrade self-heal sentinel.
 *                        Joomla's joomla/event Dispatcher binds exactly ONE
 *                        listener per event name, so the strip-before-bootstrap
 *                        ordering is enforced INLINE within this one method
 *                        (NOT via two separately-prioritised subscriptions —
 *                        see {@see onAfterInitialise} + ADR-002 note below).
 *  - onAfterRoute      → bootstrap yt-builder-mcp shared modules
 *                        (cookbook §1.3.1 — equivalent of WP
 *                        `after_setup_theme` priority 20: runs AFTER
 *                        YOOtheme's own bootstrap so `\YOOtheme\app()`
 *                        is available).
 *  - onTaskOptionsList / onExecuteTask
 *                      → com_scheduler dispatch for {@see TransientCleanupTask}.
 *
 * Route registration (onBeforeApiRoute) is NOT handled here — it lives in
 * the sibling plg_webservices_ytbmcp plugin, because Joomla's
 * ApiApplication imports only webservices-group plugins before dispatching
 * onBeforeApiRoute (a system-plugin listener for it is never called).
 *
 * ADR-001 (2026-05-24): Web Services API mounted via com_ytbmcp component;
 * each REST controller lazy-bootstraps YT on demand
 * (cookbook §S2 finding). This class therefore does NOT need to bootstrap
 * YT inside onBeforeApiRoute — controllers handle it.
 *
 * ADR-002 (Audit A2 P0-2, 2026-05-24; corrected Audit A1-L1, 2026-05-25):
 * the session-revival defense MUST fire before any downstream subscriber
 * relies on a session-bound user. The original design intended a separate
 * priority-1 subscription, but Joomla's joomla/event Dispatcher binds only
 * ONE listener per event name — an array-of-tuples shape makes the callback
 * non-callable and throws. The defense is therefore folded INLINE as the
 * first statement of the single {@see onAfterInitialise} method (strip →
 * autoloader/seed), which preserves the exact same ordering guarantee
 * without a second subscription. The {@see onStripApiSession} method remains
 * a discrete, independently-testable unit; it is simply invoked inline rather
 * than bound as its own listener.
 *
 * @package    WootsUp\Plugin\System\Ytbmcp
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Plugin\System\Ytbmcp\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Application\ApiApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use WootsUp\Plugin\System\Ytbmcp\Task\TransientCleanupTask;

final class Ytbmcp extends CMSPlugin implements SubscriberInterface
{
    /**
     * On-disk code version, kept in lockstep with the package manifest
     * `<version>` (pkg_ytbmcp.xml) + the WP-side YTB_MCP_VERSION constant.
     *
     * Drives the W9-T7 (#23) upgrade self-heal sentinel: a manual file-swap
     * (SFTP / Akeeba / ZIP-overwrite) lands NEW code carrying this constant
     * but never runs the installer postflight, so comparing it against the
     * stored `plugin_version` option detects the silent upgrade on the next
     * request and triggers a reseed + stale-media prune.
     */
    public const YTBMCP_VERSION = '1.1.7';

    /**
     * URI substring tested against the request to decide whether the
     * current request is targeting the yt-builder-mcp Web Services
     * API surface. Matches the canonical route mount-point declared
     * in {@see Ytbmcp::onBeforeApiRoute}.
     */
    private const API_PATH_NEEDLE = '/api/index.php/v1/yt-builder-mcp/';

    /**
     * Secondary detection signal — the option= query-string that
     * Joomla rewrites our routes into after route-resolution.
     *
     * Round-3 audit A2 P2-203: anchored regex (not stripos) — a
     * referer URL like `?ref=...&example=option=com_ytbmcp...` would
     * have matched the previous `stripos($query, 'option=com_ytbmcp')`
     * probe and triggered a false session-strip on an unrelated
     * request. The regex now demands the literal token is at the
     * start of the query OR preceded by `&`, AND followed by `=`, `&`,
     * or end-of-string. `(?:^|&)option=com_ytbmcp(?:&|$|=)` is
     * case-insensitive (i flag) per Joomla's case-insensitive option
     * resolution.
     */
    private const API_OPTION_REGEX = '/(?:^|&)option=com_ytbmcp(?:&|$|=)/i';

    public static function getSubscribedEvents(): array
    {
        // Joomla's joomla/event Dispatcher::addSubscriber() only supports
        // ONE listener per event name. The accepted shapes are:
        //   'event' => 'method'                 (NORMAL priority)
        //   'event' => ['method', $priority]    (single method + priority)
        // It does NOT support an array-of-tuples (multiple listeners) per
        // event — that makes $params[0] an array, so the resulting
        // [$subscriber, [...]] callback is not callable and Joomla throws
        // "addListener(): Argument #2 ($callback) must be of type callable".
        // We therefore route onAfterInitialise through a SINGLE method that
        // performs the session-strip FIRST and then the autoloader/seed,
        // preserving the priority-1-before-priority-10 ordering inline.
        return [
            'onAfterInitialise' => 'onAfterInitialise',
            'onAfterRoute'     => 'onAfterRoute',
            // NB: route registration (onBeforeApiRoute) lives in the
            // SEPARATE plg_webservices_ytbmcp plugin — ApiApplication only
            // imports webservices-group plugins before dispatching that
            // event (see plg_webservices_ytbmcp manifest comment).
            // Audit-A4 P1-2 (Wave 4 fix-round F3): forward com_scheduler
            // events to {@see TransientCleanupTask} so admins can wire
            // up a periodic GC for the long-tail transients-table rows
            // (RateLimiter buckets, PickupChannel one-shot codes).
            'onTaskOptionsList' => 'onTaskOptionsList',
            'onExecuteTask'     => 'onExecuteTask',
        ];
    }

    /**
     * com_scheduler dispatcher — registers our routine ID.
     *
     * See {@see TransientCleanupTask::onTaskOptionsList} for the actual
     * handler. The main subscriber re-dispatches so com_scheduler does
     * not require a separate plugin-of-type-`task` extension.
     */
    public function onTaskOptionsList(Event $event): void
    {
        (new TransientCleanupTask())->onTaskOptionsList($event);
    }

    /**
     * com_scheduler dispatcher — runs the GC when our routine fires.
     *
     * See {@see TransientCleanupTask::onExecuteTask} for the actual
     * handler. The wrapper accepts the generic {@see Event} shape and
     * delegates so we don't hard-import the com_scheduler ExecuteTaskEvent
     * class on installs where com_scheduler is absent (would fatal at
     * class-load otherwise).
     *
     * @return int|null Joomla {@see \Joomla\Component\Scheduler\Administrator\Task\Status}
     *                  enum value when our routine matched, null when
     *                  another listener's routine is firing.
     */
    public function onExecuteTask(Event $event): ?int
    {
        $args = $event->getArguments();
        $eventObject = $args['subject'] ?? ($args[0] ?? null);
        if (!\is_object($eventObject) || !\method_exists($eventObject, 'getRoutineId')) {
            return null;
        }
        // The ExecuteTaskEvent shape carries `getRoutineId()` itself;
        // older Joomla shipped the routine ID through the subject. We
        // pass whichever object exposes the method.
        /** @var \Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent $eventObject */
        return (new TransientCleanupTask())->onExecuteTask($eventObject);
    }

    /**
     * SESSION-REVIVAL DEFENSE (cookbook §2.2.4 + §2.12.3 #1 + §2.12.4 #3).
     *
     * On Joomla's {@see ApiApplication}, a request that carries the
     * Joomla session cookie of a logged-in admin would normally make
     * `Factory::getUser()` return the admin's account, which any
     * downstream controller could mistake for "implicit auth". The
     * yt-builder-mcp REST surface MUST authenticate exclusively via
     * Bearer tokens (cookbook Bearer-Deny-Invariant) — never via
     * session cookies. This step:
     *
     *   1. Detects ApiApplication + yt-builder-mcp URL.
     *   2. Logs the user out for the duration of this request via
     *      `Factory::getApplication()->logout()`, which clears the
     *      session-bound user identity without touching the user's
     *      authoritative login state in storage.
     *   3. Emits a SecurityLogger event so the audit trail captures
     *      every strip (forensics + over-time anomaly detection).
     *
     * Invoked as the FIRST statement of {@see onAfterInitialise} (the single
     * bound listener — see ADR-002 note at the top of this file), so it runs
     * BEFORE the autoloader/seed work and no later step can rely on a
     * session-bound user. It is a discrete method purely for testability; it
     * is NOT a separately-bound priority-1 subscription.
     */
    public function onStripApiSession(): void
    {
        $app = Factory::getApplication();
        if (!$app instanceof ApiApplication) {
            return;
        }

        // Cheap URL probe — no Input filter, no router resolution.
        // Uri::getInstance() is request-scoped + idempotent.
        $uri  = (string) Uri::getInstance();
        $path = \parse_url($uri, \PHP_URL_PATH);
        $query = (string) (\parse_url($uri, \PHP_URL_QUERY) ?: '');

        $pathMatch   = \is_string($path)
            && \stripos($path, self::API_PATH_NEEDLE) !== false;
        $optionMatch = (bool) \preg_match(self::API_OPTION_REGEX, $query);

        if (!$pathMatch && !$optionMatch) {
            return;
        }

        // Eagerly load the composer autoloader: we run at priority 1,
        // BEFORE the default-priority onAfterInitialise() that normally
        // loads it. The SecurityLogger call below needs the PSR-4 map.
        $this->ensureAutoloader();

        try {
            // Strip the session-bound user identity for this request.
            // ApiApplication exposes logout() via its parent class —
            // call defensively so a future Joomla refactor that hides
            // it does not crash the REST surface.
            if (\method_exists($app, 'logout')) {
                $app->logout();
            }
            $session = $app->getSession();
            if (\method_exists($session, 'clear')) {
                // Scrub session state for the request duration only —
                // does NOT destroy the persistent session record.
                $session->clear();
            }
        } catch (\Throwable) {
            // Swallow — defense-in-depth must never block the request.
        }

        if (\class_exists('\WootsUp\BuilderMcp\Util\SecurityLogger')) {
            \WootsUp\BuilderMcp\Util\SecurityLogger::log(
                \WootsUp\BuilderMcp\Util\SecurityLogger::EVENT_SESSION_REVIVAL_STRIPPED,
                [
                    'platform' => 'joomla',
                    // Log only the path; query may contain debug tokens.
                    'path'     => \is_string($path) ? $path : '',
                ]
            );
        }
    }

    /**
     * Default-priority hook — load composer autoloader (PSR-4 for
     * WootsUp\BuilderMcp\* + WootsUp\Plugin\System\Ytbmcp\* +
     * WootsUp\Component\Ytbmcp\*) and seed the schema-version sentinel
     * so first-run callers see consistent state.
     */
    public function onAfterInitialise(): void
    {
        // SESSION-REVIVAL DEFENSE runs FIRST (cookbook §2.2.4) — must fire
        // before any downstream subscriber can rely on a session-bound
        // user. Previously this was a separate priority-1 listener, but
        // Joomla's Dispatcher only binds one method per event, so the
        // ordering is now enforced inline (strip → autoloader/seed).
        $this->onStripApiSession();

        $this->ensureAutoloader();
        // Schema-version seed is delegated to InstallerScript on install;
        // this hook is the recovery path for orphaned installs.
        if (\class_exists('\WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaSchemaVersion')) {
            \WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaSchemaVersion::ensure();
        }

        // W9-T7 (#23): upgrade self-heal sentinel. Catches a MANUAL file-swap
        // (SFTP / Akeeba / ZIP-overwrite) that never ran the installer
        // postflight — compares the on-disk YTBMCP_VERSION against the stored
        // `plugin_version` option and, on a mismatch, reseeds the schema +
        // prunes stale media the new package no longer ships. Idempotent +
        // fail-safe (each step swallows its own errors); a one-shot no-op
        // once the version is reconciled. Wrapped so a sentinel failure NEVER
        // unwinds the request bootstrap.
        if (\class_exists('\WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaUpgradeSentinel')) {
            try {
                $mediaRoot = \defined('JPATH_ROOT') ? (JPATH_ROOT . '/media') : '';
                if ($mediaRoot !== '') {
                    (new \WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaUpgradeSentinel())
                        ->reconcile(self::YTBMCP_VERSION, $mediaRoot);
                }
            } catch (\Throwable) {
                // Recoverable on the next request — the sentinel is idempotent.
            }
        }
    }

    /**
     * Bootstrap yt-builder-mcp's shared modules once Joomla routing is
     * resolved. By this point YOOtheme's system plugin (which loads at
     * onAfterInitialise priority 5) has already registered `\YOOtheme\app`
     * IF its `template_bootstrap.php` allowlist matches the current
     * component. For com_api requests YT is NOT loaded — cookbook §S2
     * documents this; REST controllers lazy-bootstrap on demand.
     */
    public function onAfterRoute(): void
    {
        // Defer to the shared bootstrap.php only when YT is available —
        // it is the loader for the brace-glob module set (cookbook §1.3.4).
        if (\function_exists('\YOOtheme\app')) {
            $bootstrap = $this->locateSharedBootstrap();
            if ($bootstrap !== null) {
                require_once $bootstrap;
            }
        }
    }

    /**
     * Locate the yt-builder-mcp shared bootstrap.php. Search-paths in
     * descending preference (allows both packaged plugin install and
     * dev-checkout symlink layouts).
     */
    private function locateSharedBootstrap(): ?string
    {
        $candidates = [
            // Packaged: plugin lives at plugins/system/ytbmcp/ AND ships
            // a `vendor/wootsup/yt-builder-mcp/src/bootstrap.php` (yet to
            // be wired by the release-system in Wave 8).
            __DIR__ . '/../../vendor/wootsup/yt-builder-mcp/src/bootstrap.php',
            // Dev-checkout: shared code is under the repo root.
            __DIR__ . '/../../../../../../bootstrap.php',
        ];
        foreach ($candidates as $candidate) {
            $real = \realpath($candidate);
            if ($real !== false && \is_file($real)) {
                return $real;
            }
        }
        return null;
    }

    /**
     * Load the plugin-local composer autoloader. Required for PSR-4
     * resolution of WootsUp\Plugin\System\Ytbmcp\* + the shared
     * WootsUp\BuilderMcp\* namespace (when the system plugin ships its
     * own vendor/ dir).
     */
    private function ensureAutoloader(): void
    {
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (\file_exists($autoload)) {
            require_once $autoload;
        }
    }
}
