<?php
/**
 * PropSanitizer — unit tests.
 *
 * Wave-1 Finding C-5 (XSS): the write surface
 * (ElementsController::add / update_settings / clone) accepted any string
 * verbatim and persisted it into wp_option('yootheme'). YOOtheme Pro
 * renders several prop fields (most notably `editor` / `content` /
 * `text` / `title` / `description`) as raw HTML on the customer-facing
 * frontend — a payload like `<script>alert("XSS")</script>` therefore
 * lands in the public page source and fires on every visit.
 *
 * PropSanitizer is the single funnel that runs on every persistence path
 * before LayoutWriter::writeTemplate touches storage. It:
 *
 *  1) preserves plain string props unchanged (no HTML tags → no change),
 *  2) preserves a small, customer-friendly HTML allow-list for rich-text
 *     props (a, b, br, em, i, p, span, strong, ul, ol, li, h1-h6,
 *     blockquote, code, pre),
 *  3) strips `<script>`, `<iframe>`, `<object>`, `<embed>`, `<style>`,
 *     `<svg>`, event-handler attributes (onerror, onclick, …), and
 *     `javascript:` URIs,
 *  4) recurses into nested arrays so `props.source.props.title` is
 *     sanitized just like `props.content`.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Util\PropSanitizer;

#[CoversClass(PropSanitizer::class)]
final class PropSanitizerTest extends TestCase
{
    public function test_plain_string_without_tags_is_unchanged(): void
    {
        // The vast majority of YT props are short attribute-style strings.
        // The sanitizer must be a no-op for them — otherwise legitimate
        // CSS-classes / image-paths / option-values get mangled.
        $props = [
            'source' => 'cat.jpg',
            'class' => 'uk-border-rounded',
            'style' => 'default',
            'alt' => 'A cat sitting on a mat',
        ];
        self::assertSame($props, PropSanitizer::sanitizeProps($props));
    }

    public function test_strips_script_tag_from_string_value(): void
    {
        // Wave-1 Finding C-5: the exact payload that surfaced the bug.
        $sanitized = PropSanitizer::sanitizeProps([
            'content' => '<script>alert("XSS-A5")</script>',
        ]);
        self::assertIsArray($sanitized);
        self::assertIsString($sanitized['content']);
        self::assertStringNotContainsString('<script', $sanitized['content']);
        self::assertStringNotContainsString('</script', $sanitized['content']);
    }

    public function test_preserves_strong_em_and_other_text_formatting_tags(): void
    {
        // The whole point of the customer-friendly allow-list: rich-text
        // formatting survives so editor content keeps working.
        $sanitized = PropSanitizer::sanitizeProps([
            'content' => '<p>Hello <strong>world</strong> and <em>others</em>.</p>',
        ]);
        self::assertSame(
            '<p>Hello <strong>world</strong> and <em>others</em>.</p>',
            $sanitized['content'],
        );
    }

    public function test_preserves_link_with_href_and_title(): void
    {
        // <a href="…" title="…"> is one of the most common rich-text uses.
        // The allow-list keeps href + title.
        $sanitized = PropSanitizer::sanitizeProps([
            'content' => '<a href="https://example.com" title="Example">click</a>',
        ]);
        self::assertStringContainsString('href="https://example.com"', $sanitized['content']);
        self::assertStringContainsString('>click</a>', $sanitized['content']);
    }

    public function test_strips_javascript_uri_from_href(): void
    {
        // `javascript:` URIs are a classic XSS vector even inside an
        // allowed <a> tag. The sanitizer must strip them.
        $sanitized = PropSanitizer::sanitizeProps([
            'content' => '<a href="javascript:alert(1)">x</a>',
        ]);
        self::assertStringNotContainsString('javascript:', $sanitized['content']);
    }

    public function test_strips_inline_event_handler_attribute(): void
    {
        // onerror / onclick / onload etc. are XSS vectors that survive
        // strip_tags. wp_kses with the allow-list removes them.
        $sanitized = PropSanitizer::sanitizeProps([
            'content' => '<img src="x" onerror="alert(1)">',
        ]);
        self::assertStringNotContainsString('onerror', $sanitized['content']);
    }

    public function test_recurses_into_nested_arrays(): void
    {
        // Multi-Items bindings put rich-text inside `props.source.props.title`.
        // Sanitizer must walk nested arrays the same way.
        $sanitized = PropSanitizer::sanitizeProps([
            'source' => [
                'query' => ['name' => 'posts.singlePost'],
                'props' => [
                    'title' => '<script>alert("nested")</script>Hi',
                    'image' => 'a.jpg',
                ],
            ],
        ]);
        self::assertStringNotContainsString('<script', $sanitized['source']['props']['title']);
        self::assertSame('a.jpg', $sanitized['source']['props']['image']);
        self::assertSame('posts.singlePost', $sanitized['source']['query']['name']);
    }

    public function test_preserves_non_string_scalars(): void
    {
        // Numbers / booleans / null pass through unchanged. The MCP tool
        // surface accepts them for things like `to_index`, `visible`,
        // `column_count`, etc.
        $sanitized = PropSanitizer::sanitizeProps([
            'visible' => true,
            'column_count' => 3,
            'opacity' => 0.5,
            'optional' => null,
        ]);
        self::assertTrue($sanitized['visible']);
        self::assertSame(3, $sanitized['column_count']);
        self::assertSame(0.5, $sanitized['opacity']);
        self::assertNull($sanitized['optional']);
    }

    public function test_strips_iframe_object_embed_style_svg(): void
    {
        // Non-script XSS vectors that strip_tags() alone wouldn't catch
        // safely (e.g. <iframe src="javascript:..."> renders in YT-Pro).
        $sanitized = PropSanitizer::sanitizeProps([
            'a' => '<iframe src="evil"></iframe>',
            'b' => '<object data="evil"></object>',
            'c' => '<embed src="evil">',
            'd' => '<style>body{display:none}</style>',
            'e' => '<svg onload="alert(1)"></svg>',
        ]);
        foreach (['a', 'b', 'c', 'd', 'e'] as $k) {
            self::assertStringNotContainsString('<iframe', $sanitized[$k] ?? '');
            self::assertStringNotContainsString('<object', $sanitized[$k] ?? '');
            self::assertStringNotContainsString('<embed', $sanitized[$k] ?? '');
            self::assertStringNotContainsString('<style', $sanitized[$k] ?? '');
            self::assertStringNotContainsString('<svg', $sanitized[$k] ?? '');
        }
    }

    public function test_returns_empty_array_for_empty_input(): void
    {
        // Edge case: empty props on element_add. No-op, no error.
        self::assertSame([], PropSanitizer::sanitizeProps([]));
    }

    /**
     * Cross-platform parity: when wp_kses is not available (Joomla
     * runtime — the same ElementOps funnel runs on plg_system_ytbmcp
     * + com_ytbmcp), the built-in fallback must produce equivalent
     * security guarantees. Drive the fallback by reflecting into the
     * private helper directly so we exercise the Joomla code path.
     */
    public function test_fallback_strips_script_tag_when_wp_kses_unavailable(): void
    {
        $ref = new \ReflectionClass(PropSanitizer::class);
        $method = $ref->getMethod('fallbackSanitize');

        $script = '<script>alert("XSS-A5")</script>';
        $out = (string) $method->invoke(null, $script);
        self::assertStringNotContainsString('<script', $out);
        self::assertStringNotContainsString('</script', $out);

        // Strong/em survive the fallback allow-list too.
        $rich = '<p>Hello <strong>world</strong></p>';
        $out = (string) $method->invoke(null, $rich);
        self::assertStringContainsString('<strong>world</strong>', $out);
    }

    public function test_fallback_strips_javascript_uri_and_event_handlers(): void
    {
        $ref = new \ReflectionClass(PropSanitizer::class);
        $method = $ref->getMethod('fallbackSanitize');

        $payload = '<a href="javascript:alert(1)" onclick="bad()">x</a>';
        $out = (string) $method->invoke(null, $payload);
        self::assertStringNotContainsString('javascript:', $out);
        self::assertStringNotContainsString('onclick', $out);
    }
}
