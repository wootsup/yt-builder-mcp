<?php
/**
 * PointerControllerTrait — shared pointer-handling helpers for REST
 * controllers that write into the Builder state via JSON-Pointer URLs.
 *
 * Wave 6 Round-2 R2.8. Before this trait, ElementsController and
 * SourcesController each maintained their own `pointerFromRequest()` +
 * `assertPointerWithinTemplate()` implementations. Same logic, two
 * copies. Trait extraction:
 *
 *  - pointerFromRequest() — reconstructs the JSON-Pointer from the
 *    `element_path` route capture, optionally stripping a trailing
 *    action-suffix (`/move`, `/clone`, `/settings`, `/binding`).
 *  - assertPointerWithinTemplate() — Wave-6 Fix 6 cross-template-write
 *    defense, now logged via SecurityLogger.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Rest
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Rest;

use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\Util\SecurityLogger;

trait PointerControllerTrait
{
    /**
     * Reconstruct the JSON-Pointer from the `element_path` route capture.
     * Optionally strips a trailing action-suffix that the route regex
     * captured into element_path.
     *
     * Returns "" (root pointer) when element_path is empty.
     *
     * 1.0.1 — When a template_id is supplied AND the captured path starts
     * with `children/...` (or `/children/...`) it is treated as a
     * rel_path (the form `element_list` surfaces in its table column)
     * and auto-prepended with `/templates/<id>/layout` so the resulting
     * pointer matches the layout pointer the layout-walker expects. This
     * lets agents copy-paste rel_path values straight from element_list
     * output without manual prefix juggling.
     */
    protected static function pointerFromRequest(\WP_REST_Request $request, string $suffix = '', ?string $templateId = null): string
    {
        $rawPath = (string) $request['element_path'];
        if ($suffix !== '' && str_ends_with($rawPath, $suffix)) {
            $rawPath = substr($rawPath, 0, -strlen($suffix));
        }
        return self::normalizeElementPath($rawPath, $templateId);
    }

    /**
     * Normalize an inbound element_path / parent_path / to_parent_path
     * value into a fully-qualified JSON-Pointer rooted at the layout state
     * (e.g. `/templates/<id>/layout/children/0/...`).
     *
     * Accepted input forms (1.0.1 — Finding 2 / agent copy-paste hygiene):
     *
     *   - "" (empty)                          → "" (root pointer)
     *   - "/templates/<id>/layout/..."        → unchanged (already fully qualified)
     *   - "/children/..."  or "children/..." → prepended with `/templates/<id>/layout`
     *     when `$templateId` is supplied; otherwise just leading-slash normalized.
     *   - "templates/<id>/layout/..."         → leading-slash prepended only.
     *   - anything else without leading "/"   → leading-slash prepended.
     *
     * The cross-template-write defense (assertPointerWithinTemplate)
     * still runs after normalization, so a request that crafts a
     * different template_id into rel_path remains contained.
     */
    protected static function normalizeElementPath(string $rawPath, ?string $templateId = null): string
    {
        if ($rawPath === '') {
            return '';
        }
        // Already fully-qualified — preserve verbatim.
        if (str_starts_with($rawPath, '/templates/') || str_starts_with($rawPath, 'templates/')) {
            return $rawPath[0] === '/' ? $rawPath : '/' . $rawPath;
        }
        // rel_path form (`children/...` / `/children/...`) — when we know
        // the addressed template, lift to a layout-rooted pointer.
        $relPath = $rawPath[0] === '/' ? $rawPath : '/' . $rawPath;
        if ($templateId !== null && $templateId !== '' && str_starts_with($relPath, '/children/')) {
            return '/templates/' . $templateId . '/layout' . $relPath;
        }
        // Edge case: rel_path exactly "/" (root of layout).
        if ($templateId !== null && $templateId !== '' && $relPath === '/') {
            return '/templates/' . $templateId . '/layout';
        }
        return $relPath;
    }

    /**
     * Assert the user-supplied JSON-Pointer addresses a node within the
     * addressed template. Prevents cross-template writes (e.g. a request
     * for template A mutating template B via a crafted pointer).
     *
     * Returns null when the pointer is OK, or a `WP_Error 400` carrying
     * the canonical error-code when the pointer escapes the template.
     *
     * 1.0.1 — Wave-1.6 Audit-D-Gap: `$errorPrefix` lets sister controllers
     * (`source_binding`, `multi_items`) share this defense while keeping
     * their canonical error-code namespace. Default stays `elements` so
     * existing ElementsController call-sites and error-code pins remain
     * byte-identical.
     */
    protected function assertPointerWithinTemplate(
        string $templateId,
        string $pointer,
        string $errorPrefix = 'elements',
    ): ?\WP_Error {
        // Tool-sweep live-bug 2026-05-22: parent_path supplied without
        // leading "/" (e.g. `templates/<id>/layout/...`) caused
        // JsonPointer::parse to throw `InvalidArgumentException` →
        // uncaught 500 instead of structured 400. Normalize defensively:
        // accept both `/foo/bar` and `foo/bar` user input forms. The
        // empty string remains valid (root pointer).
        if ($pointer !== '' && $pointer[0] !== '/') {
            $pointer = '/' . $pointer;
        }

        // Wave-1.5 Finding (Audit B6a): double-prefix detection. When a
        // crafted pointer like `/templates/tpl/layout/templates/tpl/layout/
        // children/0` reaches the layout walker it silently 404s — the
        // inner `templates` token is a literal child key, not a template
        // selector. That confuses agents who fed rel_path AND fully-
        // qualified forms into the same parameter. Detect explicitly and
        // emit a structured 400 with the canonical double_prefix code.
        //
        // Wave-1.6 audit-E E-Minor-1: build the prefix via JsonPointer::
        // compile() (same idiom as the bottom-half allowedPrefix below)
        // so a template-id containing `/` or `~` is RFC-6901 encoded
        // (`~1`, `~0`) instead of breaking the literal string compare.
        $layoutPrefix = JsonPointer::compile(['templates', $templateId, 'layout']) . '/';
        if ($pointer !== '' && str_starts_with($pointer, $layoutPrefix)) {
            $tail = substr($pointer, strlen($layoutPrefix));
            if (str_contains($tail, '/templates/') || str_starts_with($tail, 'templates/')) {
                SecurityLogger::log(SecurityLogger::EVENT_CROSS_TEMPLATE_DENY, [
                    'pointer' => $pointer,
                    'template_id' => $templateId,
                    'reason' => 'double_prefix',
                ]);
                return new \WP_Error(
                    'yootheme_builder_mcp.' . $errorPrefix . '.double_prefix',
                    sprintf(
                        'Pointer "%s" contains a duplicated `/templates/<id>/layout` prefix. ' .
                        'Pass EITHER the rel_path form (e.g. `/children/0`) OR the fully-' .
                        'qualified pointer once — never both concatenated.',
                        $pointer,
                    ),
                    ['status' => 400],
                );
            }
        }

        $allowedPrefix = JsonPointer::compile(['templates', $templateId]);
        if (JsonPointer::isWithinPrefix($pointer, $allowedPrefix)) {
            return null;
        }

        SecurityLogger::log(SecurityLogger::EVENT_CROSS_TEMPLATE_DENY, [
            'pointer' => $pointer,
            'template_id' => $templateId,
            'allowed_prefix' => $allowedPrefix,
        ]);
        return new \WP_Error(
            'yootheme_builder_mcp.' . $errorPrefix . '.cross_template_write_denied',
            sprintf(
                'Pointer "%s" is not within template "%s" (allowed prefix "%s").',
                $pointer,
                $templateId,
                $allowedPrefix,
            ),
            ['status' => 400],
        );
    }
}
