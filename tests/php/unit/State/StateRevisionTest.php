<?php
/**
 * StateRevision — monotonic write-revision counter test.
 *
 * F-07 fix coverage. The counter must never decrement (ABA-resistance
 * property). Verifies the bump() return contract (returns the NEW value,
 * not the previous one), and the defensive cold-start path (option
 * missing or corrupt → returns 0).
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\State\StateRevision;

#[CoversClass(StateRevision::class)]
final class StateRevisionTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [];
    }

    public function test_cold_start_returns_zero(): void
    {
        $rev = new StateRevision();
        self::assertSame(0, $rev->current());
    }

    public function test_bump_returns_new_value_starting_at_one(): void
    {
        $rev = new StateRevision();
        self::assertSame(1, $rev->bump());
        self::assertSame(1, $rev->current());
    }

    public function test_bump_is_monotonic(): void
    {
        $rev = new StateRevision();
        $a = $rev->bump();
        $b = $rev->bump();
        $c = $rev->bump();
        self::assertSame(1, $a);
        self::assertSame(2, $b);
        self::assertSame(3, $c);
    }

    public function test_bump_persists_across_instances(): void
    {
        (new StateRevision())->bump();
        (new StateRevision())->bump();
        self::assertSame(2, (new StateRevision())->current());
    }

    public function test_current_handles_corrupt_negative_value(): void
    {
        $GLOBALS['ytb_test_options'][StateRevision::OPTION] = -42;
        self::assertSame(0, (new StateRevision())->current());
    }

    public function test_current_handles_string_numeric(): void
    {
        // WP sometimes returns options as strings (autoload=false serialised path).
        $GLOBALS['ytb_test_options'][StateRevision::OPTION] = '17';
        self::assertSame(17, (new StateRevision())->current());
    }

    public function test_current_handles_non_numeric_garbage(): void
    {
        $GLOBALS['ytb_test_options'][StateRevision::OPTION] = ['oops'];
        self::assertSame(0, (new StateRevision())->current());
    }

    public function test_aba_scenario_yields_distinct_revisions(): void
    {
        // The core property: three bumps in any A→B→A sequence yield
        // three different revision values.
        $rev = new StateRevision();
        $r1 = $rev->bump();
        $r2 = $rev->bump();
        $r3 = $rev->bump();
        self::assertNotSame($r1, $r2);
        self::assertNotSame($r2, $r3);
        self::assertNotSame($r1, $r3);
    }

    public function test_option_uses_ytb_mcp_prefix(): void
    {
        // Hard-constraint: prefix must be `ytb_mcp_*` (Thomas-rule, no DB migration).
        self::assertStringStartsWith('ytb_mcp_', StateRevision::OPTION);
    }
}
