<?php
/**
 * HealthController (com_ytbmcp api) smoke-test — anonymous-vs-Bearer field split.
 *
 * Wave-9 Task 4 (#19 health parity, 2026-05-24). The api-application
 * Controller extends Joomla's BaseController and is autoloaded by Joomla's
 * MVCFactory at runtime (NOT in composer's PSR-4 map), so — like the other
 * api-controller smoke suites — we inspect the source file directly with
 * structural assertions on the contract surfaces.
 *
 * The single load-bearing contract: the ANONYMOUS payload MUST be exactly
 * {plugin_version, status} (parity with the WP-side L4-tier reduction).
 * `yootheme_loaded` — a host-fingerprint bit — leaked pre-auth on Joomla
 * before this wave; it now lives ONLY inside the `if ($this->hasValidBearer())`
 * augmentation branch.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Controller
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Platform\Joomla\Controller;

use PHPUnit\Framework\TestCase;

final class HealthControllerSmokeTest extends TestCase
{
    private const REL_PATH = 'src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/HealthController.php';

    private function controllerPath(): string
    {
        return \dirname(__DIR__, 6) . '/' . self::REL_PATH;
    }

    private function controllerSource(): string
    {
        $path = $this->controllerPath();
        if (!\is_file($path)) {
            self::fail("Controller source missing: $path");
        }
        return (string) \file_get_contents($path);
    }

    /**
     * Extract the body of the public get() method — the anonymous payload
     * is assembled there before the Bearer-augmentation branch.
     */
    private function getMethodBody(): string
    {
        $src = $this->controllerSource();
        if (!\preg_match(
            '/public function\s+get\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s',
            $src,
            $m,
        )) {
            self::fail('Could not locate get() method body in HealthController.');
        }
        return $m[1];
    }

    /**
     * Split get() into the anonymous-assembly segment (before the bearer
     * branch) and the augmentation segment (inside `if ($this->hasValidBearer())`).
     *
     * @return array{anonymous: string, augmented: string}
     */
    private function payloadSegments(): array
    {
        $body = $this->getMethodBody();
        $pos = \strpos($body, 'hasValidBearer()');
        self::assertNotFalse($pos, 'get() must gate augmentation behind hasValidBearer().');

        // The augmentation block opens with the `if (... hasValidBearer())` line.
        // Walk back to the start of that `if` so the anonymous segment excludes it.
        $ifPos = \strrpos(\substr($body, 0, $pos), 'if');
        self::assertNotFalse($ifPos, 'hasValidBearer() must sit inside an if-guard.');

        return [
            'anonymous' => \substr($body, 0, $ifPos),
            'augmented' => \substr($body, $ifPos),
        ];
    }

    public function test_controller_file_exists(): void
    {
        self::assertFileExists($this->controllerPath());
    }

    /**
     * #19 fix: the anonymous payload (assembled before the Bearer branch)
     * must NOT assign `yootheme_loaded`. Parity with WP's anonymous L4
     * payload of {plugin_version, status} only.
     */
    public function test_anonymous_payload_does_not_expose_yootheme_loaded(): void
    {
        $segments = $this->payloadSegments();
        self::assertStringNotContainsString(
            "'yootheme_loaded'",
            $segments['anonymous'],
            'Anonymous /health must NOT expose yootheme_loaded (host-fingerprint leak — #19).'
        );
    }

    /**
     * The anonymous payload key-set must be exactly {plugin_version, status}.
     * Asserted structurally: only those two single-quoted keys appear before
     * the bearer branch.
     */
    public function test_anonymous_payload_is_exactly_plugin_version_and_status(): void
    {
        $segments = $this->payloadSegments();
        \preg_match_all("/'([a-z_]+)'\s*=>/", $segments['anonymous'], $m);
        self::assertSame(
            ['plugin_version', 'status'],
            $m[1],
            'Anonymous /health payload must be exactly {plugin_version, status}.'
        );
    }

    /**
     * #19 fix: `yootheme_loaded` must now be surfaced INSIDE the
     * Bearer-augmentation branch (tier-2 disclosure, matching WP's
     * authenticated payload).
     */
    public function test_yootheme_loaded_is_bearer_gated(): void
    {
        $segments = $this->payloadSegments();
        self::assertStringContainsString(
            "'yootheme_loaded'",
            $segments['augmented'],
            'yootheme_loaded must be surfaced only behind a valid Bearer (tier-2 parity with WP).'
        );
    }

    /**
     * The augmentation branch must still gate on a real Bearer verify (never
     * on bearer-absence try/catch) — guards against accidental un-gating.
     */
    public function test_augmentation_is_gated_on_valid_bearer(): void
    {
        $src = $this->controllerSource();
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$this->hasValidBearer\(\)\s*\)/',
            $src,
            'Augmented payload must be gated on hasValidBearer().'
        );
    }
}
