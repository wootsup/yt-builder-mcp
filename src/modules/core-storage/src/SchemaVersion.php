<?php
/**
 * SchemaVersion — plugin storage schema version stamp.
 *
 * Tracks the on-disk schema version for the plugin's wp_option-stored data
 * (keystore envelope, rate-limiter transients, signing-secret envelope, etc).
 * When a future release changes the storage layout, a migration routine reads
 * the version, runs the diff, and bumps the version. Forward compatibility
 * requires this stamp to exist from day one.
 *
 * Current schema version: 1 (initial — keystore versioned envelope,
 * AES-256-GCM signing-secret).
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Storage
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Storage;

final class SchemaVersion
{
    public const CURRENT_VERSION = 1;

    public const OPTION_KEY = 'ytb_mcp_schema_version';

    /**
     * Read the persisted schema version. Returns 0 if no stamp exists
     * (fresh install pre-ensure(), or wiped option).
     */
    public static function get(): int
    {
        if (!function_exists('get_option')) {
            return 0;
        }
        $value = get_option(self::OPTION_KEY, 0);
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Bump the persisted schema version. Used by migration routines after
     * a schema-change transformation runs.
     *
     * @throws \InvalidArgumentException when $version is not positive.
     */
    public static function bump(int $version): void
    {
        if ($version < 1) {
            throw new \InvalidArgumentException(
                sprintf('SchemaVersion::bump() requires positive int, got %d.', $version)
            );
        }
        if (!function_exists('update_option')) {
            return;
        }
        update_option(self::OPTION_KEY, $version, false);
    }

    /**
     * Ensure a schema-version stamp exists. Called from the plugin's
     * activation hook (and idempotently at bootstrap for pre-activation
     * installs). Adds the stamp only if absent — never overwrites.
     */
    public static function ensure(): void
    {
        if (!function_exists('get_option') || !function_exists('add_option')) {
            return;
        }
        $current = get_option(self::OPTION_KEY, null);
        if ($current === null || $current === false) {
            // add_option fails (returns false) if the key already exists — that's
            // the safe behavior we want.
            add_option(self::OPTION_KEY, self::CURRENT_VERSION, '', false);
        }
    }
}
