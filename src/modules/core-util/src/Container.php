<?php
/**
 * Minimal singleton-cache for plugin services.
 *
 * Replaces YOOtheme's DI-Container (Pimple wrapped by YT's own
 * Container/ParameterResolver). YT 4's `Container::resolveService()`
 * invokes the registered factory closure through `ParameterResolver`,
 * which fails to auto-resolve untyped `$container` parameters such as
 * `static function ($container) { … }` because the Container is not
 * registered as a service under its own class-name. The runtime
 * exception we hit on yootheme-pro 4.5.33 looks like:
 *
 *   RuntimeException: Can't resolve Parameter #0 [ <required> $container ]
 *   for YOOtheme\Application::{closure}() in platform-wordpress/bootstrap.php
 *
 * Instead of registering services with YT's container we keep our own
 * tiny lazy-singleton cache. Bootstraps still get loaded via
 * `app()->load(.../bootstrap.php)` because we want the side-effects
 * (the `add_action()` calls) — we just instantiate our services
 * ourselves.
 *
 * Usage:
 *   $svc = Container::get(SomeClass::class, fn () => new SomeClass(...));
 *
 * @package WootsUp\BuilderMcp\Util
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Util;

final class Container
{
    /** @var array<class-string, object> */
    private static array $instances = [];

    /**
     * Lazily resolve a singleton instance keyed by its FQCN.
     *
     * The factory is invoked exactly once per process; subsequent calls
     * return the cached instance.
     *
     * @template T of object
     * @param class-string<T> $id
     * @param callable(): T   $factory
     * @return T
     */
    public static function get(string $id, callable $factory): object
    {
        if (!isset(self::$instances[$id])) {
            self::$instances[$id] = $factory();
        }

        /** @var T */
        return self::$instances[$id];
    }

    /**
     * Drop every cached instance. Intended for tests only.
     */
    public static function reset(): void
    {
        self::$instances = [];
    }
}
