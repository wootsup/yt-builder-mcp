# Stream A2 (Sources Discovery + Bind Structure) — Acceptance

**Datum:** 2026-05-22
**Scope:** F-04 (HIGH), F-13 (HIGH)
**Branch:** `feat/yootheme-builder-mcp`
**Status:** Code-complete, gates green for changed surface. Awaiting live-curl re-verification against dev.wootsup.com (consolidation phase).

## Commits

| SHA          | Subject                                                                                 |
|--------------|-----------------------------------------------------------------------------------------|
| `7c8f5b8f5`  | (absorbed F-04 changes alongside Stream A1's F-02 commit — see "Commit-Co-Mingling")    |
| `2c60df62f`  | `fix(extension): F-13 yt-mcp structured source-binding object (Maria-Audit)`            |

### Commit-Co-Mingling note

Because all three streams (A1/A2/A3) share the same worktree on `feat/yootheme-builder-mcp`, A1's `git add -A` pulled my staged F-04 files into A1's F-02 commit (`7c8f5b8f5`). The code is correct, but the commit title only mentions F-02. The actual content of `7c8f5b8f5` covers BOTH F-02 (TreeWalker/PageQuery/PagesController) AND F-04 (SourceRegistry/YoothemeAdapter + tests). No data lost; ledger note here so the audit-trail is intact.

## F-04 — `/v1/sources` returns enriched, group-classified entries

### Problem (from audit)

`GET /v1/sources` returned `{apimapper: [], wordpress: [], essentials: []}` — three empty arrays. The classifier used a name-prefix heuristic (`apimapper_*` / `essentials_*`) ignoring YT's own `metadata.group` value attached by every YT source-provider.

### Fix

1. **`YoothemeAdapter::getSourceFieldEntries()` (new method)** — enriches raw `getSourceFields()` into a list of `{name, label, group, type}` records, reading:
   - `label` from `FieldDefinition->config['metadata']['label']` (fallback to name)
   - `group` from `FieldDefinition->config['metadata']['group']` (fallback to '')
   - `type` from `FieldDefinition->getType()` `__toString()` (fallback to '')

   Handles BOTH webonyx FieldDefinition objects (production) AND plain array fields (test fixtures + api-mapper MockYooThemeSource).

2. **`SourceRegistry::classify()` — canonical group-string match** — uses YT's `metadata.group` value first:
   - `*api mapper*` / `*apimapper*` → `apimapper`
   - `*essentials*` / `*uikit*` → `essentials`
   - Anything else metadata-tagged → `wordpress`

   Falls back to name-prefix only when `metadata.group` is empty (legacy providers).

3. **`final class` → `class`** removed on `YoothemeAdapter` to enable test-extension via PHPUnit anonymous-class harnesses (no factory pattern needed; one keyword diff).

### Tests

| File                                              | Δ tests | Notes                                                            |
|---------------------------------------------------|---------|------------------------------------------------------------------|
| `tests/php/unit/SourceBinding/SourceRegistryTest` | +3      | YT metadata.group classify, name-prefix fallback, case-insensitive |
| `tests/php/unit/Yootheme/YoothemeAdapterTest`     | +4      | getSourceFieldEntries: null without YT, FieldDefinition-object, array-shape, name-as-label fallback |

### Files

- `src/modules/core-yootheme/src/YoothemeAdapter.php` — `+90 LoC` (getSourceFieldEntries) + `final` removal
- `src/modules/builder-source-binding/src/SourceRegistry.php` — net `+30 LoC` (classify rewrite + entriesProvider test seam)

## F-13 — `bind_source` writes structured `source` object

### Problem (from audit)

`PUT /v1/templates/{id}/elements/at/{path}/bind` (and the underlying SourcesController::put_binding) wrote `props.source = "<source_name>"` as a flat string. YOOtheme's renderer expects:

```json
"source": {
    "query": {"name": "posts.singlePost"},
    "props": {
        "title": {"name": "post_title", "filters": {}},
        "content": {"name": "post_content", "filters": {}}
    }
}
```

A flat-string binding rendered as "no binding" — every Element→Source connection was a no-op end-to-end.

### Fix

1. **`SourcesController::buildSourceValue($name, $field_mappings)`** — constructs the canonical wire shape: `query.name` always; `props` only when `field_mappings` non-empty; `filters` always present as empty `stdClass` (YT's wire-format).

2. **New optional PUT-body field `field_mappings`** — `{<prop_name>: <source_field_name>}`. Validated:
   - Must be an object/associative array → 400 `invalid_body` if not
   - Each key must be a non-empty string → 400
   - Each value must be a string → 400
   - Round-tripped through `buildSourceValue` into `source.props`

3. **`SourcesController::extractBinding($node)`** — rewritten to de-structure the YT-canonical shape back into `{source_name, field_mappings}` for the response. Read-through-compatible with legacy plain-string state (pre-F-13 user data): surfaces as `{source_name: "<legacy_string>", field_mappings: []}`. Unbound returns `{source_name: null, field_mappings: []}`.

4. **PUT-response shape consolidated** — `binding` field now uniformly `{source_name, field_mappings}` for BOTH GET and PUT, so MCP-clients see one contract.

5. **Unbind unchanged** — DELETE binding OR PUT with `source_name: null` removes the `props.source` key entirely (no empty-string sentinel).

### Tests

| Test                                                          | What it pins                                            |
|---------------------------------------------------------------|---------------------------------------------------------|
| `test_put_binding_writes_structured_source_object`            | PUT `source_name` only → on-disk `{query:{name:X}}`, no `props` |
| `test_put_binding_with_field_mappings_writes_canonical_props` | PUT `source_name + field_mappings` → on-disk `{query.name, props.<el>.name, props.<el>.filters}` |
| `test_put_binding_with_null_unbinds`                          | PUT `source_name: null` removes `props.source`           |
| `test_put_binding_400_when_source_name_missing`               | 400 on missing source_name                              |
| `test_put_binding_400_when_field_mappings_not_object`         | 400 on string/scalar field_mappings                     |
| `test_put_binding_400_when_field_mappings_value_not_string`   | 400 on non-string value inside field_mappings           |
| `test_put_binding_412_on_etag_mismatch`                       | Optimistic-lock preserved                               |
| `test_delete_binding_removes_source_prop`                     | DELETE removes `props.source`                           |
| `test_put_binding_404_for_unknown_element`                    | 404 on bad pointer                                      |
| `test_get_binding_returns_destructured_shape`                 | GET pulls `{source_name, field_mappings}` from structured state |
| `test_get_binding_returns_legacy_string_as_source_name`       | GET on pre-F-13 plain-string state still works          |
| `test_get_binding_returns_null_when_unbound`                  | GET on unbound element returns `{source_name: null, field_mappings: []}` |

`BindingWriteTest`: was 6 → now 12.

### Files

- `src/modules/builder-source-binding/src/SourcesController.php` — `+138 LoC` (validation + buildSourceValue + buildBindingResponse + extractBinding rewrite + structured signature on mutateBinding)
- `tests/php/integration/SourceBinding/BindingWriteTest.php` — `+106 LoC` (5 new tests, 1 modified)

## Test Delta

| Suite                                | Baseline | After A2 |
|--------------------------------------|----------|----------|
| Source/Bind/Yootheme filter           | 14       | 34       |
| Full PHPUnit                         | 305      | 347      |
| Net delta                            | -        | **+42**  |

```text
$ vendor/bin/phpunit --no-coverage --filter "Source|Bind|Yootheme"
OK (34 tests, 91 assertions)

$ vendor/bin/phpunit --no-coverage
OK (347 tests, 897 assertions)
```

## PHPStan (level 8, src/)

The A2 scope (`src/modules/builder-source-binding/**`, `src/modules/core-yootheme/src/YoothemeAdapter.php` for the F-04 changes) is clean. PHPStan reports 1 error in `core-yootheme/src/YoothemeAdapter.php:75` — this is from Stream A3's `getVersion()` reflection probe (commit `bacad698e` + unstaged extension), unrelated to F-04/F-13. A3 will resolve in their own loop.

## Live-curl verification (deferred to consolidation)

To be run after Streams A1/A2/A3 land and DXT alpha.3 deploys to dev:

```bash
# F-04: /sources returns non-empty groups (wordpress at minimum)
curl -s -H "Authorization: Bearer $YTB_KEY" \
  "https://dev.wootsup.com/wp-json/yt-builder-mcp/v1/sources" \
  | jq '.sources | {apimapper: .apimapper | length, wordpress: .wordpress | length, essentials: .essentials | length}'
# expect: { wordpress: >= 7 (posts.* etc.), ... }

# F-13: bind writes structured source object
ETAG=$(curl -s -H "Authorization: Bearer $YTB_KEY" \
  "https://dev.wootsup.com/wp-json/yt-builder-mcp/v1/etag" | jq -r '.etag')

curl -s -X PUT -H "Authorization: Bearer $YTB_KEY" \
  -H "Content-Type: application/json" \
  -H "If-Match: $ETAG" \
  --data '{"source_name":"posts.singlePost","field_mappings":{"title":"post_title"}}' \
  "https://dev.wootsup.com/wp-json/yt-builder-mcp/v1/pages/$TPL/elements/templates%2F$TPL%2Flayout%2Fchildren%2F0/binding" \
  | jq '.binding'
# expect: {source_name: "posts.singlePost", field_mappings: {title: "post_title"}}

# Verify on-disk shape via layout-read:
curl -s -H "Authorization: Bearer $YTB_KEY" \
  "https://dev.wootsup.com/wp-json/yt-builder-mcp/v1/templates/$TPL/layout" \
  | jq '.layout.children[0].props.source'
# expect: {query: {name: "posts.singlePost"}, props: {title: {name: "post_title", filters: {}}}}
```

## Risks / Followups

- **Audit re-run pending** — needs the consolidated dev deploy.
- **PUT-response `etag` field** still relies on `LayoutReader::etag()` which after Stream A3 lands will include the revision suffix (`<sha256>-r<n>`). Behavioural change is transparent to BindingWriteTest because it asserts on `status` + `binding.*` only, not on the etag-string format.
- **Legacy plain-string read-through** is one-way: `extractBinding` projects legacy state to the new shape, but the next PUT will overwrite with the structured object. No silent state-corruption — the read surfaces the legacy value so MCP-clients see a coherent contract, and any write upgrades to the canonical shape.
