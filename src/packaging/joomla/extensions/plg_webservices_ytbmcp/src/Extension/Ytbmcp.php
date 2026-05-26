<?php
/**
 * plg_webservices_ytbmcp main subscriber class.
 *
 * Sole responsibility: register the 31 Web Services API routes on
 * `onBeforeApiRoute`. This MUST be a `webservices`-group plugin — Joomla's
 * {@see \Joomla\CMS\Application\ApiApplication::route()} imports only
 * `webservices`-group plugins (ApiApplication.php:234) before dispatching
 * `onBeforeApiRoute`; `system`-group plugins are imported later
 * (ApiApplication.php:427, in dispatch()), so a system-plugin listener for
 * this event is never invoked and every route 404s. (Wave 7 deploy-fix.)
 *
 * The routes' `component => com_ytbmcp` default makes the ApiRouter
 * dispatch into com_ytbmcp's api/ controllers. Each REST controller
 * lazy-bootstraps YOOtheme on demand per ADR-001 (cookbook §S2) — this
 * class deliberately does NO YT bootstrap; the system plugin
 * (plg_system_ytbmcp) owns autoloader / session-strip / shared-module
 * bootstrap / scheduler duties.
 *
 * @package    WootsUp\Plugin\WebServices\Ytbmcp
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Plugin\WebServices\Ytbmcp\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\ApiRouter;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Router\Route;

final class Ytbmcp extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onBeforeApiRoute' => 'onBeforeApiRoute',
        ];
    }

    /**
     * Register the 31 Web Services API routes. Defaults dispatch to
     * com_ytbmcp's api/ controllers; routes marked `public => true`
     * skip Joomla's session/token check and rely on the controller's
     * own Bearer-Deny-Invariant logic (cookbook §2.2.4).
     *
     * @param Event $event  Event-stack carrying `[ApiRouter &$router]`.
     */
    public function onBeforeApiRoute(Event $event): void
    {
        $arguments = $event->getArguments();
        $router    = $arguments['subject'] ?? ($arguments['router'] ?? ($arguments[0] ?? null));
        if (!$router instanceof ApiRouter) {
            // ApiApplication passes the router under 'router'; some Joomla
            // builds put the application under 'subject'. Fall back to the
            // 'router' key if 'subject' was not the ApiRouter.
            $router = $arguments['router'] ?? null;
            if (!$router instanceof ApiRouter) {
                return;
            }
        }

        // ALL routes are `public => true` at the Joomla layer. This does
        // NOT mean "unauthenticated" — it means "skip Joomla's own
        // session/core.login.api auth check" so the request reaches our
        // controllers. Authentication is enforced INSIDE each controller:
        //   - Public-tier endpoints (health/identity/pickup) accept anon.
        //   - Every other controller extends AbstractApiController, whose
        //     dispatch() reads the Authorization header, verifies the
        //     Bearer via BearerVerifier, and returns 401 (+ RFC-6750
        //     WWW-Authenticate) on missing/invalid/expired/revoked tokens
        //     and 403 on insufficient scope (cookbook Bearer-Deny-Invariant).
        // If a route were `public => false`, Joomla's ApiApplication would
        // throw AuthenticationFailed (→ HTTP 500, empty envelope) BEFORE the
        // controller ever runs, because we authenticate by Bearer, not by a
        // Joomla user session (the system plugin even strips any session).
        $defaults = [
            'component' => 'com_ytbmcp',
            'public'    => true,
            'format'    => ['application/json'],
        ];
        $publicDefaults = $defaults;

        // F-103 fix (2026-05-26 exhaustive audit): Joomla's
        // `Route::buildRegexAndVarList` ALREADY wraps each rule in `(...)`
        // (see libraries/vendor/joomla/router/src/Route.php — the line
        // `$regex[] = '(' . $this->getRules()[$varName] . ')'`). If we put
        // outer parens in the rules ourselves, the compiled pattern ends up
        // double-wrapped: `((.+))`. PCRE counts groups left-to-right, so the
        // second route variable resolved to the INNER group of the FIRST
        // variable — every `:path` was silently bound to the `:templateId`
        // value. Live symptom: `Pointer "/I99YS8Ii" is not within template
        // "I99YS8Ii"` on every `/pages/:templateId/elements/:path/...`
        // request. Rules must therefore be group-free; Joomla supplies the
        // single capture wrap.
        $tplRx  = ['templateId' => '[A-Za-z0-9_-]{1,32}'];
        $pathRx = ['templateId' => '[A-Za-z0-9_-]{1,32}', 'path' => '.+'];
        $typeRx = ['typeName'   => '[A-Za-z0-9_-]+'];
        $artRx  = ['articleId'  => '\\d+'];

        $router->addRoutes([
            // Public (3)
            new Route(['GET'],    'v1/yt-builder-mcp/health',                                  'health.get',         [], $publicDefaults),
            new Route(['GET'],    'v1/yt-builder-mcp/identity',                                'health.identity',    [], $publicDefaults),
            new Route(['POST'],   'v1/yt-builder-mcp/setup/pickup',                            'pickup.claim',       [], $publicDefaults),

            // Auth read — pages (4)
            new Route(['GET'],    'v1/yt-builder-mcp/etag',                                    'etag.get',           [], $defaults),
            new Route(['GET'],    'v1/yt-builder-mcp/pages',                                   'pages.list',         [], $defaults),
            new Route(['GET'],    'v1/yt-builder-mcp/pages/:templateId/layout',                'pages.getLayout',    $tplRx, $defaults),
            new Route(['GET'],    'v1/yt-builder-mcp/pages/:templateId/schema',                'pages.getSchema',    $tplRx, $defaults),
            new Route(['GET'],    'v1/yt-builder-mcp/pages/:templateId/summary',               'pages.getSummary',   $tplRx, $defaults),

            // Auth write — pages (2)
            new Route(['POST'],   'v1/yt-builder-mcp/pages/:templateId/save',                  'pages.save',         $tplRx, $defaults),
            new Route(['POST'],   'v1/yt-builder-mcp/pages/:templateId/publish',               'pages.publish',      $tplRx, $defaults),

            // F-103 fix (2026-05-26 audit): SPECIFIC-FIRST ordering.
            // Joomla's Router iterates routes in registration order and the
            // first regex-match wins (vendor/joomla/router/src/Router.php
            // :128). A bare `/elements/:path` registered BEFORE the longer
            // `/elements/:path/multi-items/inspect` greedily consumes the
            // `/multi-items/inspect` suffix into `:path`, sending the
            // request to elements.get with `path =
            // children/0/multi-items/inspect`. We therefore register every
            // SUFFIXED `:path` route (binding / multi-items / settings /
            // move / clone) BEFORE the bare `/elements/:path` get + delete.
            new Route(['GET'],    'v1/yt-builder-mcp/pages/:templateId/elements',              'elements.list',      $tplRx, $defaults),

            // SUFFIXED :path routes — must precede the bare /elements/:path catch-alls.
            new Route(['GET'],    'v1/yt-builder-mcp/pages/:templateId/elements/:path/binding','sources.get',        $pathRx, $defaults),
            new Route(['PUT'],    'v1/yt-builder-mcp/pages/:templateId/elements/:path/binding','sources.put',        $pathRx, $defaults),
            new Route(['DELETE'], 'v1/yt-builder-mcp/pages/:templateId/elements/:path/binding','sources.delete',     $pathRx, $defaults),
            new Route(['GET'],    'v1/yt-builder-mcp/pages/:templateId/elements/:path/multi-items/inspect',       'multiItems.inspect',      $pathRx, $defaults),
            new Route(['POST'],   'v1/yt-builder-mcp/pages/:templateId/elements/:path/multi-items/clean-implode', 'multiItems.cleanImplode', $pathRx, $defaults),
            new Route(['PUT'],    'v1/yt-builder-mcp/pages/:templateId/elements/:path/settings','elements.updateSettings', $pathRx, $defaults),
            new Route(['POST'],   'v1/yt-builder-mcp/pages/:templateId/elements/:path/move',   'elements.move',      $pathRx, $defaults),
            new Route(['POST'],   'v1/yt-builder-mcp/pages/:templateId/elements/:path/clone',  'elements.clone',     $pathRx, $defaults),

            // Bare :path catch-alls — registered LAST so suffixed siblings win first.
            new Route(['GET'],    'v1/yt-builder-mcp/pages/:templateId/elements/:path',        'elements.get',       $pathRx, $defaults),
            new Route(['DELETE'], 'v1/yt-builder-mcp/pages/:templateId/elements/:path',        'elements.delete',    $pathRx, $defaults),

            // Auth write — element add (no :path; the templateId-only POST).
            new Route(['POST'],   'v1/yt-builder-mcp/pages/:templateId/elements',              'elements.add',       $tplRx, $defaults),

            // Auth read — inspection (2)
            new Route(['GET'],    'v1/yt-builder-mcp/element-types',                           'inspection.listTypes', [], $defaults),
            new Route(['GET'],    'v1/yt-builder-mcp/element-types/:typeName/schema',          'inspection.getSchema', $typeRx, $defaults),

            // Sources index route (no :path conflict).
            new Route(['GET'],    'v1/yt-builder-mcp/sources',                                 'sources.list',       [], $defaults),

            // -- L2 (Joomla-only extras — cookbook §4.13.5) ----------------

            // Auth read — articles (2)
            new Route(['GET'],    'v1/yt-builder-mcp/articles',                                'articles.list',           [], $defaults),
            new Route(['GET'],    'v1/yt-builder-mcp/articles/:articleId/page-layout',         'articles.getLayout',      $artRx, $defaults),

            // Auth write — articles (4)
            new Route(['POST'],   'v1/yt-builder-mcp/articles/:articleId/page-layout/save',    'articles.saveLayout',     $artRx, $defaults),
            new Route(['GET'],    'v1/yt-builder-mcp/articles/:articleId/elements/:path',      'articleElements.get',     $artRx + ['path' => '.+'], $defaults),
            new Route(['PUT'],    'v1/yt-builder-mcp/articles/:articleId/elements/:path',      'articleElements.update',  $artRx + ['path' => '.+'], $defaults),
            new Route(['DELETE'], 'v1/yt-builder-mcp/articles/:articleId/elements/:path',      'articleElements.delete',  $artRx + ['path' => '.+'], $defaults),
        ]);
    }
}
