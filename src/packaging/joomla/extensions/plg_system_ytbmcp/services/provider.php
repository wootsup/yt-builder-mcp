<?php
/**
 * Service provider for plg_system_ytbmcp.
 *
 * Joomla 5/6 canonical pattern: each modern extension exposes a
 * `services/provider.php` returning a ServiceProviderInterface that
 * registers the extension's main class into the DI container under the
 * `Joomla\CMS\Extension\PluginInterface` key.
 *
 * @license   GPL-2.0-or-later
 * @copyright (C) 2026 getimo productions
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use WootsUp\Plugin\System\Ytbmcp\Extension\Ytbmcp;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $config  = (array) PluginHelper::getPlugin('system', 'ytbmcp');
                $subject = $container->get(DispatcherInterface::class);

                $plugin = new Ytbmcp($subject, $config);
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
