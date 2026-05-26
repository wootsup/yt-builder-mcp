<?php
/**
 * Admin capability gate for the com_ytbmcp backend dashboard.
 *
 * Single choke-point shared by the admin View (render) and the
 * DashboardController (generateKey / revokeKey tasks). It is the Joomla
 * parity-twin of WP's `manage_options` gate on
 * {@see \WootsUp\BuilderMcp\Platform\WordPress\SettingsPage::render}:
 *
 *   - WP gates render + every mutating handler behind `manage_options`.
 *   - Joomla gates render + every mutating task behind `core.admin`
 *     (super-admin parity) OR `core.manage` (component-manager) on the
 *     `com_ytbmcp` asset.
 *
 * Both checks are scoped to the COMPONENT asset (`com_ytbmcp`), so a
 * site-admin who has been explicitly denied the component in
 * Global Config → Permissions is correctly locked out, and a delegated
 * manager who has been granted it is let in — exactly what the shipped
 * `access.xml` makes governable in Joomla's permission UI.
 *
 * This guard is the ADMIN-PANEL ACL only. It has nothing to do with the
 * REST/Bearer surface: the api controllers extend AbstractApiController
 * and are gated solely by the Bearer scope hierarchy (ADR
 * `l2-bearer-as-authority`). The two authorities never overlap.
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Auth
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Auth;

defined('_JEXEC') or die;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

final class JoomlaAdminAccess
{
    /** Component asset the capability checks are scoped to. */
    public const ASSET = 'com_ytbmcp';

    /** Super-admin / manage_options parity action. */
    public const ACTION_ADMIN = 'core.admin';

    /** Component-manager fallback action. */
    public const ACTION_MANAGE = 'core.manage';

    /**
     * Pure predicate: may this identity reach the admin dashboard + key
     * tasks? Accepts the result of `Factory::getApplication()->getIdentity()`
     * (a `Joomla\CMS\User\User`, or null when no user is bound).
     *
     * `core.admin` OR `core.manage` on the component asset is sufficient,
     * matching the actions declared in `access.xml`. A null identity, or
     * one without an `authorise()` method, is fail-closed to false.
     */
    public static function isAllowed(?object $identity): bool
    {
        if ($identity === null || !\method_exists($identity, 'authorise')) {
            return false;
        }

        return $identity->authorise(self::ACTION_ADMIN, self::ASSET)
            || $identity->authorise(self::ACTION_MANAGE, self::ASSET);
    }

    /**
     * Enforce the gate or throw. Resolves the current identity from the
     * application when one is not supplied (the render + task call-sites
     * pass it explicitly; the convenience no-arg form is for any future
     * caller). Throws a 403 {@see NotAllowed}, mirroring Joomla convention
     * (the dispatcher renders the standard "not authorised" error page).
     */
    public static function assert(?object $identity = null): void
    {
        if (\func_num_args() === 0) {
            $identity = self::currentIdentity();
        }

        if (!self::isAllowed($identity)) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * Resolve the bound identity from the Joomla application, tolerating a
     * runtime where the application or getIdentity() is unavailable.
     */
    private static function currentIdentity(): ?object
    {
        $app = Factory::getApplication();
        if (!\method_exists($app, 'getIdentity')) {
            return null;
        }
        $identity = $app->getIdentity();

        return \is_object($identity) ? $identity : null;
    }
}
