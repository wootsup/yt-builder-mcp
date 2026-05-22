<?php
/**
 * WwwAuthenticateFilter — RFC-6750 §3 header injection tests.
 *
 * Wave 6 Round-2 R2.12. The filter MUST attach a `WWW-Authenticate`
 * header on 401/403 responses inside our namespace and MUST stay off
 * other plugins' responses.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Rest\WwwAuthenticateFilter;

/**
 * Minimal in-process stub of WP_REST_Response — captures headers we
 * inject so the test can assert on them.
 */
final class FakeRestResponse
{
    /** @var array<string, string> */
    public array $headers = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly int $status, private readonly array $data = [])
    {
    }

    public function get_status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_data(): array
    {
        return $this->data;
    }

    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }
}

final class FakeRequest
{
    public function __construct(private readonly string $route)
    {
    }

    public function get_route(): string
    {
        return $this->route;
    }
}

#[CoversClass(WwwAuthenticateFilter::class)]
final class WwwAuthenticateFilterTest extends TestCase
{
    public function test_attaches_header_on_401_in_namespace(): void
    {
        $response = new FakeRestResponse(401, ['code' => 'yootheme_builder_mcp.auth.bearer_invalid']);
        $request = new FakeRequest('/yt-builder-mcp/v1/pages');

        WwwAuthenticateFilter::inject($response, null, $request);

        self::assertArrayHasKey('WWW-Authenticate', $response->headers);
        self::assertStringContainsString('Bearer', $response->headers['WWW-Authenticate']);
        self::assertStringContainsString('realm="yt-builder-mcp"', $response->headers['WWW-Authenticate']);
        self::assertStringContainsString('error="invalid_token"', $response->headers['WWW-Authenticate']);
    }

    public function test_skips_response_outside_namespace(): void
    {
        $response = new FakeRestResponse(401, ['code' => 'unrelated_plugin.auth_failed']);
        $request = new FakeRequest('/wp/v2/posts');

        WwwAuthenticateFilter::inject($response, null, $request);

        self::assertArrayNotHasKey('WWW-Authenticate', $response->headers);
    }

    public function test_skips_2xx_responses(): void
    {
        $response = new FakeRestResponse(200, ['data' => 'ok']);
        $request = new FakeRequest('/yt-builder-mcp/v1/health');

        WwwAuthenticateFilter::inject($response, null, $request);

        self::assertArrayNotHasKey('WWW-Authenticate', $response->headers);
    }

    public function test_attaches_insufficient_scope_error_on_403(): void
    {
        $response = new FakeRestResponse(403, ['code' => 'yootheme_builder_mcp.auth.insufficient_scope']);
        $request = new FakeRequest('/yt-builder-mcp/v1/pages');

        WwwAuthenticateFilter::inject($response, null, $request);

        self::assertStringContainsString('error="insufficient_scope"', $response->headers['WWW-Authenticate']);
    }

    public function test_attaches_expired_token_description_on_expired_code(): void
    {
        $response = new FakeRestResponse(401, ['code' => 'yootheme_builder_mcp.auth.bearer_expired']);
        $request = new FakeRequest('/yt-builder-mcp/v1/pages');

        WwwAuthenticateFilter::inject($response, null, $request);

        self::assertStringContainsString('error="invalid_token"', $response->headers['WWW-Authenticate']);
        self::assertStringContainsString('expired', $response->headers['WWW-Authenticate']);
    }

    public function test_attaches_revoked_token_description(): void
    {
        $response = new FakeRestResponse(401, ['code' => 'yootheme_builder_mcp.auth.bearer_revoked']);
        $request = new FakeRequest('/yt-builder-mcp/v1/pages');

        WwwAuthenticateFilter::inject($response, null, $request);

        self::assertStringContainsString('revoked', $response->headers['WWW-Authenticate']);
    }

    public function test_returns_response_unchanged_when_not_response_object(): void
    {
        $result = WwwAuthenticateFilter::inject(null, null, null);
        self::assertNull($result);

        $result = WwwAuthenticateFilter::inject('not-an-object', null, null);
        self::assertSame('not-an-object', $result);
    }
}
