<?php
/**
 * SourceRegistry — group-aware view of registered YOOtheme Builder sources.
 *
 * Wave 2 Task 2.5 (read-only). Returns a structure with three top-level
 * groups — `apimapper` (sources contributed by the WootsUp API Mapper
 * companion plugin), `wordpress` (built-in WP post-types, terms, options),
 * and `essentials` (third-party uEssentials types) — so MCP-clients can
 * present them with stable grouping no matter which subset is installed.
 *
 * When YOOtheme Pro is not loaded (e.g. unit-test bootstrap), all groups
 * are empty arrays. When YT is present, we ask its source-schema for the
 * Query type's fields and bucket them by name-prefix heuristic. The full
 * field-introspection (return-type, args, current-binding) is a Wave 3
 * task and lives in `SourceBinding`'s write path.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\SourceBinding
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\SourceBinding;

use WootsUp\BuilderMcp\Yootheme\YoothemeAdapter;

final class SourceRegistry
{
    private readonly YoothemeAdapter $yootheme;

    public function __construct(?YoothemeAdapter $yootheme = null)
    {
        $this->yootheme = $yootheme ?? new YoothemeAdapter();
    }

    /**
     * Return the registered sources grouped by origin.
     *
     * The shape is intentionally stable across installs:
     * ```
     * [
     *   'apimapper'  => [...],
     *   'wordpress'  => [...],
     *   'essentials' => [...],
     * ]
     * ```
     *
     * Each group value is `list<array{name: string, ...}>`. Wave-2 emits
     * names only; Wave-3 will enrich with return-type and args.
     *
     * @return array{apimapper: list<array<string, mixed>>, wordpress: list<array<string, mixed>>, essentials: list<array<string, mixed>>}
     */
    public function listAll(): array
    {
        /** @var list<array<string, mixed>> $apimapper */
        $apimapper = [];
        /** @var list<array<string, mixed>> $wordpress */
        $wordpress = [];
        /** @var list<array<string, mixed>> $essentials */
        $essentials = [];

        if (!$this->yootheme->isLoaded()) {
            return [
                'apimapper' => $apimapper,
                'wordpress' => $wordpress,
                'essentials' => $essentials,
            ];
        }

        $fields = $this->ytFields();
        if ($fields === null) {
            return [
                'apimapper' => $apimapper,
                'wordpress' => $wordpress,
                'essentials' => $essentials,
            ];
        }

        foreach ($fields as $name => $_meta) {
            $entry = ['name' => (string) $name];
            switch (self::classify((string) $name)) {
                case 'apimapper':
                    $apimapper[] = $entry;
                    break;
                case 'essentials':
                    $essentials[] = $entry;
                    break;
                default:
                    $wordpress[] = $entry;
                    break;
            }
        }

        return [
            'apimapper' => $apimapper,
            'wordpress' => $wordpress,
            'essentials' => $essentials,
        ];
    }

    /**
     * Best-effort: pull the GraphQL `Query` type's fields out of the YOOtheme
     * Builder source-schema. Returns null on any failure — we never throw.
     *
     * @return array<string, mixed>|null
     */
    private function ytFields(): ?array
    {
        // Wave-6 R2.7: every YOOtheme symbol access funnels through the
        // adapter (single coupling point — see core-yootheme module).
        return $this->yootheme->getSourceFields();
    }

    /**
     * Bucket a Query-field name into one of the three group keys.
     *
     * Heuristic (Wave-2):
     *  - `apimapper_*`   → apimapper
     *  - `essentials_*`  → essentials
     *  - everything else → wordpress
     *
     * Wave-3 will refine this once we mine the group-metadata YOOtheme
     * itself attaches via `metadata.group`.
     */
    private static function classify(string $name): string
    {
        if (str_starts_with($name, 'apimapper_')) {
            return 'apimapper';
        }
        if (str_starts_with($name, 'essentials_') || str_starts_with($name, 'uikit_')) {
            return 'essentials';
        }
        return 'wordpress';
    }
}
