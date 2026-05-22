<?php
/**
 * core-auth module bootstrap.
 *
 * Defines the Bearer-token authentication primitives (KeyService,
 * KeyStore, BearerVerifier, SigningSecret) under the
 * `WootsUp\BuilderMcp\Auth\` PSR-4 namespace.
 *
 * Services are NOT registered with YOOtheme's DI-Container — consumer
 * bootstraps (rest-bridge, platform-wordpress, builder-*) instantiate
 * them on demand via {@see \WootsUp\BuilderMcp\Util\Container::get()}.
 * See Container.php for the rationale (YT4 ParameterResolver cannot
 * auto-resolve untyped `$container` factory parameters).
 *
 * @package WootsUp\BuilderMcp\Auth
 */

declare(strict_types=1);

return [];
