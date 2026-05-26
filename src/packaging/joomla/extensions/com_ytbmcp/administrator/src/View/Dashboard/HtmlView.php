<?php
/**
 * Admin Dashboard HtmlView — full 3-tab settings surface.
 *
 * Wave-9 (2026-05-24): replaces the Wave-1 scaffold shim. This view is the
 * Joomla parity-twin of the WP-side
 * {@see \WootsUp\BuilderMcp\Platform\WordPress\SettingsPage}. It loads every
 * datum the `tmpl/dashboard/default.php` template needs:
 *
 *   - the active tab (validated against an allow-list, fail-closed to Keys)
 *   - the Bearer-key list (from {@see JoomlaKeyStore})
 *   - the one-shot revealed token + pickup nonce (consumed from user-state)
 *   - diagnostics: plugin / YOOtheme / CMS / PHP / schema versions,
 *     signing-secret presence, REST endpoint inventory + probe URLs
 *
 * MVC split rationale: Joomla convention keeps data-loading in the View and
 * markup in the template. The WP side is a single monolithic class because
 * WP has no MVC layer for admin pages; the Joomla side honours the framework
 * convention while rendering byte-identical brand copy (cookbook §6 verbatim).
 *
 * @package    WootsUp\Component\Ytbmcp\Administrator\View\Dashboard
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Component\Ytbmcp\Administrator\View\Dashboard;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Version;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaAdminAccess;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaKeyStore;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaSigningSecret;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaPickupChannel;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaSchemaVersion;
use WootsUp\BuilderMcp\Platform\Joomla\Yootheme\JoomlaYoothemeProbe;

class HtmlView extends BaseHtmlView
{
    /** Plugin version — single source of truth mirrored from packaging manifest. */
    public const PLUGIN_VERSION = '1.1.6';

    /** Minimum YOOtheme Pro version (parity with WP YTB_MCP_MIN_YT_VERSION). */
    public const MIN_YT_VERSION = '4.0';

    /** Tab slugs — the allow-list for the `tab` request var. */
    public const TAB_KEYS = 'keys';
    public const TAB_DIAGNOSTICS = 'diagnostics';
    public const TAB_ABOUT = 'about';

    private const TABS = [self::TAB_KEYS, self::TAB_DIAGNOSTICS, self::TAB_ABOUT];

    /** User-state keys for the one-shot reveal (consumed once on render). */
    public const STATE_REVEAL_TOKEN = 'com_ytbmcp.reveal.token';
    public const STATE_REVEAL_NONCE = 'com_ytbmcp.reveal.pickup_nonce';

    /** Documentation / repo / npm / product-home URLs (parity with WP SettingsPage constants). */
    public const DOCS_URL = 'https://github.com/wootsup/yt-builder-mcp#readme';
    public const REPO_URL = 'https://github.com/wootsup/yt-builder-mcp';
    public const NPM_URL = 'https://www.npmjs.com/package/@wootsup/yt-builder-mcp';

    /** WootsUp product site — the prominent header/footer CTA (parity with WP HOME_URL). */
    public const HOME_URL = 'https://wootsup.com';

    /** Active tab slug. */
    public string $activeTab = self::TAB_KEYS;

    /** @var array<string, array{label:string,scope:string,created_at:int,expires_at:int|null,revoked_at:int|null}> */
    public array $keys = [];

    /** Freshly minted token to reveal once (null when none pending). */
    public ?string $revealedToken = null;

    /** Pickup nonce paired with the revealed token ('' when none / failed). */
    public string $pickupNonce = '';

    /** Pickup REST URL (built only when a live nonce exists). */
    public string $pickupUrl = '';

    /** Site base URL (no trailing slash). */
    public string $siteUrl = '';

    /** Diagnostics payload. */
    public string $pluginVersion = self::PLUGIN_VERSION;
    public ?string $ytVersion = null;

    /**
     * Whether YOOtheme Pro is present in this install. W11-T4 (2026-05-24):
     * detected via {@see JoomlaYoothemeProbe} — a Joomla-native probe that
     * reads the `yootheme` template row from `#__extensions` (manifest_cache
     * version), with a `templateDetails.xml` fallback. This works in the
     * ADMINISTRATOR application with NO runtime YT bootstrap.
     *
     * Previously this flag was derived from a runtime bootstrap (the
     * YtBootstrapper + YoothemeAdapter version-probe), but YT
     * Pro only lazy-bootstraps on the REST/API + frontend surface (ADR-001:
     * com_api's template_bootstrap allowlist excludes the admin app; YT is
     * the SITE template, never loaded here). So that path always saw null →
     * a FALSE "YOOtheme Pro required" notice even on installs where YT Pro
     * was active. The DB/manifest probe is the reliable admin-context signal.
     *
     * When false the template renders a branded "YOOtheme Pro required"
     * notice (parity with WP's `after_setup_theme` fallback admin page),
     * while the Keys tab stays fully usable.
     */
    public bool $ytPresent = false;
    public ?string $cmsVersion = null;
    public string $phpVersion = PHP_VERSION;
    public int $schemaVersion = 0;
    public bool $signingSecretPresent = false;
    public int $bearerKeyCount = 0;

    /** @var list<string> Declared REST endpoint paths. */
    public array $endpoints = [];

    public string $healthUrl = '';
    public string $identityUrl = '';

    /** Markdown blob for the "Copy diagnostics as Markdown" button. */
    public string $diagnosticsMarkdown = '';

    public function display($tpl = null): void
    {
        $app = Factory::getApplication();

        // --- Admin capability gate (parity with WP render() manage_options) -
        // Render is gated by the SAME choke-point as the mutating tasks, so
        // a user without core.admin/core.manage on com_ytbmcp never even
        // sees the keys table or the diagnostics surface. Throws a 403
        // NotAllowed which the dispatcher renders as the standard error page.
        JoomlaAdminAccess::assert($app->getIdentity());

        // --- Native Joomla component toolbar -------------------------------
        // W11 (2026-05-24): the admin UI now renders as a first-class native
        // Joomla component — standard Atum toolbar + Bootstrap/uitab body.
        // We dropped the bespoke "brand" surface (the former custom component
        // stylesheet + .ytb-* colour classes); the ONLY brand element kept is
        // the logo SVG, rendered inline next to the native page heading in the
        // template. Because every body component is now native Bootstrap/Atum,
        // the page is correct in BOTH Joomla light and dark mode with zero
        // custom colour CSS and no WebAssetManager dependency.
        // ToolbarHelper::title emits the standard Atum page heading bar; the
        // icon class is a core Joomla icon (the WootsUp logo lives in the page
        // heading below it).
        ToolbarHelper::title(Text::_('COM_YTBMCP'), 'wand');

        // --- Active tab (fail-closed allow-list) ---------------------------
        $requested = (string) $app->getInput()->getCmd('tab', '');
        $this->activeTab = \in_array($requested, self::TABS, true) ? $requested : self::TAB_KEYS;

        $this->siteUrl = \rtrim((string) Uri::root(), '/');

        // --- Bearer keys ---------------------------------------------------
        $keyStore = new JoomlaKeyStore();
        $this->keys = $keyStore->list();
        $this->bearerKeyCount = \count($this->keys);

        // --- One-shot reveal (consume from user-state) ---------------------
        $this->consumeRevealedToken($app);

        // --- Diagnostics ---------------------------------------------------
        // W11-T4 (#13/#19, 2026-05-24): detect YOOtheme Pro via the Joomla
        // framework's own `#__extensions` table (manifest_cache version) with
        // a `templateDetails.xml` fallback — NOT via a runtime YT bootstrap.
        //
        // YOOtheme Pro is the SITE template; it only lazy-bootstraps on the
        // REST/API + frontend surface (ADR-001 — com_api's
        // template_bootstrap allowlist excludes the administrator
        // application). So the former runtime-bootstrap + adapter
        // version-probe path could NEVER load YT in this
        // admin View and always returned null → a FALSE "YOOtheme Pro
        // required" notice + Diagnostics "—" even on installs where YT Pro
        // was active. JoomlaYoothemeProbe reads the install state from the DB
        // (the api-mapper-mirrored approach), which is reliable in the admin
        // app. The probe is fail-safe (any DB/FS error → null → "—").
        $this->ytVersion = (new JoomlaYoothemeProbe())->detectVersion();
        // YT-presence flag drives the branded "YOOtheme Pro required" notice
        // (#13). Null only when no enabled `yootheme` template row exists and
        // no manifest is on disk. The Keys tab stays usable regardless.
        $this->ytPresent = $this->ytVersion !== null;
        $this->cmsVersion = Version::MAJOR_VERSION . '.' . Version::MINOR_VERSION . '.' . Version::PATCH_VERSION;
        $this->schemaVersion = JoomlaSchemaVersion::get();
        $this->signingSecretPresent = JoomlaSigningSecret::get() !== null;
        $this->endpoints = $this->declaredEndpoints();
        $this->healthUrl = $this->siteUrl . '/api/index.php/v1/yt-builder-mcp/health';
        $this->identityUrl = $this->siteUrl . '/api/index.php/v1/yt-builder-mcp/identity';
        $this->diagnosticsMarkdown = $this->buildDiagnosticsMarkdown();

        parent::display($tpl);
    }

    /**
     * Consume the freshly minted token + pickup nonce stashed by
     * {@see DashboardController::generateKey()}. One-shot: read then clear
     * the user-state so the token cannot be re-revealed by a page refresh.
     */
    private function consumeRevealedToken(object $app): void
    {
        if (!\method_exists($app, 'getUserState') || !\method_exists($app, 'setUserState')) {
            return;
        }
        /** @var mixed $token */
        $token = $app->getUserState(self::STATE_REVEAL_TOKEN, null);
        if (\is_string($token) && $token !== '') {
            $this->revealedToken = $token;
            $app->setUserState(self::STATE_REVEAL_TOKEN, null);
        }
        /** @var mixed $nonce */
        $nonce = $app->getUserState(self::STATE_REVEAL_NONCE, '');
        if (\is_string($nonce) && JoomlaPickupChannel::isValidNonceShape($nonce)) {
            $this->pickupNonce = $nonce;
            $this->pickupUrl = $this->siteUrl . '/api/index.php/v1/yt-builder-mcp/setup/pickup';
        }
        $app->setUserState(self::STATE_REVEAL_NONCE, null);
    }

    /**
     * Declared v1 endpoint inventory (cookbook §3.2). Mirrors the
     * HealthController augmented-payload list; the Joomla routes are
     * registered in code (plg_webservices_ytbmcp) rather than introspectable
     * at runtime like WP's rest_get_server(), so we surface the canonical
     * declared set.
     *
     * @return list<string>
     */
    private function declaredEndpoints(): array
    {
        $endpoints = [
            '/v1/yt-builder-mcp/health',
            '/v1/yt-builder-mcp/identity',
            '/v1/yt-builder-mcp/setup/pickup',
            '/v1/yt-builder-mcp/etag',
            '/v1/yt-builder-mcp/pages',
            '/v1/yt-builder-mcp/pages/<templateId>/layout',
            '/v1/yt-builder-mcp/pages/<templateId>/schema',
            '/v1/yt-builder-mcp/pages/<templateId>/summary',
            '/v1/yt-builder-mcp/pages/<templateId>/save',
            '/v1/yt-builder-mcp/pages/<templateId>/publish',
            '/v1/yt-builder-mcp/pages/<templateId>/elements',
            '/v1/yt-builder-mcp/pages/<templateId>/elements/<path>',
            '/v1/yt-builder-mcp/pages/<templateId>/elements/<path>/settings',
            '/v1/yt-builder-mcp/pages/<templateId>/elements/<path>/move',
            '/v1/yt-builder-mcp/pages/<templateId>/elements/<path>/clone',
            '/v1/yt-builder-mcp/element-types',
            '/v1/yt-builder-mcp/element-types/<typeName>/schema',
            '/v1/yt-builder-mcp/sources',
            '/v1/yt-builder-mcp/pages/<templateId>/elements/<path>/binding',
            '/v1/yt-builder-mcp/pages/<templateId>/elements/<path>/multi-items/inspect',
            '/v1/yt-builder-mcp/pages/<templateId>/elements/<path>/multi-items/clean-implode',
            // L2 — Joomla-only article extensions
            '/v1/yt-builder-mcp/articles',
            '/v1/yt-builder-mcp/articles/<articleId>/page-layout',
            '/v1/yt-builder-mcp/articles/<articleId>/page-layout/save',
            '/v1/yt-builder-mcp/articles/<articleId>/elements/<path>',
        ];
        \sort($endpoints);
        return $endpoints;
    }

    /**
     * Build the diagnostics body as a GitHub-flavoured markdown blob the
     * operator can paste straight into an Issue. Byte-parity with the WP
     * SettingsPage::build_diagnostics_markdown() structure.
     */
    private function buildDiagnosticsMarkdown(): string
    {
        $lines = [];
        $lines[] = '## YT Builder MCP for YOOtheme Pro (unofficial) — Diagnostics';
        $lines[] = '';
        $lines[] = '### Versions';
        $lines[] = '';
        $lines[] = '| Component | Version |';
        $lines[] = '| --- | --- |';
        $lines[] = '| Plugin | ' . $this->pluginVersion . ' |';
        $lines[] = '| YOOtheme Pro | ' . ($this->ytVersion ?? '—') . ' |';
        $lines[] = '| Joomla | ' . ($this->cmsVersion ?? '—') . ' |';
        $lines[] = '| PHP | ' . $this->phpVersion . ' |';
        $lines[] = '| Schema | ' . (string) $this->schemaVersion . ' |';
        $lines[] = '';
        $lines[] = '### Security';
        $lines[] = '';
        $lines[] = '| Item | Status |';
        $lines[] = '| --- | --- |';
        $lines[] = '| Signing secret | ' . ($this->signingSecretPresent ? 'present (encrypted at rest)' : 'missing — regenerate on next key issue') . ' |';
        $lines[] = '| Bearer keys | ' . (string) $this->bearerKeyCount . ' |';
        $lines[] = '';
        $lines[] = '### REST surface';
        $lines[] = '';
        $lines[] = '| Item | Value |';
        $lines[] = '| --- | --- |';
        $lines[] = '| Endpoints | ' . (string) \count($this->endpoints) . ' |';
        $lines[] = '| Probe URL | ' . $this->healthUrl . ' |';
        if ($this->endpoints !== []) {
            $lines[] = '';
            $lines[] = '<details><summary>Registered REST endpoints</summary>';
            $lines[] = '';
            $lines[] = '```';
            foreach ($this->endpoints as $endpoint) {
                $lines[] = $endpoint;
            }
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '</details>';
        }
        return \implode("\n", $lines) . "\n";
    }
}
