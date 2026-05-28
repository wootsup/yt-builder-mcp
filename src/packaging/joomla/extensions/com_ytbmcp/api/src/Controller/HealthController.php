<?php
/**
 * GET /v1/health + GET /v1/identity — public-tier diagnostic endpoints.
 *
 * Cookbook §3.4.1 (health) + §3.4.2 (identity) + §2.10 / §2.11.2 / R2.13
 * (L4-tier reduction). Anonymous probe; augments payload ONLY when a
 * valid Bearer is supplied (tier-2 disclosure matching WP-side
 * {@see \WootsUp\BuilderMcp\Rest\PublicRestController::has_valid_bearer}
 * pattern).
 *
 * SECURITY (Audit A2 P0-1, 2026-05-24; tightened W9-T4 #19, 2026-05-24):
 * the anonymous payload MUST contain ONLY {plugin_version, status}.
 * Everything else (cms_version, php_version, site_url, schema_version,
 * endpoint inventory, storage_target, docs URL, AND the `yootheme_loaded`
 * flag) leaks a fingerprint that helps a passive attacker pick known-CVE
 * platform exploits — `yootheme_loaded` in particular discloses whether
 * YOOtheme Pro is installed pre-auth. All of it is reserved for the
 * Bearer-gated augmentation, matching the WP-side anonymous shape 1:1.
 * Augmented payload is gated behind a successful Bearer verify — never via
 * `try/catch on bearer absence`, only via "header present AND verifies".
 *
 * Does NOT extend {@see AbstractApiController} because health is a
 * public-tier surface: missing Bearer must NOT return 401, only suppress
 * augmentation. Identity stays minimal regardless of bearer (cookbook
 * §3.4.2 — the wizard cross-checks `product === 'yt-builder-mcp'`
 * before trusting any URL, so identity needs zero auth).
 *
 * @package    WootsUp\Component\Ytbmcp\Api\Controller
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Component\Ytbmcp\Api\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Version;
use WootsUp\BuilderMcp\Auth\BearerVerifier;
use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaKeyStore;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaSigningSecret;
use WootsUp\BuilderMcp\Platform\Joomla\Exception\YTNotBootstrappedException;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaBearerHeaderReader;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaJsonResponse;
use WootsUp\BuilderMcp\Platform\Joomla\Util\YtBootstrapper;
use WootsUp\BuilderMcp\Platform\Joomla\Yootheme\JoomlaYoothemeProbe;

class HealthController extends BaseController
{
    /**
     * Plugin-version constant kept in sync with composer.json /
     * packaging manifests. Single source of truth: `YTB_MCP_VERSION`
     * defined by the WP entry-file; on Joomla we mirror via constant
     * to avoid hardcoding it in two places.
     */
    private const PLUGIN_VERSION = '1.1.7';

    /**
     * Round-3 audit A2 P2-202: class-level memoized verifier. The
     * Bearer-verify path can fire twice per request (header probe in
     * {@see get()} + the optional augmentation branch); each
     * instantiation previously cost a fresh SigningSecret::ensure()
     * round-trip plus a JoomlaKeyStore allocation. The cached instance
     * removes the per-request duplication without sacrificing test
     * isolation — {@see resetVerifierForTests()} wipes the cache in
     * setUp.
     */
    private static ?BearerVerifier $verifier = null;

    private static function verifier(): BearerVerifier
    {
        return self::$verifier ??= new BearerVerifier(
            new KeyService(JoomlaSigningSecret::ensure()),
            new JoomlaKeyStore()
        );
    }

    /** @internal Test-only reset hook (round-3 audit A2 P2-202). */
    public static function resetVerifierForTests(): void
    {
        self::$verifier = null;
    }

    public function get(): void
    {
        /** @var CMSApplicationInterface $app */
        $app = Factory::getApplication();

        // Anonymous-tier payload — the absolute minimum a setup-wizard
        // needs to confirm "the plugin is installed at this URL"
        // (cookbook §3.4.1). Parity with the WP-side L4-tier reduction
        // (R2.13): ONLY {plugin_version, status}. Every other field —
        // cms_version, php_version, endpoint inventory AND the
        // `yootheme_loaded` flag — leaks a host-fingerprint bit to
        // unauthenticated callers and is reserved for Bearer-holders.
        //
        // W9-T4 (#19, 2026-05-24): `yootheme_loaded` was previously
        // emitted here, diverging from WP which gates it behind the
        // Bearer. Moved into the augmentation branch below so the
        // anonymous shape matches WP 1:1.
        $payload = [
            'plugin_version'  => self::PLUGIN_VERSION,
            'status'          => 'ok',
        ];

        // L2 augmentation: only when a valid Bearer is presented.
        // Pattern parity with WP-side `has_valid_bearer()` — no 401
        // on missing/invalid, just suppress the augmentation silently.
        if ($this->hasValidBearer()) {
            $payload['cms']             = 'joomla';
            $payload['cms_version']     = Version::MAJOR_VERSION . '.' . Version::MINOR_VERSION . '.' . Version::PATCH_VERSION;
            $payload['php_version']     = PHP_VERSION;
            $payload['site_url']        = (string) Uri::root();
            // F-Frontend-URL (2026-05-25 customer-flow gap): WP-side
            // PublicRestController::payload() surfaces both `site_url`
            // (admin/install URL) and `home_url` (public-facing front-end
            // URL). On Joomla the two URLs are the same — `Uri::root()`
            // already returns the canonical public site root — but the
            // MCP TS HEALTH_OUTPUT_SCHEMA expects both keys to be present
            // when the bearer-gated augmentation fires. Surfacing the
            // same value as `home_url` keeps the wire shape 1:1 with WP
            // and lets a cross-platform agent ask "what's home_url?"
            // without branching on platform first.
            $payload['home_url']        = (string) Uri::root();
            // F-001 fix (2026-05-25 audit): com_api requests do NOT
            // auto-bootstrap YOOtheme (ADR-001 / cookbook §S2). Without the
            // lazy bootstrap, `\YOOtheme\app` is never defined in this
            // request → `yootheme_loaded` is always false on installs where
            // YT is in fact present, → sources_list / element-type schemas
            // / template parsers all collapse downstream. We now try the
            // idempotent bootstrap here and surface a structured reason
            // string when it fails, so the wizard can distinguish "YT not
            // installed" from "YT installed but bootstrap broke".
            try {
                YtBootstrapper::ensure();
                $payload['yootheme_loaded'] = \function_exists('\YOOtheme\app');
            } catch (YTNotBootstrappedException $e) {
                $payload['yootheme_loaded'] = false;
                $payload['yootheme_load_error'] = $e->getMessage();
            }
            // yootheme_version — uses the file-system / extensions-table
            // probe so it works even if the bootstrap failed (e.g. YT
            // installed but template files corrupted). Wire-shape parity
            // with WP-side payload (audit F-201).
            $payload['yootheme_version'] = (new JoomlaYoothemeProbe())->detectVersion();
            $payload['storage_type']    = 'joomla_extension_custom_data';
            $payload['storage_target']  = 'yootheme';
            $payload['schema_version']  = 1;
            $payload['element_path_format']       = '/templates/<templateId>/layout/children/<n>';
            $payload['element_path_example']      = '/templates/HoMeTpL1/layout/children/0';
            $payload['available_endpoints']       = $this->declaredEndpoints();
            $payload['available_endpoints_count'] = \count($payload['available_endpoints']);
            $payload['docs']            = 'https://github.com/wootsup/yt-builder-mcp/blob/main/docs/getting-started.md';
        }

        JoomlaJsonResponse::send($app, $payload, 200);
    }

    /**
     * GET /v1/identity — minimal product-discriminator probe.
     *
     * The npm setup wizard cross-checks `product === 'yt-builder-mcp'`
     * before trusting the URL; cookbook §3.4.2 — Joomla MUST emit
     * `platform: "joomla"` (vs WP's "wordpress"). Stays minimal
     * regardless of Bearer presence: no fingerprint reduction is
     * possible here because the four fields ARE the contract.
     */
    public function identity(): void
    {
        /** @var CMSApplicationInterface $app */
        $app = Factory::getApplication();
        JoomlaJsonResponse::send($app, [
            'product'        => 'yt-builder-mcp',
            'platform'       => 'joomla',
            'siteurl'        => (string) Uri::root(),
            'plugin_version' => self::PLUGIN_VERSION,
        ], 200);
    }

    /**
     * Mirror of WP-side `PublicRestController::has_valid_bearer`. Reads
     * the Authorization header and verifies it via BearerVerifier; any
     * failure (missing header, bad shape, expired, revoked, invalid
     * signature) returns false silently — health is a public-tier
     * surface and must NOT 401 on Bearer absence.
     */
    private function hasValidBearer(): bool
    {
        $header = JoomlaBearerHeaderReader::read();
        if ($header === '') {
            return false;
        }
        try {
            self::verifier()->verify($header);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return string[] Sorted list of declared v1 endpoints (cookbook §3.2). */
    private function declaredEndpoints(): array
    {
        return [
            '/v1/etag',
            '/v1/element-types',
            '/v1/element-types/<type>/schema',
            '/v1/health',
            '/v1/identity',
            '/v1/pages',
            '/v1/pages/<templateId>/elements',
            '/v1/pages/<templateId>/elements/<path>',
            '/v1/pages/<templateId>/elements/<path>/binding',
            '/v1/pages/<templateId>/elements/<path>/clone',
            '/v1/pages/<templateId>/elements/<path>/move',
            '/v1/pages/<templateId>/elements/<path>/multi-items/clean-implode',
            '/v1/pages/<templateId>/elements/<path>/multi-items/inspect',
            '/v1/pages/<templateId>/elements/<path>/settings',
            '/v1/pages/<templateId>/layout',
            '/v1/pages/<templateId>/publish',
            '/v1/pages/<templateId>/save',
            '/v1/pages/<templateId>/schema',
            '/v1/pages/<templateId>/summary',
            '/v1/setup/pickup',
            '/v1/sources',
            // L2 — Joomla-only
            '/v1/articles',
            '/v1/articles/<articleId>/elements/<path>',
            '/v1/articles/<articleId>/page-layout',
            '/v1/articles/<articleId>/page-layout/save',
        ];
    }
}
