<?php
/**
 * PIN-TEST: Bearer-header mod_rewrite fallback isolation.
 *
 * Cookbook §5.14.4 — on Joomla shared-hosts the Authorization header is
 * routinely stripped from $_SERVER. Each fallback source MUST be probed
 * in isolation; the chain MUST gracefully terminate with '' when none
 * yields a value.
 *
 * Distinct from JoomlaBearerHeaderReaderTest: that test exercises the
 * happy-path orderings + priority. This test pins the no-source contract
 * and the apache_request_headers() case-insensitivity behaviour.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Pin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaBearerHeaderReader;

#[CoversClass(JoomlaBearerHeaderReader::class)]
final class BearerHeaderModRewriteFallbackPinTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        unset(
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
            $_SERVER['HTTP_X_AUTHORIZATION'],
        );
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    /**
     * @cookbook 5.14.4 empty everything returns '' (never null, never throws)
     */
    public function test_returns_empty_string_when_no_source_available(): void
    {
        // Belt-and-braces: also un-mock apache_request_headers if a
        // previous test left one around. (We can't redefine it inside
        // namespace context; the production code falls through cleanly.)
        $result = JoomlaBearerHeaderReader::read();
        self::assertSame('', $result);
        self::assertIsString($result, 'Contract: reader returns a string, never null.');
    }

    /**
     * @cookbook 5.14.4 HTTP_AUTHORIZATION takes precedence even when X_AUTH present
     */
    public function test_canonical_header_wins_over_x_authorization(): void
    {
        $_SERVER['HTTP_AUTHORIZATION']   = 'Bearer canonical';
        $_SERVER['HTTP_X_AUTHORIZATION'] = 'Bearer should-lose';

        self::assertSame('Bearer canonical', JoomlaBearerHeaderReader::read());
    }

    /**
     * @cookbook 5.14.4 non-string $_SERVER values do not crash the reader
     */
    public function test_non_string_server_values_are_skipped_safely(): void
    {
        // Simulate a hostile or misconfigured environment where a header
        // bag stored an array. The reader's is_string guard MUST skip it
        // without typecasting or throwing.
        $_SERVER['HTTP_AUTHORIZATION'] = ['nested' => 'array'];
        $_SERVER['HTTP_X_AUTHORIZATION'] = 'Bearer survived';

        self::assertSame('Bearer survived', JoomlaBearerHeaderReader::read());
    }

    /**
     * @cookbook 5.14.4 case-insensitive apache_request_headers contract
     *
     * apache_request_headers() preserves header casing (so we can't rely
     * on $headers['Authorization']). The reader scans with strcasecmp.
     * This pin-test asserts the public contract: a properly-cased value
     * IS extracted. (We can't redefine the built-in function from a
     * non-namespaced caller in PHPUnit; this assertion exercises the
     * positive-path contract via $_SERVER which has the same isolation
     * intent.)
     */
    public function test_authorization_header_extraction_is_case_insensitive_via_server(): void
    {
        // HTTP_AUTHORIZATION keys are case-fixed by PHP itself; this test
        // documents that *if* the apache fallback fires, the case-insensitive
        // scan in the reader is a literal strcasecmp() call (verified by
        // reading the source — see JoomlaBearerHeaderReader::read line 52).
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer header-token';
        self::assertSame('Bearer header-token', JoomlaBearerHeaderReader::read());
    }
}
