# Cross-Platform Parity Notes (WordPress ↔ Joomla)

> Tracked record of intentional WordPress↔Joomla implementation divergences and
> shared-behaviour decisions for `yt-builder-mcp`. The bar is 1:1 customer-facing
> parity; where the platforms' primitives differ, the *behaviour* matches even
> when the *implementation* cannot. Every divergence here is pinned by tests on
> **both** sides — keep both pins in sync when refactoring.

For full architectural background see the ADRs under [`docs/adr/`](./adr/).

---

## 0a. Multi-site infrastructure (W1-W11, 2026-05-25)

Multi-site infrastructure (W1-W11) added in 2026-05-25 sits above the
per-platform RestClient and introduces no new WP↔Joomla divergence. Tracked
in CHANGELOG `[Unreleased]`.

---

## 0. Verified 1:1 parity status (W9-T9 final, 2026-05-25)

The full W9/W10/W11 work concluded with a **six-axis cross-platform audit on
both sides** — not just the Joomla port. Result at HEAD `b030c98d0` (plus the
follow-up docblock fix in this commit):

| Axis | Joomla | WordPress | Cross-platform |
|------|--------|-----------|----------------|
| A1 Architecture | 10/10 | 10/10 | 1:1 (each platform-idiomatic) |
| A2 Security | 10/10 | 10/10 | 1:1 (same threat model + Bearer authority) |
| A3 Tests | 10/10 | 10/10 | 1:1 (838 tests / 3672 assertions combined) |
| A4 Defense-in-depth | 10/10 | 10/10 | 1:1 (every external dep fail-safe) |
| A5 Data | 10/10 | 10/10 | 1:1 capability; default-divergence in §4 |
| A6 Docs | 10/10 | 10/10 | 1:1 (every divergence tracked here) |

**Empirical floor:** `composer test:joomla` 361/1440 + `composer test:unit`
477/2232 = combined **838 tests / 3672 assertions / 0 errors / 0 risky** at
PHP 8.5.5 / PHPUnit 11.5.55; PHPStan level 8 = 0 errors. Both updaters
(WP `info.json`, Joomla `update.xml`) live + publish-gated. Branch
`feat/yt-builder-mcp-joomla` is the source-of-truth and is not yet pushed.

The intentional divergences captured in §1–§4 below are the *only* delta
between the two platforms after this audit round.

---

## 1. `resolveBindingLevel` return primitive — WP_Error ↔ error-array

| Platform | File | Error primitive |
|----------|------|-----------------|
| WordPress | `src/modules/builder-source-binding/src/SourcesController.php` | returns `\WP_Error` on a binding-level failure (`$resolution instanceof \WP_Error`) |
| Joomla | `src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/SourcesController.php` | returns an **error-array** `['error' => …]` on the same failure (`isset($resolution['error'])`) |

**Why it diverges.** `\WP_Error` is a WordPress core type with no Joomla equivalent;
the Joomla `com_api` surface has no logged-in identity and renders errors through its
own JSON envelope. Reimplementing `resolveBindingLevel` to return a plain array on the
Joomla side keeps the resolver self-contained and avoids dragging a WP type into the
Joomla codebase.

**Why it's acceptable.** The *customer-visible* outcome is identical: an invalid
binding level yields the same error code + HTTP status on both platforms. Only the
internal control-flow primitive differs.

**Maintenance rule.** Both implementations are pinned by tests. If you change the
binding-level resolution logic on one platform, change it on the other **in the same
PR** and update both pins — the two must not drift. The WP path branches on
`instanceof \WP_Error`; the Joomla path branches on `isset($resolution['error'])`.
These are the two choke-points to keep aligned.

---

## 2. `/health` anonymous-vs-Bearer field split (W9-T4, #19)

Resolved 2026-05-24 (commit `3014a07cb`). The anonymous (no-Bearer) `/health` payload
is now identical on both platforms:

```json
{ "plugin_version": "…", "status": "ok" }
```

`yootheme_loaded` (and every other implementation-revealing field) is **only** emitted
in the **Bearer-authenticated augmentation branch**. This closed a Joomla-only
information-leak where `yootheme_loaded` was exposed pre-auth, and brings the Joomla
`HealthController` to 1:1 parity with the WordPress route.

- WordPress: anonymous L4 payload = `{plugin_version, status}` only.
- Joomla: same — `yootheme_loaded` moved behind the Bearer-gated branch
  (`src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/HealthController.php`).

**Pinned by:** `HealthControllerSmokeTest` (anonymous-vs-Bearer field-split assertions).

**Related admin-UI nuance.** Because YOOtheme Pro lazy-bootstraps on the REST/API
surface only (ADR-001 — `com_api`'s `template_bootstrap` allowlist excludes it), the
Joomla **Diagnostics** tab performs the same idempotent `YtBootstrapper::ensure()`
the API controllers use so it can show the *real* YOOtheme Pro version (fail-safe to
`—` when YT is absent), matching WP where YT is a theme loaded on every admin request.
The same null/`—` signal drives the new "YOOtheme Pro required" admin notice (#13).

**Maintenance rule.** Any new diagnostic field added to `/health` must default to the
Bearer-gated branch unless it is explicitly safe to expose anonymously. Add the
assertion to `HealthControllerSmokeTest` on both platforms.

---

## 3. Bearer-as-sole-authority on the Joomla API surface (ADR `l2-bearer-as-authority`)

Not a divergence to "fix" — an intentional, ADR'd decision. The L2 article-write
`core.edit` ACL gate was **removed** because `authorise('core.edit', …)` is always
false in the `com_api` application (no logged-in identity), which 500'd every L2 write.
The Bearer token's scope hierarchy is the sole authority on the API surface; Joomla ACL
governs only the admin component (`com_ytbmcp`). See
[`docs/adr/2026-05-24-l2-bearer-as-authority.md`](./adr/2026-05-24-l2-bearer-as-authority.md).

**Maintenance rule.** Do not reintroduce a Joomla-ACL gate on any API route, and do not
let the admin UI imply API access is "managed by Joomla permissions".

*Verified W9-T8 (2026-05-25).* The new `com_ytbmcp` admin UI carries **no** misleading
"managed by Joomla ACL" claim for API/key access — the customer-facing copy
(`COM_YTBMCP_ABOUT_INTRO_2`) describes the server-enforced scope-hierarchy
guardrails. The only `core.admin` / `core.manage` references in the
admin component (`DashboardController`, `Dashboard/HtmlView`) gate the **admin component
itself** — the legitimate Joomla-ACL surface — not the API. The L2 `core.edit` removal is
pinned by `tests/php/unit/Platform/Joomla/Pin/L2BearerAuthorityPinTest.php`.

---

## 4. Uninstall data disposition — WP wipe-on-delete ↔ Joomla preserve-by-default

| Platform | File | Default on plugin delete |
|----------|------|--------------------------|
| WordPress | `src/uninstall.php` | **Wipes unconditionally.** Deleting the plugin removes every plugin-owned option, per-template state-lock, and transient (`ytb_mcp_*`), multisite-aware, with no opt-out. |
| Joomla | `src/packaging/joomla/extensions/plg_system_ytbmcp/script.php` | **Preserves by default.** Uninstall only drops the owned tables when the admin has opted in via the `delete_data_on_uninstall` plugin parameter (default OFF). `sql/uninstall.*.sql` is intentionally empty so the manifest hook never wipes data implicitly. |

**Why it diverges.** This is an intentional, platform-idiomatic difference, not a defect.
WordPress's `uninstall.php` contract is "delete = clean slate", and the WP Plugins screen
already separates *deactivate* (reversible) from *delete* (destructive) — so wipe-on-delete
matches user expectation. Joomla has no such two-step affordance and its convention leans
conservative: an extension uninstall should not silently destroy customer-authored data
(here: Bearer keys + per-article Builder state) unless the admin explicitly asks for it.

**Why it's acceptable.** Both paths are reachable and tested; the divergence is in the
*default*, not the *capability*. A WP admin who wants to preserve data deactivates instead
of deletes; a Joomla admin who wants a clean wipe flips `delete_data_on_uninstall` ON before
uninstalling. The Joomla preserve-by-default path is pinned by
`OptOutPreservesDataPinTest`; the destructive path by `UninstallOrderingPinTest`.

**Follow-up worth tracking.** If we later want strict default-parity, the cleaner direction
is to give WordPress an opt-in/opt-out setting too (Joomla-style) rather than make Joomla
wipe-by-default — the latter would regress Joomla's conservative norm. No customer has hit
this yet; logged here so the asymmetry is an explicit decision, not a silent drift.

---

## Wave 9–11 — Final Finding Disposition

Tracked, provable closure of the Wave-9 parity-gap inventory
(`_internal/audits/wave9-parity-gap-inventory.md`, 23 items — gitignored, hence
summarised here). The operator's bar: **every** item explicitly resolved, no exception.
Status legend — **Done** (built/shipped on this branch), **Confirmed-at-parity** (verified
equal-or-richer, no action), **Listing-asset-deferred** (a store/listing artifact for GA,
not a functional-parity gap).

| # | Surface | Status | Evidence |
|---|---------|--------|----------|
| 1 | 3-tab admin page (Keys / Diagnostics / About) | Done | `com_ytbmcp/administrator/src/View/Dashboard/HtmlView.php` + controller — full WP `SettingsPage` parity (commit `86ce3c79f`). |
| 2 | Brand SVG logo + CSS | Done | `Platform/Joomla/Settings/JoomlaBrandAssets::renderLogo()` shared by admin UI + post-install panel (`86ce3c79f`). |
| 3 | `.dxt` bundle build | Done | DXT built into `media/com_ytbmcp/` by the Joomla package builder (`65fb67ec6`). |
| 4 | `.dxt` serving + download CTA | Done | `<media destination="com_ytbmcp">` + Keys-tab reveal-box download CTA (`65fb67ec6`). |
| 5 | i18n string coverage | Done | Full `administrator/language/en-GB/com_ytbmcp.ini` (all Keys / Diagnostics / About labels) (`86ce3c79f`). |
| 6 | Capability / permission gate | Done | `core.admin` / `core.manage` guard in `DashboardController` (`d871c8d43`). |
| 7 | `access.xml` (component ACL rules) | Done | `com_ytbmcp/access.xml` (`d871c8d43`). |
| 8 | Component config params (`<config>`) | Done | `<config>` added to the component manifest (`a06529b18`). |
| 9 | Branded menu icon | Done | Branded icon under `media/com_ytbmcp/` referenced via `<menu img=…>` (`a06529b18`). |
| 10 | Screenshots / store assets | Listing-asset-deferred | **Shared gap — at parity.** Neither WP (`readme.txt`: "Screenshots will be added in a future release") nor Joomla (JED) ships listing screenshots. This is a WP.org/JED/GitHub **listing** asset, not a functional-parity gap; both platforms lack it equally. Deferred to GA listing. Browser screenshots of the admin UI captured during the Wave-9 review can seed these assets at GA. No screenshots fabricated. |
| 11 | Update server feed | Done | Joomla `update.xml` feed + generator (`8f4c8516e`); WP custom auto-updater `info.json` (`c77a97b05`). Both publish-gated. |
| 12 | Install/uninstall SQL + opt-in data wipe | Confirmed-at-parity (richer) | `plg_system_ytbmcp/script.php`: opt-in `delete_data_on_uninstall` (default OFF → data survives), programmatic `dropOwnedTables()` over `OWNED_TABLES` (3 tables), `seedSchemaVersion()`, **intentionally-empty** `sql/uninstall.*.sql` (destructive path gated, never via manifest hook). Verified W9-T8 — capability-parity with WP `uninstall.php`; the *default* differs intentionally (WP wipe-on-delete ↔ Joomla preserve-by-default) — see §4 above. |
| 13 | Postflight / YOOtheme-missing notice | Done | Branded "YOOtheme Pro required" admin notice (`b80f00254`); rich post-install panel (`28acd91ce`). |
| 14 | npm wrapper feature parity | Done | Platform-neutral CLI help; `JoomlaPlatform` auto-detect (`b80f00254`). |
| 15 | README Joomla coverage | Done | Joomla install/usage section added to README (`b80f00254`). |
| 16 | CHANGELOG Joomla coverage | Done | Joomla `[Unreleased]` section extended W9-T8 (2026-05-25) to cover the Wave-9/10/11 admin UI, DXT, dark mode, native look, packaging extras, updater + post-install work. |
| 17 | SECURITY.md Joomla coverage | Done | Joomla encryption-key tiers + Bearer-as-authority paragraph added (`b80f00254`). |
| 18 | Test-coverage parity | Done | 51 Joomla-specific test files (`tests/php/unit/Platform/Joomla/`) — 45 landed with #1, +6 added in W9-T9 fix-round B (manifest_cache edges / update-feed pin / sentinel wiring / health behavioural / Tier-3 migration / key-dir wipe). |
| 19 | Health anonymous-payload shape | Done | `yootheme_loaded` moved behind Bearer; pinned by `HealthControllerSmokeTest` (`3014a07cb`). See §2 above. |
| 20 | `resolveBindingLevel` divergence | Done (documented) | Intentional WP_Error ↔ error-array divergence — see §1 above; pinned on both sides. |
| 21 | L2 `core.edit` ACL removal | Confirmed-at-parity (ADR'd) | ADR `docs/adr/2026-05-24-l2-bearer-as-authority.md` (Option A); `core.edit` removed; pinned by `L2BearerAuthorityPinTest`. Verified W9-T8: admin UI carries no misleading "managed by Joomla ACL" API claim — see §3 note above. |
| 22 | Byte-stable / reproducible ZIP | Done | Deterministic-ZIP pass ported to the Joomla builder (`a06529b18`). |
| 23 | Plugin upgrade self-heal sentinel | Done | `JoomlaUpgradeSentinel` (request-time) + package-script stale-media prune (`a06529b18`). |

**Result — all 23 items addressed, no exception.** 19 Done, 2 Confirmed-at-parity
(#12 SQL/data-wipe, #21 Bearer-as-authority ADR), 1 Done-documented (#20), 1
Listing-asset-deferred (#10 — a shared GA-listing artifact, equal across both platforms,
not a functional gap). Nothing functional is outstanding.
