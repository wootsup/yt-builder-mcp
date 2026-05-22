<?php
/**
 * builder-state module bootstrap.
 *
 * Defines the canonical read window into `wp_option('yootheme')`
 * (LayoutReader), the RFC-6901 JSON-Pointer evaluator, and the
 * per-template StateLock used by LayoutWriter to serialise concurrent
 * writes.
 *
 * Services are NOT registered with YOOtheme's DI-Container — consumer
 * bootstraps (builder-pages, builder-elements, builder-source-binding)
 * instantiate them on demand via
 * {@see \WootsUp\BuilderMcp\Util\Container::get()}. See Container.php
 * for the rationale.
 *
 * @package WootsUp\BuilderMcp\State
 */

declare(strict_types=1);

return [];
