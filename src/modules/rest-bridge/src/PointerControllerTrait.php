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
     */
    protected static function pointerFromRequest(\WP_REST_Request $request, string $suffix = ''): string
    {
        $rawPath = (string) $request['element_path'];
        if ($suffix !== '' && str_ends_with($rawPath, $suffix)) {
            $rawPath = substr($rawPath, 0, -strlen($suffix));
        }
        if ($rawPath === '') {
            return '';
        }
        return $rawPath[0] === '/' ? $rawPath : '/' . $rawPath;
    }

    /**
     * Assert the user-supplied JSON-Pointer addresses a node within the
     * addressed template. Prevents cross-template writes (e.g. a request
     * for template A mutating template B via a crafted pointer).
     *
     * Returns null when the pointer is OK, or a `WP_Error 400` carrying
     * the canonical error-code when the pointer escapes the template.
     */
    protected function assertPointerWithinTemplate(string $templateId, string $pointer): ?\WP_Error
    {
        // Tool-sweep live-bug 2026-05-22: parent_path supplied without
        // leading "/" (e.g. `templates/<id>/layout/...`) caused
        // JsonPointer::parse to throw `InvalidArgumentException` →
        // uncaught 500 instead of structured 400. Normalize defensively:
        // accept both `/foo/bar` and `foo/bar` user input forms. The
        // empty string remains valid (root pointer).
        if ($pointer !== '' && $pointer[0] !== '/') {
            $pointer = '/' . $pointer;
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
            'yootheme_builder_mcp.elements.cross_template_write_denied',
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
