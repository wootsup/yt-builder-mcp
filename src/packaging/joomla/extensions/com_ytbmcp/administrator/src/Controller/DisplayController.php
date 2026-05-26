<?php
/**
 * com_ytbmcp default admin display controller.
 *
 * MVCFactory dispatches `?option=com_ytbmcp[&view=…]` to this controller
 * when no explicit `controller=` is given. Defaults to the dashboard
 * view (3-tab UI per cookbook §6.2).
 *
 * @license   GPL-2.0-or-later
 * @copyright (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Component\Ytbmcp\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;

class DisplayController extends BaseController
{
    /** @var string default view. */
    protected $default_view = 'dashboard';
}
