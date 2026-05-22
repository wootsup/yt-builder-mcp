<?php
/**
 * PagesMetaStore — per-template `modified_at` tracking option.
 *
 * F-08 fix (Maria-Audit 2026-05-22). The live audit reported empty
 * `modified_at` on the first pages_list call — turns out
 * `wp_option('yootheme').templates.<id>` does NOT always carry a
 * `modified` field (the Builder JS only writes it on explicit save
 * operations, and on a fresh template-rename the field may be entirely
 * absent). To guarantee a non-null `modified_at` on every list-row,
 * yt-builder-mcp maintains its OWN per-template tracking option that is
 * bumped from LayoutWriter::writeTemplate() on every persisted mutation.
 *
 * Storage shape (wp_option('ytb_mcp_pages_meta'), autoload=false):
 *
 *   array<template_id, array{modified_at: string}>
 *
 * PageQuery::list() reads the store as a fallback when the layout blob
 * itself doesn't carry a `modified` field — so cold-start lookups still
 * yield an ISO-8601 timestamp instead of an undefined key.
 *
 * @license GPL-2.0-or-later
 * @package WootsUp\BuilderMcp\Pages
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Pages;

final class PagesMetaStore
{
    public const OPTION = 'ytb_mcp_pages_meta';

    /**
     * Read the entire `{template_id => meta}` map. Returns an empty array
     * when the option is missing or corrupt.
     *
     * @return array<string, array{modified_at: string}>
     */
    public function all(): array
    {
        /** @var mixed $raw */
        $raw = \get_option(self::OPTION, []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $entry) {
            if (!is_string($key) || !is_array($entry)) {
                continue;
            }
            $modifiedAt = $entry['modified_at'] ?? null;
            if (!is_string($modifiedAt) || $modifiedAt === '') {
                continue;
            }
            $out[$key] = ['modified_at' => $modifiedAt];
        }
        return $out;
    }

    /**
     * Look up the modified_at ISO-8601 for one template, or null when
     * never tracked.
     */
    public function modifiedAt(string $templateId): ?string
    {
        $all = $this->all();
        if (!isset($all[$templateId])) {
            return null;
        }
        return $all[$templateId]['modified_at'];
    }

    /**
     * Stamp `modified_at` for one template with the current UTC time
     * (ISO-8601 with explicit zone offset). Called from
     * LayoutWriter::persist() so every committed mutation surfaces as a
     * fresh timestamp on the next pages_list call.
     */
    public function touch(string $templateId): void
    {
        if ($templateId === '') {
            // Root/library pointers don't correspond to a single template —
            // there's nothing meaningful to stamp.
            return;
        }
        $all = $this->all();
        $all[$templateId] = ['modified_at' => \gmdate('c')];
        \update_option(self::OPTION, $all, false);
    }

    /**
     * Forget the entry for one template — used when a template is deleted
     * so we don't leak stale meta entries forever.
     */
    public function forget(string $templateId): void
    {
        $all = $this->all();
        if (!isset($all[$templateId])) {
            return;
        }
        unset($all[$templateId]);
        \update_option(self::OPTION, $all, false);
    }
}
