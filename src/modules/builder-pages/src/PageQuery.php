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

use WootsUp\BuilderMcp\Elements\TreeWalker;
use WootsUp\BuilderMcp\SourceBinding\BindingSerializer;
use WootsUp\BuilderMcp\State\JsonPointer;
use WootsUp\BuilderMcp\State\LayoutReader;

final class PageQuery
{
    private readonly PagesMetaStore $meta;

    public function __construct(
        private readonly LayoutReader $reader,
        ?PagesMetaStore $meta = null,
    ) {
        $this->meta = $meta ?? new PagesMetaStore();
    }

    /**
     * Return a list of template-meta records — one per top-level template
     * in wp_option('yootheme').templates.
     *
     * Returns the empty list (NOT null) when no templates exist.
     *
     * F-02: each entry now carries `elements_count` — the recursive count of
     * every element in the template's `layout` tree, sourced from the same
     * walker (TreeWalker::countDescendants) that powers `element_list.total`
     * and `page_get_schema.total`. The three counts are guaranteed to agree
     * for any given state.
     *
     * F-08: `type` (template-type — currently 'template'/'layout') and
     * `modified_at` (ISO-8601 from layout.modified or post.post_modified_gmt)
     * are surfaced eagerly so the LLM-facing pages_list table is filled-in on
     * first call instead of forcing a follow-up page_get_layout per row.
     *
     * @return list<array{id: string, name?: string, label?: string, title?: string, type: string, elements_count: int, modified_at: string|null, etag: string}>
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
            $elementsCount = 0;
            if (is_array($tpl)) {
                if (isset($tpl['name']) && is_string($tpl['name'])) {
                    $entry['name'] = $tpl['name'];
                    // F-08: surface `label` as the human-friendly alias of `name`
                    // so the MCP table mapper can fill the NAME column without
                    // a per-row follow-up call.
                    $entry['label'] = $tpl['name'];
                }
                if (isset($tpl['title']) && is_string($tpl['title'])) {
                    $entry['title'] = $tpl['title'];
                }
                if (isset($tpl['layout']) && is_array($tpl['layout'])) {
                    /** @var array<string, mixed> $layout */
                    $layout = $tpl['layout'];
                    $elementsCount = TreeWalker::countDescendants($layout);
                }
                // F-08: surface template `type` (YT distinguishes 'template'
                // / 'layout' for full-page vs sectional templates) and
                // `modified_at` from whatever the layout-blob carries.
                if (isset($tpl['type']) && is_string($tpl['type'])) {
                    $entry['type'] = $tpl['type'];
                }
                if (isset($tpl['modified']) && (is_string($tpl['modified']) || is_int($tpl['modified']))) {
                    $entry['modified_at'] = self::iso8601From($tpl['modified']);
                } elseif (isset($tpl['modified_at']) && is_string($tpl['modified_at'])) {
                    $entry['modified_at'] = $tpl['modified_at'];
                }
            }
            // F-08 fix (Maria-Audit 2026-05-22): when the YT-side blob does
            // not carry a `modified` / `modified_at` field, fall back to the
            // per-template tracking option populated by
            // LayoutWriter::writeTemplate(). Cold-start lookups still get a
            // non-null timestamp instead of an undefined key.
            if (!isset($entry['modified_at'])) {
                $tracked = $this->meta->modifiedAt($id);
                // T7 (Audit-v3 B.9): the `modified_at` key is ALWAYS present
                // in the response — never a missing key. A template that
                // has never been written through the MCP surface and
                // carries no YT-side timestamp legitimately has an unknown
                // modification time; `null` is the honest value (the MCP
                // table mapper renders it as "—" rather than guessing).
                $entry['modified_at'] = $tracked;
            }
            if (!isset($entry['type'])) {
                // Default — YT-Pro distinguishes 'template' from 'layout';
                // when the blob doesn't carry the key, the canonical value
                // is the legacy 'template'.
                $entry['type'] = 'template';
            }
            $entry['elements_count'] = $elementsCount;
            $entry['etag'] = $etag;
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Coerce a YT `modified` field (which may be an ISO-string OR a unix
     * timestamp integer) into a canonical ISO-8601 string.
     *
     * @param string|int $value
     */
    private static function iso8601From($value): string
    {
        if (is_int($value)) {
            return gmdate('c', $value);
        }
        return $value;
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
     * F-01 / F-02: each entry exposes `element_type` (canonical wire field —
     * the raw YT layout node uses `type`) plus a `label` alias of `name`
     * and a `has_binding` boolean derived from `props.source`. This is the
     * SAME shape the MCP TS `schema-format` mapper reads, so the table
     * fills in correctly on first call.
     *
     * @return list<array{path: string, element_type: string, type: string, name?: string, label?: string, has_binding: bool}>|null
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
     * Compute the recursive element count for a template's layout. F-02:
     * delegates to TreeWalker::countDescendants() so callers get the same
     * total surfaced in pages_list and element_list.
     */
    public function elementsCount(string $templateId): int
    {
        $tpl = $this->reader->readTemplate($templateId);
        if ($tpl === null || !isset($tpl['layout']) || !is_array($tpl['layout'])) {
            return 0;
        }
        /** @var array<string, mixed> $layout */
        $layout = $tpl['layout'];
        return TreeWalker::countDescendants($layout);
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
     * T9 (Audit-v3 B.5): a token-efficient template overview computed
     * server-side in a single recursive walk. Replaces a 20 kB+ raw
     * element_list dump with a ~1 kB structured summary — the agent gets
     * counts + depth + named landmarks for ~0 tokens of its own context.
     *
     * @return array{template_id: string, counts_by_type: array<string, int>, bound_count: int, max_depth: int, total: int, named_sections: list<array{path: string, name: string}>, etag: string}|null
     */
    public function summary(string $templateId): ?array
    {
        $tpl = $this->reader->readTemplate($templateId);
        if ($tpl === null) {
            return null;
        }

        /** @var array<string, int> $countsByType */
        $countsByType = [];
        $boundCount = 0;
        $maxDepth = 0;
        $total = 0;
        /** @var list<array{path: string, name: string}> $namedSections */
        $namedSections = [];

        if (isset($tpl['layout']) && is_array($tpl['layout'])) {
            $basePointer = JsonPointer::compile(['templates', $templateId, 'layout']);
            /** @var array<string, mixed> $layout */
            $layout = $tpl['layout'];
            foreach (TreeWalker::walk($layout, $basePointer) as [$pointer, $node]) {
                $total++;
                $type = isset($node['type']) && is_string($node['type'])
                    ? $node['type']
                    : 'unknown';
                $countsByType[$type] = ($countsByType[$type] ?? 0) + 1;

                if (BindingSerializer::serialize($node) !== null) {
                    $boundCount++;
                }

                // Depth = number of `/children/` segments past the layout root.
                $depth = substr_count($pointer, '/children/');
                if ($depth > $maxDepth) {
                    $maxDepth = $depth;
                }

                // Named landmarks — any node carrying a human `name`.
                if (isset($node['name']) && is_string($node['name']) && $node['name'] !== '') {
                    $namedSections[] = ['path' => $pointer, 'name' => $node['name']];
                }
            }
        }

        ksort($countsByType);

        return [
            'template_id' => $templateId,
            'counts_by_type' => $countsByType,
            'bound_count' => $boundCount,
            'max_depth' => $maxDepth,
            'total' => $total,
            'named_sections' => $namedSections,
            'etag' => $this->reader->etag(),
        ];
    }

    /**
     * Depth-first walk over a node + its children, appending one entry per
     * descended-into node to $out.
     *
     * The root layout node itself is not emitted — only its children-tree.
     * Each child sits at $pointer + "/children/<index>".
     *
     * @param array<string, mixed> $node
     * @param list<array{path: string, element_type: string, type: string, name?: string, label?: string, has_binding: bool}> $out
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
            $type = isset($child['type']) && is_string($child['type'])
                ? $child['type']
                : 'unknown';
            $entry = [
                'path' => $childPointer,
                // F-01: canonical wire field is `element_type`. We keep
                // `type` as a legacy alias for back-compat with older
                // MCP TS builds that may still read it.
                'element_type' => $type,
                'type' => $type,
                'has_binding' => self::hasBinding($child),
            ];
            if (isset($child['name']) && is_string($child['name'])) {
                $entry['name'] = $child['name'];
                $entry['label'] = $child['name'];
            }
            $out[] = $entry;
            self::walk($child, $childPointer, $out);
        }
    }

    /**
     * Return true if the node carries a source-binding.
     *
     * D1 / T1 (F-01-Rest, 2026-05-22): delegates to the single source-of-
     * truth `BindingSerializer`. Recognises the F-13 structured shape
     * `{query:{name}, props:{<el>:{name,...}}}`, top-level `node.source`,
     * cached `node.source_extended`, and legacy plain-string sources.
     *
     * @param array<string, mixed> $node
     */
    private static function hasBinding(array $node): bool
    {
        return BindingSerializer::hasBinding($node);
    }
}
