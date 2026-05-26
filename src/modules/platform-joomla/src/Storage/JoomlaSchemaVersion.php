<?php
/**
 * Joomla twin of {@see WootsUp\BuilderMcp\Storage\SchemaVersion}.
 *
 * Stamps the storage schema version (current: 1) in
 * `#__ytb_mcp_options(option_key='schema_version')` to seed forward
 * migrations. Called by the InstallerScript's postflight + as an idempotent
 * safety-net by the system plugin's onAfterInitialise hook.
 *
 * Cookbook reference: §1.3 (storage-schema bootstrap contract) +
 * §2.10 (schema-version slug + monotonic-bump rules) +
 * §4.6 (Joomla-side option-table install/uninstall lifecycle).
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\Storage
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\Storage;

defined('_JEXEC') or die;

final class JoomlaSchemaVersion
{
    public const OPTION_KEY     = 'schema_version';
    public const CURRENT_VERSION = 1;

    public static function ensure(?JoomlaOptionStore $store = null): void
    {
        $store ??= new JoomlaOptionStore();
        $store->add(self::OPTION_KEY, (string) self::CURRENT_VERSION);
    }

    public static function get(?JoomlaOptionStore $store = null): int
    {
        $store ??= new JoomlaOptionStore();
        $raw     = $store->get(self::OPTION_KEY, '0');
        return \is_string($raw) && \ctype_digit($raw) ? (int) $raw : 0;
    }
}
