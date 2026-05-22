<?php
/**
 * SettingsPage — WP-Admin "Rich Brand Page" for YT Builder MCP.
 *
 * Top-level menu (was: Tools submenu; promoted 2026-05-22 per Plugin-Audit
 * Round 2) with three tabs:
 *
 *   1. Bearer Keys (default)        — generate / revoke API keys
 *   2. Diagnostics                  — live /health probe + environment info
 *   3. About                        — MCP intro, NPM install, AI-client list
 *
 * The render path is composed from small `render_*` helpers (one per tab
 * + brand header / footer) so each method stays well under 60 LoC — see
 * the UI-development skill's "Composition > Boolean" rule. Tab switching
 * is driven by `$_GET['tab']` validated against an allow-list.
 *
 * Workflow (Keys tab):
 *  1. Operator fills label + scope + expiry → POST with WP-nonce
 *  2. SettingsPage::handle_generate() calls KeyService::generate() +
 *     KeyStore::register(), then stores the freshly minted token in a
 *     transient (one-shot reveal) and redirects with `?revealed=<kid>`
 *  3. Page renders the token ONCE — the transient is consumed on first read
 *  4. List below shows all kids with their metadata + revoke buttons
 *
 * Capability gate: `manage_options` everywhere (admin-only).
 * Nonce gate: every mutating POST verifies `wp_verify_nonce` + the
 * action-specific name (`ytb_mcp_generate_key`, `ytb_mcp_revoke_key`).
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Platform\WordPress
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\WordPress;

use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Auth\KeyStore;
use WootsUp\BuilderMcp\Auth\SigningSecret;
use WootsUp\BuilderMcp\Storage\PickupChannel;
use WootsUp\BuilderMcp\Storage\SchemaVersion;
use WootsUp\BuilderMcp\Util\SecurityLogger;
use WootsUp\BuilderMcp\Yootheme\YoothemeAdapter;

final class SettingsPage
{
    /** WP-Admin menu slug (also acts as the Keys-tab parent). */
    public const SLUG = 'ytb-mcp-settings';

    /** Tab slugs — the allow-list for $_GET['tab']. */
    public const TAB_KEYS = 'keys';
    public const TAB_DIAGNOSTICS = 'diagnostics';
    public const TAB_ABOUT = 'about';

    /** All tabs, in display order. */
    private const TABS = [self::TAB_KEYS, self::TAB_DIAGNOSTICS, self::TAB_ABOUT];

    /** Transient name for the one-shot token reveal. */
    private const REVEAL_TRANSIENT = 'ytb_mcp_revealed_token_';

    /**
     * Pickup REST path (without leading slash, without /wp-json prefix).
     * Matches the route registered by {@see \WootsUp\BuilderMcp\Rest\PickupController}.
     *
     * The transient prefix + TTL + nonce shape live in
     * {@see \WootsUp\BuilderMcp\Storage\PickupChannel} since H2 — this class
     * uses PickupChannel::issue() for storage and only owns the REST route
     * path (which is a plugin-specific routing concern, not a storage one).
     */
    private const PICKUP_REST_PATH = 'yt-builder-mcp/v1/setup/pickup';

    /** Settings-error code for the revealed-token notice. */
    private const SETTINGS_ERROR_SLUG = 'ytb_mcp_settings';

    /** Documentation root (used by brand header + about tab). */
    private const DOCS_URL = 'https://github.com/wootsup/yt-builder-mcp#readme';

    /** GitHub repository URL. */
    private const REPO_URL = 'https://github.com/wootsup/yt-builder-mcp';

    /** Companion NPM package page. */
    private const NPM_URL = 'https://www.npmjs.com/package/@wootsup/yt-builder-mcp';

    public function __construct(
        private readonly KeyService $keyService,
        private readonly KeyStore $keyStore,
    ) {
    }

    public function register(): void
    {
        \add_action('admin_menu', [$this, 'add_menu']);
        \add_action('admin_post_ytb_mcp_generate_key', [$this, 'handle_generate']);
        \add_action('admin_post_ytb_mcp_revoke_key', [$this, 'handle_revoke']);
    }

    /**
     * Tools submenu (was: top-level briefly during Plugin-Audit Round 2 2026-05-22 morning;
     * demoted back to Tools-submenu later same day per Wave-A operator decision —
     * the plugin is a utility, not a primary surface).
     *
     * NOTE: When YOOtheme Pro is absent, the plugin's main entry registers
     * a *fallback* page on the SAME slug (`ytb-mcp-settings`) at hook
     * priority 5 of `after_setup_theme`. WordPress's last-writer-wins
     * semantics mean both registrations resolve to a single sidebar entry —
     * the YT-loaded path always shows the rich settings page; the YT-missing
     * fallback only renders when bootstrap does not run.
     */
    public function add_menu(): void
    {
        // Settings page lives under Tools → YT Builder MCP. Keeps the WP
        // sidebar uncluttered — yt-builder-mcp is a utility plugin
        // and does not warrant a top-level entry. The three tabs (Keys,
        // Diagnostics, About) live inside the page itself via ?tab=.
        \add_submenu_page(
            'tools.php',
            \__('YT Builder MCP for YOOtheme Pro (unofficial)', 'yt-builder-mcp'),
            \__('YT Builder MCP', 'yt-builder-mcp'),
            'manage_options',
            self::SLUG,
            [$this, 'render'],
        );
    }

    public function render(): void
    {
        if (!\current_user_can('manage_options')) {
            \wp_die(\esc_html__('Insufficient permissions.', 'yt-builder-mcp'));
        }

        $activeTab = $this->resolve_active_tab();

        echo '<div class="wrap">';
        echo BrandAssets::renderInlineStyles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline CSS

        $this->render_brand_header();
        $this->render_tabs_nav($activeTab);

        echo '<div class="ytb-tab-panel">';
        switch ($activeTab) {
            case self::TAB_DIAGNOSTICS:
                $this->render_diagnostics_tab();
                break;
            case self::TAB_ABOUT:
                $this->render_about_tab();
                break;
            case self::TAB_KEYS:
            default:
                $this->render_keys_tab();
                break;
        }
        echo '</div>';

        $this->render_brand_footer();
        echo '</div>';

        // Inline copy-to-clipboard helper (only on keys tab).
        if ($activeTab === self::TAB_KEYS) {
            $this->render_copy_script();
        }
    }

    /**
     * Validate `$_GET['tab']` against the allow-list. Fail-closed: any
     * unrecognised value resolves to the default Keys tab.
     */
    private function resolve_active_tab(): string
    {
        $requested = isset($_GET['tab']) ? \sanitize_key((string) $_GET['tab']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav
        return in_array($requested, self::TABS, true) ? $requested : self::TAB_KEYS;
    }

    /**
     * Brand header: logo, title, version badge, tagline, CTA row.
     */
    private function render_brand_header(): void
    {
        $version = defined('YTB_MCP_VERSION') ? (string) \YTB_MCP_VERSION : 'dev';

        echo '<div class="ytb-brand-header">';
        echo '<div class="ytb-brand-header__mark">' . BrandAssets::renderLogo(48) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG
        echo '<div class="ytb-brand-header__body">';
        echo '<h1 class="ytb-brand-header__title">';
        echo \esc_html__('YT Builder MCP for YOOtheme Pro', 'yt-builder-mcp');
        echo ' <span class="ytb-version-badge">v' . \esc_html($version) . '</span>';
        echo ' <span class="ytb-unofficial-badge" title="' . \esc_attr__('Independent third-party plugin. Not affiliated with or endorsed by YOOtheme GmbH.', 'yt-builder-mcp') . '">' . \esc_html__('unofficial', 'yt-builder-mcp') . '</span>';
        echo '</h1>';
        echo '<p class="ytb-brand-header__tagline">';
        echo \esc_html__('Drive your YOOtheme Pro page builder programmatically from Claude, Cursor, ChatGPT, and other AI assistants via the Model Context Protocol.', 'yt-builder-mcp');
        echo '</p>';
        echo '<div class="ytb-brand-header__ctas">';
        echo '<a class="button ytb-brand-cta-primary" href="' . \esc_url(\add_query_arg(['page' => self::SLUG], \admin_url('admin.php'))) . '">';
        echo \esc_html__('Generate Key', 'yt-builder-mcp');
        echo '</a>';
        echo '<a class="button button-secondary" href="' . \esc_url(self::DOCS_URL) . '" target="_blank" rel="noopener noreferrer">';
        echo \esc_html__('Documentation', 'yt-builder-mcp');
        echo '</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Tab navigation strip — WP-standard `nav-tab-wrapper` with deep-links
     * to `?page=&tab=`. The active tab is purely visual; render() decides
     * which content panel to invoke.
     */
    private function render_tabs_nav(string $activeTab): void
    {
        $tabs = [
            self::TAB_KEYS => \__('Bearer Keys', 'yt-builder-mcp'),
            self::TAB_DIAGNOSTICS => \__('Diagnostics', 'yt-builder-mcp'),
            self::TAB_ABOUT => \__('About', 'yt-builder-mcp'),
        ];

        echo '<h2 class="nav-tab-wrapper" style="margin-top:16px;">';
        foreach ($tabs as $slug => $label) {
            $args = ['page' => self::SLUG];
            if ($slug !== self::TAB_KEYS) {
                $args['tab'] = $slug;
            }
            $url = \add_query_arg($args, \admin_url('admin.php'));
            $cls = 'nav-tab' . ($slug === $activeTab ? ' nav-tab-active' : '');
            echo '<a href="' . \esc_url($url) . '" class="' . \esc_attr($cls) . '">';
            echo \esc_html($label);
            echo '</a>';
        }
        echo '</h2>';
    }

    /**
     * Keys tab: generate form + existing keys table + reveal notice.
     */
    private function render_keys_tab(): void
    {
        // I7: consume revealed-token transient + strip `?revealed=<kid>` so
        // the URL is not bookmarked / leaked through browser history.
        $revealedKid = isset($_GET['revealed']) ? \sanitize_text_field((string) $_GET['revealed']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display
        $revealedToken = $revealedKid !== '' ? $this->consume_revealed_token($revealedKid) : null;

        // Wave C: pickup-nonce parallel-channel to the revealed token. The
        // wp-admin user copies a pre-built `npx ... setup --pickup ... --nonce ...`
        // prompt that the AI client's Bash tool executes; the wizard POSTs
        // the nonce to /v1/setup/pickup and gets the token + URL back. Nonce
        // shape validation lives in PickupController.
        $pickupNonceRaw = isset($_GET['pickup']) ? \sanitize_text_field((string) $_GET['pickup']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $pickupNonce = PickupChannel::isValidNonceShape($pickupNonceRaw) ? $pickupNonceRaw : '';

        // Fallback path: the generator stashed a token via add_settings_error
        // (because set_transient returned false on this host). Read it back
        // exactly once — the settings_errors() helper auto-clears on render.
        $fallbackToken = $this->consume_fallback_token();

        echo '<h2 style="margin-top:0;">' . \esc_html__('Bearer Keys', 'yt-builder-mcp') . '</h2>';

        $displayToken = $revealedToken ?? $fallbackToken;
        if ($displayToken !== null && $displayToken !== '') {
            $this->render_revealed_token_notice($displayToken, $pickupNonce);
        }

        // Surface any admin_notices the generator queued (e.g. KeyService
        // failure). settings_errors() is the canonical WP path for these.
        \settings_errors(self::SETTINGS_ERROR_SLUG);

        $this->render_form();
        $this->render_list();
    }

    public function handle_generate(): void
    {
        if (!\current_user_can('manage_options')) {
            \wp_die(\esc_html__('Insufficient permissions.', 'yt-builder-mcp'));
        }
        \check_admin_referer('ytb_mcp_generate_key');

        $label = \sanitize_text_field((string) ($_POST['label'] ?? ''));
        $scope = \sanitize_text_field((string) ($_POST['scope'] ?? 'read'));
        $expires = \sanitize_text_field((string) ($_POST['expires'] ?? '90d'));

        if (!in_array($scope, ['read', 'write', 'admin'], true)) {
            $scope = 'read';
        }

        $now = time();
        $expiresAt = $this->expires_to_timestamp($expires, $now);

        $kid = $this->generate_kid();
        // Wave 6.5: include `iss` (issuer = site URL) so the npm setup wizard
        // can pre-fill the WordPress site URL from the token payload —
        // matches api-mapper's UX where the user pastes the token and the
        // URL prompt is pre-filled, often a single Enter press.
        $claims = [
            'scope' => $scope,
            'iss'   => \rtrim((string) \get_site_url(), '/'),
        ];
        if ($expiresAt !== null) {
            $claims['exp'] = $expiresAt;
        }

        // C3: defensive try/catch — a failed generate or register must NOT
        // produce a white-screen fatal. Show an admin_notice and redirect
        // back to the settings page.
        try {
            $token = $this->keyService->generate($kid, $claims);

            $this->keyStore->register($kid, [
                'label' => $label !== '' ? $label : 'Untitled',
                'scope' => $scope,
                'created_at' => $now,
                'expires_at' => $expiresAt,
                'revoked_at' => null,
            ]);
        } catch (\Throwable $e) {
            $this->log_security_event('key_generate_failed', [
                'reason' => $e->getMessage(),
            ]);
            \add_settings_error(
                self::SETTINGS_ERROR_SLUG,
                'ytb_mcp_generate_failed',
                \sprintf(
                    /* translators: %s: error message */
                    \esc_html__('Could not generate a new key: %s', 'yt-builder-mcp'),
                    \esc_html($e->getMessage()),
                ),
                'error',
            );
            // Persist the error across the redirect (admin_notices fire on
            // the next request — settings-errors uses a transient for this).
            \set_transient(
                'settings_errors',
                \get_settings_errors(),
                30,
            );
            \wp_safe_redirect(\add_query_arg(['page' => self::SLUG], \admin_url('admin.php')));
            exit;
        }

        // C3: transient may legitimately fail (broken object-cache, disk full,
        // restricted host). Fall back to a one-shot settings-error payload
        // so the operator never loses the freshly generated key.
        $stored = \set_transient(self::REVEAL_TRANSIENT . $kid, $token, 60);
        if ($stored === false) {
            $this->log_security_event('reveal_transient_failed', [
                'kid' => $kid,
            ]);
            \add_settings_error(
                self::SETTINGS_ERROR_SLUG,
                'ytb_mcp_transient_fallback',
                \esc_html__('Storage of the reveal token failed; the key is shown below as a fallback. Copy it now — it cannot be retrieved later.', 'yt-builder-mcp'),
                'warning',
            );
            // Stash the token itself into the settings-errors transient so
            // the next page render can display it once and then drop it.
            $errors = \get_settings_errors();
            $errors[] = [
                'setting' => self::SETTINGS_ERROR_SLUG,
                'code' => 'ytb_mcp_fallback_token',
                'message' => $token,
                'type' => 'fallback-token',
            ];
            \set_transient('settings_errors', $errors, 60);
        }

        // Wave C: pickup-nonce for the "Copy AI prompt" flow. Stores the
        // token + site URL + originator IP in a 5-min transient that the
        // npm setup-wizard can claim via POST /v1/setup/pickup. Storage
        // primitives live in PickupChannel (H2 — single owner of the
        // pickup-URL transient channel).
        //
        // IP-binding is currently always on. render_form does not expose a
        // checkbox to disable it, so honouring `$_POST['pickup_any_ip']`
        // would be a dead feature flag exploitable by hand-crafted POSTs.
        // TODO Wave-X: surface an explicit "claim from any IP" checkbox in
        // the Generate-Key form if customer demand for cross-network setup
        // (e.g. wp-admin behind VPN, wizard on laptop) materialises.
        $ipBound = true;
        $pickupNonce = PickupChannel::issue([
            'token' => $token,
            'site_url' => \rtrim((string) \get_site_url(), '/'),
            'ip' => isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
            'ip_bound' => $ipBound,
        ]);
        if ($pickupNonce === '') {
            // PickupChannel returns '' on set_transient failure — signal to
            // render-layer: no pickup CTA, only the bare-token reveal box.
            $this->log_security_event('pickup_transient_failed', ['kid' => $kid]);
        }

        $redirectArgs = ['page' => self::SLUG, 'revealed' => $kid];
        if ($pickupNonce !== '') {
            $redirectArgs['pickup'] = $pickupNonce;
        }
        \wp_safe_redirect(\add_query_arg($redirectArgs, \admin_url('admin.php')));
        exit;
    }

    public function handle_revoke(): void
    {
        if (!\current_user_can('manage_options')) {
            \wp_die(\esc_html__('Insufficient permissions.', 'yt-builder-mcp'));
        }
        \check_admin_referer('ytb_mcp_revoke_key');

        $kid = \sanitize_text_field((string) ($_POST['kid'] ?? ''));
        if ($kid !== '') {
            $this->keyStore->revoke($kid);
        }

        \wp_safe_redirect(\add_query_arg(['page' => self::SLUG], \admin_url('admin.php')));
        exit;
    }

    private function render_form(): void
    {
        echo '<h3>' . \esc_html__('Generate New Key', 'yt-builder-mcp') . '</h3>';
        echo '<form method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '">';
        \wp_nonce_field('ytb_mcp_generate_key');
        echo '<input type="hidden" name="action" value="ytb_mcp_generate_key" />';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label for="ytb-label">' . \esc_html__('Label', 'yt-builder-mcp') . '</label></th>';
        echo '<td><input id="ytb-label" type="text" name="label" class="regular-text" required /></td></tr>';

        echo '<tr><th><label for="ytb-scope">' . \esc_html__('Scope', 'yt-builder-mcp') . '</label></th>';
        echo '<td><select id="ytb-scope" name="scope">';
        echo '<option value="read">' . \esc_html__('read', 'yt-builder-mcp') . '</option>';
        echo '<option value="write" selected>' . \esc_html__('write', 'yt-builder-mcp') . '</option>';
        echo '<option value="admin">' . \esc_html__('admin', 'yt-builder-mcp') . '</option>';
        echo '</select></td></tr>';

        // N3: translatable expiry labels.
        echo '<tr><th><label for="ytb-expires">' . \esc_html__('Expires', 'yt-builder-mcp') . '</label></th>';
        echo '<td><select id="ytb-expires" name="expires">';
        echo '<option value="90d" selected>' . \esc_html__('90 days', 'yt-builder-mcp') . '</option>';
        echo '<option value="1y">' . \esc_html__('1 year', 'yt-builder-mcp') . '</option>';
        echo '<option value="never">' . \esc_html__('Never', 'yt-builder-mcp') . '</option>';
        echo '</select></td></tr>';

        echo '</tbody></table>';
        \submit_button(\__('Generate Key', 'yt-builder-mcp'), 'primary ytb-brand-cta-primary');
        echo '</form>';
    }

    private function render_list(): void
    {
        echo '<h3>' . \esc_html__('Existing Keys', 'yt-builder-mcp') . '</h3>';

        $all = $this->keyStore->list();
        if ($all === []) {
            // I5: onboarding text on empty state.
            echo '<p>' . \esc_html__('No keys yet. Click "Generate Key" above to issue your first Bearer token for the MCP server.', 'yt-builder-mcp') . '</p>';
            echo '<p>' . \wp_kses(
                \sprintf(
                    /* translators: 1: getting-started docs link, 2: NPM package link */
                    \__('New to MCP? Read the <a href="%1$s" target="_blank" rel="noopener noreferrer">getting started guide</a> or install the <a href="%2$s" target="_blank" rel="noopener noreferrer">companion NPM package</a> next to your AI client.', 'yt-builder-mcp'),
                    'https://github.com/wootsup/yt-builder-mcp/blob/main/docs/getting-started.md',
                    self::NPM_URL,
                ),
                [
                    'a' => [
                        'href' => true,
                        'target' => true,
                        'rel' => true,
                    ],
                ],
            ) . '</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . \esc_html__('Label', 'yt-builder-mcp') . '</th>';
        echo '<th>' . \esc_html__('Kid', 'yt-builder-mcp') . '</th>';
        echo '<th>' . \esc_html__('Scope', 'yt-builder-mcp') . '</th>';
        echo '<th>' . \esc_html__('Created', 'yt-builder-mcp') . '</th>';
        echo '<th>' . \esc_html__('Expires', 'yt-builder-mcp') . '</th>';
        echo '<th>' . \esc_html__('Status', 'yt-builder-mcp') . '</th>';
        echo '<th>' . \esc_html__('Actions', 'yt-builder-mcp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($all as $kid => $meta) {
            // I6: include revoked-at timestamp in the status cell so the
            // operator can correlate revocations with logs / incidents.
            if ($meta['revoked_at'] !== null) {
                $status = \sprintf(
                    /* translators: %s: ISO-like revocation timestamp */
                    \__('Revoked (%s)', 'yt-builder-mcp'),
                    \date_i18n('Y-m-d H:i', (int) $meta['revoked_at']),
                );
            } else {
                $status = \__('Active', 'yt-builder-mcp');
            }

            echo '<tr>';
            echo '<td>' . \esc_html($meta['label']) . '</td>';
            echo '<td><code>' . \esc_html($kid) . '</code></td>';
            echo '<td>' . \esc_html($meta['scope']) . '</td>';
            echo '<td>' . \esc_html(\date_i18n('Y-m-d H:i', $meta['created_at'])) . '</td>';
            echo '<td>' . \esc_html(
                $meta['expires_at'] !== null
                    ? \date_i18n('Y-m-d H:i', $meta['expires_at'])
                    : \__('never', 'yt-builder-mcp'),
            ) . '</td>';
            echo '<td>' . \esc_html($status) . '</td>';
            echo '<td>';
            if ($meta['revoked_at'] === null) {
                // C2: confirmation dialog + aria-label. Label is escaped for
                // both JS-string ('foo\'bar' breaks the JS quote) and HTML.
                $labelForJs = \esc_js((string) $meta['label']);
                $labelForAria = \esc_attr(\sprintf(
                    /* translators: %s: key label */
                    \__('Revoke key "%s"', 'yt-builder-mcp'),
                    (string) $meta['label'],
                ));
                $confirmPrompt = \sprintf(
                    /* translators: %s: key label */
                    \__('Revoke key \'%s\'? Any MCP client using this key will immediately lose access. This cannot be undone.', 'yt-builder-mcp'),
                    $labelForJs,
                );
                echo '<form method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '" style="display:inline;">';
                \wp_nonce_field('ytb_mcp_revoke_key');
                echo '<input type="hidden" name="action" value="ytb_mcp_revoke_key" />';
                echo '<input type="hidden" name="kid" value="' . \esc_attr($kid) . '" />';
                echo '<button type="submit" class="button button-small" ';
                echo 'aria-label="' . $labelForAria . '" ';
                echo 'onclick="return confirm(' . "'" . \esc_attr($confirmPrompt) . "'" . ');">';
                echo \esc_html__('Revoke', 'yt-builder-mcp');
                echo '</button>';
                echo '</form>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Diagnostics tab: live snapshot of the things ops most often need
     * for triage — plugin/YT/WP version, schema version, signing-secret
     * status, and the list of REST endpoints the bridge exposes. Read-only
     * — every value is sourced from already-loaded singletons, no extra
     * HTTP hop required.
     */
    private function render_diagnostics_tab(): void
    {
        $yt = new YoothemeAdapter();
        $ytVersion = $yt->getVersion();
        $wpVersion = isset($GLOBALS['wp_version']) && is_string($GLOBALS['wp_version']) ? $GLOBALS['wp_version'] : null;
        $schemaVersion = SchemaVersion::get();
        $signingSecretPresent = SigningSecret::get() !== null;
        $endpoints = $this->collect_rest_endpoints();
        $pluginVersion = defined('YTB_MCP_VERSION') ? (string) \YTB_MCP_VERSION : 'dev';

        // Build the same data as a markdown blob, so the "Copy as
        // Markdown" button can drop it directly into a GitHub Issue
        // body without the user needing to type or screenshot anything.
        $markdown = $this->build_diagnostics_markdown(
            $pluginVersion,
            $ytVersion,
            $wpVersion,
            $schemaVersion,
            $signingSecretPresent,
            count($this->keyStore->list()),
            $endpoints,
        );

        echo '<h2 style="margin-top:0;">' . \esc_html__('Diagnostics', 'yt-builder-mcp') . '</h2>';
        echo '<p class="description">' . \esc_html__('Snapshot of the plugin\'s runtime state. Useful when reporting an issue — copy the markdown below and paste it into the GitHub Issue.', 'yt-builder-mcp') . '</p>';

        // Copy button + screen-reader-only confirmation. We put the
        // markdown into a data-attribute so the JS handler can grab it
        // without round-tripping through the DOM.
        echo '<p style="margin:8px 0 16px;">';
        echo '<button type="button" class="button button-primary" id="ytb-mcp-copy-diagnostics" data-ytb-mcp-markdown="' . \esc_attr($markdown) . '">';
        echo '<span class="dashicons dashicons-clipboard" style="vertical-align:text-bottom;margin-right:6px;" aria-hidden="true"></span>';
        echo \esc_html__('Copy diagnostics as Markdown', 'yt-builder-mcp');
        echo '</button>';
        echo ' <span id="ytb-mcp-copy-diagnostics-status" aria-live="polite" style="margin-left:10px;color:#46b450;font-weight:600;"></span>';
        echo '</p>';
        $this->print_copy_diagnostics_script();

        echo '<div class="ytb-diag-grid">';

        echo '<div class="ytb-diag-card">';
        echo '<h3>' . \esc_html__('Versions', 'yt-builder-mcp') . '</h3>';
        echo '<dl>';
        echo '<dt>' . \esc_html__('Plugin', 'yt-builder-mcp') . '</dt>';
        echo '<dd>' . \esc_html($pluginVersion) . '</dd>';
        echo '<dt>' . \esc_html__('YOOtheme Pro', 'yt-builder-mcp') . '</dt>';
        echo '<dd>' . \esc_html($ytVersion ?? '—') . '</dd>';
        echo '<dt>' . \esc_html__('WordPress', 'yt-builder-mcp') . '</dt>';
        echo '<dd>' . \esc_html($wpVersion ?? '—') . '</dd>';
        echo '<dt>' . \esc_html__('PHP', 'yt-builder-mcp') . '</dt>';
        echo '<dd>' . \esc_html(PHP_VERSION) . '</dd>';
        echo '<dt>' . \esc_html__('Schema', 'yt-builder-mcp') . '</dt>';
        echo '<dd>' . \esc_html((string) $schemaVersion) . '</dd>';
        echo '</dl>';
        echo '</div>';

        echo '<div class="ytb-diag-card">';
        echo '<h3>' . \esc_html__('Security', 'yt-builder-mcp') . '</h3>';
        echo '<dl>';
        echo '<dt>' . \esc_html__('Signing secret', 'yt-builder-mcp') . '</dt>';
        echo '<dd>' . ($signingSecretPresent
            ? \esc_html__('present (encrypted at rest)', 'yt-builder-mcp')
            : \esc_html__('missing — regenerate on next key issue', 'yt-builder-mcp')) . '</dd>';
        echo '<dt>' . \esc_html__('Bearer keys', 'yt-builder-mcp') . '</dt>';
        echo '<dd>' . \esc_html((string) count($this->keyStore->list())) . '</dd>';
        echo '</dl>';
        echo '</div>';

        echo '<div class="ytb-diag-card">';
        echo '<h3>' . \esc_html__('REST surface', 'yt-builder-mcp') . '</h3>';
        echo '<dl>';
        echo '<dt>' . \esc_html__('Endpoints', 'yt-builder-mcp') . '</dt>';
        echo '<dd>' . \esc_html((string) count($endpoints)) . '</dd>';
        if ($endpoints !== []) {
            echo '<dt>' . \esc_html__('Probe URL', 'yt-builder-mcp') . '</dt>';
            $healthUrl = \rest_url('yt-builder-mcp/v1/health');
            echo '<dd><a href="' . \esc_url($healthUrl) . '" target="_blank" rel="noopener noreferrer">' . \esc_html($healthUrl) . '</a></dd>';
        }
        echo '</dl>';
        echo '</div>';

        echo '</div>';

        if ($endpoints !== []) {
            echo '<h3 style="margin-top:24px;">' . \esc_html__('Registered REST endpoints', 'yt-builder-mcp') . '</h3>';
            echo '<ul style="font-family:Menlo,Consolas,monospace;font-size:13px;">';
            foreach ($endpoints as $endpoint) {
                echo '<li>' . \esc_html($endpoint) . '</li>';
            }
            echo '</ul>';
        }
    }

    /**
     * About tab: what MCP is, how to set it up, supported clients,
     * license + version info.
     */
    private function render_about_tab(): void
    {
        $pluginVersion = defined('YTB_MCP_VERSION') ? (string) \YTB_MCP_VERSION : 'dev';

        echo '<h2 style="margin-top:0;">' . \esc_html__('About YT Builder MCP for YOOtheme Pro (unofficial)', 'yt-builder-mcp') . '</h2>';

        echo '<p>' . \esc_html__('Model Context Protocol (MCP) is an open standard that lets AI assistants talk to tools and data. YT Builder MCP exposes a Bearer-authenticated REST dialect of your YOOtheme Pro page builder; the companion NPM server, run locally next to your AI client, translates AI tool-calls into REST requests.', 'yt-builder-mcp') . '</p>';

        echo '<p>' . \esc_html__('Once both halves are configured you can ask Claude, Cursor, ChatGPT and others to read your templates, add or edit elements, bind dynamic sources, and save / publish pages — all under the optimistic-lock + scope-hierarchy guardrails the plugin enforces server-side.', 'yt-builder-mcp') . '</p>';

        echo '<h3>' . \esc_html__('Connect from your AI assistant', 'yt-builder-mcp') . '</h3>';
        echo '<p>' . \esc_html__('Run the setup wizard on the same machine as your AI client. It prompts for the site URL, the Bearer key you generated on the Keys tab, and the clients it should configure:', 'yt-builder-mcp') . '</p>';
        echo '<code class="ytb-about-cmd">npx -y @wootsup/yt-builder-mcp setup</code>';

        echo '<h3>' . \esc_html__('Supported AI clients', 'yt-builder-mcp') . '</h3>';
        echo '<p style="color:#666;font-size:13px;margin:0 0 8px;">' . \esc_html__('The setup wizard auto-configures all 9 clients below. Bearer-token + site URL are written to each client\'s native MCP-config file.', 'yt-builder-mcp') . '</p>';
        echo '<ul class="ytb-about-clients">';
        foreach ([
            'Claude Desktop',
            'Claude Code',
            'Cursor',
            'Zed',
            'Continue',
            'Cline',
            'Roo Code',
            'Codex CLI',
            'Gemini CLI',
        ] as $client) {
            echo '<li>' . \esc_html($client) . '</li>';
        }
        echo '</ul>';

        echo '<h3>' . \esc_html__('Version & license', 'yt-builder-mcp') . '</h3>';
        echo '<table class="widefat striped" style="max-width:520px;">';
        echo '<tbody>';
        echo '<tr><th>' . \esc_html__('Plugin', 'yt-builder-mcp') . '</th>';
        echo '<td><code>v' . \esc_html($pluginVersion) . '</code> &middot; GPL-2.0-or-later</td></tr>';
        echo '<tr><th>' . \esc_html__('MCP server (NPM)', 'yt-builder-mcp') . '</th>';
        echo '<td><code>@wootsup/yt-builder-mcp@' . \esc_html($pluginVersion) . '</code> &middot; MIT</td></tr>';
        echo '<tr><th>' . \esc_html__('Repository', 'yt-builder-mcp') . '</th>';
        echo '<td><a href="' . \esc_url(self::REPO_URL) . '" target="_blank" rel="noopener noreferrer">' . \esc_html(self::REPO_URL) . '</a></td></tr>';
        echo '<tr><th>' . \esc_html__('NPM package', 'yt-builder-mcp') . '</th>';
        echo '<td><a href="' . \esc_url(self::NPM_URL) . '" target="_blank" rel="noopener noreferrer">' . \esc_html(self::NPM_URL) . '</a></td></tr>';
        echo '</tbody></table>';
    }

    /**
     * I8: footer panel with brand lock-up + docs / issues / security links.
     */
    private function render_brand_footer(): void
    {
        echo '<div class="ytb-brand-footer">';
        echo '<div class="ytb-brand-footer__mark">' . BrandAssets::renderLogo(20) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG
        echo '<div class="ytb-brand-footer__copy">';
        echo \wp_kses(
            \sprintf(
                /* translators: 1: docs link, 2: issues link, 3: security mailto link */
                \__('&copy; WootsUp &mdash; A getimo productions company &middot; <a href="%1$s" target="_blank" rel="noopener noreferrer">Documentation</a> &middot; <a href="%2$s" target="_blank" rel="noopener noreferrer">Report an issue</a> &middot; <a href="%3$s">Security disclosures</a>', 'yt-builder-mcp'),
                self::DOCS_URL,
                'https://github.com/wootsup/yt-builder-mcp/issues',
                'mailto:security@wootsup.com',
            ),
            [
                'a' => [
                    'href' => true,
                    'target' => true,
                    'rel' => true,
                ],
            ],
        );
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render the one-shot success notice for a freshly minted token.
     *
     * Wave C UX: three CTAs in order of recommended use —
     *   1. "Connect your AI client" — copy a pre-built prompt with the
     *      pickup-URL (NOT the token). Customer pastes it into Claude / Cursor /
     *      etc. with a Bash tool; the LLM runs `npx ... setup --pickup ...`
     *      and the wizard fetches the token + URL via the pickup endpoint
     *      and writes the client config. Fastest happy path.
     *   2. "Show manual setup" — collapsed `<details>` with the explicit
     *      `npx ... setup` + interactive prompts for power-users.
     *   3. Token + Copy — for customers who already have the wizard
     *      configured or want to wire MCP manually.
     *
     * I9: aria-live + role="alert" so screen-readers announce.
     * I4: click-to-copy buttons via data-ytb-copy attribute (multi-button).
     */
    private function render_revealed_token_notice(string $token, string $pickupNonce = ''): void
    {
        $siteUrl = \rtrim((string) \get_site_url(), '/');
        $pickupUrl = ($pickupNonce !== '')
            ? $siteUrl . '/wp-json/' . self::PICKUP_REST_PATH
            : '';

        echo '<div class="notice notice-success ytb-reveal" role="alert" aria-live="polite" style="padding:14px 18px;">';
        echo '<h3 style="margin:0 0 6px;font-size:15px;">' . \esc_html__('Your Bearer key is ready', 'yt-builder-mcp') . '</h3>';
        echo '<p style="margin:0 0 14px;color:#444;">' . \esc_html__('Copy the AI prompt below and paste it into Claude Desktop, Cursor, or any AI client with a Bash tool. The setup runs automatically — your token is never sent through the chat.', 'yt-builder-mcp') . '</p>';

        if ($pickupUrl !== '' && $pickupNonce !== '') {
            $this->render_ai_prompt_section($pickupUrl, $pickupNonce, $siteUrl);
        }
        $this->render_manual_fallback_section($siteUrl);
        $this->render_token_section($token);

        echo '</div>';
    }

    /**
     * Section 1 — Copy AI prompt (recommended path).
     *
     * Only rendered when the pickup channel is alive (pickup-nonce stored
     * successfully). Carries pickup-URL + nonce — NOT the token itself, so
     * the prompt is safe to paste into AI-chat history / provider logs.
     */
    private function render_ai_prompt_section(string $pickupUrl, string $pickupNonce, string $siteUrl): void
    {
        $aiPrompt = $this->build_ai_prompt($pickupUrl, $pickupNonce, $siteUrl);
        echo '<div class="ytb-reveal__section" style="background:#f0fbf9;border:1px solid #c5e9e3;border-radius:6px;padding:12px 14px;margin-bottom:14px;">';
        echo '<p style="margin:0 0 8px;"><strong>' . \esc_html__('1. Paste this prompt into your AI assistant', 'yt-builder-mcp') . '</strong> ';
        echo '<span style="color:#666;font-size:12px;">' . \esc_html__('— expires in 5 minutes, one-shot, IP-bound', 'yt-builder-mcp') . '</span></p>';
        echo '<pre id="ytb-mcp-ai-prompt" style="background:#0b3534;color:#e6f7f6;padding:10px 12px;border-radius:4px;font-family:Menlo,Consolas,monospace;font-size:12px;overflow-x:auto;white-space:pre-wrap;margin:0 0 10px;">' . \esc_html($aiPrompt) . '</pre>';
        echo '<p style="margin:0;">';
        echo '<button type="button" class="button button-primary ytb-brand-cta-primary" data-ytb-copy="ytb-mcp-ai-prompt" data-ytb-copy-status="ytb-mcp-copy-status-prompt">';
        echo \esc_html__('Copy AI prompt', 'yt-builder-mcp');
        echo '</button> ';
        echo '<span id="ytb-mcp-copy-status-prompt" aria-live="polite" style="margin-left:0.5em;color:#46b450;"></span>';
        echo '</p>';
        echo '</div>';
    }

    /**
     * Section 2 — Manual wizard fallback (collapsed by default).
     *
     * For customers whose pickup channel is unavailable or who prefer
     * explicit interactive setup. Renders an `<details>` element with the
     * npm-wizard incantation and the three prompts the wizard asks.
     */
    private function render_manual_fallback_section(string $siteUrl): void
    {
        echo '<details class="ytb-reveal__section" style="margin-bottom:14px;">';
        echo '<summary style="cursor:pointer;font-weight:600;padding:6px 0;">' . \esc_html__('2. Or run the wizard manually', 'yt-builder-mcp') . '</summary>';
        echo '<div style="padding:8px 0 0 16px;color:#444;">';
        echo '<p style="margin:0 0 8px;">' . \esc_html__('In a terminal on the same machine as your AI client:', 'yt-builder-mcp') . '</p>';
        echo '<pre style="background:#0b3534;color:#e6f7f6;padding:10px 12px;border-radius:4px;font-family:Menlo,Consolas,monospace;font-size:12px;overflow-x:auto;margin:0 0 8px;">npx -y @wootsup/yt-builder-mcp setup</pre>';
        echo '<p style="margin:0 0 4px;">' . \esc_html__('The wizard will ask for:', 'yt-builder-mcp') . '</p>';
        echo '<ul style="margin:0 0 8px 18px;">';
        /* translators: %s: site URL */
        echo '<li>' . \sprintf(\esc_html__('Your site URL — paste: %s', 'yt-builder-mcp'), '<code>' . \esc_html($siteUrl) . '</code>') . '</li>';
        echo '<li>' . \esc_html__('Your Bearer token — paste the token from section 3 below', 'yt-builder-mcp') . '</li>';
        echo '<li>' . \esc_html__('Which AI client(s) to configure — pick from the detected list', 'yt-builder-mcp') . '</li>';
        echo '</ul>';
        echo '</div></details>';
    }

    /**
     * Section 3 — Bearer token + click-to-copy (single source of truth).
     *
     * Always rendered. Shows the raw token once, with a "save it now"
     * warning. Token is not stored server-side once revealed (KeyStore
     * keeps only the hash), so this is the operator's only chance.
     */
    private function render_token_section(string $token): void
    {
        echo '<div class="ytb-reveal__section" style="background:#fff8dc;border:1px solid #f0e0a0;border-radius:6px;padding:12px 14px;">';
        echo '<p style="margin:0 0 8px;"><strong>' . \esc_html__('3. Your Bearer token', 'yt-builder-mcp') . '</strong> ';
        echo '<span style="color:#7a5b00;font-size:12px;">' . \esc_html__('— save it now, it will not be shown again', 'yt-builder-mcp') . '</span></p>';
        echo '<p style="margin:0 0 10px;"><code id="ytb-mcp-revealed-token" style="word-break:break-all;background:#fff;padding:6px 8px;border-radius:4px;display:inline-block;max-width:100%;">' . \esc_html($token) . '</code></p>';
        echo '<p style="margin:0;">';
        echo '<button type="button" class="button button-secondary" data-ytb-copy="ytb-mcp-revealed-token" data-ytb-copy-status="ytb-mcp-copy-status-token">';
        echo \esc_html__('Copy token', 'yt-builder-mcp');
        echo '</button> ';
        echo '<span id="ytb-mcp-copy-status-token" aria-live="polite" style="margin-left:0.5em;color:#46b450;"></span>';
        echo '</p>';
        echo '</div>';
    }

    /**
     * Build the AI-prompt string for the "Copy AI prompt" CTA.
     *
     * Intentionally token-free: the prompt carries only the pickup URL +
     * nonce. The LLM's Bash tool runs the wizard, which POSTs the nonce
     * to the pickup endpoint and receives the token from there. The
     * customer's AI-chat session therefore never sees the actual token —
     * provider logs (Anthropic/OpenAI etc.), chat history, and bash history
     * see only an expired-after-claim URL.
     */
    private function build_ai_prompt(string $pickupUrl, string $pickupNonce, string $siteUrl): string
    {
        $lines = [
            'Configure yt-builder-mcp on this machine.',
            'Run this single command, then restart your AI client:',
            '',
            'npx -y @wootsup/yt-builder-mcp setup \\',
            '  --pickup ' . $pickupUrl . ' \\',
            '  --nonce ' . $pickupNonce . ' \\',
            '  --client claude-desktop',
            '',
            'After it completes, you will be connected to the WordPress at ' . $siteUrl . '.',
            'The pickup URL expires in 5 minutes and can only be claimed once.',
        ];
        return implode("\n", $lines);
    }

    /**
     * Inline JS for the click-to-copy buttons. Multi-button support via
     * `data-ytb-copy="<source-id>"` + `data-ytb-copy-status="<status-id>"`.
     * No jQuery dep, no event-delegation footgun on async-rendered nodes.
     */
    private function render_copy_script(): void
    {
        ?>
<script>
(function () {
    var COPY_OK = '<?php echo \esc_js(\__('Copied!', 'yt-builder-mcp')); ?>';
    var COPY_FAIL = '<?php echo \esc_js(\__('Copy failed — please select & copy manually.', 'yt-builder-mcp')); ?>';

    function writeText(text, done, fail) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, fail);
            return;
        }
        try {
            var hidden = document.createElement('textarea');
            hidden.value = text;
            hidden.setAttribute('readonly', '');
            hidden.style.position = 'absolute';
            hidden.style.left = '-9999px';
            document.body.appendChild(hidden);
            hidden.select();
            document.execCommand('copy');
            document.body.removeChild(hidden);
            done();
        } catch (e) { fail(); }
    }

    var buttons = document.querySelectorAll('[data-ytb-copy]');
    Array.prototype.forEach.call(buttons, function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-ytb-copy') || '';
            var statusId = btn.getAttribute('data-ytb-copy-status') || '';
            var target = document.getElementById(targetId);
            var status = document.getElementById(statusId);
            if (!target) { return; }
            var text = target.textContent || target.value || '';
            var done = function () { if (status) { status.textContent = COPY_OK; } };
            var fail = function () { if (status) { status.textContent = COPY_FAIL; } };
            writeText(text, done, fail);
        });
    });
})();
</script>
        <?php
    }

    private function consume_revealed_token(string $kid): ?string
    {
        $key = self::REVEAL_TRANSIENT . $kid;
        /** @var mixed $value */
        $value = \get_transient($key);
        if (!is_string($value) || $value === '') {
            return null;
        }
        \delete_transient($key);
        return $value;
    }

    /**
     * Read the fallback-token payload (if any) from the settings_errors
     * transient. Consumed exactly once.
     */
    private function consume_fallback_token(): ?string
    {
        $errors = \get_transient('settings_errors');
        if (!is_array($errors)) {
            return null;
        }
        $token = null;
        $remaining = [];
        foreach ($errors as $entry) {
            if (
                is_array($entry)
                && ($entry['code'] ?? '') === 'ytb_mcp_fallback_token'
                && is_string($entry['message'] ?? null)
            ) {
                $token = (string) $entry['message'];
                continue;
            }
            $remaining[] = $entry;
        }
        if ($remaining === []) {
            \delete_transient('settings_errors');
        } else {
            \set_transient('settings_errors', $remaining, 30);
        }
        return $token;
    }

    private function generate_kid(): string
    {
        // 16 hex chars = 64 bits of entropy — sufficient for a kid namespace
        // scoped to a single site's keystore.
        return bin2hex(random_bytes(8));
    }

    private function expires_to_timestamp(string $preset, int $now): ?int
    {
        return match ($preset) {
            '90d' => $now + (90 * DAY_IN_SECONDS),
            '1y' => $now + YEAR_IN_SECONDS,
            'never' => null,
            default => $now + (90 * DAY_IN_SECONDS),
        };
    }

    /**
     * Enumerate REST routes filtered to our namespace. Sorted alphabetically
     * for deterministic display + assertion.
     *
     * @return list<string>
     */
    private function collect_rest_endpoints(): array
    {
        if (!function_exists('rest_get_server')) {
            return [];
        }
        try {
            $server = \rest_get_server();
            if (!is_object($server) || !method_exists($server, 'get_routes')) {
                return [];
            }
            /** @var mixed $routes */
            $routes = $server->get_routes();
            if (!is_array($routes)) {
                return [];
            }
            $prefix = '/yt-builder-mcp/v1';
            $out = [];
            foreach (array_keys($routes) as $route) {
                $route = (string) $route;
                if (str_starts_with($route, $prefix)) {
                    $out[] = $route;
                }
            }
            sort($out);
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Defensive: only log if SecurityLogger is callable in this context.
     *
     * @param array<string, mixed> $context
     */
    private function log_security_event(string $event, array $context): void
    {
        try {
            SecurityLogger::log($event, $context);
        } catch (\Throwable) {
            // Best-effort — never let the logger itself fatal the request.
        }
    }

    /**
     * Build the diagnostics body as a single GitHub-flavoured markdown
     * blob the user can paste straight into an Issue.
     *
     * Format: H2 "Diagnostics" → 3 sub-tables (Versions / Security /
     * REST surface) → a fenced code-block listing every registered
     * endpoint. No HTML, no smart quotes — must survive a roundtrip
     * through the OS clipboard and GitHub's markdown parser unchanged.
     *
     * @param list<string> $endpoints
     */
    private function build_diagnostics_markdown(
        string $pluginVersion,
        ?string $ytVersion,
        ?string $wpVersion,
        int $schemaVersion,
        bool $signingSecretPresent,
        int $bearerKeyCount,
        array $endpoints,
    ): string {
        $lines = [];
        $lines[] = '## YT Builder MCP for YOOtheme Pro (unofficial) — Diagnostics';
        $lines[] = '';
        $lines[] = '### Versions';
        $lines[] = '';
        $lines[] = '| Component | Version |';
        $lines[] = '| --- | --- |';
        $lines[] = '| Plugin | ' . $pluginVersion . ' |';
        $lines[] = '| YOOtheme Pro | ' . ($ytVersion ?? '—') . ' |';
        $lines[] = '| WordPress | ' . ($wpVersion ?? '—') . ' |';
        $lines[] = '| PHP | ' . PHP_VERSION . ' |';
        $lines[] = '| Schema | ' . (string) $schemaVersion . ' |';
        $lines[] = '';
        $lines[] = '### Security';
        $lines[] = '';
        $lines[] = '| Item | Status |';
        $lines[] = '| --- | --- |';
        $lines[] = '| Signing secret | ' . ($signingSecretPresent ? 'present (encrypted at rest)' : 'missing — regenerate on next key issue') . ' |';
        $lines[] = '| Bearer keys | ' . (string) $bearerKeyCount . ' |';
        $lines[] = '';
        $lines[] = '### REST surface';
        $lines[] = '';
        $lines[] = '| Item | Value |';
        $lines[] = '| --- | --- |';
        $lines[] = '| Endpoints | ' . (string) count($endpoints) . ' |';
        $healthUrl = \rest_url('yt-builder-mcp/v1/health');
        $lines[] = '| Probe URL | ' . $healthUrl . ' |';
        if ($endpoints !== []) {
            $lines[] = '';
            $lines[] = '<details><summary>Registered REST endpoints</summary>';
            $lines[] = '';
            $lines[] = '```';
            foreach ($endpoints as $endpoint) {
                $lines[] = $endpoint;
            }
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '</details>';
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Inline JS for the "Copy diagnostics as Markdown" button. Reads
     * the markdown blob from the button's data-attribute and writes it
     * to the clipboard via the async Clipboard API; falls back to a
     * hidden textarea + document.execCommand('copy') on browsers /
     * contexts where navigator.clipboard is unavailable (e.g. non-HTTPS
     * dev environments). Either way the adjacent status span is
     * updated for ~2.4s to confirm the copy.
     */
    private function print_copy_diagnostics_script(): void
    {
        ?>
<script>
(function () {
    var btn = document.getElementById('ytb-mcp-copy-diagnostics');
    var status = document.getElementById('ytb-mcp-copy-diagnostics-status');
    if (!btn || !status) { return; }
    btn.addEventListener('click', function () {
        var md = btn.getAttribute('data-ytb-mcp-markdown') || '';
        var done = function () {
            status.textContent = '<?php echo \esc_js(\__('Copied!', 'yt-builder-mcp')); ?>';
            setTimeout(function () { status.textContent = ''; }, 2400);
        };
        var fail = function () {
            status.style.color = '#dc3232';
            status.textContent = '<?php echo \esc_js(\__('Copy failed — select the text manually.', 'yt-builder-mcp')); ?>';
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(md).then(done, function () { fallback(md, done, fail); });
        } else {
            fallback(md, done, fail);
        }
    });
    function fallback(text, ok, ko) {
        try {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            var copied = document.execCommand('copy');
            document.body.removeChild(ta);
            copied ? ok() : ko();
        } catch (e) { ko(); }
    }
})();
</script>
        <?php
    }
}
