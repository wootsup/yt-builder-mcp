<?php
/**
 * com_ytbmcp API extension class.
 *
 * Joomla 5/6: the API section uses a separate Extension class to surface
 * `ApiMVCFactoryInterface`-resolved controllers via the Web Services API
 * router. Routes registered in plg_system_ytbmcp's onBeforeApiRoute set
 * `component => com_ytbmcp`; the ApiRouter then instantiates this class
 * and dispatches to the matched controller.
 *
 * @license   GPL-2.0-or-later
 * @copyright (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\Component\Ytbmcp\Api\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Extension\MVCComponent;

class YtbmcpComponent extends MVCComponent
{
}
