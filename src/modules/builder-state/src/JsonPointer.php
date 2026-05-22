<?php
/**
 * JsonPointer — RFC-6901 compliant pointer evaluator.
 *
 * Spike-2 (2026-05-21) settled that yt-builder-mcp addresses every
 * Builder node via a JSON-Pointer-path string into the
 * `wp_option('yootheme')` JSON tree, because the Builder JSON has no
 * node-level IDs. This class is the reference resolver every read/write
 * path in Wave 2+ goes through.
 *
 * Encoding rules (RFC-6901 Section 3):
 *  - Empty string ""           → whole document
 *  - "/foo"                    → key "foo"
 *  - "/foo/0"                  → index 0 of array under "foo"
 *  - "/a~1b"                   → key "a/b" (~1 unescapes to "/")
 *  - "/m~0n"                   → key "m~n" (~0 unescapes to "~")
 *
 * Escape order is significant: when encoding, "~" must be replaced FIRST
 * (otherwise an existing "/" would be escaped to "~1", then the new "~"
 * would be re-escaped to "~0" and create "~01" which would round-trip
 * incorrectly). When decoding, the opposite — "~1"→"/" first, then
 * "~0"→"~" — would also corrupt sequences like "~01". The canonical
 * approach is therefore to do the decoding in a single left-to-right pass
 * treating "~0" and "~1" as atomic two-character escape codes.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\State
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\State;

final class JsonPointer
{
    /**
     * Maximum supported pointer depth (segments). Anything deeper indicates
     * abuse or corruption — Builder JSON trees never reach this depth
     * legitimately. Defense-in-depth against pathological writes that could
     * blow the call-stack on auto-create or saturate memory on parse().
     */
    public const MAX_DEPTH = 64;

    /**
     * Walk into $document along the JSON-Pointer $pointer.
     *
     * Returns null if any segment cannot be resolved (missing key, missing
     * index, or attempting to descend into a scalar). Throws
     * InvalidArgumentException if the pointer is structurally invalid
     * (RFC-6901 Section 3: must start with "/" if non-empty).
     *
     * @param array<mixed> $document
     */
    public static function get(array $document, string $pointer): mixed
    {
        if ($pointer === '') {
            return $document;
        }
        if ($pointer[0] !== '/') {
            throw new \InvalidArgumentException(
                'JSON-Pointer must start with "/" (RFC-6901 §3): ' . $pointer
            );
        }

        $segments = self::parse($pointer);
        /** @var mixed $cursor */
        $cursor = $document;

        foreach ($segments as $segment) {
            if (is_array($cursor)) {
                // Array: try integer-index, then string key.
                if (array_is_list($cursor) && ctype_digit($segment)) {
                    $idx = (int) $segment;
                    if (!array_key_exists($idx, $cursor)) {
                        return null;
                    }
                    $cursor = $cursor[$idx];
                    continue;
                }
                if (!array_key_exists($segment, $cursor)) {
                    return null;
                }
                $cursor = $cursor[$segment];
                continue;
            }

            // Descended into a scalar — cannot continue.
            return null;
        }

        return $cursor;
    }

    /**
     * Decode a single reference-token (segment between "/"s) per RFC-6901 §3.
     */
    public static function unescape(string $segment): string
    {
        // Single left-to-right pass treating ~0 and ~1 as atomic two-char
        // escape codes. PHP's strtr with a $replace_pairs ARRAY performs
        // exactly this longest-prefix-match semantic: it does NOT chain
        // replacements, so "~01" → "~1" (correct) rather than "/".
        return strtr($segment, ['~1' => '/', '~0' => '~']);
    }

    /**
     * Encode a single reference-token per RFC-6901 §3.
     *
     * Order matters: encode "~" first so that any subsequent "/" → "~1"
     * does not get its leading "~" re-escaped into "~0".
     */
    public static function escape(string $segment): string
    {
        // "~" first, then "/".
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }

    /**
     * Split a JSON-Pointer into its decoded segments. Returns the empty
     * array for the root pointer "".
     *
     * @return list<string>
     */
    public static function parse(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }
        if ($pointer[0] !== '/') {
            throw new \InvalidArgumentException(
                'JSON-Pointer must start with "/" (RFC-6901 §3): ' . $pointer
            );
        }

        // Strip leading "/" then split. "/" alone (i.e. pointing at the key
        // "") becomes [""] — one empty-string segment, NOT [].
        $raw = explode('/', substr($pointer, 1));
        if (count($raw) > self::MAX_DEPTH) {
            throw new \InvalidArgumentException(
                sprintf(
                    'JSON-Pointer exceeds MAX_DEPTH (%d segments, limit %d): defense against pathological depth.',
                    count($raw),
                    self::MAX_DEPTH
                )
            );
        }
        return array_map(static fn (string $s): string => self::unescape($s), $raw);
    }

    /**
     * Build a JSON-Pointer from a list of raw (unescaped) segments.
     *
     * @param list<string|int> $segments
     */
    public static function compile(array $segments): string
    {
        if ($segments === []) {
            return '';
        }
        $parts = [];
        foreach ($segments as $segment) {
            $parts[] = self::escape((string) $segment);
        }
        return '/' . implode('/', $parts);
    }

    /**
     * Set the value at $pointer in $document. Auto-creates intermediate
     * containers as needed (objects when next segment is a non-digit key,
     * arrays when next segment is "-" or a digit-index).
     *
     * The "-" segment per RFC-6901 §4 represents "the element past the end
     * of the array" — used here for append-style writes.
     *
     * Mutates $document in-place AND returns it (for chaining).
     *
     * @param array<mixed> $document
     * @param mixed $value
     * @return array<mixed>
     */
    public static function set(array &$document, string $pointer, $value): array
    {
        if ($pointer === '') {
            if (!is_array($value)) {
                throw new \InvalidArgumentException('Cannot set non-array value at root pointer.');
            }
            /** @var array<mixed> $value */
            $document = $value;
            return $document;
        }
        if ($pointer[0] !== '/') {
            throw new \InvalidArgumentException(
                'JSON-Pointer must start with "/" (RFC-6901 §3): ' . $pointer
            );
        }

        $segments = self::parse($pointer);
        /** @var array<mixed> $cursor */
        $cursor = &$document;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $i => $segment) {
            $isLast = $i === $lastIndex;

            // Determine whether we're appending to a list ("-") or addressing
            // a specific index/key.
            if ($segment === '-') {
                if (!$isLast) {
                    throw new \InvalidArgumentException(
                        'The "-" segment is only valid as the final reference-token (RFC-6901 §4): ' . $pointer
                    );
                }
                if (!is_array($cursor)) {
                    throw new \InvalidArgumentException(
                        'Cannot append to non-array container at pointer: ' . $pointer
                    );
                }
                $cursor[] = $value;
                return $document;
            }

            if ($isLast) {
                if (is_array($cursor) && array_is_list($cursor) && ctype_digit($segment)) {
                    $cursor[(int) $segment] = $value;
                } else {
                    $cursor[$segment] = $value;
                }
                return $document;
            }

            // Descend, auto-creating containers as needed.
            if (is_array($cursor) && array_is_list($cursor) && ctype_digit($segment)) {
                $idx = (int) $segment;
                if (!array_key_exists($idx, $cursor) || !is_array($cursor[$idx])) {
                    $cursor[$idx] = [];
                }
                $cursor = &$cursor[$idx];
                continue;
            }

            if (!array_key_exists($segment, $cursor) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }

        return $document;
    }

    /**
     * Return true if $pointer is within $allowedPrefix (i.e. starts with
     * the prefix's segments). Used by controllers to assert that a
     * user-supplied write-pointer cannot escape the addressed template
     * (Wave-6 Fix 6, cross-template-write defense).
     *
     * Empty prefix matches everything (root-scoped). A pointer that is
     * equal to the prefix counts as "within".
     */
    public static function isWithinPrefix(string $pointer, string $allowedPrefix): bool
    {
        if ($allowedPrefix === '') {
            return true;
        }
        if ($pointer === '') {
            return false;
        }

        $ptrSegments = self::parse($pointer);
        $prefSegments = self::parse($allowedPrefix);
        if (count($ptrSegments) < count($prefSegments)) {
            return false;
        }
        foreach ($prefSegments as $i => $seg) {
            if ($ptrSegments[$i] !== $seg) {
                return false;
            }
        }
        return true;
    }

    /**
     * Remove the value at $pointer from $document. Returns true if the
     * removal succeeded, false if the path did not resolve.
     *
     * For lists, removing index N reindexes the list (preserving list
     * semantics — every Builder children-array stays a list).
     *
     * @param array<mixed> $document
     */
    public static function remove(array &$document, string $pointer): bool
    {
        if ($pointer === '') {
            $document = [];
            return true;
        }
        if ($pointer[0] !== '/') {
            throw new \InvalidArgumentException(
                'JSON-Pointer must start with "/" (RFC-6901 §3): ' . $pointer
            );
        }

        $segments = self::parse($pointer);
        /** @var array<mixed> $cursor */
        $cursor = &$document;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $i => $segment) {
            $isLast = $i === $lastIndex;

            if ($isLast) {
                if (!is_array($cursor)) {
                    return false;
                }
                if (array_is_list($cursor) && ctype_digit($segment)) {
                    $idx = (int) $segment;
                    if (!array_key_exists($idx, $cursor)) {
                        return false;
                    }
                    array_splice($cursor, $idx, 1);
                    return true;
                }
                if (!array_key_exists($segment, $cursor)) {
                    return false;
                }
                unset($cursor[$segment]);
                return true;
            }

            if (!is_array($cursor)) {
                return false;
            }
            if (array_is_list($cursor) && ctype_digit($segment)) {
                $idx = (int) $segment;
                if (!array_key_exists($idx, $cursor)) {
                    return false;
                }
                $cursor = &$cursor[$idx];
                continue;
            }
            if (!array_key_exists($segment, $cursor)) {
                return false;
            }
            $cursor = &$cursor[$segment];
        }

        return false;
    }
}
