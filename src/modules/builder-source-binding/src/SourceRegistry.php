<?php
/**
 * SourceRegistry — group-aware view of registered YOOtheme Builder sources.
 *
 * Reads the canonical YOOtheme source schema (`\YOOtheme\Builder\Source`'s
 * GraphQL `Query` type) via {@see YoothemeAdapter::getSourceFieldEntries()}
 * and groups every registered query field into one of:
 *
 *  - `apimapper`  — Sources contributed by the WootsUp API Mapper plugin.
 *  - `wordpress`  — WP core sources (posts.*, terms.*, users.*, …) and
 *                   classic third-party sources (ACF, WooCommerce, …).
 *  - `essentials` — uEssentials / YOOessentials sources.
 *
 * The classifier prefers YT's own `metadata.group` value (canonical) and
 * only falls back to a name-prefix heuristic when YT did not annotate the
 * field (very old plugins). When YT is not loaded the registry yields
 * three empty arrays so MCP-clients always see the expected scaffold.
 *
 * Each entry exposes `{name, label, group, type}` — Wave-6 F-04 fix.
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

    /**
     * Optional entry-source override (test seam). When set, listAll()
     * skips the YT adapter entirely and uses this closure's return as
     * the raw `getSourceFieldEntries()` result. Production code does NOT
     * pass this — `bootstrap.php` injects only the adapter.
     *
     * @var (\Closure(): (list<array{name: string, label: string, group: string, type: string}>|null))|null
     */
    private $entriesProvider;

    /**
     * @param (\Closure(): (list<array{name: string, label: string, group: string, type: string}>|null))|null $entriesProvider
     */
    public function __construct(?YoothemeAdapter $yootheme = null, ?\Closure $entriesProvider = null)
    {
        $this->yootheme = $yootheme ?? new YoothemeAdapter();
        $this->entriesProvider = $entriesProvider;
    }

    /**
     * Return the registered sources grouped by origin.
     *
     * The shape is stable across installs:
     * ```
     * [
     *   'apimapper'  => [{name, label, group, type}, ...],
     *   'wordpress'  => [...],
     *   'essentials' => [...],
     * ]
     * ```
     *
     * @return array{apimapper: list<array{name: string, label: string, group: string, type: string}>, wordpress: list<array{name: string, label: string, group: string, type: string}>, essentials: list<array{name: string, label: string, group: string, type: string}>}
     */
    public function listAll(): array
    {
        /** @var list<array{name: string, label: string, group: string, type: string}> $apimapper */
        $apimapper = [];
        /** @var list<array{name: string, label: string, group: string, type: string}> $wordpress */
        $wordpress = [];
        /** @var list<array{name: string, label: string, group: string, type: string}> $essentials */
        $essentials = [];

        if ($this->entriesProvider !== null) {
            $entries = ($this->entriesProvider)();
        } else {
            if (!$this->yootheme->isLoaded()) {
                return [
                    'apimapper' => $apimapper,
                    'wordpress' => $wordpress,
                    'essentials' => $essentials,
                ];
            }
            $entries = $this->yootheme->getSourceFieldEntries();
        }
        if ($entries === null) {
            return [
                'apimapper' => $apimapper,
                'wordpress' => $wordpress,
                'essentials' => $essentials,
            ];
        }

        foreach ($entries as $entry) {
            switch (self::classify($entry)) {
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
     * Classify a source field into one of the three top-level groups.
     *
     * Precedence:
     *  1. `metadata.group` value matched case-insensitively against
     *     well-known group-strings (canonical — every well-behaved YT
     *     source-provider sets this).
     *  2. Name-prefix fallback for fields without group-metadata.
     *
     * @param array{name: string, label: string, group: string, type: string} $entry
     */
    private static function classify(array $entry): string
    {
        $group = strtolower($entry['group']);
        if ($group !== '') {
            // API Mapper publishes flows under the explicit
            // "WootsUp - API Mapper" group string.
            if (str_contains($group, 'api mapper') || str_contains($group, 'apimapper')) {
                return 'apimapper';
            }
            if (str_contains($group, 'essentials') || str_contains($group, 'uikit')) {
                return 'essentials';
            }
            // Everything else metadata-tagged (WordPress, WooCommerce,
            // ACF, Toolset, custom plugin sources) groups under
            // 'wordpress' for now — that's the platform bucket.
            return 'wordpress';
        }

        // No metadata.group — fall back to name-prefix heuristic.
        $name = $entry['name'];
        if (str_starts_with($name, 'apimapper_') || str_starts_with($name, 'apimapperFlow')) {
            return 'apimapper';
        }
        if (str_starts_with($name, 'essentials_') || str_starts_with($name, 'uikit_')) {
            return 'essentials';
        }
        return 'wordpress';
    }
}
