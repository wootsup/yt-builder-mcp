# ADR-001 + ADR-002 — Joomla Port Foundation (Wave 0 Spikes)

**Date:** 2026-05-24
**Status:** ✅ Both spikes resolved. ADR-001 + ADR-002 issued and Thomas-approved.
**Scope:** Foundation architectural decisions for the `yt-builder-mcp` Joomla 5/6 port.
**Dev-Server context:** dev.wootsup.com/joomla — Joomla 6.0.2 Stable, YT Pro 4.5.x, db-prefix `j6_`

> **Note on provenance:** This document is the committed copy of the Wave-0 spike
> report originally drafted at `_internal/audits/wave-0-spikes-report.md`. The
> `_internal/` location is gitignored (`.gitignore:25`) — this `docs/adr/` copy
> is the canonical source-of-truth for the architectural rationale. The
> `_internal/` copy may continue to exist as session-internal notes but MUST
> NOT be cited from committed code or docs.

---

## Spike S1 — Joomla cache-invalidation after writes to `#__extensions.custom_data`

### Outcome

L1 cache-flush surface on Joomla is **smaller** than WP — only the YT cache layer is structurally required (no Joomla autoload-options-cache equivalent). L2 (per-article) adds two more layers.

### Caches to flush per write-scope

| Scope | Required flush | Conditional flush |
|---|---|---|
| **L1 — `#__extensions.custom_data`** | `\YOOtheme\app('cache')->flush()` only | — |
| **L2 — `#__content.fulltext`** | `\YOOtheme\app('cache')->flush()` + `com_content` cache-group via `CacheControllerFactoryInterface` | `page` cache-group if `PluginHelper::isEnabled('system','cache')` |

### Anti-patterns (must NOT regress to)

- Nuclear `Cache::cleanCache()` — same class-of-bug as the `wp_cache_flush()` regression we fixed on WP-side in Wave-6 Fix 14.
- Filesystem `rm -rf templates/yootheme/cache/*` — these are content-hashed compiled package configs, NOT user-state. Deletion forces unnecessary recompile + race risk.
- Legacy static `JCache::getInstance('callback')->clean(...)` — deprecated in J5, throws `E_USER_DEPRECATED` in J6. Always use DI-container.

### Implementation target (Wave 6.5)

`platform-joomla/src/Cache/JoomlaCacheFlusher.php` — two methods:

```php
public function flushL1(): void {
    $this->flushYoothemeCache();    // identical call to WP-side
}

public function flushL2(int $articleId): void {
    $this->flushYoothemeCache();
    $this->cleanGroup('com_content');
    if (PluginHelper::isEnabled('system','cache')) {
        $this->cleanGroup('page');
    }
}
```

Each call wrapped in `try/catch \Throwable` + `SecurityLogger::log('cache_flush_failed', ...)` (WP-parity invariant: cache-flush failure NEVER undoes write success).

### Files inspected (read-only, mcp__getimo__server_exec)

- `/templates/yootheme/packages/platform-joomla/src/Storage.php` (deferred-write pattern)
- `/templates/yootheme/packages/platform-joomla/src/Platform.php`
- `/templates/yootheme/packages/theme-settings/src/CacheController.php`
- `/templates/yootheme/packages/builder-joomla/src/PageController.php`
- `/templates/yootheme/packages/theme-joomla/src/Listener/LoadConfigCache.php`
- `/plugins/system/cache` — present, runtime-check required

---

## Spike S2 — YT `Builder::load(context:'save')` behavior on Joomla 6

### Outcome (architecture-critical)

**`loadWithContext($tree, 'save')` runs byte-identically on Joomla** — the YT 4.5.33 Builder pipeline is identical to WP. `\YOOtheme\app('builder')->withParams(['context'=>'save'])->load($json)` is the EXACT same call used in YT-Joomla's own `builder-joomla/src/PageController.php:110`.

The risk is NOT transform divergence — it is **YT bootstrap availability per request-routing-path**.

### THE CRITICAL FINDING

`templates/yootheme/template_bootstrap.php` allowlist (line 7):

```
['com_ajax', 'com_content', 'com_templates', 'com_modules', 'com_advancedmodules']
```

**`com_api` is NOT in the allowlist.** Web Services API requests bypass YT bootstrap entirely. Any controller that calls `\YOOtheme\app('builder')` from a com_api-mounted route hits `undefined function` → fatal.

This **inverts the plan-doc decision** to "use Web Services API canonical (NICHT com_ajax)".

### Save-transform pipeline (deterministic, complete)

`->withParams(['context'=>'save'])->load($json)` triggers `applyTransforms('load')` then `applyTransforms('save')` resolving as `pre{$context}` + `{$context}`:

| Hook | Transform | Effect |
|---|---|---|
| `preload` | `UpdateTransform` | Element migrations by theme-version |
| `preload` | `DefaultTransform` | Fills `props` from defaults |
| `preload` | `CollapseTransform::preload` | Inlines collapsed-state props |
| `presave` | `OptimizeTransform` (builder) | Drops empty `name`/`children`/`props`, removes default-value props, ksorts |
| `presave` | `Source\OptimizeTransform` | Drops `source` when query empty + props set |
| `save` | *(none currently)* | Empty hook |

**No element-level `transforms` JSON keys exist. No theme-specific save-transforms. Zero Joomla-specific deltas.** Confirmed by byte-identical diff across builder/, builder-source/, theme-*, builder-joomla/, platform-joomla/.

### Files inspected (read-only)

- `/templates/yootheme/packages/builder/src/Builder.php` (byte-identical WP↔Joomla)
- `/templates/yootheme/packages/builder/bootstrap.php` (byte-identical)
- `/templates/yootheme/packages/builder/src/Builder/OptimizeTransform.php`
- `/templates/yootheme/packages/builder-source/src/Source/OptimizeTransform.php`
- `/templates/yootheme/packages/builder-joomla/src/PageController.php` (lines 95-145 — reference impl)
- `/templates/yootheme/template_bootstrap.php` (allowlist line 7)

---

## ADR-001 — REST-Routing: Web Services API + manual YT-Bootstrap

**Status:** Thomas-approved 2026-05-24.

### Context

The Joomla port needs to expose the same REST surface as the WordPress plugin
(25 routes, Bearer-authenticated, ETag-guarded). Joomla offers two viable
transports:

1. **Web Services API** (`com_api`) — the canonical J5/6 REST channel,
   path-segmented URLs (`/api/index.php/v1/...`), Bearer header passes
   through the stock `htaccess.txt`.
2. **com_ajax** — proven by api-mapper but URL shape is uglier
   (`?option=com_ajax&plugin=...`) and breaks REST-purist tooling.

The plan-doc canonical preference is Web Services API. Spike-S2 then
surfaced the YT-bootstrap-availability constraint described above.

### Decision

Mount the REST surface via **Web Services API** (`plg_webservices_ytbmcp` +
`com_ytbmcp/api Controllers`) per plan-doc canonical preference. EVERY REST
controller that touches `\YOOtheme\app('builder')` MUST lazy-require
`templates/yootheme/template_bootstrap.php` in its constructor before any
YT call. The bootstrap is idempotent (cached as singleton via require's
return).

### Rationale

- Plan-doc canonical preference for Web Services API honored.
- com_ajax pattern proven by api-mapper but URL shape is uglier (`?option=com_ajax&plugin=...`) and breaks REST-purist tooling.
- Manual bootstrap fragility is **manageable via defense layers** (typed exception, pin-test, sentinel-test).

### Consequences

- Every write-path controller (and read-paths that touch the Builder)
  carries a `$this->ytBootstrapper->ensure()` call in its constructor.
- Read-only routes (Health, Identity, Pickup, Sources-list,
  Inspection-list) MAY skip the bootstrap and answer without YT — this is
  intentional graceful-degradation: a misconfigured YT shouldn't prevent
  customers from probing the plugin or claiming a pickup.
- A YT-update that breaks the bootstrap interface fails fast at the pin-
  test gate before reaching production.

### Defense layers

1. **Typed exception** `WootsUp\BuilderMcp\Platform\Joomla\Exception\YTNotBootstrappedException` — thrown when `\function_exists('\YOOtheme\app')` is false after bootstrap-attempt. Returns HTTP 503 with structured `{error:"yt_not_bootstrapped", remediation:"..."}` so customers see actionable error not fatal.
2. **Pin-test** in `tests/php/Joomla/YtBootstrapAvailabilityPinTest.php` — asserts the bootstrap.php file exists at expected path AND that after-include `\YOOtheme\app('builder')` resolves with `withParams` + `load` methods.
3. **Sentinel-test** in `tests/php/Joomla/YtTransformInventorySentinelTest.php` — snapshots the count + class names of `preload`/`presave`/`save`/`render` registered transforms. Fails-loud on YT-update-drift (forces re-audit before shipping).
4. **Graceful degradation** — read-only routes (Health, Identity, Pickup, Sources-list, Inspection-list) do NOT call `\YOOtheme\app('builder')` — they can answer without YT-bootstrap. Only write-routes + layout-reads hit the Builder.

### Alternatives considered + rejected

- **com_ajax only** — proven (api-mapper) but URL-shape inferior and diverges from plan-doc.
- **Web Services API + 503 on missing YT** — leaves customers with no workaround; fails enterprise-grade-tauglich check.

### Pin-test cookbook references

- Spike S2 Risk A4 — Sentinel-Test that snapshots count + class names of `presave`/`save` transforms; fails-loud on drift.
- Wave-6.6 includes "Custom PHPStan-Rules verbieten die J6-removed-APIs" — the Sentinel-Test family extends that defense pattern.

---

## ADR-002 — Cache-flush scoping (Wave 6.5 input from S1)

**Status:** Thomas-approved 2026-05-24.

### Context

The Joomla port handles two storage layers:

- **L1 — Type Templates**, stored in
  `#__extensions.custom_data WHERE element='yootheme' AND folder='system'`.
- **L2 — Per-Article Content**, stored in `#__content.fulltext` via
  `com_content` (Joomla-extra scope per Thomas-Direktive 2026-05-23).

Each layer interacts with a different set of Joomla caches. A poorly
scoped flush is both a performance regression and a class-of-bug —
WP-side suffered exactly this on Wave-6 Fix 14 when `wp_cache_flush()`
was used as a sledgehammer.

### Decision

`JoomlaCacheFlusher` exposes scope-specific methods:
- `flushL1()` → flushes the YT cache only (identical call surface to the WP-side).
- `flushL2(int $articleId)` → flushes YT cache + the `com_content` cache-group + conditionally the `page` cache-group when `system/cache` plugin is enabled.

NO nuclear `Cache::cleanCache()` invocation anywhere in the codebase.

### Rationale

- L1 storage is `#__extensions.custom_data` — Joomla reloads the row per-request, no application-cache layer exists. WP-side flushes 3 `wp_option` keys + `'alloptions'` bucket; Joomla has no equivalent.
- L2 storage is `#__content.fulltext` — Joomla DOES cache this via `com_content`; our direct-write bypasses the model events that would auto-evict, so we must call `clean('com_content')` ourselves.
- `page` cache-group is plugin-gated (`PluginHelper::isEnabled('system','cache')`) — only flush when enabled.

### Consequences

- Cache-flush failures are caught + logged via `SecurityLogger` and NEVER undo the underlying write — WP-parity invariant.
- All cache-API calls go through `CacheControllerFactoryInterface` (DI) — never the deprecated `JCache::getInstance(...)` static.
- A future scope (e.g. L3 module-level layouts) needs its own `flushL3()` method — flusher-methods are NOT generic.

### Anti-patterns codified

- NEVER nuclear `Cache::cleanCache()` — same regression-class as WP `wp_cache_flush()` (Wave-6 Fix 14).
- NEVER static `JCache::getInstance()` — deprecated in J5, throws in J6. Always DI-container.
- NEVER filesystem-rm of `templates/yootheme/cache/*` — content-hashed compiled configs, not user state.

### Alternatives considered + rejected

- **Single `flush()` method that always flushes everything** — over-broad,
  causes unnecessary recompile cost on L1 writes (which are far more
  frequent than L2 writes). Rejected.
- **Auto-detect scope by inspecting the written row** — fragile, couples
  flusher to storage internals. Rejected in favour of explicit caller
  intent.

---

## Wave 0 Status

- [x] Cookbook Master + Ch1-Ch6 vollständig gelesen + verstanden
- [x] Plan-Doc + Session-Brief vollständig gelesen
- [x] MEMORY-Files relevant gelesen
- [x] Dev-Umgebung verifiziert (J 6.0.2, `j6_` prefix, YT Pro live, 3 templates incl. system)
- [x] Spike S1 — JoomlaCacheFlusher scope dokumentiert (ADR-002)
- [x] Spike S2 — REST-routing-decision (ADR-001 — Thomas-approved)
- [x] Wave 1 unblocked

Next: Wave 1 — Joomla plugin-skeleton with manual-YT-bootstrap helper, SQL install files, plg_system_ytbmcp + com_ytbmcp packaging artifacts.
