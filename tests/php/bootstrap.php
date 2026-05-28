<?php
/**
 * PHPUnit test bootstrap for the unit test suite.
 *
 * Provides minimal in-process WordPress function stubs (get_option /
 * update_option / delete_option) so that the wp_options-backed wrappers
 * (KeyStore, SigningSecret) can be tested without spinning up the full
 * WP-Testbench. The stubs are deliberately minimal — anything more
 * realistic belongs in tests/php/integration/ with WP_UnitTestCase.
 *
 * Tests are expected to reset `$GLOBALS['ytb_test_options']` between cases
 * (e.g. in setUp()).
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('YTB_MCP_VERSION')) {
    define('YTB_MCP_VERSION', '0.1.0-alpha.1');
}

if (!defined('AUTH_KEY')) {
    // Provide a deterministic test-only AUTH_KEY so SigningSecret's
    // encrypt-at-rest path exercises in tests without flooding the test
    // output with "AUTH_KEY missing" warnings. NOT a real-world key.
    define('AUTH_KEY', 'ytb-mcp-test-suite-auth-key-deterministic-value-do-not-use-in-prod');
}

if (!isset($GLOBALS['ytb_test_options'])) {
    $GLOBALS['ytb_test_options'] = [];
}

if (!function_exists('get_option')) {
    /**
     * @param mixed $default
     *
     * @return mixed
     */
    function get_option(string $option, $default = false)
    {
        return $GLOBALS['ytb_test_options'][$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    /**
     * @param mixed $value
     */
    function update_option(string $option, $value, ?bool $autoload = null): bool
    {
        // Faithful WP contract: update_option applies the
        // `pre_update_option_{$option}` value filter BEFORE persisting, so
        // tests model the real filter chain (e.g. YOOtheme's / an mu-plugin's
        // json-encoder, and LayoutWriter's own pin filter). add_option does
        // NOT run this filter — matching WordPress.
        if (function_exists('apply_filters')) {
            /** @var mixed $old */
            $old = $GLOBALS['ytb_test_options'][$option] ?? false;
            /** @var mixed $value */
            $value = apply_filters('pre_update_option_' . $option, $value, $old, $option);
        }
        // Test sentinel: when `ytb_test_update_option_force_return` is set,
        // the stub returns the configured value (typically false to emulate
        // the "no-op" return path) WHILE still persisting the value. This
        // lets LayoutWriter's T6 verify-read logic prove it does not throw
        // on a no-op write that already holds the expected value.
        $GLOBALS['ytb_test_options'][$option] = $value;
        if (array_key_exists('ytb_test_update_option_force_return', $GLOBALS)) {
            /** @var bool $forced */
            $forced = $GLOBALS['ytb_test_update_option_force_return'];
            return $forced;
        }
        return true;
    }
}

if (!function_exists('add_option')) {
    /**
     * @param mixed $value
     */
    function add_option(string $option, $value = '', string $deprecated = '', ?bool $autoload = null): bool
    {
        // Track add_option calls so T6 R-01 tests can pin "first-write uses
        // add_option with explicit autoload=false".
        if (!isset($GLOBALS['ytb_test_add_option_calls']) || !is_array($GLOBALS['ytb_test_add_option_calls'])) {
            $GLOBALS['ytb_test_add_option_calls'] = [];
        }
        $GLOBALS['ytb_test_add_option_calls'][] = [
            'option' => $option,
            'autoload' => $autoload,
        ];
        if (array_key_exists($option, $GLOBALS['ytb_test_options'])) {
            return false;
        }
        $GLOBALS['ytb_test_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        $existed = array_key_exists($option, $GLOBALS['ytb_test_options']);
        unset($GLOBALS['ytb_test_options'][$option]);
        return $existed;
    }
}

if (!isset($GLOBALS['ytb_test_transients'])) {
    $GLOBALS['ytb_test_transients'] = [];
}

if (!function_exists('get_transient')) {
    /**
     * @return mixed
     */
    function get_transient(string $key)
    {
        $entry = $GLOBALS['ytb_test_transients'][$key] ?? null;
        if ($entry === null) {
            return false;
        }
        if (isset($entry['expires']) && $entry['expires'] > 0 && $entry['expires'] < time()) {
            unset($GLOBALS['ytb_test_transients'][$key]);
            return false;
        }
        return $entry['value'];
    }
}

if (!function_exists('set_transient')) {
    /**
     * @param mixed $value
     */
    function set_transient(string $key, $value, int $ttl = 0): bool
    {
        // Test sentinel: when `ytb_test_transient_force_fail` is truthy, the
        // stub returns false to emulate a broken object-cache backend (full
        // disk / restricted host / Redis down). Used by failure-path tests
        // for PickupChannel + SettingsPage::handle_generate.
        if (!empty($GLOBALS['ytb_test_transient_force_fail'])) {
            return false;
        }
        $GLOBALS['ytb_test_transients'][$key] = [
            'value' => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ];
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        $existed = isset($GLOBALS['ytb_test_transients'][$key]);
        unset($GLOBALS['ytb_test_transients'][$key]);
        return $existed;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        unset($GLOBALS['ytb_test_wp_cache'][$group][$key]);
        if (!isset($GLOBALS['ytb_test_cache_delete_calls']) || !is_array($GLOBALS['ytb_test_cache_delete_calls'])) {
            $GLOBALS['ytb_test_cache_delete_calls'] = [];
        }
        $GLOBALS['ytb_test_cache_delete_calls'][] = ['key' => $key, 'group' => $group];
        return true;
    }
}

if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush(): bool
    {
        $GLOBALS['ytb_test_wp_cache'] = [];
        return true;
    }
}

if (!function_exists('wp_json_encode')) {
    /**
     * @param mixed $data
     *
     * @return string|false
     */
    function wp_json_encode($data, int $options = 0, int $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}

if (!isset($GLOBALS['ytb_test_filters']) || !is_array($GLOBALS['ytb_test_filters'])) {
    $GLOBALS['ytb_test_filters'] = [];
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['ytb_test_filters'][$hook][] = [
            'cb' => $callback,
            'priority' => $priority,
            'args' => $accepted_args,
        ];
        return true;
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter(string $hook, callable $callback, int $priority = 10): bool
    {
        if (!isset($GLOBALS['ytb_test_filters'][$hook]) || !is_array($GLOBALS['ytb_test_filters'][$hook])) {
            return false;
        }
        foreach ($GLOBALS['ytb_test_filters'][$hook] as $i => $entry) {
            if ($entry['cb'] === $callback && (int) $entry['priority'] === $priority) {
                unset($GLOBALS['ytb_test_filters'][$hook][$i]);
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * Minimal but faithful: runs registered callbacks in ascending priority,
     * threading the value through, honouring each callback's accepted_args
     * (0 → called with no args, matching WordPress' pin-filter idiom).
     *
     * @param mixed $value
     * @param mixed ...$args
     *
     * @return mixed
     */
    function apply_filters(string $hook, $value, ...$args)
    {
        if (empty($GLOBALS['ytb_test_filters'][$hook]) || !is_array($GLOBALS['ytb_test_filters'][$hook])) {
            return $value;
        }
        $entries = array_values($GLOBALS['ytb_test_filters'][$hook]);
        usort($entries, static fn (array $a, array $b): int => (int) $a['priority'] <=> (int) $b['priority']);
        foreach ($entries as $entry) {
            $accepted = (int) $entry['args'];
            if ($accepted === 0) {
                $callArgs = [];
            } else {
                $callArgs = array_slice(array_merge([$value], $args), 0, $accepted);
            }
            /** @var mixed $value */
            $value = ($entry['cb'])(...$callArgs);
        }
        return $value;
    }
}

if (!function_exists('get_site_url')) {
    function get_site_url(): string
    {
        return $GLOBALS['ytb_test_site_url'] ?? 'https://example.test';
    }
}

if (!function_exists('get_home_url')) {
    function get_home_url(): string
    {
        return $GLOBALS['ytb_test_home_url'] ?? 'https://example.test';
    }
}

if (!function_exists('random_bytes')) {
    // PHP 7.0+ ships random_bytes — defensive stub for partial-mock scenarios.
    function random_bytes(int $n): string
    {
        return str_repeat("\x00", $n);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        // WP-faithful stub: strip tags + collapse whitespace + trim. Sufficient
        // for unit-test inputs (XSS payloads must be reflected-but-escaped,
        // not silently swallowed).
        $stripped = strip_tags($str);
        $collapsed = preg_replace('/\s+/u', ' ', $stripped) ?? $stripped;
        return trim($collapsed);
    }
}

if (!function_exists('esc_js')) {
    function esc_js(string $text): string
    {
        // Minimal escaper for JS string-literals inside HTML attributes
        return strtr($text, ['\\' => '\\\\', "'" => "\\'", '"' => '\\"', "\n" => '\\n', "\r" => '', '</' => '<\\/']);
    }
}

if (!function_exists('wp_kses')) {
    /**
     * Test stub for wp_kses — hardened to mimic the REAL WordPress
     * implementation along four divergence classes the previous stub
     * silently ignored (R8-A4 audit 2026-05-27 findings A4-2/A4-3):
     *
     *   (a) PER-TAG attribute allow-list — real wp_kses strips any
     *       attribute NOT in `wp_kses_allowed_html` for THAT tag. The
     *       previous stub only scrubbed on*= + javascript: globally,
     *       so a malicious `<a onclick="…">` got the on* stripped but
     *       a `<a style="…">` (not on the customer-friendly allow-list
     *       for `<a>`) survived even though real wp_kses would strip it.
     *
     *   (b) RECURSIVE ENTITY-DECODE on URI attributes — real wp_kses
     *       runs `wp_kses_bad_protocol` which decodes entities (numeric
     *       like `&#x6a;` and named like `&colon;`) before testing the
     *       protocol. The previous stub's lowercase + strncmp on the
     *       RAW value let `&#x6a;avascript:` through, defeating the
     *       defence.
     *
     *   (c) HTML-COMMENT stripping — real wp_kses removes `<!-- … -->`
     *       comment blocks entirely. The previous stub passed them
     *       through, which could carry IE-style conditional payloads
     *       (`<!--[if IE]><script>…</script><![endif]-->`).
     *
     *   (d) MALFORMED-TAG state-machine reconstitution — real wp_kses
     *       parses character-by-character so a payload like
     *       `<scr<script>ipt>alert(1)</script>` reduces to nothing
     *       after the inner `<script>` is stripped (the outer `<scr` is
     *       not a valid tag and gets dropped). The previous stub used
     *       a single-pass regex on `<script>` which left the OUTER
     *       fragment intact.
     *
     * The stub is intentionally NOT a copy of PropSanitizer's
     * fallbackSanitize() — that path is what runs when wp_kses is
     * absent, and a tautological stub would mean tests on the WP path
     * are validating the SAME logic the Joomla fallback validates,
     * which the audit flagged as `live-green != tested-green`. The
     * divergence-class tests in tests/php/unit/Util/PropSanitizerWpKsesDivergenceTest.php
     * prove the WP-vs-fallback contract differs along (a)-(d).
     *
     * @param array<string, array<string, bool>> $allowed_html
     * @param array<string>                      $allowed_protocols
     */
    function wp_kses(string $content, array $allowed_html = [], array $allowed_protocols = []): string
    {
        // (c) Strip HTML comments — real wp_kses removes <!-- ... --> blocks
        // before tag-parsing. Drop both balanced and trailing comments.
        $content = (string) preg_replace('/<!--.*?-->/s', '', $content);
        // Also drop any unclosed trailing comment (defence-in-depth).
        $content = (string) preg_replace('/<!--.*$/s', '', $content);

        // (d) Malformed-tag state-machine pre-pass: iteratively eliminate
        // forbidden block tags (script/iframe/object/embed/style/svg/...)
        // INCLUDING their content. Repeat until stable so payloads like
        // `<scr<script>ipt>X</script>` collapse fully (the outer `<scr`
        // becomes orphaned and the next-pass strip_tags drops it).
        $forbidden = [
            'script', 'iframe', 'object', 'embed', 'style', 'svg',
            'link', 'meta', 'form', 'input', 'button', 'textarea',
            'select', 'option', 'applet', 'frame', 'frameset',
            'audio', 'video', 'source',
        ];
        do {
            $previous = $content;
            foreach ($forbidden as $tag) {
                $content = (string) preg_replace(
                    '#<' . $tag . '\b[^>]*>.*?(</' . $tag . '\s*>|$)#is',
                    '',
                    $content,
                );
                $content = (string) preg_replace(
                    '#<' . $tag . '\b[^>]*/?>#i',
                    '',
                    $content,
                );
            }
        } while ($content !== $previous);

        // Allow-list pass — drops every tag not on the allow-list.
        $allowedTags = '';
        foreach (array_keys($allowed_html) as $tag) {
            $allowedTags .= '<' . $tag . '>';
        }
        $content = strip_tags($content, $allowedTags);

        // (a) Per-tag attribute allow-list + (b) recursive entity-decode on
        // URI-bearing attributes. Walk every surviving tag and:
        //   - drop attributes not in the per-tag allow-list
        //     ($allowed_html[$tag] map)
        //   - drop attributes whose decoded value uses javascript:/vbscript:/
        //     data:text/html — entities decoded recursively per wp_kses_bad_protocol.
        $scrubbed = preg_replace_callback(
            '#<([a-z][a-z0-9]*)([^>]*)>#i',
            static function (array $m) use ($allowed_html): string {
                $tag = strtolower($m[1]);
                $allowedAttrs = isset($allowed_html[$tag]) && is_array($allowed_html[$tag])
                    ? $allowed_html[$tag]
                    : [];

                $attrs = preg_replace_callback(
                    '#\s+([a-z][a-z0-9_-]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i',
                    static function (array $am) use ($allowedAttrs): string {
                        $attrName = strtolower($am[1]);
                        $rawVal = $am[3] ?? ($am[4] ?? ($am[5] ?? ''));
                        $quote = isset($am[3]) ? '"' : (isset($am[4]) ? '\'' : '"');

                        // (a) per-tag allow-list: only keep attrs whose key
                        // is on the allow-list for this tag. event-handlers
                        // (on*) are never on the customer-friendly map.
                        if (!array_key_exists($attrName, $allowedAttrs)) {
                            return '';
                        }

                        // (b) recursive entity-decode on the value, then
                        // test for dangerous URI schemes. wp_kses_bad_protocol
                        // calls _bad_protocol_once iteratively until stable.
                        $decoded = $rawVal;
                        $prev = '';
                        $rounds = 0;
                        while ($decoded !== $prev && $rounds < 6) {
                            $prev = $decoded;
                            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            // Also handle bare numeric-decimal / hex entities
                            // that survive html_entity_decode (e.g. without
                            // the trailing semicolon).
                            $decoded = (string) preg_replace_callback(
                                '/&#(x[0-9a-f]+|[0-9]+);?/i',
                                static function (array $em): string {
                                    $code = $em[1];
                                    if (stripos($code, 'x') === 0) {
                                        $n = hexdec(substr($code, 1));
                                    } else {
                                        $n = (int) $code;
                                    }
                                    return $n > 0 && $n < 0x110000
                                        ? mb_chr($n, 'UTF-8')
                                        : '';
                                },
                                $decoded,
                            );
                            $rounds++;
                        }
                        $lower = ltrim(strtolower(trim($decoded)));
                        // Strip whitespace inside the protocol (real
                        // wp_kses normalises before the strncmp).
                        $lowerNoWs = (string) preg_replace('/\s+/', '', $lower);
                        if (
                            strncmp($lowerNoWs, 'javascript:', 11) === 0
                            || strncmp($lowerNoWs, 'vbscript:', 9) === 0
                            || strncmp($lowerNoWs, 'data:text/html', 14) === 0
                        ) {
                            return '';
                        }
                        return ' ' . $attrName . '=' . $quote . $rawVal . $quote;
                    },
                    $m[2],
                );
                return '<' . $tag . (string) $attrs . '>';
            },
            $content,
        );
        return is_string($scrubbed) ? $scrubbed : $content;
    }
}

if (!function_exists('settings_errors')) {
    function settings_errors(string $setting = '', bool $sanitize = false, bool $hide_on_update = false): void
    {
        // No-op stub: SettingsPage::render() calls this to flush admin
        // notices from add_settings_error(). Tests don't assert on the
        // notice surface — they assert on the page-render contract.
    }
}

if (!function_exists('add_settings_error')) {
    function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
    {
        if (!isset($GLOBALS['ytb_test_settings_errors']) || !is_array($GLOBALS['ytb_test_settings_errors'])) {
            $GLOBALS['ytb_test_settings_errors'] = [];
        }
        $GLOBALS['ytb_test_settings_errors'][] = [
            'setting' => $setting,
            'code' => $code,
            'message' => $message,
            'type' => $type,
        ];
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $special_chars = true, bool $extra_special_chars = false): string
    {
        return bin2hex(random_bytes((int) ceil($length / 2)));
    }
}

if (!function_exists('add_query_arg')) {
    /**
     * Minimal stub: appends an associative array of query args to a URL.
     * Mirrors the WP signature `add_query_arg(array $args, string $url)`.
     *
     * @param array<string, string|int> $args
     */
    function add_query_arg(array $args, string $url): string
    {
        $sep = str_contains($url, '?') ? '&' : '?';
        $pairs = [];
        foreach ($args as $key => $value) {
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }
        return $url . $sep . implode('&', $pairs);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        $out = strtolower($key);
        return (string) preg_replace('/[^a-z0-9_\-]/', '', $out);
    }
}

if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return 'https://example.test/wp-json/' . ltrim($path, '/');
    }
}

if (!isset($GLOBALS['ytb_test_menu_pages'])) {
    $GLOBALS['ytb_test_menu_pages'] = [];
}
if (!isset($GLOBALS['ytb_test_submenu_pages'])) {
    $GLOBALS['ytb_test_submenu_pages'] = [];
}

if (!function_exists('add_menu_page')) {
    /**
     * @param callable|string $callback
     */
    function add_menu_page(
        string $page_title,
        string $menu_title,
        string $capability,
        string $menu_slug,
        $callback = '',
        string $icon_url = '',
        ?int $position = null,
    ): string {
        $GLOBALS['ytb_test_menu_pages'][$menu_slug] = [
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'slug' => $menu_slug,
            'icon' => $icon_url,
            'position' => $position,
        ];
        return 'toplevel_page_' . $menu_slug;
    }
}

if (!function_exists('add_submenu_page')) {
    /**
     * @param callable|string $callback
     */
    function add_submenu_page(
        string $parent_slug,
        string $page_title,
        string $menu_title,
        string $capability,
        string $menu_slug,
        $callback = '',
        ?int $position = null,
    ): string {
        $GLOBALS['ytb_test_submenu_pages'][] = [
            'parent' => $parent_slug,
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'slug' => $menu_slug,
        ];
        return $parent_slug . '_page_' . $menu_slug;
    }
}

if (!function_exists('__return_null')) {
    function __return_null(): mixed
    {
        return null;
    }
}

/**
 * H3 — TEST-STUBS-1: stubs previously defined inline (per-test eval) in
 * SettingsPageTest / SettingsPageTabsTest, hoisted here so PHPStan can see
 * them and so duplicate evals across test files don't fight over the
 * global symbol table. `current_user_can` is data-driven from
 * `$GLOBALS['ytb_test_cap_allowed']` so tests can toggle gate behaviour.
 */
if (!function_exists('current_user_can')) {
    function current_user_can(string $cap): bool
    {
        return $GLOBALS['ytb_test_cap_allowed'] ?? true;
    }
}

if (!function_exists('wp_die')) {
    /**
     * @param array<string, mixed>|string|int $args
     */
    function wp_die(string $msg = '', string $title = '', $args = []): void
    {
        throw new \RuntimeException('wp_die: ' . $msg);
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = ''): string
    {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = ''): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = ''): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return $url;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . $path;
    }
}

if (!function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string
    {
        return 'https://example.test/wp-content/plugins/yt-builder-mcp/' . $path;
    }
}

if (!defined('YTB_MCP_FILE')) {
    define('YTB_MCP_FILE', '/test/wp-content/plugins/yt-builder-mcp/yt-builder-mcp.php');
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action): void
    {
        echo '<input type="hidden" name="_wpnonce" />';
    }
}

if (!function_exists('submit_button')) {
    function submit_button(string $text = 'Submit', string $type = 'primary'): void
    {
        echo '<button class="' . htmlspecialchars($type) . '">' . $text . '</button>';
    }
}

if (!function_exists('date_i18n')) {
    function date_i18n(string $fmt, int $ts): string
    {
        return date($fmt, $ts);
    }
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('YEAR_IN_SECONDS')) {
    define('YEAR_IN_SECONDS', 31536000);
}

/**
 * Minimal REST-server stub for unit-testing HealthController's endpoint
 * introspection without spinning up WP-Testbench. Tests can populate
 * `$GLOBALS['ytb_test_rest_routes']` with a list of route paths; the stub
 * returns an associative array `[route => []]` shaped like the real
 * WP_REST_Server::get_routes() return value.
 */
if (!isset($GLOBALS['ytb_test_rest_routes'])) {
    $GLOBALS['ytb_test_rest_routes'] = [];
}

require_once __DIR__ . '/stubs/RestServerStub.php';
require_once __DIR__ . '/TestVerifierFactory.php';

if (!function_exists('rest_get_server')) {
    function rest_get_server(): \WootsUp\BuilderMcp\Tests\Stub\RestServerStub
    {
        return new \WootsUp\BuilderMcp\Tests\Stub\RestServerStub();
    }
}

/**
 * Minimal WP_REST_Request stub — just enough for headers + body params so
 * Wave-3 write-endpoint tests can drive controllers without WP-Testbench.
 */
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request implements \ArrayAccess
    {
        /** @var array<string, string> */
        private array $headers = [];
        /** @var array<string, mixed> */
        private array $params = [];
        private string $method;
        private string $route;

        public function __construct(string $method = 'GET', string $route = '')
        {
            $this->method = $method;
            $this->route = $route;
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_header(string $key): ?string
        {
            $key = strtolower($key);
            return $this->headers[$key] ?? null;
        }

        /**
         * @param mixed $value
         */
        public function set_param(string $key, $value): void
        {
            $this->params[$key] = $value;
        }

        /**
         * @return mixed
         */
        public function get_param(string $key)
        {
            return $this->params[$key] ?? null;
        }

        /**
         * @return array<string, mixed>
         */
        public function get_params(): array
        {
            return $this->params;
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }

        /**
         * Array-access shorthand used by controllers (`$req['key']`).
         */
        #[\ReturnTypeWillChange]
        public function offsetGet(mixed $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        public function offsetExists(mixed $key): bool
        {
            return array_key_exists($key, $this->params);
        }

        public function offsetSet(mixed $key, mixed $value): void
        {
            if ($key === null) {
                $this->params[] = $value;
            } else {
                $this->params[$key] = $value;
            }
        }

        public function offsetUnset(mixed $key): void
        {
            unset($this->params[$key]);
        }

        /**
         * @return array<string, mixed>
         */
        public function get_json_params(): array
        {
            return $this->params;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /** @var mixed */
        public $data;
        public int $status;
        /** @var array<string, string> */
        private array $headers = [];

        /**
         * @param mixed $data
         */
        public function __construct($data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        /**
         * @return mixed
         */
        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function header(string $key, string $value): void
        {
            $this->headers[$key] = $value;
        }

        /**
         * @return array<string, string>
         */
        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public string $code;
        public string $message;
        /** @var array<string, mixed>|mixed */
        public $data;

        /**
         * @param array<string, mixed>|mixed $data
         */
        public function __construct(string $code = '', string $message = '', $data = [])
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /**
         * @return array<string, mixed>|mixed
         */
        public function get_error_data()
        {
            return $this->data;
        }
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args = []): bool
    {
        $key = '/' . trim($namespace, '/') . '/' . ltrim($route, '/');
        if (!isset($GLOBALS['ytb_test_rest_routes'])) {
            $GLOBALS['ytb_test_rest_routes'] = [];
        }
        if (!in_array($key, $GLOBALS['ytb_test_rest_routes'], true)) {
            $GLOBALS['ytb_test_rest_routes'][] = $key;
        }
        // Also expose the per-route args for tests that need to assert on
        // methods / callback / permission_callback (e.g. TEST-T-3 H3 pin
        // on the public-permission sentinel for /setup/pickup).
        if (!isset($GLOBALS['ytb_test_rest_route_args']) || !is_array($GLOBALS['ytb_test_rest_route_args'])) {
            $GLOBALS['ytb_test_rest_route_args'] = [];
        }
        $GLOBALS['ytb_test_rest_route_args'][$key] = $args;
        return true;
    }
}


// =========================================================================
// Joomla CMS stubs — loaded for the `joomla` testsuite. Idempotent (uses
// the YTB_JOOMLA_STUBS_LOADED sentinel), so loading them here for every
// suite is safe.
// =========================================================================

if (file_exists(__DIR__ . "/joomla-bootstrap.php")) {
    require_once __DIR__ . "/joomla-bootstrap.php";
}
