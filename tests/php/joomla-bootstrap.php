<?php
/**
 * PHPUnit bootstrap for the `joomla` testsuite.
 *
 * Defines the Joomla runtime sentinels (`_JEXEC`, JPATH_*) that
 * platform-joomla source files guard on, loads the composer autoloader,
 * and pulls in the Joomla CMS stubs (Factory / DatabaseInterface /
 * ParameterType / CMSPlugin / InstallerScript / …).
 *
 * Tests reset the in-memory DB / factory between cases by calling
 * `\MockJoomlaFactory::reset()` in setUp().
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// =========================================================================
// Joomla runtime sentinels
// =========================================================================

if (!defined('_JEXEC')) {
    define('_JEXEC', 1);
}

if (!defined('JPATH_ROOT')) {
    define('JPATH_ROOT', sys_get_temp_dir() . '/ytb-mcp-test-jroot');
}

if (!defined('JPATH_SITE')) {
    define('JPATH_SITE', JPATH_ROOT);
}

if (!defined('JPATH_ADMINISTRATOR')) {
    define('JPATH_ADMINISTRATOR', JPATH_ROOT . '/administrator');
}

if (!defined('JPATH_LIBRARIES')) {
    define('JPATH_LIBRARIES', JPATH_ROOT . '/libraries');
}

if (!defined('JPATH_PLUGINS')) {
    define('JPATH_PLUGINS', JPATH_ROOT . '/plugins');
}

if (!defined('JPATH_CACHE')) {
    define('JPATH_CACHE', sys_get_temp_dir() . '/ytb-mcp-test-jcache');
}

if (!defined('YTB_MCP_VERSION')) {
    define('YTB_MCP_VERSION', '0.1.0-alpha.1');
}

// =========================================================================
// Load Joomla CMS stubs (Factory / DatabaseInterface / ParameterType / …)
// =========================================================================

require_once __DIR__ . '/joomla-stubs/JoomlaCmsStubs.php';

// =========================================================================
// Per-suite test helpers
// =========================================================================

/**
 * Convenience: install a fresh MockJoomlaDatabase into the Joomla DI
 * container for the current test. Returns the registered instance.
 */
if (!function_exists('ytb_test_install_mock_db')) {
    function ytb_test_install_mock_db(): \MockJoomlaDatabase
    {
        \MockJoomlaFactory::reset();
        // Use the typed-bridge subclass so production return-types like
        // `: DatabaseInterface` accept the mock.
        $db = new \MockJoomlaDatabaseTypedBridge();
        \MockJoomlaContainer::register('Joomla\\Database\\DatabaseInterface', $db);
        return $db;
    }
}

/**
 * Convenience: in-memory option-store backing for JoomlaOptionStore tests.
 * Replaces the DB-backed lookup with a simple array so tests don't need
 * to model the full SQL surface for each get/set/add/delete round-trip.
 */
if (!function_exists('ytb_test_option_store_proxy')) {
    /**
     * Build an in-memory option-store stand-in. Returned object exposes
     * the same surface as JoomlaOptionStore (get / set / add / delete)
     * — the four methods JoomlaKeyStore and JoomlaSigningSecret need.
     */
    function ytb_test_option_store_proxy(): object
    {
        return new class {
            /** @var array<string, string> */
            public array $store = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            public function set(string $key, string $value, ?bool $autoload = null): bool
            {
                $this->store[$key] = $value;
                return true;
            }

            public function add(string $key, string $value, bool $autoload = false): bool
            {
                if (array_key_exists($key, $this->store)) {
                    return false;
                }
                $this->store[$key] = $value;
                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);
                return true;
            }
        };
    }
}
