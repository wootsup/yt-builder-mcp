<?php
/**
 * SchemaVersion — storage schema version stamp tests.
 *
 * Wave 6 Round-2 Fix R2.6. Forward-compatibility: every install must carry
 * a schema-version marker so future migrations know which on-disk layout
 * they're upgrading from.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Storage\SchemaVersion;

#[CoversClass(SchemaVersion::class)]
final class SchemaVersionTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [];
    }

    public function test_get_returns_zero_on_fresh_install(): void
    {
        self::assertSame(0, SchemaVersion::get());
    }

    public function test_ensure_stamps_current_version_when_missing(): void
    {
        SchemaVersion::ensure();
        self::assertSame(SchemaVersion::CURRENT_VERSION, SchemaVersion::get());
    }

    public function test_ensure_does_not_overwrite_existing_stamp(): void
    {
        // Simulate an older install (version 99 — deliberately above the
        // current to prove that ensure() never downgrades).
        $GLOBALS['ytb_test_options'][SchemaVersion::OPTION_KEY] = 99;
        SchemaVersion::ensure();
        self::assertSame(99, SchemaVersion::get());
    }

    public function test_bump_persists_new_version(): void
    {
        SchemaVersion::bump(7);
        self::assertSame(7, SchemaVersion::get());
    }

    public function test_bump_rejects_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SchemaVersion::bump(0);
    }

    public function test_bump_rejects_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SchemaVersion::bump(-1);
    }

    public function test_current_version_is_positive(): void
    {
        // Defensive sanity: CURRENT_VERSION must always be >= 1 so that
        // existing-stamp detection never collides with the "no stamp" sentinel.
        self::assertGreaterThanOrEqual(1, SchemaVersion::CURRENT_VERSION);
    }

    public function test_get_handles_corrupted_non_numeric_value(): void
    {
        // Defense: if a third party clobbers the option with a non-numeric
        // payload, get() must report 0 (sentinel) rather than crash.
        $GLOBALS['ytb_test_options'][SchemaVersion::OPTION_KEY] = ['not', 'a', 'number'];
        self::assertSame(0, SchemaVersion::get());
    }
}
