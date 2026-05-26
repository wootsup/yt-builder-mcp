<?php
/**
 * JoomlaBearerHeaderReader — 4-fallback probe order for `Authorization`.
 *
 * Defends against the well-known mod_rewrite / mod_fcgid quirk where the
 * canonical `Authorization` header is stripped from $_SERVER before PHP
 * sees it. Cookbook §5.14.4 — Joomla-gotcha pin "Authorization header
 * stripped by mod_rewrite".
 *
 * Probe order:
 *   1. $_SERVER['HTTP_AUTHORIZATION']
 *   2. $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
 *   3. $_SERVER['HTTP_X_AUTHORIZATION']
 *   4. apache_request_headers() (case-preserving — scan with strcasecmp)
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaBearerHeaderReader;

#[CoversClass(JoomlaBearerHeaderReader::class)]
final class JoomlaBearerHeaderReaderTest extends TestCase
{
    /** @var array<string, mixed> Snapshot of $_SERVER for restoration. */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        // Clear the four keys we manipulate. Touch nothing else (CLI
        // bookkeeping like SCRIPT_FILENAME must stay intact).
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
     * @cookbook 5.14.4 probe order — first non-empty wins
     */
    #[DataProvider('probeOrderProvider')]
    public function test_each_server_variant_is_extracted_in_isolation(
        string $serverKey,
        string $value,
        string $expected
    ): void {
        $_SERVER[$serverKey] = $value;
        self::assertSame($expected, JoomlaBearerHeaderReader::read());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function probeOrderProvider(): iterable
    {
        yield 'HTTP_AUTHORIZATION'          => ['HTTP_AUTHORIZATION',          'Bearer ytb_live_abc', 'Bearer ytb_live_abc'];
        yield 'REDIRECT_HTTP_AUTHORIZATION' => ['REDIRECT_HTTP_AUTHORIZATION', 'Bearer ytb_live_red', 'Bearer ytb_live_red'];
        yield 'HTTP_X_AUTHORIZATION'        => ['HTTP_X_AUTHORIZATION',        'Bearer ytb_live_x',   'Bearer ytb_live_x'];
    }

    /**
     * @cookbook 5.14.4 probe priority order — HTTP_AUTHORIZATION wins over redirect/X
     */
    public function test_http_authorization_takes_priority(): void
    {
        $_SERVER['HTTP_AUTHORIZATION']          = 'Bearer first-wins';
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer second';
        $_SERVER['HTTP_X_AUTHORIZATION']        = 'Bearer third';

        self::assertSame('Bearer first-wins', JoomlaBearerHeaderReader::read());
    }

    /**
     * @cookbook 5.14.4 empty $_SERVER falls through to apache_request_headers()
     */
    public function test_empty_server_returns_empty_when_apache_fallback_unavailable(): void
    {
        // Nothing planted, no apache_request_headers() override.
        $result = JoomlaBearerHeaderReader::read();
        self::assertSame('', $result, 'Without any source the reader must return an empty string.');
    }

    /**
     * @cookbook 5.14.4 all-empty $_SERVER values are treated as absent
     */
    public function test_empty_string_values_are_skipped(): void
    {
        $_SERVER['HTTP_AUTHORIZATION']          = '';
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = '';
        $_SERVER['HTTP_X_AUTHORIZATION']        = 'Bearer fallback-wins';

        self::assertSame('Bearer fallback-wins', JoomlaBearerHeaderReader::read());
    }

    /**
     * @cookbook 5.14.4 redirect fallback wins when HTTP_AUTHORIZATION absent
     */
    public function test_redirect_fallback_wins_when_canonical_absent(): void
    {
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer redirect-token';

        self::assertSame('Bearer redirect-token', JoomlaBearerHeaderReader::read());
    }
}
