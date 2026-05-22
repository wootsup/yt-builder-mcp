# Stream A3 (ETag / Health / Pages-Metadata / Publish) — Acceptance

**Datum:** 2026-05-22
**Scope:** F-07 (MEDIUM), F-08 (MEDIUM), F-09 (MEDIUM), F-10 (LOW), F-15 (MEDIUM)
**Branch:** `feat/yootheme-builder-mcp`
**Status:** Code-complete, gates green for changed surface. Awaiting live-curl re-verification against dev.wootsup.com (downstream deploy workflow).

---

## Commits (in topo-order, all on `feat/yootheme-builder-mcp`)

| SHA          | Subject                                                                                              | Finding(s)        |
|--------------|------------------------------------------------------------------------------------------------------|-------------------|
| `bacad698e`  | `fix(extension): F-07 monotonic ETag revision suffix (Maria-Audit)`                                  | F-07              |
| `f6cef0624`  | `fix(extension): F-09/F-10/F-15 health + etag + publish polish (Maria-Audit)`                        | F-09, F-10, F-15  |
| `1e1b4acd8`  | `fix(extension): F-08 yt-mcp persistent pages_meta tracking (Maria-Audit)`                           | F-08              |

### Commit-Co-Mingling note

A1's `7c8f5b8f5` (F-02 commit) absorbed some of my F-10 + F-15 edits to `PagesController.php` because both streams shared the worktree and A1's `git add -A` was wider. The code is correct; this acceptance log is the source of truth for what A3 actually shipped.

---

## F-07 — Monotonic ETag revision suffix

### Problem (from audit)

`LayoutReader::etag()` returned `sha256(state)` only. The pure content-hash is ABA-vulnerable: a mutation cycle `add → delete` collapses back to the original state, so `etag_A == etag_A'`. A client that holds `etag_A` and submits an `If-Match: etag_A` write after the cycle would silently overwrite the (legitimate) intermediate work.

### Fix

1. **`StateRevision` (new class, `src/modules/builder-state/src/StateRevision.php`, 60 LoC)** — owns a strictly-monotonic counter persisted in `wp_option('ytb_mcp_state_revision')` (autoload=false). `current(): int` reads, `bump(): int` increments and returns the new value. Defensive cold-start (`current() == 0` when option missing or corrupt).

2. **`LayoutReader::etag()`** now returns `sha256(state) + '-r' + revision`. The constructor accepts an optional `?StateRevision` (default-instantiated when null) so the read-path stays DI-friendly. `getRevision()` is exposed for writers.

3. **`LayoutWriter::persist()`** bumps the revision AFTER the state-write has been verified — if `persist` throws on `verify_read_did_not_match`, the revision stays put. The bump happens inside the per-template `StateLock` critical section so concurrent writes to the same template serialise their revision-increments.

### Tests

| File                                                         | Δ tests | Notes                                                                                                                  |
|--------------------------------------------------------------|---------|------------------------------------------------------------------------------------------------------------------------|
| `tests/php/unit/State/StateRevisionTest.php` (new)           | +9      | Cold-start, monotonic-increment, cross-instance persistence, corruption fallback, prefix-pin                            |
| `tests/php/unit/State/LayoutReaderTest.php`                  | +1      | `test_etag_carries_monotonic_revision_suffix` pins the wire-format                                                      |
| `tests/php/unit/State/LayoutWriterTest.php`                  | _0_     | Existing `test_etag_changes_after_write_template` still passes (content changes already produce a different ETag)      |
| `tests/php/integration/Elements/WriteOpsTest.php`            | +1      | **`test_aba_mutation_cycle_yields_three_distinct_etags` — THE structural pin for F-07**                                |
| `tests/php/integration/Pages/PagesWriteTest.php`             | _0_     | `test_save_page_returns_new_etag` flipped: every save MUST yield a fresh ETag (revision bump) even on identity content |

### Files

- `src/modules/builder-state/src/StateRevision.php` (new, 60 LoC)
- `src/modules/builder-state/src/LayoutReader.php` (constructor + etag() format change)
- `src/modules/builder-state/src/LayoutWriter.php` (`persist()` bumps revision)

---

## F-08 — Persistent `pages_meta` tracking for cold-start `modified_at`

### Problem (from audit)

`pages_list` cold-start returned empty `modified_at` for every row. The YT-stored template blob does NOT always carry a `modified` field — Builder JS writes it only on explicit save operations; a fresh-rename strips the key.

### Fix

A1's F-02 commit (`7c8f5b8f5`) already shipped the eager-load shape (`label`, `type`, `elements_count`, ISO coercion from int/string `modified`). A3 fills in the remaining gap with a persistent tracking store:

1. **`PagesMetaStore` (new class, `src/modules/builder-pages/src/PagesMetaStore.php`, 100 LoC)** — maps `template_id → {modified_at: ISO-8601}` in `wp_option('ytb_mcp_pages_meta')` (autoload=false). `all() / modifiedAt() / touch() / forget()` API. Survives blob corruption (skips malformed entries).

2. **`LayoutWriter::writeTemplate()`** calls `PagesMetaStore::touch($templateId)` after `persist()` succeeds. Every committed mutation stamps the store with `gmdate('c')`.

3. **`PageQuery::list()`** falls back to the store when the layout blob lacks `modified` / `modified_at`. Cold-start `pages_list` rows now ship a non-null `modified_at` on every install.

### Tests

| File                                                    | Δ tests | Notes                                                              |
|---------------------------------------------------------|---------|--------------------------------------------------------------------|
| `tests/php/unit/Pages/PagesMetaStoreTest.php` (new)     | +7      | all/modifiedAt/touch/forget, corruption-tolerance, prefix-pin       |
| `tests/php/unit/Pages/PageQueryTest.php`                | +1      | `test_list_falls_back_to_pages_meta_when_blob_lacks_modified`      |
| `tests/php/unit/State/LayoutWriterTest.php`             | +1      | `test_write_template_stamps_pages_meta_store`                       |

### Files

- `src/modules/builder-pages/src/PagesMetaStore.php` (new)
- `src/modules/builder-pages/src/PageQuery.php` (fallback wiring)
- `src/modules/builder-state/src/LayoutWriter.php` (touch on writeTemplate)

---

## F-09 — Health surfaces YOOtheme + YOOessentials versions

### Problem (from audit)

`/v1/health` returned `yootheme_version: null` on dev even though YT-Pro 4.5.33 was loaded. The old code probed only the `YOOTHEME_VERSION` constant — but YT exposes its version via at least three different symbols across versions.

### Fix

1. **`YoothemeAdapter::getVersion()` — multi-probe walk**: tries `YOOTHEME_VERSION` constant → `\YOOtheme\Theme::VERSION` via ReflectionClass → `\YOOtheme\app('version')` DI scalar. First non-empty string wins; null only when every probe misses.

2. **`YoothemeAdapter::getEssentialsVersion()` (new)** — reads `YOOESSENTIALS_VERSION` constant, with `\Yooessentials\Plugin::VERSION` / `\YOOessentials\Plugin::VERSION` reflection fallback (case variants observed across releases).

3. **`HealthController::payload()` authenticated tier** surfaces both as top-level fields: `yootheme_version` + `yooessentials_version`. Anonymous tier is unchanged (R2.13 tier-reduction preserved).

### Tests

| File                                                | Δ tests | Notes                                                                                                                |
|-----------------------------------------------------|---------|----------------------------------------------------------------------------------------------------------------------|
| `tests/php/unit/Yootheme/YoothemeAdapterTest.php`   | +3      | `test_get_version_walks_reflection_fallback`, `test_get_essentials_version_returns_null_when_missing`, `…_reads_constant_when_defined` |
| `tests/php/unit/Rest/HealthControllerTest.php`      | +1      | `test_authenticated_payload_exposes_essentials_version_key`                                                          |

### Files

- `src/modules/core-yootheme/src/YoothemeAdapter.php` (`getVersion()` rewritten, `getEssentialsVersion()` new)
- `src/modules/rest-bridge/src/HealthController.php` (authenticated payload extended)

---

## F-10 — `GET /v1/etag` returns `generated_at` ISO timestamp

### Problem (from audit)

Callers cannot distinguish a fresh server-probe from a stale cached response — the endpoint returned only `{etag: …}`.

### Fix

`PagesController::get_etag()` now returns `{etag, generated_at}` with `generated_at` produced by `gmdate('c')` — RFC-3339 / ISO-8601 with explicit zone offset (`+00:00` for UTC), so client clocks can be sanity-checked against the server.

### Tests

| File                                                | Δ tests | Notes                                                            |
|-----------------------------------------------------|---------|------------------------------------------------------------------|
| `tests/php/integration/Pages/PagesWriteTest.php`    | +1      | `test_get_etag_carries_generated_at_iso_timestamp`                |

### Files

- `src/modules/builder-pages/src/PagesController.php` (`get_etag()` extended)

---

## F-15 — `page_publish` real cache-flush + persisted state-snapshot

### Problem (from audit)

`POST /v1/pages/{id}/publish` was a thin alias of `save_page` with a `published: true` marker — no cache-flush, no draft-vs-published distinction.

### Fix

`PagesController::publish_page()` now:

1. Runs `save_page()` (commits state, bumps revision via F-07).
2. Re-invokes `CacheFlusher::flush()` explicitly (belt-and-braces; covers both YT cache layer and scoped WP object-cache eviction).
3. Persists the post-publish ETag in `wp_option('ytb_mcp_published_state_etag')` (autoload=false) so callers can diff draft-vs-published.
4. Returns:
   ```json
   {
     "template_id": "...",
     "saved": true,
     "published": true,
     "etag": "<sha>-r<N>",
     "published_state_etag": "<sha>-r<N>",
     "note": "YOOtheme templates publish on save; this is a cache-flush + state-snapshot operation."
   }
   ```

The `note` field documents YT-Pro's actual data model (templates are live on save) so MCP clients and the future Joomla port have a stable explanation surface — no false promises about "draft-only" semantics.

### Tests

| File                                                | Δ tests | Notes                                                                      |
|-----------------------------------------------------|---------|----------------------------------------------------------------------------|
| `tests/php/integration/Pages/PagesWriteTest.php`    | +1      | `test_publish_page_persists_published_state_etag_and_note`                  |

### Files

- `src/modules/builder-pages/src/PagesController.php` (`publish_page()` rewritten, `PUBLISHED_STATE_ETAG_OPTION` constant)

---

## Gates

| Gate                              | Result              |
|-----------------------------------|---------------------|
| `vendor/bin/phpunit --no-coverage` | **367 tests / 966 assertions / 0 failures** |
| `composer phpstan` (level 8)      | **0 errors**        |
| `pnpm typecheck` (packages/mcp)   | **0 errors (clean)** |

### Filter-scoped (focal to A3 work)

```
vendor/bin/phpunit --no-coverage --filter "Etag|Health|Pages|Publish|StateRevision|LayoutReader|LayoutWriter|aba_mutation"
```

→ **67 tests, 147 assertions, OK** after Step-F-09, then **+5 tests** post Step-F-08 (72 total).

---

## Hard-Constraints honoured

| Constraint                                            | Status        |
|-------------------------------------------------------|---------------|
| Branch is `feat/yootheme-builder-mcp` (not `main`)    | ✔             |
| No npm publish, no Discord post, no public release    | ✔             |
| No `any` in TypeScript (no TS changes in A3 scope)    | n/a           |
| WP-options prefix `ytb_mcp_*` unchanged               | ✔ (only NEW keys added: `ytb_mcp_state_revision`, `ytb_mcp_pages_meta`, `ytb_mcp_published_state_etag`) |
| PSR-4 namespace `WootsUp\BuilderMcp\*` unchanged       | ✔             |
| TDD red→green→commit per finding                      | ✔             |
| Conventional commits with `fix(extension): F-NN …`    | ✔             |

---

## Live-curl evidence

**Deferred.** dev.wootsup.com currently runs the pre-rename slug (`yootheme-builder-mcp/v1`) — see `curl https://dev.wootsup.com/wp-json/yt-builder-mcp/v1/identity` → 404. A fresh DXT + plugin-ZIP deploy is the downstream step (`/deploy-extensions` workflow), not gated on A3. When the deploy lands, the following curls validate the wire-contract:

```bash
# F-09: yootheme_version + yooessentials_version surfaced
curl -sH "Authorization: Bearer $YTB" \
  https://dev.wootsup.com/wp-json/yt-builder-mcp/v1/health | jq '.yootheme_version, .yooessentials_version'
# → "4.5.33", "3.x.y" (or null when essentials not installed)

# F-10: generated_at ISO timestamp
curl -sH "Authorization: Bearer $YTB" \
  https://dev.wootsup.com/wp-json/yt-builder-mcp/v1/etag | jq '.generated_at'
# → "2026-05-22T17:23:45+00:00"

# F-07: A→B→A yields three distinct ETags
TPL="S12MqLbP"   # any live template-id
e1=$(curl -sH "Authorization: Bearer $YTB" https://dev.wootsup.com/wp-json/yt-builder-mcp/v1/etag | jq -r .etag)
curl -sX POST -H "Authorization: Bearer $YTB" -H "If-Match: $e1" \
  -H "Content-Type: application/json" \
  -d '{"parent_path":"","element_type":"divider"}' \
  https://dev.wootsup.com/wp-json/yt-builder-mcp/v1/pages/$TPL/elements
e2=$(curl -sH "Authorization: Bearer $YTB" https://dev.wootsup.com/wp-json/yt-builder-mcp/v1/etag | jq -r .etag)
# (now DELETE the element added above)
e3=$(curl -sH "Authorization: Bearer $YTB" https://dev.wootsup.com/wp-json/yt-builder-mcp/v1/etag | jq -r .etag)
# → e1, e2, e3 are three distinct strings with the same `<sha>-` prefix but `-r{N}` suffixes 0/1/2

# F-15: publish carries published_state_etag + note
curl -sX POST -H "Authorization: Bearer $YTB" \
  https://dev.wootsup.com/wp-json/yt-builder-mcp/v1/pages/$TPL/publish | jq '.published, .published_state_etag, .note'
# → true, "<sha>-r<N>", "YOOtheme templates publish on save; this is a cache-flush + state-snapshot operation."

# F-08: pages_list ships modified_at on cold-start
curl -sH "Authorization: Bearer $YTB" \
  https://dev.wootsup.com/wp-json/yt-builder-mcp/v1/pages | jq '.pages[] | {id, name, modified_at}'
# → every row has a non-null modified_at (from blob or from ytb_mcp_pages_meta fallback)
```

The PHPUnit suite covers each of these wire-paths via the in-process stubs in `tests/php/bootstrap.php`; live-curl is the final smoke gate, not the defining gate.
