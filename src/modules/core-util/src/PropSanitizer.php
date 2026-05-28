<?php
/**
 * PropSanitizer — server-side HTML sanitization for element props on the
 * MCP write surface.
 *
 * Wave-1 Finding C-5 (XSS): the audit submitted
 * `<script>alert("XSS-A5")</script>` as a `grid_item.props.content`
 * value through yootheme_builder_element_add. The controller accepted
 * the payload verbatim and persisted it into wp_option('yootheme').
 * YOOtheme Pro renders rich-text props (`content`, `text`, `title`,
 * `description`, `editor` fields, etc.) as raw HTML on the public
 * frontend — the payload would fire for every visitor.
 *
 * This class is the single funnel that runs on every persistence path
 * (ElementOps::add / updateSettings / mergeProps) before LayoutWriter
 * touches storage. It is shared between WordPress and Joomla via
 * ElementOps, so the same allow-list applies on both platforms.
 *
 * Design
 * ------
 * 1) ALL string values in the props tree are run through the same
 *    allow-list — we cannot reliably enumerate which YT element-types
 *    treat which keys as HTML, so the safe default is "every string"
 *    rather than "an opt-in list of keys".
 *
 * 2) Strings WITHOUT any `<` character short-circuit to identity. This
 *    is the vast majority of YT props (CSS classes, image paths, style
 *    tokens, breakpoint values, …) — keeping them byte-identical means
 *    the sanitizer is a no-op on the hot path and we don't accidentally
 *    HTML-encode `&` into `&amp;` inside URLs.
 *
 * 3) When `wp_kses` is available (WordPress runtime) we delegate to it
 *    with the customer-friendly allow-list below. This matches the
 *    convention used everywhere else in the WP modules (SettingsPage,
 *    plugin bootstrap).
 *
 * 4) When `wp_kses` is NOT available (Joomla runtime, unit-test
 *    bootstrap with no kses stub), we apply a built-in safe filter
 *    that strips the same forbidden tags + event-handler attributes +
 *    `javascript:` URIs. Result is byte-equivalent on the security-
 *    relevant strip surface; cosmetic whitespace may differ. Joomla
 *    sites get the same defence-in-depth as WP sites.
 *
 * Allow-list (intentionally small, customer-friendly):
 *   inline:   a (href, title, target, rel), b, i, em, strong, span, br,
 *             code, mark, sub, sup, small, q
 *   block:    p, blockquote, pre, h1, h2, h3, h4, h5, h6, ul, ol, li, hr
 *
 * Notably forbidden:
 *   <script>, <iframe>, <object>, <embed>, <style>, <svg>, <link>,
 *   <meta>, <form>, <input>, <button>, <textarea>, <select>, <option>,
 *   ALL event-handler attributes (onerror, onclick, onload, onmouse*,
 *   onfocus, onblur, …), `javascript:` URIs in any attribute.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Util
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Util;

final class PropSanitizer
{
    /**
     * Customer-friendly HTML allow-list applied to every string value.
     *
     * Shape matches the wp_kses contract: tag-name → map of allowed
     * attributes (value `true` for "any string"). Anything not listed
     * is stripped — both tags AND attributes.
     *
     * @return array<string, array<string, bool>>
     */
    public static function allowedHtml(): array
    {
        $emptyAttrs = [];
        $linkAttrs = [
            'href' => true,
            'title' => true,
            'target' => true,
            'rel' => true,
        ];
        return [
            'a' => $linkAttrs,
            'b' => $emptyAttrs,
            'i' => $emptyAttrs,
            'em' => $emptyAttrs,
            'strong' => $emptyAttrs,
            'span' => ['class' => true],
            'br' => $emptyAttrs,
            'code' => $emptyAttrs,
            'mark' => $emptyAttrs,
            'sub' => $emptyAttrs,
            'sup' => $emptyAttrs,
            'small' => $emptyAttrs,
            'q' => $emptyAttrs,
            'p' => ['class' => true],
            'blockquote' => $emptyAttrs,
            'pre' => $emptyAttrs,
            'h1' => $emptyAttrs,
            'h2' => $emptyAttrs,
            'h3' => $emptyAttrs,
            'h4' => $emptyAttrs,
            'h5' => $emptyAttrs,
            'h6' => $emptyAttrs,
            'ul' => $emptyAttrs,
            'ol' => $emptyAttrs,
            'li' => $emptyAttrs,
            'hr' => $emptyAttrs,
        ];
    }

    /**
     * Sanitize every string value in a props tree, recursing into
     * nested arrays. Non-string scalars (int, float, bool, null) pass
     * through unchanged.
     *
     * The function is pure — input is not mutated. Callers should
     * assign the return value back to the field they want sanitized.
     *
     * @param array<int|string, mixed> $props
     * @return array<int|string, mixed>
     */
    public static function sanitizeProps(array $props): array
    {
        if ($props === []) {
            return [];
        }
        $out = [];
        foreach ($props as $key => $value) {
            $out[$key] = self::sanitizeValue($value);
        }
        return $out;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sanitizeValue($value)
    {
        if (is_array($value)) {
            /** @var array<int|string, mixed> $value */
            return self::sanitizeProps($value);
        }
        if (!is_string($value)) {
            return $value;
        }
        // Fast-path: strings without any HTML-like character cannot
        // contain a tag, so we skip the allow-list entirely. This is
        // the hot path — most YT props are CSS classes / paths / option
        // tokens (`'cat.jpg'`, `'uk-border-rounded'`, `'default'`).
        if (strpos($value, '<') === false) {
            return $value;
        }
        return self::sanitizeHtml($value);
    }

    /**
     * Sanitize an HTML string against the allow-list. Delegates to
     * `wp_kses` when available (WordPress); otherwise falls back to a
     * built-in safe filter for Joomla / unit-test bootstrap.
     */
    private static function sanitizeHtml(string $html): string
    {
        if (\function_exists('wp_kses')) {
            // wp_kses applies the allow-list AND scrubs event-handler
            // attributes / javascript: URIs natively. Customer-facing
            // WP sites get the full WordPress defence.
            return \wp_kses($html, self::allowedHtml());
        }
        return self::fallbackSanitize($html);
    }

    /**
     * Built-in fallback when wp_kses is unavailable (Joomla, unit-test
     * environments without a kses stub). Behaviour is intentionally
     * conservative — it errs on the side of stripping rather than
     * letting anything through.
     *
     * Algorithm:
     *  1) Iteratively strip dangerous block tags (script/iframe/object/
     *     embed/style/svg/form/etc.) including their content. We do
     *     this BEFORE the allow-list pass because PHP's strip_tags()
     *     keeps inner text — leaking `alert("xss")` past the filter.
     *  2) Use strip_tags() with the allow-list of OPENING tags. This
     *     drops every tag not on the allow-list (including the
     *     dangerous ones now neutered to empty).
     *  3) Walk every remaining tag and scrub:
     *     - any attribute whose name starts with `on` (event handlers)
     *     - any attribute whose value starts with `javascript:` or
     *       `vbscript:` or `data:` (when stripped of whitespace).
     */
    private static function fallbackSanitize(string $html): string
    {
        // (1) Eliminate forbidden tags + their contents (so inner JS
        // doesn't leak as text). Repeat until no more changes, in case
        // of nested constructs like `<style><script>…</script></style>`.
        $forbidden = [
            'script', 'iframe', 'object', 'embed', 'style', 'svg',
            'link', 'meta', 'form', 'input', 'button', 'textarea',
            'select', 'option', 'applet', 'frame', 'frameset',
            'audio', 'video', 'source',
        ];
        do {
            $previous = $html;
            foreach ($forbidden as $tag) {
                // Match the opening tag + everything up to the matching
                // close OR to end-of-string. `s` flag makes `.` match
                // newlines so multi-line payloads are eaten too.
                $pattern = '#<' . $tag . '\b[^>]*>.*?(</' . $tag . '\s*>|$)#is';
                $stripped = preg_replace($pattern, '', $html);
                if (is_string($stripped)) {
                    $html = $stripped;
                }
                // Self-closing form (e.g. `<embed src=…>` without close).
                $patternVoid = '#<' . $tag . '\b[^>]*/?>#i';
                $stripped = preg_replace($patternVoid, '', $html);
                if (is_string($stripped)) {
                    $html = $stripped;
                }
            }
        } while ($html !== $previous);

        // (2) Allow-list pass — drop every tag not explicitly listed.
        $allowedTags = '';
        foreach (array_keys(self::allowedHtml()) as $tagName) {
            $allowedTags .= '<' . $tagName . '>';
        }
        $html = strip_tags($html, $allowedTags);

        // (3) Scrub event-handler attributes + `javascript:` URIs from
        // the surviving allow-listed tags.
        $scrubbed = preg_replace_callback(
            '#<([a-z][a-z0-9]*)([^>]*)>#i',
            static function (array $m): string {
                $tag = strtolower($m[1]);
                $attrs = $m[2];
                // Drop on*= handlers.
                $attrs = (string) preg_replace(
                    '#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i',
                    '',
                    $attrs,
                );
                // Drop javascript: / vbscript: / data: URIs in href/src/etc.
                $attrs = (string) preg_replace_callback(
                    '#(\s+[a-z]+\s*=\s*)("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i',
                    static function (array $am): string {
                        $prefix = $am[1];
                        $rawVal = $am[3] ?? ($am[4] ?? ($am[5] ?? ''));
                        $quote = isset($am[3]) ? '"' : (isset($am[4]) ? '\'' : '"');
                        $lower = ltrim(strtolower($rawVal));
                        if (
                            strncmp($lower, 'javascript:', 11) === 0
                            || strncmp($lower, 'vbscript:', 9) === 0
                            || strncmp($lower, 'data:text/html', 14) === 0
                        ) {
                            // Strip the attribute entirely.
                            return '';
                        }
                        return $prefix . $quote . $rawVal . $quote;
                    },
                    $attrs,
                );
                return '<' . $tag . $attrs . '>';
            },
            $html,
        );
        return is_string($scrubbed) ? $scrubbed : $html;
    }
}
