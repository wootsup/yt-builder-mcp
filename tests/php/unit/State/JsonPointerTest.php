<?php
/**
 * JsonPointer — RFC-6901 compliance.
 *
 * Wave 2 Task 2.1. RFC-6901 has a canonical test-vector (Section 5) that we
 * pin verbatim here so that the implementation cannot silently drift.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\State\JsonPointer;

#[CoversClass(JsonPointer::class)]
final class JsonPointerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function rfc6901Document(): array
    {
        // Verbatim from RFC-6901 Section 5.
        return [
            'foo'    => ['bar', 'baz'],
            ''       => 0,
            'a/b'    => 1,
            'c%d'    => 2,
            'e^f'    => 3,
            'g|h'    => 4,
            'i\\j'   => 5,
            "k\"l"   => 6,
            ' '      => 7,
            'm~n'    => 8,
        ];
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function rfc6901Vectors(): iterable
    {
        // RFC-6901 Section 5 canonical evaluations. The empty-string pointer
        // returns the whole document; "/foo" returns the foo array; etc.
        yield 'whole document'   => ['',        '__WHOLE__'];
        yield '/foo'             => ['/foo',    ['bar', 'baz']];
        yield '/foo/0'           => ['/foo/0',  'bar'];
        yield '/'                => ['/',       0];
        yield '/a~1b'            => ['/a~1b',   1];
        yield '/c%d'             => ['/c%d',    2];
        yield '/e^f'             => ['/e^f',    3];
        yield '/g|h'             => ['/g|h',    4];
        yield '/i\\j'            => ['/i\\j',   5];
        yield '/k"l'             => ['/k"l',    6];
        yield '/ '               => ['/ ',      7];
        yield '/m~0n'            => ['/m~0n',   8];
    }

    #[DataProvider('rfc6901Vectors')]
    public function test_rfc6901_canonical_vector(string $pointer, mixed $expected): void
    {
        $doc = $this->rfc6901Document();

        if ($expected === '__WHOLE__') {
            self::assertSame($doc, JsonPointer::get($doc, $pointer));
            return;
        }

        self::assertSame($expected, JsonPointer::get($doc, $pointer));
    }

    public function test_unescape_replaces_tilde_one_with_slash(): void
    {
        // ~1 → /, per RFC-6901 Section 3.
        self::assertSame('a/b', JsonPointer::unescape('a~1b'));
    }

    public function test_unescape_replaces_tilde_zero_with_tilde(): void
    {
        // ~0 → ~, per RFC-6901 Section 3.
        self::assertSame('a~b', JsonPointer::unescape('a~0b'));
    }

    public function test_unescape_handles_combined_sequence(): void
    {
        // The order matters: ~01 must NOT become "/" — it must become "~1".
        // Decoding order is ~1→/ THEN ~0→~ per RFC-6901 (i.e. ~0 wins over a
        // subsequent /; but our concern here is that the decoder treats them
        // as atomic two-character sequences).
        self::assertSame('~1', JsonPointer::unescape('~01'));
    }

    public function test_escape_round_trip(): void
    {
        $original = 'has/slash~and~tilde';
        $escaped = JsonPointer::escape($original);
        self::assertSame($original, JsonPointer::unescape($escaped));
    }

    public function test_get_returns_null_for_missing_path(): void
    {
        $doc = ['a' => ['b' => 'c']];
        self::assertNull(JsonPointer::get($doc, '/a/missing'));
    }

    public function test_get_returns_null_for_missing_index(): void
    {
        $doc = ['list' => ['x', 'y']];
        self::assertNull(JsonPointer::get($doc, '/list/9'));
    }

    public function test_get_returns_null_when_descending_into_scalar(): void
    {
        $doc = ['a' => 'scalar'];
        self::assertNull(JsonPointer::get($doc, '/a/b'));
    }

    public function test_get_throws_on_invalid_pointer(): void
    {
        // Must start with `/` if non-empty (RFC-6901 Section 3).
        $this->expectException(\InvalidArgumentException::class);
        JsonPointer::get(['a' => 1], 'a');
    }

    public function test_compile_builds_pointer_from_segments(): void
    {
        $segments = ['templates', 'bFIb-syj', 'layout', 'children', 0];
        self::assertSame(
            '/templates/bFIb-syj/layout/children/0',
            JsonPointer::compile($segments),
        );
    }

    public function test_compile_escapes_special_chars_in_segments(): void
    {
        self::assertSame('/a~1b/c~0d', JsonPointer::compile(['a/b', 'c~d']));
    }

    public function test_parse_returns_segments(): void
    {
        self::assertSame(
            ['templates', 'bFIb-syj', 'layout', 'children', '0'],
            JsonPointer::parse('/templates/bFIb-syj/layout/children/0'),
        );
    }

    public function test_parse_returns_empty_array_for_root(): void
    {
        self::assertSame([], JsonPointer::parse(''));
    }

    // ---------------------------------------------------------------------
    // Wave 3 — set() / remove() (write-path)
    // ---------------------------------------------------------------------

    public function test_set_writes_value_at_existing_key(): void
    {
        $doc = ['foo' => ['bar' => 'baz']];
        JsonPointer::set($doc, '/foo/bar', 'NEW');
        self::assertSame('NEW', $doc['foo']['bar']);
    }

    public function test_set_writes_value_at_list_index(): void
    {
        $doc = ['list' => ['a', 'b', 'c']];
        JsonPointer::set($doc, '/list/1', 'BB');
        self::assertSame(['a', 'BB', 'c'], $doc['list']);
    }

    public function test_set_creates_intermediate_object_containers(): void
    {
        $doc = [];
        JsonPointer::set($doc, '/a/b/c', 'deep');
        self::assertSame('deep', $doc['a']['b']['c']);
    }

    public function test_set_appends_with_dash_token(): void
    {
        $doc = ['list' => ['a', 'b']];
        JsonPointer::set($doc, '/list/-', 'c');
        self::assertSame(['a', 'b', 'c'], $doc['list']);
    }

    public function test_set_throws_on_non_leading_slash(): void
    {
        $doc = [];
        $this->expectException(\InvalidArgumentException::class);
        JsonPointer::set($doc, 'no-slash', 'val');
    }

    public function test_set_at_root_replaces_document(): void
    {
        $doc = ['old' => 'value'];
        JsonPointer::set($doc, '', ['new' => 'shape']);
        self::assertSame(['new' => 'shape'], $doc);
    }

    public function test_set_throws_on_root_non_array(): void
    {
        $doc = [];
        $this->expectException(\InvalidArgumentException::class);
        JsonPointer::set($doc, '', 'not-an-array');
    }

    public function test_remove_unsets_object_key(): void
    {
        $doc = ['a' => 1, 'b' => 2];
        self::assertTrue(JsonPointer::remove($doc, '/a'));
        self::assertSame(['b' => 2], $doc);
    }

    public function test_remove_splices_list_index(): void
    {
        $doc = ['list' => ['a', 'b', 'c']];
        self::assertTrue(JsonPointer::remove($doc, '/list/1'));
        self::assertSame(['a', 'c'], $doc['list']);
    }

    public function test_remove_returns_false_when_path_missing(): void
    {
        $doc = ['a' => 1];
        self::assertFalse(JsonPointer::remove($doc, '/b'));
    }

    public function test_remove_returns_false_on_missing_list_index(): void
    {
        $doc = ['list' => ['a']];
        self::assertFalse(JsonPointer::remove($doc, '/list/9'));
    }

    public function test_remove_at_root_empties_document(): void
    {
        $doc = ['a' => 1];
        self::assertTrue(JsonPointer::remove($doc, ''));
        self::assertSame([], $doc);
    }

    // -------- Wave-6 Fix 6: isWithinPrefix ---------------------------------

    public function test_is_within_prefix_matches_inside_prefix(): void
    {
        self::assertTrue(
            JsonPointer::isWithinPrefix('/templates/tpl/layout', '/templates/tpl'),
        );
        self::assertTrue(
            JsonPointer::isWithinPrefix('/templates/tpl/layout/children/0', '/templates/tpl'),
        );
    }

    public function test_is_within_prefix_matches_equal(): void
    {
        self::assertTrue(
            JsonPointer::isWithinPrefix('/templates/tpl', '/templates/tpl'),
        );
    }

    public function test_is_within_prefix_rejects_sibling(): void
    {
        // The classic cross-template attack: pointer targets a different template.
        self::assertFalse(
            JsonPointer::isWithinPrefix('/templates/other/layout', '/templates/tpl'),
        );
    }

    public function test_is_within_prefix_rejects_shorter_pointer(): void
    {
        self::assertFalse(
            JsonPointer::isWithinPrefix('/templates', '/templates/tpl'),
        );
    }

    public function test_is_within_prefix_empty_prefix_matches_everything(): void
    {
        self::assertTrue(JsonPointer::isWithinPrefix('/anything/at/all', ''));
        self::assertTrue(JsonPointer::isWithinPrefix('', ''));
    }

    public function test_is_within_prefix_empty_pointer_rejects_non_empty_prefix(): void
    {
        self::assertFalse(JsonPointer::isWithinPrefix('', '/templates/tpl'));
    }

    public function test_is_within_prefix_does_not_match_prefix_substring_segment(): void
    {
        // "/templates/tpl-other" must NOT match prefix "/templates/tpl"
        // (segment equality, not string-prefix).
        self::assertFalse(
            JsonPointer::isWithinPrefix('/templates/tpl-other/layout', '/templates/tpl'),
        );
    }

    public function test_rejects_pointer_exceeding_max_depth(): void
    {
        // MAX_DEPTH + 1 segments → must throw.
        $deep = '/' . implode('/', array_fill(0, JsonPointer::MAX_DEPTH + 1, 'a'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds MAX_DEPTH');
        JsonPointer::parse($deep);
    }

    public function test_accepts_pointer_at_max_depth(): void
    {
        // MAX_DEPTH segments → must NOT throw (boundary is inclusive).
        $atLimit = '/' . implode('/', array_fill(0, JsonPointer::MAX_DEPTH, 'a'));
        $segments = JsonPointer::parse($atLimit);
        self::assertCount(JsonPointer::MAX_DEPTH, $segments);
    }

    public function test_max_depth_const_is_sane(): void
    {
        // Sanity-check the constant: Builder JSON trees never reach this depth
        // legitimately, but the cap must be high enough for normal use
        // (template → layout → row → column → grid → element → settings →
        // some nested object) which is ~8.
        self::assertGreaterThanOrEqual(32, JsonPointer::MAX_DEPTH);
        self::assertLessThanOrEqual(256, JsonPointer::MAX_DEPTH);
    }
}
