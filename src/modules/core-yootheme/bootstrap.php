<?php
/**
 * core-yootheme module bootstrap.
 *
 * Defines the YoothemeAdapter facade that hides
 * `class_exists('\YOOtheme\…')` open-coding from every other module
 * (LayoutWriter, SourceRegistry, Inspector, HealthController,
 * CacheFlusher).
 *
 * The adapter is NOT registered with YOOtheme's DI-Container —
 * consumer bootstraps instantiate it on demand via
 * {@see \WootsUp\BuilderMcp\Util\Container::get()} (singleton cache).
 * See Container.php for the rationale.
 *
 * @package WootsUp\BuilderMcp\Yootheme
 */

declare(strict_types=1);

return [];
