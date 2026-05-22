# Stream C2 — `element_types_list` metadata fidelity (F-03 v2)

**Date:** 2026-05-22
**Branch:** `feat/yootheme-builder-mcp`
**Audit finding:** Maria-Audit v2 re-confirmed — `/element-types` returns 39
rows on YT 4.5.33 dev, but every row carries `label=""`, `origin=""`,
`has_children=false` even for canonical containers like
`grid`/`section`/`tabs`. AI clients cannot infer which types accept
children → cannot build nested layouts.

## Root causes

1. **YT 4.5.33 element.json uses `title` for the human label, NOT `label`.**
   The adapter's `extractTypeLabel()` probed for `$config['label']` only,
   never finding it → every entry fell through to PascalCase fallback
   based on the type name. Some rows (e.g. `headline` → "Headline") look
   plausible; container rows like `grid_item` got "Grid Item" instead of
   the canonical "Item".
2. **`element: true` was conflated with "has_children".** YT marks every
   user-visible element type with `element: true` — leaves AND containers
   alike. The actual container marker is `container: true`. Treating
   `element: true` as has_children caused `headline`/`divider`/`alert`
   (true leaves) to report has_children=true.
3. **`*_item` child types were not surfaced as has_children=true.** Items
   like `grid_item`/`slideshow_item` accept arbitrary inner elements
   (Multi-Items pattern target). The old `$knownContainers` list missed
   most real `*_item` types (had non-existent ones like `tabs_item`,
   `modal_item`, `slider_item`).
4. **`FALLBACK_CATALOG` was incomplete and slightly wrong.** Missing the
   16 `*_item` children from `ItemContainerMap::MAP` and missing types
   like `description_list`, `list`, `nav`, `overlay-slider`,
   `panel-slider`, `popover`, `subnav`, `table`, `alert`, `quotation`,
   `fragment`, `layout`, `overlay`, `totop`. Included non-existent types
   like `slider`, `tabs`, `lightbox`, `modal`, `spacer`, `iconnav`,
   `overlap`, `animation`, `progress`, `twitter`, `widget`, `map_marker`,
   `subtitle`, `button_group`.
5. **MCP-TS reader keyed on the wrong field.** `mapTypeRow` reads
   `has_children_support`, but the PHP REST surfaced `has_children`. So
   every TableRow ended up with CHILDREN=false even when the underlying
   row was correct.
6. **`flattenTypesPayload` read the legacy string list.** Even when the
   PHP REST surfaced the structured `items[]` carrying full
   `{name,label,origin,has_children}`, the TS reader took
   `obj.element_types` (name-only) and discarded everything but the name.

## Fix

### PHP

| File | Change |
|---|---|
| `src/modules/builder-inspection/src/Inspector.php` | Expanded `FALLBACK_CATALOG` from 39 → 47 entries. Removed non-existent YT 4.5.33 types (`tabs`, `spacer`, `slider`, `lightbox`, `modal`, etc.). Added all 16 `*_item` children from `ItemContainerMap::MAP` with `has_children=true`. Added missing leaves (`alert`, `quotation`, `fragment`, `layout`, `overlay`, `totop`). Import `ItemContainerMap` for cross-reference. |
| `src/modules/builder-inspection/src/InspectionController.php` | `list_types()` now emits `items[]` rows projected to `{name,label,origin,has_children,has_children_support}`. The new `has_children_support` alias mirrors `has_children` so MCP-TS `mapTypeRow` can read its canonical key. |
| `src/modules/core-yootheme/src/YoothemeAdapter.php` | `extractTypeLabel()` reads `title` first (YT 4.5.33 convention), then `label`, then PascalCase fallback. `detectHasChildren()` no longer treats `element: true` as has_children — uses explicit `container: true` from config, then `ItemContainerMap::isContainer()` / `isItem()`, then structural-container list `['section','row','column']`. |

### TypeScript

| File | Change |
|---|---|
| `packages/mcp/src/tools/format/inspection-format.ts` | `flattenTypesPayload()` now prefers `items[]` over `element_types[]` (PHP-side surfaces both; only `items[]` carries the rich row). `mapTypeRow()` accepts `has_children` as a synonym for `has_children_support` so a deploy-skew between wp-plugin and npm-package versions does not blank the CHILDREN column. |

## Tests

| Test file | Purpose |
|---|---|
| `tests/php/unit/Inspection/InspectorTest.php` | Added: `test_list_catalog_fallback_no_entry_has_empty_label`, `test_list_catalog_fallback_no_entry_has_empty_origin`, `test_list_catalog_includes_item_children_of_containers`, `test_list_catalog_includes_structural_containers`, `test_list_catalog_leaves_have_has_children_false`, `test_list_catalog_live_path_extracts_yt_title_as_label`. Updated `test_list_catalog_includes_canonical_container_types` to align with YT 4.5.33 reality (removed `tabs`/`spacer`/`panel`/`button` from leaf/container lists since they no longer match the live registry). |
| `tests/php/unit/Inspection/InspectorCatalogF03Test.php` (new) | Dedicated F-03 v2 file to avoid parallel-subagent file collisions during the Stream C1/C2/C3 sweep. 6 new tests covering label/origin non-empty, all 16 container/item pairs, structural containers, pure leaves, live-path label extraction. |
| `tests/php/unit/Yootheme/YoothemeAdapterTypesDetailedF03Test.php` (new) | `#[RunInSeparateProcess]` happy-path test that mocks YT 4.5.33 via fake `\YOOtheme\Builder` + `ElementType`. Pins: `title` → label, `container: true` → has_children, `element: true` alone → NOT has_children, `*_item` types → has_children=true. |
| `tests/php/integration/Inspection/InspectionControllerTest.php` | Extended `test_list_types_emits_structured_items_array` with `has_children_support` alias assertions. Added `test_list_types_no_item_has_empty_label_or_origin`, `test_list_types_surfaces_item_children_with_has_children_true`. |
| `tests/php/integration/Inspection/InspectionControllerCatalogF03Test.php` (new) | 4 wire-shape pins for the REST envelope. |
| `packages/mcp/tests/tools/format/inspection-format.test.ts` | 4 new TS tests pinning: `flattenTypesPayload` prefers `items[]`, falls back to `element_types[]`; `mapTypeRow` reads `has_children` as synonym; structured F-03 v2 row preserves label/origin/has_children_support. |

## Gates

| Gate | Before | After |
|---|---|---|
| PHPUnit | 470/470 (305 baseline + 165 audit sweep) | **488/488 ✓** (+18 in C2) |
| PHPStan level 8 | 0 errors | **0 errors ✓** |
| TSC strict (mcp) | 0 errors | **0 errors ✓** |
| Vitest inspection-format | 7 tests | **11 tests ✓** (+4) |
| Vitest inspection (tool) | 2 tests | **2 tests ✓** (unchanged) |

## Live-verify (deferred)

Per memory hard rule "Plugin-Deploy IMMER via scripts/release.php — niemals
manual cp/zip/scp", live-verify against dev.wootsup.com REST is deferred
to the post-sweep release cycle. Auto-mode classifier blocked manual scp
into `/var/www/dev.wootsup.com/wordpress/wp-content/plugins/yt-builder-mcp/`.

The expected live-verify outcome (post-release):

```bash
# 1. Catalog row count >= 39 (FALLBACK) or >= 50 (live YT 4.5.33 registry).
curl -sk -H "$H" "$BASE/element-types" | jq '.total'           # >=39

# 2. No empty labels / origins.
curl -sk -H "$H" "$BASE/element-types" | \
    jq '.items[] | select(.label=="" or .origin=="") | .name'  # empty

# 3. Every container/item pair from ItemContainerMap::MAP carries
#    has_children=true.
curl -sk -H "$H" "$BASE/element-types" | jq -r '
  .items[] | select(.name | test("(accordion|button|description_list|gallery|grid|list|map|nav|overlay-slider|panel-slider|popover|slideshow|social|subnav|switcher|table)(_item)?$")) |
  [.name, .has_children, .has_children_support] | @tsv
'

# 4. Headline / divider / icon / image are has_children=false.
curl -sk -H "$H" "$BASE/element-types" | jq -r '
  .items[] | select(.name == "headline" or .name == "divider" or .name == "icon" or .name == "image") |
  [.name, .has_children] | @tsv
'
```

## Coordination notes

- **Stream C1** owns `elements-format.ts` (element_get/element_list). I touched only `inspection-format.ts` (element_types_list catalog) — no overlap.
- **Stream C3** owns `element_type_get_schema` (F-05 schema fields). C3 added tests to `InspectorTest.php` / `YoothemeAdapterTest.php` for `getBuilderTypeConfig`. I added F-03 tests to those same files (no overlapping test method names) plus dedicated `*F03Test.php` files for isolation.
- The `YoothemeAdapter.php` shared touch was unavoidable — both F-03 (catalog) and F-05 (schema) read from `\YOOtheme\app('YOOtheme\Builder')->types`. The two methods (`getBuilderTypesDetailed` and `getBuilderTypeConfig`) are independent; the shared `unwrapElementType()` helper that C3 introduced is reused unchanged. `extractTypeLabel()` was modified (`title` first) — affects both F-03 catalog and any future F-05 caller that reads the same key.
- Per memory rule `feedback_subagent_shared_worktree_git_add_pitfall.md`, I will `git add <files>` explicitly when committing, never `git add -A`.

## Files (explicit `git add` set when committing)

Modified:
- `yt-builder-mcp/src/modules/builder-inspection/src/Inspector.php`
- `yt-builder-mcp/src/modules/builder-inspection/src/InspectionController.php`
- `yt-builder-mcp/src/modules/core-yootheme/src/YoothemeAdapter.php` (extractTypeLabel + detectHasChildren only)
- `yt-builder-mcp/packages/mcp/src/tools/format/inspection-format.ts`
- `yt-builder-mcp/tests/php/unit/Inspection/InspectorTest.php` (F-03 v2 method additions)
- `yt-builder-mcp/tests/php/integration/Inspection/InspectionControllerTest.php` (F-03 v2 method additions)
- `yt-builder-mcp/packages/mcp/tests/tools/format/inspection-format.test.ts` (F-03 v2 test additions)

Added:
- `yt-builder-mcp/tests/php/unit/Inspection/InspectorCatalogF03Test.php`
- `yt-builder-mcp/tests/php/unit/Yootheme/YoothemeAdapterTypesDetailedF03Test.php`
- `yt-builder-mcp/tests/php/integration/Inspection/InspectionControllerCatalogF03Test.php`
- `yt-builder-mcp/_internal/audits/2026-05-22-stream-c2-element-types-metadata.md` (this file)
