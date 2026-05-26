<?php
/**
 * REGRESSION TESTS (R8-A3 #4 + R8-A4 P2): JoomlaJsonResponse body emission.
 *
 * 1. echo-vs-setBody (#4): on Joomla's ApiApplication the response body MUST
 *    be ECHOED (renderComponent captures echoed output via ob_start/ob_get_clean
 *    and OVERWRITES any controller setBody()/setBuffer()). We assert the JSON
 *    envelope is echoed, not stashed on the app object.
 *
 * 2. output-buffer hygiene (A4-P2): a stray PHP notice/echo emitted during
 *    handler execution (display_errors=On) lands in the SAME buffer and would
 *    prepend non-JSON bytes → corrupt envelope, silently NOT caught by
 *    dispatch()'s catch(\Throwable). We assert that stray pre-output is
 *    discarded so the emitted body is EXACTLY the JSON envelope.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Rest
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Rest;

use Joomla\CMS\Application\CMSApplicationInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Platform\Joomla\Rest\JoomlaJsonResponse;

#[CoversClass(JoomlaJsonResponse::class)]
final class JoomlaJsonResponseTest extends TestCase
{
    /** A minimal CMSApplicationInterface-shaped app that records headers. */
    private function makeApp(): object
    {
        return new class implements CMSApplicationInterface {
            /** @var array<string, string> */
            public array $headers = [];
            /** @var string|null Set if anything wrongly used setBody(). */
            public ?string $body = null;

            public function setHeader(string $name, string $value, bool $replace = false): void
            {
                $this->headers[$name] = $value;
            }

            public function setBody(string $body): void
            {
                // Recorded so the test can prove the helper did NOT use it.
                $this->body = $body;
            }

            public function enqueueMessage(string $msg, string $type = 'message'): void
            {
            }
        };
    }

    public function test_send_echoes_json_envelope_not_setbody(): void
    {
        $app = $this->makeApp();

        \ob_start();
        JoomlaJsonResponse::send($app, ['ok' => true, 'n' => 42], 200);
        $emitted = (string) \ob_get_clean();

        self::assertSame('{"ok":true,"n":42}', $emitted, 'Body MUST be echoed (renderComponent captures echoed output).');
        self::assertNull($app->body, 'Body MUST NOT be set via setBody() — ApiApplication overwrites it.');
        self::assertSame('200', $app->headers['status'] ?? null);
        self::assertSame('application/json; charset=utf-8', $app->headers['Content-Type'] ?? null);
    }

    public function test_error_envelope_shape_is_echoed(): void
    {
        $app = $this->makeApp();

        \ob_start();
        JoomlaJsonResponse::error($app, 'yootheme_builder_mcp.auth.bearer_invalid', 'Nope.', 401);
        $emitted = (string) \ob_get_clean();

        $decoded = \json_decode($emitted, true);
        self::assertIsArray($decoded, 'Error body must be valid JSON.');
        self::assertSame('yootheme_builder_mcp.auth.bearer_invalid', $decoded['code']);
        self::assertSame('Nope.', $decoded['message']);
        self::assertSame(401, $decoded['data']['status']);
        self::assertSame('401', $app->headers['status'] ?? null);
    }

    /**
     * A4-P2: a stray notice/echo during handler execution must NOT corrupt the
     * JSON. The helper drains stray pre-output before echoing the envelope.
     */
    public function test_stray_output_does_not_corrupt_json_body(): void
    {
        $app = $this->makeApp();

        \ob_start();
        // Simulate a PHP notice / accidental echo emitted earlier in the
        // request lifecycle, sitting in the active output buffer.
        echo "Notice: Undefined variable \$x in /path/Handler.php on line 7\n";
        JoomlaJsonResponse::send($app, ['clean' => true], 200);
        $emitted = (string) \ob_get_clean();

        self::assertSame('{"clean":true}', $emitted, 'Stray pre-output must be discarded so the body is EXACTLY the JSON envelope.');
        $decoded = \json_decode($emitted, true);
        self::assertIsArray($decoded, 'Body must still parse as valid JSON despite the injected notice.');
        self::assertTrue($decoded['clean']);
    }
}
