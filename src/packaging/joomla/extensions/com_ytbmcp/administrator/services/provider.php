<?php
/**
 * Service provider for com_ytbmcp.
 *
 * Wires both administrator and API extension classes into the DI
 * container under their canonical Joomla 5/6 service keys.
 *
 * @license   GPL-2.0-or-later
 * @copyright (C) 2026 getimo productions
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ApiDispatcherFactoryInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use WootsUp\Component\Ytbmcp\Administrator\Extension\YtbmcpComponent;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new MVCFactory('\\WootsUp\\Component\\Ytbmcp'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\WootsUp\\Component\\Ytbmcp'));

        $container->set(
            ComponentInterface::class,
            static function (Container $container): ComponentInterface {
                $component = new YtbmcpComponent($container->get(ComponentDispatcherFactoryInterface::class));
                $component->setMVCFactory($container->get(MVCFactoryInterface::class));

                return $component;
            }
        );
    }
};
