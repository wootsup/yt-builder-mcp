# Cross-Platform Parity Notes (WordPress ↔ Joomla)

> Tracked record of intentional WordPress ↔ Joomla implementation divergences
> and shared-behaviour decisions for `yt-builder-mcp`. The bar is 1:1
> customer-facing parity; where the platforms' primitives differ, the
> *behaviour* matches even when the *implementation* cannot. Every divergence
> here is pinned by tests on **both** sides. Keep both pins in sync when
> refactoring.

---

## 0. Verified parity

I run a cross-platform audit on every release. The bar across both platforms:

| Axis | Status |
|------|--------|
| Architecture | 1:1 customer-facing (each platform-idiomatic underneath) |
| Security | 1:1 (same threat model, same Bearer authority surface) |
| Tests | 1:1 (every divergence pinned on both sides) |
| Defense-in-depth | 1:1 (every external dep fail-safe) |
| Data | 1:1 capability (default divergence noted in §4) |
| Docs | 1:1 (every divergence tracked here) |

Both update servers (WordPress `info.json`, Joomla `update.xml`) are live and
publish-gated.

The intentional divergences captured in §1 to §4 below are the *only* delta
between the two platforms.

---

## 1. `resolveBindingLevel` return primitive: WP_Error vs error-array

| Platform | File | Error primitive |
|----------|------|-----------------|
| WordPress | `src/modules/builder-source-binding/src/SourcesController.php` | returns `\WP_Error` on a binding-level failure (`$resolution instanceof \WP_Error`) |
| Joomla | `src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/SourcesController.php` | returns an **error-array** `['error' => …]` on the same failure (`isset($resolution['error'])`) |

**Why it diverges.** `\WP_Error` is a WordPress core type with no Joomla
equivalent; the Joomla `com_api` surface has no logged-in identity and renders
errors through its own JSON envelope. Reimplementing `resolveBindingLevel` to
return a plain array on the Joomla side keeps the resolver self-contained and
avoids dragging a WP type into the Joomla codebase.

**Why it's acceptable.** The *customer-visible* outcome is identical: an
invalid binding level yields the same error code and HTTP status on both
platforms. Only the internal control-flow primitive differs.

**Maintenance rule.** Both implementations are pinned by tests. If you change
the binding-level resolution logic on one platform, change it on the other in
the same PR and update both pins.

---

## 2. `/health` anonymous-vs-Bearer field split

The anonymous (no-Bearer) `/health` payload is identical on both platforms:

```json
{ "plugin_version": "…", "status": "ok" }
```

`yootheme_loaded` and every other implementation-revealing field is **only**
emitted in the Bearer-authenticated augmentation branch. This closed an
earlier Joomla-only information-leak and keeps the two `HealthController`
implementations at 1:1 parity.

- WordPress: anonymous payload = `{plugin_version, status}` only.
- Joomla: same, with `yootheme_loaded` moved behind the Bearer-gated branch
  (`src/packaging/joomla/extensions/com_ytbmcp/api/src/Controller/HealthController.php`).

**Pinned by:** `HealthControllerSmokeTest` (anonymous-vs-Bearer field-split
assertions, on both sides).

**Related admin-UI nuance.** YOOtheme Pro lazy-bootstraps on the REST/API
surface only on Joomla (`com_api`'s `template_bootstrap` allowlist excludes
it), so the Joomla **Diagnostics** tab performs the same idempotent
`YtBootstrapper::ensure()` the API controllers use so it can show the real
YOOtheme Pro version (fail-safe to a placeholder when YT is absent). On
WordPress YT is a theme loaded on every admin request, so this trick is not
needed. The same null signal drives the "YOOtheme Pro required" admin notice.

**Maintenance rule.** Any new diagnostic field added to `/health` must default
to the Bearer-gated branch unless it is explicitly safe to expose anonymously.
Add the assertion to `HealthControllerSmokeTest` on both platforms.

---

## 3. Bearer-as-sole-authority on the Joomla API surface

Not a divergence to "fix". An intentional design decision. The L2 article-write
`core.edit` ACL gate is intentionally absent because
`authorise('core.edit', …)` is always false in the `com_api` application (no
logged-in identity), which would 500 every L2 write. The Bearer token's scope
hierarchy is the sole authority on the API surface; Joomla ACL governs only
the admin component (`com_ytbmcp`).

The admin UI carries no misleading "managed by Joomla ACL" claim for
API/key access. The customer-facing copy describes the server-enforced
scope-hierarchy guardrails. The only `core.admin` / `core.manage` references
in the admin component (`DashboardController`, `Dashboard/HtmlView`) gate the
admin component itself, never the API.

**Maintenance rule.** Do not reintroduce a Joomla-ACL gate on any API route,
and do not let the admin UI imply API access is "managed by Joomla
permissions".

---

## 4. Uninstall data disposition: WP wipe-on-delete vs Joomla preserve-by-default

| Platform | File | Default on plugin delete |
|----------|------|--------------------------|
| WordPress | `src/uninstall.php` | **Wipes unconditionally.** Deleting the plugin removes every plugin-owned option, per-template state-lock, and transient (`ytb_mcp_*`), multisite-aware, with no opt-out. |
| Joomla | `src/packaging/joomla/extensions/plg_system_ytbmcp/script.php` | **Preserves by default.** Uninstall only drops the owned tables when the admin has opted in via the `delete_data_on_uninstall` plugin parameter (default OFF). `sql/uninstall.*.sql` is intentionally empty so the manifest hook never wipes data implicitly. |

**Why it diverges.** This is an intentional, platform-idiomatic difference,
not a defect. WordPress's `uninstall.php` contract is "delete = clean slate",
and the WP Plugins screen already separates *deactivate* (reversible) from
*delete* (destructive), so wipe-on-delete matches user expectation. Joomla
has no such two-step affordance and its convention leans conservative: an
extension uninstall should not silently destroy customer-authored data (here:
Bearer keys + per-article Builder state) unless the admin explicitly asks
for it.

**Why it's acceptable.** Both paths are reachable and tested; the divergence
is in the *default*, not the *capability*. A WP admin who wants to preserve
data deactivates instead of deletes; a Joomla admin who wants a clean wipe
flips `delete_data_on_uninstall` ON before uninstalling. The Joomla
preserve-by-default path is pinned by `OptOutPreservesDataPinTest`; the
destructive path by `UninstallOrderingPinTest`.

**Follow-up worth tracking.** If I later want strict default-parity, the
cleaner direction is to give WordPress an opt-in/opt-out setting too
(Joomla-style) rather than make Joomla wipe-by-default. The latter would
regress Joomla's conservative norm. No customer has hit this; logged here
so the asymmetry is an explicit decision, not a silent drift.
