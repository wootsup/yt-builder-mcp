<?php
/**
 * Per-template "last-modified" tracker (cookbook §4.7 / F-08 fix).
 *
 * YT's `wp_option('yootheme').templates.<id>` doesn't always carry a
 * `modified` field — the Builder JS only writes it on explicit saves.
 * yt-builder-mcp keeps its own tracking option, bumped on every
 * `LayoutWriter::writeTemplate()` mutation, so `/pages` listing always
 * returns a consistent ISO-8601 `modified_at`.
 *
 * Storage: `#__ytb_mcp_options(option_key='pages_meta')`, value JSON
 * `{<templateId>: {modified_at: 'YYYY-MM-DDTHH:mm:ss+00:00'}}`.
 *
 * @package    WootsUp\BuilderMcp\Platform\Joomla\State
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Platform\Joomla\State;

defined('_JEXEC') or die;

use WootsUp\BuilderMcp\Pages\PagesMetaStoreInterface;
use WootsUp\BuilderMcp\Platform\Joomla\Storage\JoomlaOptionStore;

final class JoomlaPagesMetaStore implements PagesMetaStoreInterface
{
    public const OPTION_KEY = 'pages_meta';

    public function __construct(private readonly JoomlaOptionStore $store = new JoomlaOptionStore())
    {
    }

    /** @return array<string, array{modified_at: string}> */
    public function all(): array
    {
        $raw = $this->store->get(self::OPTION_KEY, null);
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);
        return \is_array($decoded) ? $decoded : [];
    }

    public function modifiedAt(string $templateId): ?string
    {
        $all = $this->all();
        return $all[$templateId]['modified_at'] ?? null;
    }

    public function touch(string $templateId): void
    {
        if ($templateId === '') {
            return;
        }
        $all = $this->all();
        $all[$templateId] = ['modified_at' => \gmdate('c')];
        $this->store->set(
            self::OPTION_KEY,
            (string) \json_encode($all, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
        );
    }

    public function forget(string $templateId): void
    {
        $all = $this->all();
        if (!isset($all[$templateId])) {
            return;
        }
        unset($all[$templateId]);
        $this->store->set(
            self::OPTION_KEY,
            (string) \json_encode($all, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
        );
    }
}
