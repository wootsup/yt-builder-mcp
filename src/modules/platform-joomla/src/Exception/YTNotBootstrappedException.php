<?php
/**
 * Thrown when a REST controller cannot acquire a YOOtheme Pro runtime
 * (`\YOOtheme\app()` is not defined). REST infrastructure maps this to
 * HTTP 503 with a structured `{error:"yt_not_bootstrapped", ...}`
 * envelope so customers see actionable error rather than a fatal.
 *
 * Background: cookbook §S2 (Spike S2 — 2026-05-24) discovered YT's
 * `template_bootstrap.php` allowlist excludes `com_api`. Our REST
 * controllers therefore lazy-bootstrap via {@see YtBootstrapper}; if
 * that fails (file missing, wrong permissions, future YT-update changes
 * the bootstrap contract), this exception is the failure-mode.
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Exception
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Exception;

defined('_JEXEC') or die;

final class YTNotBootstrappedException extends \RuntimeException
{
    public function __construct(
        string $reason = '',
        public readonly string $remediation = 'Restart YOOtheme Pro or check that the theme is installed and active. If the issue persists, contact support.'
    ) {
        parent::__construct(
            $reason === ''
                ? 'YOOtheme Pro is not bootstrapped for this request lifecycle (Web Services API does not auto-load YT).'
                : $reason
        );
    }
}
