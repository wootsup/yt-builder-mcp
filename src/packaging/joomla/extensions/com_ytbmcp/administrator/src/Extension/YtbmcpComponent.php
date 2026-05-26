<?php
/**
 * com_ytbmcp administrator extension class.
 *
 * Joomla 5/6 canonical: each component has a top-level Extension class
 * that the DI ComponentInterface key resolves to. We extend
 * `MVCComponent` so the admin section gets standard MVC routing
 * (controller=dashboard&task=display etc.) for free.
 *
 * @license   GPL-2.0-or-later
 * @copyright (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Component\Ytbmcp\Administrator\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Extension\MVCComponent;

class YtbmcpComponent extends MVCComponent
{
}
