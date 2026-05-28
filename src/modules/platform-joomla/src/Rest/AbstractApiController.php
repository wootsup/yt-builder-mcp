<?php
/**
 * Base for every com_ytbmcp api/ controller.
 *
 * Wires the cross-platform auth-stack: BearerVerifier (with
 * JoomlaKeyStore + JoomlaSigningSecret), scope hierarchy enforcement,
 * rate-limiting, ETag enforcement, WWW-Authenticate header injection.
 *
 * Each concrete controller calls $this->dispatch($scope, fn) which
 * runs the full pre-flight before invoking the handler.
 *
 * Cookbook §3.1 (REST-layer overview) + §2.2-2.7 (auth stack).
 *
 * @package WootsUp\BuilderMcp\Platform\Joomla\Rest
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Rest;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use WootsUp\BuilderMcp\Auth\BearerVerifier;
use WootsUp\BuilderMcp\Auth\ExpiredTokenException;
use WootsUp\BuilderMcp\Auth\InvalidTokenException;
use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Auth\RevokedTokenException;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaKeyStore;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaSigningSecret;
use WootsUp\BuilderMcp\Platform\Joomla\Exception\AuthUnavailableException;
use WootsUp\BuilderMcp\Platform\Joomla\Exception\YTNotBootstrappedException;
use WootsUp\BuilderMcp\Platform\Joomla\Util\YtBootstrapper;
use WootsUp\BuilderMcp\Util\SecurityLogger;

abstract class AbstractApiController extends BaseController
{
    public const SCOPE_RANK = ['read' => 1, 'write' => 2, 'admin' => 3];

    /** App handle for response emission. */
    protected function app(): CMSApplicationInterface
    {
        return Factory::getApplication();
    }

    /**
     * Pre-flight auth + rate-limit + scope check; runs $handler() on success.
     * $handler receives the verified claims array.
     *
     * @param string $minScope read|write|admin
     * @param callable(array<string,mixed>):void $handler
     */
    protected function dispatch(string $minScope, callable $handler): void
    {
        $app = $this->app();
        $headers = [];

        // 1. Read bearer header
        $authHeader = JoomlaBearerHeaderReader::read();
        if ($authHeader === '') {
            $headers['WWW-Authenticate'] = $this->wwwAuthenticate('invalid_token', 'Authorization header is required.');
            JoomlaJsonResponse::error($app, 'yootheme_builder_mcp.auth.bearer_invalid', 'Authorization header is required.', 401, [], $headers);
            return;
        }

        // 2. Verify bearer.
        //
        // R8-A4 P2: the auth-stack construction (JoomlaSigningSecret::ensure()
        // + KeyService + JoomlaKeyStore) used to run OUTSIDE any try. With the
        // P1 hardening, ensure() now THROWS AuthUnavailableException when the
        // signing secret cannot be persisted; a bare throw here would escape
        // dispatch() entirely → ApiApplication emits a bare 500 (possibly with
        // stack detail if display_errors=On). Wrap construction so any throw
        // (AuthUnavailable, a broken CSPRNG in random_bytes(), KeyStore ctor)
        // becomes a structured 503 envelope, never a leaked fatal.
        try {
            $verifier = new BearerVerifier(
                new KeyService(JoomlaSigningSecret::ensure()),
                new JoomlaKeyStore()
            );
        } catch (AuthUnavailableException $e) {
            SecurityLogger::log(SecurityLogger::EVENT_WRITE_FAILED, ['platform' => 'joomla', 'stage' => 'auth_stack_construct', 'reason' => $e->getMessage()]);
            JoomlaJsonResponse::error(
                $app,
                'yootheme_builder_mcp.auth.unavailable',
                $e->getMessage(),
                503,
                ['remediation' => $e->remediation]
            );
            return;
        } catch (\Throwable $e) {
            SecurityLogger::log(SecurityLogger::EVENT_CONTROLLER_UNHANDLED, ['platform' => 'joomla', 'stage' => 'auth_stack_construct', 'class' => $e::class, 'message' => $e->getMessage()]);
            JoomlaJsonResponse::error(
                $app,
                'yootheme_builder_mcp.auth.unavailable',
                'The authentication subsystem is temporarily unavailable.',
                503
            );
            return;
        }
        try {
            $claims = $verifier->verify($authHeader);
        } catch (ExpiredTokenException $e) {
            SecurityLogger::log(SecurityLogger::EVENT_BEARER_FAIL, ['platform' => 'joomla', 'reason' => 'expired']);
            $headers['WWW-Authenticate'] = $this->wwwAuthenticate('invalid_token', 'The bearer token has expired.');
            JoomlaJsonResponse::error($app, 'yootheme_builder_mcp.auth.bearer_expired', 'The bearer token has expired.', 401, [], $headers);
            return;
        } catch (RevokedTokenException $e) {
            SecurityLogger::log(SecurityLogger::EVENT_BEARER_FAIL, ['platform' => 'joomla', 'reason' => 'revoked']);
            $headers['WWW-Authenticate'] = $this->wwwAuthenticate('invalid_token', 'The bearer token has been revoked.');
            JoomlaJsonResponse::error($app, 'yootheme_builder_mcp.auth.bearer_revoked', 'The bearer token has been revoked.', 401, [], $headers);
            return;
        } catch (InvalidTokenException $e) {
            SecurityLogger::log(SecurityLogger::EVENT_BEARER_FAIL, ['platform' => 'joomla', 'reason' => 'invalid', 'detail' => $e->getMessage()]);
            $headers['WWW-Authenticate'] = $this->wwwAuthenticate('invalid_token', 'The bearer token is invalid.');
            JoomlaJsonResponse::error($app, 'yootheme_builder_mcp.auth.bearer_invalid', 'The bearer token is invalid.', 401, [], $headers);
            return;
        }

        // 3. Scope-rank check
        $tokenScope = (string) ($claims['scope'] ?? 'read');
        $tokenRank = self::SCOPE_RANK[$tokenScope] ?? 0;
        $requiredRank = self::SCOPE_RANK[$minScope] ?? \PHP_INT_MAX;
        if ($tokenRank < $requiredRank) {
            SecurityLogger::log(SecurityLogger::EVENT_SCOPE_DENY, ['platform' => 'joomla', 'token_scope' => $tokenScope, 'required_scope' => $minScope]);
            $headers['WWW-Authenticate'] = $this->wwwAuthenticate('insufficient_scope', 'Authentication required.');
            JoomlaJsonResponse::error(
                $app,
                'yootheme_builder_mcp.auth.insufficient_scope',
                \sprintf('Token scope "%s" is insufficient (requires "%s" or higher).', $tokenScope, $minScope),
                403,
                ['required_scope' => $minScope, 'token_scope' => $tokenScope],
                $headers
            );
            return;
        }

        // 4. Rate-limit on write+
        if ($requiredRank >= self::SCOPE_RANK['write']) {
            $kid = (string) ($claims['kid'] ?? '');
            $limiter = new JoomlaRateLimiter();
            $err = $limiter->checkWrite($kid);
            if ($err !== null) {
                // Wave-1 Fix C-4 — HTTP/1.1 §7.1.3 Retry-After header on 429.
                $retryAfterHeaders = ['Retry-After' => (string) JoomlaRateLimiter::WINDOW_SECONDS];
                JoomlaJsonResponse::send($app, $err['payload'], $err['status'], $retryAfterHeaders);
                return;
            }
        }

        // 5. Invoke handler — catches YT-bootstrap failure (ADR-001)
        //
        // F-001 fix (2026-05-25 exhaustive audit): com_api requests do NOT
        // auto-bootstrap YOOtheme (cookbook §S2). Every authenticated
        // controller in this hierarchy needs YT (pages, elements, sources,
        // multi-items, inspection, articles, article-elements, etag).
        // Centralising the idempotent ensure() here closes the entire
        // F-001 / F-002 / F-003 / F-101 cluster — without it, the
        // sources registry, element-type registry and template parser all
        // silently return empty/degraded shapes because `\YOOtheme\app` is
        // never registered in the request lifecycle. The existing
        // YTNotBootstrappedException catch below converts a hard failure
        // (YT not installed / corrupted) into a structured 503 with a
        // remediation hint — no controller has to repeat the boilerplate.
        try {
            YtBootstrapper::ensure();
            $handler($claims);
        } catch (YTNotBootstrappedException $e) {
            JoomlaJsonResponse::error(
                $app,
                'yootheme_builder_mcp.yt_not_bootstrapped',
                $e->getMessage(),
                503,
                ['remediation' => $e->remediation]
            );
        } catch (\Throwable $e) {
            SecurityLogger::log(SecurityLogger::EVENT_CONTROLLER_UNHANDLED, ['platform' => 'joomla', 'class' => $e::class, 'message' => $e->getMessage()]);
            JoomlaJsonResponse::error(
                $app,
                'yootheme_builder_mcp.internal_error',
                'An unexpected error occurred while processing the request.',
                500
            );
        }
    }

    /**
     * Build the request body decoded as array, or empty array on no/invalid body.
     *
     * @return array<string,mixed>
     */
    protected function requestBody(): array
    {
        $input = (string) ($this->input?->getRaw() ?? \file_get_contents('php://input') ?: '');
        if ($input === '') {
            return [];
        }
        $decoded = \json_decode($input, true);
        return \is_array($decoded) ? $decoded : [];
    }

    protected function queryString(string $name, string $default = ''): string
    {
        $v = $this->input?->getString($name, $default);
        return $v === null ? $default : (string) $v;
    }

    /**
     * Read a route path-parameter (e.g. `templateId`, `articleId`).
     *
     * Wave-7 deploy-fix: Joomla's ApiApplication::route() injects matched
     * route vars into DIFFERENT input bags by HTTP method —
     *   GET/PUT/DELETE → $input        (via $this->input->set())
     *   POST           → $input->post  (via $this->input->post->set())
     * (libraries/src/Application/ApiApplication.php). `$input->getString()`
     * does NOT fall through to the POST bag, so on a POST route like
     * `pages/:templateId/save` the templateId was empty → "templateId is
     * required" 400 and no template could ever be created. Read the main
     * input first, then fall back to the POST bag.
     */
    protected function pathParam(string $name, string $default = ''): string
    {
        $v = $this->input?->getString($name, '');
        if (\is_string($v) && $v !== '') {
            return \trim($v);
        }
        // POST bag fallback. Use ->get($name, '', 'string') rather than
        // ->getString() — on the JInput POST sub-bag the route-var set by
        // ApiApplication is reliably read via get() (verified live), whereas
        // getString() returned '' for the same key in the API request scope.
        $post = $this->input?->post ?? null;
        if (\is_object($post) && \method_exists($post, 'get')) {
            $pv = $post->get($name, '', 'string');
            if (\is_string($pv) && $pv !== '') {
                return \trim($pv);
            }
        }
        return $default;
    }

    /**
     * Read a route path-parameter using the `raw` input filter so pointer
     * slashes survive Joomla's filtering chain (e.g. the `:path` route var,
     * which is a full JSON-Pointer like `children/0/children/2`).
     *
     * A1-P3 (R8): the `templateId` POST-bag fallback was centralised into
     * {@see pathParam()}, but the sibling `path`/raw fallback was copy-pasted
     * verbatim into ElementsController::pointerFromPath() AND
     * MultiItemsController::pointerFromPath(). This is the shared helper for
     * that variant — same per-method input-bag split as pathParam(), but with
     * the `raw` filter on BOTH the main bag and the POST sub-bag, and NO trim
     * (the raw pointer must not be whitespace-mangled).
     */
    protected function pathParamRaw(string $name, string $default = ''): string
    {
        $v = $this->input?->get($name, '', 'raw');
        if (\is_string($v) && $v !== '') {
            return $v;
        }
        $post = $this->input?->post ?? null;
        if (\is_object($post) && \method_exists($post, 'get')) {
            $pv = $post->get($name, '', 'raw');
            if (\is_string($pv) && $pv !== '') {
                return $pv;
            }
        }
        return $default;
    }

    /** RFC-6750 §3 header builder. */
    private function wwwAuthenticate(string $error, string $description): string
    {
        $desc = \str_replace(['\\', '"'], ['\\\\', '\\"'], $description);
        return \sprintf(
            'Bearer realm="yt-builder-mcp", error="%s", error_description="%s"',
            $error,
            $desc
        );
    }
}
