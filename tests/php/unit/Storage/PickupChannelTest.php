<?php
/**
 * PickupChannel — pickup-URL transient channel unit tests.
 *
 * Hardening H2 (2026-05-22): PickupChannel is the single owner of the
 * pickup-URL transient channel — these tests pin the producer + consumer
 * contracts at the storage layer so future controller refactors stay
 * compatible.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Storage\PickupChannel;

#[CoversClass(PickupChannel::class)]
final class PickupChannelTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_transients'] = [];
    }

    public function test_issue_returns_43_char_base64url_nonce(): void
    {
        $nonce = PickupChannel::issue([
            'token' => 'ytb_v1_token_AAA',
            'site_url' => 'https://example.test',
            'ip' => '203.0.113.10',
            'ip_bound' => true,
        ]);

        self::assertSame(43, strlen($nonce), 'random_bytes(32) base64url-encoded is 43 chars');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $nonce);
    }

    public function test_issue_returns_empty_string_when_set_transient_fails(): void
    {
        // Swap in a transient store that always returns false to simulate
        // a broken object-cache backend.
        $original = $GLOBALS['ytb_test_transients'] ?? [];
        // Override the test helper's set_transient by toggling a sentinel.
        $GLOBALS['ytb_test_transient_force_fail'] = true;
        try {
            $nonce = PickupChannel::issue([
                'token' => 'ytb_v1_token_FAIL',
                'site_url' => 'https://example.test',
                'ip' => '203.0.113.11',
                'ip_bound' => true,
            ]);
            self::assertSame('', $nonce, 'PickupChannel::issue returns "" on set_transient failure');
        } finally {
            unset($GLOBALS['ytb_test_transient_force_fail']);
            $GLOBALS['ytb_test_transients'] = $original;
        }
    }

    public function test_claim_returns_payload_on_happy_path(): void
    {
        $nonce = PickupChannel::issue([
            'token' => 'ytb_v1_token_HAPPY',
            'site_url' => 'https://example.test',
            'ip' => '203.0.113.20',
            'ip_bound' => true,
        ]);

        $result = PickupChannel::claim($nonce, '203.0.113.20');

        self::assertIsArray($result);
        self::assertSame('ytb_v1_token_HAPPY', $result['token']);
        self::assertSame('https://example.test', $result['site_url']);
    }

    public function test_claim_deletes_transient_on_success(): void
    {
        $nonce = PickupChannel::issue([
            'token' => 'ytb_v1_token_DELETE',
            'site_url' => 'https://example.test',
            'ip' => '203.0.113.21',
            'ip_bound' => true,
        ]);
        $key = PickupChannel::TRANSIENT_PREFIX . $nonce;

        self::assertNotFalse(\get_transient($key), 'pre-condition: transient exists');

        PickupChannel::claim($nonce, '203.0.113.21');

        self::assertFalse(\get_transient($key), 'one-shot delete must run BEFORE return');
    }

    public function test_claim_returns_null_on_expired(): void
    {
        // Never-issued = expired/never-existed from the caller's perspective.
        $result = PickupChannel::claim(
            'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG',
            '203.0.113.22',
        );
        self::assertNull($result);
    }

    public function test_claim_returns_null_on_already_consumed(): void
    {
        $nonce = PickupChannel::issue([
            'token' => 'ytb_v1_token_ONESHOT',
            'site_url' => 'https://example.test',
            'ip' => '203.0.113.23',
            'ip_bound' => true,
        ]);

        $first = PickupChannel::claim($nonce, '203.0.113.23');
        self::assertIsArray($first);
        self::assertArrayHasKey('token', $first);

        $second = PickupChannel::claim($nonce, '203.0.113.23');
        self::assertNull($second, 'second claim with same nonce must return null');
    }

    public function test_claim_returns_ip_mismatch_marker_when_ip_bound_and_different(): void
    {
        $nonce = PickupChannel::issue([
            'token' => 'ytb_v1_token_IPMM',
            'site_url' => 'https://example.test',
            'ip' => '203.0.113.30',
            'ip_bound' => true,
        ]);
        $key = PickupChannel::TRANSIENT_PREFIX . $nonce;

        $result = PickupChannel::claim($nonce, '198.51.100.99');

        self::assertIsArray($result);
        self::assertTrue($result['__ip_mismatch__'] ?? false);

        // Transient must remain so the legitimate user can still pick up.
        $remaining = \get_transient($key);
        self::assertIsArray($remaining);
        self::assertSame('ytb_v1_token_IPMM', $remaining['token']);
    }

    public function test_claim_allows_different_ip_when_ip_bound_false(): void
    {
        $nonce = PickupChannel::issue([
            'token' => 'ytb_v1_token_OPTOUT',
            'site_url' => 'https://example.test',
            'ip' => '203.0.113.40',
            'ip_bound' => false, // explicit opt-out
        ]);

        $result = PickupChannel::claim($nonce, '198.51.100.99');

        self::assertIsArray($result);
        self::assertSame('ytb_v1_token_OPTOUT', $result['token'] ?? null);
        // One-shot delete still fires.
        $key = PickupChannel::TRANSIENT_PREFIX . $nonce;
        self::assertFalse(\get_transient($key));
    }

    public function test_claim_returns_null_on_invalid_nonce_shape(): void
    {
        // Too short.
        self::assertNull(PickupChannel::claim('short', '203.0.113.50'));
        // Too long.
        self::assertNull(PickupChannel::claim(str_repeat('A', 65), '203.0.113.51'));
        // Invalid chars.
        self::assertNull(PickupChannel::claim(
            'abcdefghijklmnopqrstuvwxyz0123456789+/=ABC!',
            '203.0.113.52',
        ));
    }

    public function test_is_valid_nonce_shape_bounds(): void
    {
        // Min boundary — exactly 32 chars, valid alphabet.
        self::assertTrue(PickupChannel::isValidNonceShape(str_repeat('A', 32)));
        // Max boundary — exactly 64 chars, valid alphabet.
        self::assertTrue(PickupChannel::isValidNonceShape(str_repeat('A', 64)));
        // One under min.
        self::assertFalse(PickupChannel::isValidNonceShape(str_repeat('A', 31)));
        // One over max.
        self::assertFalse(PickupChannel::isValidNonceShape(str_repeat('A', 65)));
        // All valid chars in the base64url alphabet.
        self::assertTrue(PickupChannel::isValidNonceShape(
            'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQR-_0123',
        ));
        // Forbidden chars (+, /, =, !, etc.).
        self::assertFalse(PickupChannel::isValidNonceShape(
            'abcdefghijklmnopqrstuvwxyz+/=0123456789ABCDEFGHIJK',
        ));
    }
}
