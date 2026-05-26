<?php
/**
 * Admin Dashboard controller — Bearer-key lifecycle tasks.
 *
 * Wave-9 (2026-05-24): adds the `generateKey` + `revokeKey` tasks that the
 * Keys tab posts to. Joomla parity-twin of the WP-side
 * {@see \WootsUp\BuilderMcp\Platform\WordPress\SettingsPage::handle_generate}
 * + `handle_revoke`. Every mutating task:
 *
 *   1. enforces the admin capability gate (`core.admin` on com_ytbmcp),
 *   2. validates the Joomla CSRF token ({@see \Joomla\CMS\Session\Session::checkToken}),
 *   3. reads input via `$app->getInput()`,
 *   4. queues a user-facing message via `$app->enqueueMessage`,
 *   5. redirects back to the Keys tab.
 *
 * One-shot reveal: the freshly minted token + pickup nonce are stashed in
 * `$app->setUserState` and consumed exactly once by
 * {@see \WootsUp\Component\Ytbmcp\Administrator\View\Dashboard\HtmlView}.
 * This mirrors the WP transient-reveal so the token is shown ONCE and never
 * lands in the URL / browser history.
 *
 * @package    WootsUp\Component\Ytbmcp\Administrator\Controller
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Component\Ytbmcp\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use WootsUp\BuilderMcp\Auth\KeyService;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaAdminAccess;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaKeyStore;
use WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaSigningSecret;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaPickupChannel;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaTransientStore;
use WootsUp\BuilderMcp\Util\SecurityLogger;
use WootsUp\Component\Ytbmcp\Administrator\View\Dashboard\HtmlView;

class DashboardController extends BaseController
{
    /** @var string default view to load when no `view=` param is set. */
    protected $default_view = 'dashboard';

    /** Allowed expiry presets → seconds (null = never). */
    private const EXPIRY_PRESETS = ['90d', '1y', 'never'];

    /** Allowed scopes (matches BearerVerifier hierarchy). */
    private const SCOPES = ['read', 'write', 'admin'];

    /**
     * Mint a new Bearer key. POST task=generateKey from the Keys form.
     */
    public function generateKey(): void
    {
        $this->assertAdmin();
        $this->assertToken();

        $app   = Factory::getApplication();
        $input = $app->getInput();

        $label = \trim((string) $input->getString('label', ''));
        $scope = (string) $input->getCmd('scope', 'read');
        $expires = (string) $input->getCmd('expires', '90d');

        if (!\in_array($scope, self::SCOPES, true)) {
            $scope = 'read';
        }
        if (!\in_array($expires, self::EXPIRY_PRESETS, true)) {
            $expires = '90d';
        }

        $now = \time();
        $expiresAt = $this->expiresToTimestamp($expires, $now);
        $kid = \bin2hex(\random_bytes(8));

        $claims = [
            'scope' => $scope,
            'iss'   => $this->siteUrl(),
        ];
        if ($expiresAt !== null) {
            $claims['exp'] = $expiresAt;
        }

        // Defensive: a failed generate/register must surface a message,
        // never a white-screen fatal (parity with WP C3 try/catch).
        try {
            $keyService = new KeyService(JoomlaSigningSecret::ensure());
            $token = $keyService->generate($kid, $claims);

            (new JoomlaKeyStore())->register($kid, [
                'label'      => $label !== '' ? $label : 'Untitled',
                'scope'      => $scope,
                'created_at' => $now,
                'expires_at' => $expiresAt,
                'revoked_at' => null,
            ]);
        } catch (\Throwable $e) {
            $this->logEvent('key_generate_failed', ['reason' => $e->getMessage()]);
            $app->enqueueMessage(
                Text::sprintf('COM_YTBMCP_KEY_GENERATE_FAILED', $e->getMessage()),
                'error'
            );
            $this->redirectToKeys();
            return;
        }

        // Stash the one-shot reveal in user-state.
        $app->setUserState(HtmlView::STATE_REVEAL_TOKEN, $token);

        // Issue a pickup nonce for the "Copy AI prompt" flow (5-min TTL,
        // IP-bound, one-shot). Empty nonce → render-layer omits the CTA.
        $nonce = JoomlaPickupChannel::generateNonce();
        $issued = (new JoomlaPickupChannel(new JoomlaTransientStore()))->issue($nonce, [
            'token'    => $token,
            'site_url' => $this->siteUrl(),
            'ip'       => $this->remoteIp(),
            'ip_bound' => true,
        ]);
        if ($issued) {
            $app->setUserState(HtmlView::STATE_REVEAL_NONCE, $nonce);
        } else {
            $this->logEvent('pickup_transient_failed', ['kid' => $kid]);
            $app->setUserState(HtmlView::STATE_REVEAL_NONCE, '');
        }

        $app->enqueueMessage(Text::_('COM_YTBMCP_KEY_GENERATED_SUCCESS'), 'message');
        $this->redirectToKeys();
    }

    /**
     * Revoke an existing key. POST task=revokeKey from the keys table.
     */
    public function revokeKey(): void
    {
        $this->assertAdmin();
        $this->assertToken();

        $app = Factory::getApplication();
        $kid = \trim((string) $app->getInput()->getCmd('kid', ''));

        if ($kid !== '') {
            try {
                (new JoomlaKeyStore())->revoke($kid);
                $app->enqueueMessage(Text::_('COM_YTBMCP_KEY_REVOKED_SUCCESS'), 'message');
            } catch (\Throwable $e) {
                $this->logEvent('key_revoke_failed', ['kid' => $kid, 'reason' => $e->getMessage()]);
                $app->enqueueMessage(
                    Text::sprintf('COM_YTBMCP_KEY_REVOKE_FAILED', $e->getMessage()),
                    'error'
                );
            }
        }

        $this->redirectToKeys();
    }

    /**
     * Admin-only capability gate — parity with WP's `manage_options`.
     * Delegates to {@see JoomlaAdminAccess}, the single choke-point shared
     * with the admin View's render path, so both the dashboard render and
     * every mutating task enforce the SAME `core.admin`/`core.manage`
     * (on `com_ytbmcp`) rule and stay in lock-step. Throws a 403
     * {@see \Joomla\CMS\Access\Exception\NotAllowed} on denial.
     */
    private function assertAdmin(): void
    {
        JoomlaAdminAccess::assert(Factory::getApplication()->getIdentity());
    }

    /**
     * CSRF gate — Joomla canonical {@see Session::checkToken}. Throws a
     * 403-style RuntimeException on a missing/forged token.
     */
    private function assertToken(): void
    {
        if (!Session::checkToken('post')) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN_NOTICE'), 403);
        }
    }

    private function redirectToKeys(): void
    {
        Factory::getApplication()->redirect(
            Route::_('index.php?option=com_ytbmcp&view=dashboard&tab=' . HtmlView::TAB_KEYS, false)
        );
    }

    private function expiresToTimestamp(string $preset, int $now): ?int
    {
        return match ($preset) {
            '90d'   => $now + (90 * 86400),
            '1y'    => $now + (365 * 86400),
            'never' => null,
            default => $now + (90 * 86400),
        };
    }

    private function siteUrl(): string
    {
        return \rtrim((string) Uri::root(), '/');
    }

    private function remoteIp(): string
    {
        if (!isset($_SERVER['REMOTE_ADDR']) || !\is_string($_SERVER['REMOTE_ADDR'])) {
            return '';
        }
        return \trim($_SERVER['REMOTE_ADDR']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logEvent(string $event, array $context): void
    {
        try {
            SecurityLogger::log($event, $context);
        } catch (\Throwable) {
            // Best-effort — never let the logger fatal the request.
        }
    }
}
