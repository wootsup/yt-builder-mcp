<?php
/**
 * PageQuery — pure-PHP listing + meta-extraction for templates.
 *
 * Wave 2 Task 2.2. PageQuery wraps LayoutReader and projects the raw
 * `wp_option('yootheme').templates` map into shapes that the REST handlers
 * (and ultimately MCP tools) expect:
 *
 *  - list()    → [{id, name?, title?, etag}, ...] for the page-index view.
 *  - layout()  → full template tree for the page-detail view.
 *  - schema()  → flat [{path, type, name?}, ...] for the structure view.
 *  - etag()    → proxies LayoutReader::etag() for optimistic-lock plumbing.
 *
 * Schema walks the layout tree depth-first and emits one entry per node,
 * using the JSON-Pointer path into the global wp_option document. The
 * walker is read-only — no mutations to either the tree or the surrounding
 * wp_option blob.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Pages
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Pages;

use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\State\LayoutReader;

final class PageQuery
{
    public function __construct(private readonly LayoutReader $reader)
    {
    }

    /**
     * Return a list of template-meta records — one per top-level template
     * in wp_option('yootheme').templates.
     *
     * Returns the empty list (NOT null) when no templates exist.
     *
     * @return list<array{id: string, name?: string, title?: string, etag: string}>
     */
    public function list(): array
    {
        $ids = $this->reader->listTemplateIds();
        if ($ids === []) {
            return [];
        }
        $etag = $this->reader->etag();
        $out = [];
        foreach ($ids as $id) {
            $tpl = $this->reader->readTemplate($id);
            $entry = ['id' => $id];
            if (is_array($tpl)) {
                if (isset($tpl['name']) && is_string($tpl['name'])) {
                    $entry['name'] = $tpl['name'];
                }
                if (isset($tpl['title']) && is_string($tpl['title'])) {
                    $entry['title'] = $tpl['title'];
                }
            }
            $entry['etag'] = $etag;
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Return the full template tree for a single template-ID, or null when
     * the template is unknown.
     *
     * @return array<string, mixed>|null
     */
    public function layout(string $templateId): ?array
    {
        return $this->reader->readTemplate($templateId);
    }

    /**
     * Return a flat depth-first list of every node in the template's
     * layout, addressed by JSON-Pointer relative to the global
     * wp_option('yootheme') document — i.e. paths start with
     * `/templates/<id>/layout/...`. Returns null for unknown templates.
     *
     * @return list<array{path: string, type: string, name?: string}>|null
     */
    public function schema(string $templateId): ?array
    {
        $tpl = $this->reader->readTemplate($templateId);
        if ($tpl === null) {
            return null;
        }

        $out = [];
        if (isset($tpl['layout']) && is_array($tpl['layout'])) {
            $basePointer = JsonPointer::compile(['templates', $templateId, 'layout']);
            self::walk($tpl['layout'], $basePointer, $out);
        }
        return $out;
    }

    /**
     * Proxy for LayoutReader::etag(), surfaced here so REST handlers don't
     * need a second dependency.
     */
    public function etag(): string
    {
        return $this->reader->etag();
    }

    /**
     * Depth-first walk over a node + its children, appending one entry per
     * descended-into node to $out.
     *
     * The root layout node itself is not emitted — only its children-tree.
     * Each child sits at $pointer + "/children/<index>".
     *
     * @param array<string, mixed> $node
     * @param list<array{path: string, type: string, name?: string}> $out
     */
    private static function walk(array $node, string $pointer, array &$out): void
    {
        if (!isset($node['children']) || !is_array($node['children'])) {
            return;
        }
        foreach ($node['children'] as $index => $child) {
            if (!is_array($child)) {
                continue;
            }
            $childPointer = $pointer . '/children/' . (string) $index;
            $entry = ['path' => $childPointer];
            $entry['type'] = isset($child['type']) && is_string($child['type'])
                ? $child['type']
                : 'unknown';
            if (isset($child['name']) && is_string($child['name'])) {
                $entry['name'] = $child['name'];
            }
            $out[] = $entry;
            self::walk($child, $childPointer, $out);
        }
    }
}
