<?php
/**
 * PropSanitizer — wp_kses-vs-fallback DIVERGENCE pin tests.
 *
 * R8-A4 audit (2026-05-27) flagged that the previous wp_kses test stub
 * was a near-tautological clone of {@see PropSanitizer::fallbackSanitize}
 * (same forbidden-tag list, same `on*=` regex, same URI strncmp). Tests
 * running against that stub on the "WP path" therefore validated the
 * SAME logic as tests on the Joomla fallback path — they could not
 * detect a divergence between actual WordPress wp_kses and our fallback.
 * That's the `live-green != tested-green` anti-pattern.
 *
 * Real wp_kses (wp-includes/kses.php) differs from PropSanitizer's
 * fallback along (at least) four classes:
 *
 *   (a) PER-TAG attribute allow-list — wp_kses strips attributes not in
 *       `wp_kses_allowed_html` FOR THAT TAG. Our fallback only strips
 *       on*=, javascript:/vbscript:/data:text/html — anything else
 *       passes (including `<a style="…">` even though `style` is not on
 *       the customer-friendly per-tag map for `<a>`).
 *
 *   (b) RECURSIVE ENTITY-DECODE on URI attributes — wp_kses's
 *       `wp_kses_bad_protocol` decodes `&#x6a;avascript:` → `javascript:`
 *       and rejects. Our fallback does a raw-string strncmp on the
 *       unmodified value; entity-encoded payloads escape it.
 *
 *   (c) HTML-COMMENT stripping — wp_kses drops `<!-- … -->` (and IE-style
 *       conditional-comment payloads like `<!--[if IE]>…<![endif]-->`).
 *       Our fallback passes them through.
 *
 *   (d) MALFORMED-TAG state-machine — wp_kses parses character-by-
 *       character so `<scr<script>ipt>alert(1)</script>` collapses
 *       fully: the inner <script> is stripped, leaving `<scr ipt>alert(1)`
 *       which is then dropped as a non-allow-listed tag. Our fallback's
 *       single-pass regex leaves the orphan `<scr` token visible.
 *
 * These tests pin the divergence: they EXERCISE PropSanitizer against
 * the hardened wp_kses stub and assert behaviour that the Joomla-
 * fallback path would FAIL. Together with the WP-path coverage in
 * {@see PropSanitizerTest} they prove the WP-vs-Joomla contract has
 * non-trivial extra defence on the WP path, and that the stub correctly
 * mimics it for tests.
 *
 * @package WootsUp\BuilderMcp\Tests\Unit\Util
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Util\PropSanitizer;

#[CoversClass(PropSanitizer::class)]
final class PropSanitizerWpKsesDivergenceTest extends TestCase
{
    protected function setUp(): void
    {
        // Sanity guard: these tests REQUIRE the WP-path (wp_kses defined).
        // The bootstrap defines it; if a future refactor removes the stub
        // the tests must skip rather than silently validate the fallback.
        if (!\function_exists('wp_kses')) {
            self::markTestSkipped('wp_kses stub absent — this suite pins the WP-path divergence.');
        }
    }

    // ------------------------------------------------------------------
    // (a) Per-tag attribute allow-list — real wp_kses strips attrs NOT
    // listed for the specific tag. The fallback only filters on*= and
    // javascript: URIs — `style` on an `<a>` survives the fallback but
    // is stripped by wp_kses.
    // ------------------------------------------------------------------

    public function test_a_style_attribute_on_anchor_is_stripped_on_wp_path(): void
    {
        // Per-tag map for <a>: href / title / target / rel — `style` is
        // NOT listed → must be removed on the WP path.
        $sanitized = PropSanitizer::sanitizeProps([
            'content' => '<a href="https://example.com" style="background:url(javascript:alert(1))">click</a>',
        ]);
        self::assertStringNotContainsString(
            'style=',
            $sanitized['content'],
            'Real wp_kses strips per-tag-disallowed attributes; the WP-path stub MUST mimic this.'
        );
        // href on <a> IS allow-listed → must survive.
        self::assertStringContainsString('href=', $sanitized['content']);
    }

    public function test_a_id_attribute_on_paragraph_is_stripped_on_wp_path(): void
    {
        // <p> allow-list: `class` only (per PropSanitizer::allowedHtml()).
        // `id` is NOT listed.
        $sanitized = PropSanitizer::sanitizeProps([
            'content' => '<p id="xss-anchor" class="ok">Hello</p>',
        ]);
        self::assertStringNotContainsString(
            'id=',
            $sanitized['content'],
            'Per-tag attr allow-list MUST strip `id` from <p> on the WP path.'
        );
        self::assertStringContainsString('class="ok"', $sanitized['content']);
    }

    // ------------------------------------------------------------------
    // (b) Recursive entity-decode on URI attributes — `&#x6a;avascript:`
    // must be rejected after decode.
    // ------------------------------------------------------------------

    public function test_b_hex_entity_encoded_javascript_uri_is_rejected(): void
    {
        // `&#x6a;` is hex-decoded by wp_kses_bad_protocol → `j` → full
        // protocol becomes `javascript:`. Must be stripped on the WP path.
        $sanitized = PropSanitizer::sanitizeProps([
            'content' => '<a href="&#x6a;avascript:alert(1)">click</a>',
        ]);
        self::assertStringNotContainsString(
            'href',
            $sanitized['content'],
            'Entity-encoded javascript: URI MUST be rejected after recursive entity-decode.'
        );
    }

    public function test_b_decimal_entity_encoded_javascript_uri_is_rejected(): void
    {
        // `&#106;` decimal-decodes to `j`.
        $sanitized = PropSanitizer::sanitizeProps([
            'content' => '<a href="&#106;avascript:alert(1)">click</a>',
        ]);
        self::assertStringNotContainsString(
            'href',
            $sanitized['content'],
            'Decimal entity-encoded javascript: URI MUST be rejected after recursive entity-decode.'
        );
    }

    public function test_b_named_entity_decode_on_javascript_uri_is_rejected(): void
    {
        // `&colon;` named-decodes to `:` → builds `javascript:` after
        // decode. Pin the named-entity branch in addition to the numeric
        // ones above.
        $sanitized = PropSanitizer::sanitizeProps([
            'content' => '<a href="javascript&colon;alert(1)">click</a>',
        ]);
        self::assertStringNotContainsString(
            'href',
            $sanitized['content'],
            'Named-entity-encoded javascript: URI MUST be rejected after recursive entity-decode.'
        );
    }

    // ------------------------------------------------------------------
    // (c) HTML-comment handling — real wp_kses removes <!-- … -->.
    // ------------------------------------------------------------------

    public function test_c_html_comment_block_is_stripped_on_wp_path(): void
    {
        $sanitized = PropSanitizer::sanitizeProps([
            'content' => '<p>Hello</p><!-- secret comment --><p>World</p>',
        ]);
        self::assertStringNotContainsString(
            '<!--',
            $sanitized['content'],
            'Real wp_kses strips HTML comments; the WP-path stub MUST mimic this.'
        );
        self::assertStringNotContainsString('secret comment', $sanitized['content']);
        self::assertStringContainsString('Hello', $sanitized['content']);
        self::assertStringContainsString('World', $sanitized['content']);
    }

    public function test_c_ie_conditional_comment_block_with_script_is_stripped(): void
    {
        // IE-style conditional comment historically smuggled <script>
        // past naive sanitizers. wp_kses strips comments before tag
        // parsing so the inner <script> never appears.
        $sanitized = PropSanitizer::sanitizeProps([
            'content' => '<p>safe</p><!--[if IE]><script>alert(1)</script><![endif]-->',
        ]);
        self::assertStringNotContainsString('<!--', $sanitized['content']);
        self::assertStringNotContainsString('<script', $sanitized['content']);
        self::assertStringNotContainsString('alert', $sanitized['content']);
    }

    // ------------------------------------------------------------------
    // (d) Malformed-tag state-machine — `<scr<script>ipt>` must collapse.
    // ------------------------------------------------------------------

    public function test_d_nested_malformed_script_payload_is_fully_neutralised(): void
    {
        // The classic "obfuscated script" payload. After the iterative
        // forbidden-tag strip + the allow-list pass, NO `<script`-shaped
        // fragment may remain.
        $sanitized = PropSanitizer::sanitizeProps([
            'content' => '<scr<script>ipt>alert(1)</script>',
        ]);
        self::assertStringNotContainsString('<script', $sanitized['content']);
        self::assertStringNotContainsString('alert', $sanitized['content']);
    }

    public function test_d_split_attribute_via_orphan_tag_is_neutralised(): void
    {
        // Similar shape with iframe: `<ifr<iframe>ame src=…>` — pin that
        // the iterative pass catches it.
        $sanitized = PropSanitizer::sanitizeProps([
            'content' => '<ifr<iframe>ame src="https://attacker"></iframe>',
        ]);
        self::assertStringNotContainsString('<iframe', $sanitized['content']);
        self::assertStringNotContainsString('attacker', $sanitized['content']);
    }

    // ------------------------------------------------------------------
    // PROOF-OF-DIVERGENCE — the fallback path is WEAKER on (a) + (b).
    //
    // Run a payload through PropSanitizer::fallbackSanitize directly and
    // assert the WEAKNESS — proves the WP-path tests above are NOT
    // validating the same logic as the fallback, defeating the
    // tautological-stub concern. If the fallback ever hardens to parity
    // with wp_kses, these tests SHOULD be updated to reflect that
    // hardening — but until then, the divergence is the contract.
    // ------------------------------------------------------------------

    public function test_proof_of_divergence_fallback_keeps_style_attribute_on_anchor(): void
    {
        // PropSanitizer::fallbackSanitize is private — invoke via reflection.
        $rc = new \ReflectionClass(PropSanitizer::class);
        $m = $rc->getMethod('fallbackSanitize');

        $payload = '<a href="https://example.com" style="background:red">click</a>';
        $result = (string) $m->invoke(null, $payload);

        // The fallback DOES NOT do per-tag attribute filtering — `style`
        // survives. This is the divergence we are pinning.
        self::assertStringContainsString(
            'style=',
            $result,
            'PropSanitizer::fallbackSanitize MUST keep `style` on `<a>` (no per-tag allow-list — '
            . 'this is the divergence the WP-path stub mimics correctly).'
        );
    }

    public function test_proof_of_divergence_fallback_lets_entity_encoded_javascript_through(): void
    {
        // The fallback's strncmp on the RAW value (no entity-decode) lets
        // `&#x6a;avascript:` survive. The WP path (above) rejects it.
        $rc = new \ReflectionClass(PropSanitizer::class);
        $m = $rc->getMethod('fallbackSanitize');

        $payload = '<a href="&#x6a;avascript:alert(1)">click</a>';
        $result = (string) $m->invoke(null, $payload);

        // Fallback keeps the href; the encoded `javascript:` survives.
        self::assertStringContainsString(
            'href=',
            $result,
            'PropSanitizer::fallbackSanitize keeps entity-encoded javascript: URIs '
            . '(no recursive decode — this is the divergence the WP-path stub mimics correctly).'
        );
    }
}
