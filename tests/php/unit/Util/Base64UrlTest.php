<?php
/**
 * Base64Url tests — RFC-4648 §5 URL-safe base64 helper.
 *
 * Hardening H4 (ARCH-REUSE-3). Pins the encoding contract that
 * PickupChannel + future bearer-token producers rely on.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Util\Base64Url;

#[CoversClass(Base64Url::class)]
final class Base64UrlTest extends TestCase
{
    public function test_generate_returns_correct_length_base64url(): void
    {
        $s = Base64Url::generate(32);
        // 32 raw bytes -> 43 base64url chars (no padding).
        self::assertSame(43, strlen($s));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $s);
        // 16 raw bytes -> 22 base64url chars.
        self::assertSame(22, strlen(Base64Url::generate(16)));
        // 64 raw bytes -> 86 base64url chars.
        self::assertSame(86, strlen(Base64Url::generate(64)));
    }

    public function test_generate_rejects_non_positive_byte_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Base64Url::generate(0);
    }

    public function test_encode_strips_padding_and_substitutes_alphabet(): void
    {
        // "Hello" -> base64 "SGVsbG8=" -> base64url "SGVsbG8" (= stripped).
        self::assertSame('SGVsbG8', Base64Url::encode('Hello'));

        // Bytes that produce `+` / `/` in standard base64 must come back as
        // `-` / `_` in base64url. Pick bytes whose encoding deterministically
        // hits both substitutions:
        //   0xFB 0xFF 0xBF -> standard base64 "+/+/", base64url "-_-_"
        $raw = "\xFB\xFF\xBF";
        $encoded = Base64Url::encode($raw);
        self::assertStringNotContainsString('+', $encoded);
        self::assertStringNotContainsString('/', $encoded);
        self::assertStringNotContainsString('=', $encoded);
        self::assertStringContainsString('-', $encoded);
        self::assertStringContainsString('_', $encoded);
    }

    public function test_is_valid_rejects_padding_and_invalid_chars(): void
    {
        // Valid: only [A-Za-z0-9_-], length in [min..max].
        self::assertTrue(Base64Url::isValid('abcDEF123_-', 1, 64));

        // Padding char rejected.
        self::assertFalse(Base64Url::isValid('SGVsbG8=', 1, 64));

        // Forward slash from standard base64 rejected.
        self::assertFalse(Base64Url::isValid('SG/Vsb', 1, 64));

        // Plus rejected.
        self::assertFalse(Base64Url::isValid('SG+Vsb', 1, 64));

        // Too short.
        self::assertFalse(Base64Url::isValid('abc', 32, 64));

        // Too long.
        self::assertFalse(Base64Url::isValid(str_repeat('a', 65), 32, 64));

        // Empty string rejected by default min=1.
        self::assertFalse(Base64Url::isValid(''));
    }
}
