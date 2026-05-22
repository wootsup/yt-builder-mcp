<?php
/**
 * builder-cache module bootstrap.
 *
 * Wave 3 Task 3.5. Defines the CacheFlusher service under the
 * `WootsUp\BuilderMcp\Cache\` PSR-4 namespace. Consumer bootstraps
 * (builder-pages, builder-elements, builder-source-binding) instantiate
 * it on demand via {@see \WootsUp\BuilderMcp\Util\Container::get()};
 * write-endpoint controllers invoke flush() after every successful
 * update_option. See Container.php for the rationale.
 *
 * @package WootsUp\BuilderMcp\Cache
 */

declare(strict_types=1);

return [];
