# ADR — L2 Bearer scope is the sole authority (per-article ACL gate removed)

**Status:** Accepted (Round-6 audit, 2026-05-24)
**Decision:** Option A — drop the per-article Joomla `core.edit` ACL gate from L2 controllers. Bearer write-scope is the sole authority for L2 writes.
**Scope:** `com_ytbmcp` Web Services API L2 (per-article Builder state) — `ArticlesController` + `ArticleElementsController`.
**Supersedes / cross-references:** ADR-001 (session-strip), cookbook §2.2.4 (Bearer-Deny-Invariant), [[feedback-parity-is-floor-not-ceiling]].

---

## Context

L2 (per-article Builder state in `#__content.fulltext`) is Joomla-extra-scope —
WordPress has no per-resource ACL equivalent. The Round-1 → Round-4 L2
controllers carried an extra defence-in-depth layer:

```php
// Pre-Round-6 — ArticlesController::saveLayout
if (!$this->assertArticleAcl($articleId, 'core.edit')) {
    return; // 403 acl_denied
}
```

The intent was "even a write-scoped Bearer must be backed by a Joomla user
with `core.edit` permission on the specific article". The implementation
called:

```php
$user    = Factory::getUser();
$allowed = $user->authorise('core.edit', "com_content.article.{$id}");
```

## The discovered bug

`Ytbmcp::onStripApiSession` (system plugin, priority 1) is the cornerstone
of ADR-001 — it deliberately strips the Joomla session for every
yt-builder-mcp API URL so a logged-in admin's cookie can NEVER substitute
for the Bearer:

```
// yt-builder-mcp/src/packaging/joomla/extensions/plg_system_ytbmcp/.../Ytbmcp.php
// onAfterInitialise priority 1 — runs BEFORE the controller fires
```

Consequence: when L2 controllers ran `Factory::getUser()` it ALWAYS returned
the Guest user. `authorise('core.edit', …)` on Guest returns false for any
asset that isn't world-editable. **Every L2 write returned `403 acl_denied`
with a valid admin-Bearer token.** Discovered in R5 audit (N-A2-001 P1
architectural finding).

The gate was structurally always-deny, masquerading as defence-in-depth.

## Options considered

### Option A (CHOSEN) — drop the per-article ACL gate

Bearer write-scope is sufficient trust. L2 controllers rely on the same
Bearer-Deny-Invariant the L1 controllers already trust (cookbook §2.2.4).
Granular access control happens at Bearer-issuance time (scope = read /
write / admin), not at per-resource ACL time.

**Pros:**

- Restores L2 functionality immediately — admin-Bearer writes succeed.
- Matches WP-side parity floor — WP has no per-resource ACL either, and
  per [[feedback-parity-is-floor-not-ceiling]] Joomla is allowed to be
  asymmetric where it has natural capability, but is NOT required to
  invent ACL where WP has none.
- Preserves ADR-001 session-strip — the cookie-bypass surface stays
  closed.
- Single source of authority (Bearer) is easier to reason about,
  forensically grep, and rate-limit than dual-gate (Bearer + Joomla ACL).

**Cons:**

- A future customer who wants "admin-A can edit article 12 but not
  article 13" cannot enforce that via Joomla user-groups — they must
  issue separate Bearers with appropriately-scoped claims (see "Path
  back" below).

### Option B (REJECTED) — keep the ACL gate, load Joomla identity in the controller

Require the controller to skip session-strip's effect and load a user
from a Bearer-bound claim (e.g. `joomla_user_id` in the JWT payload),
then call `authorise()` against that loaded user.

**Why rejected:**

- Re-opens the cookie-bypass surface ADR-001 closed. The whole reason
  session-strip exists is to prevent a logged-in admin's stale cookie
  from substituting for an explicit Bearer.
- Couples Bearer issuance to a Joomla `#__users.id` — Bearers become
  user-bound rather than client-bound, breaking the existing cross-
  device pattern.
- Substantial work — Bearer claim extension, KeyService rotation,
  client-side token caching changes.

### Option C (REJECTED) — keep the gate but make it a no-op warn-only

Log a warning when the ACL check fails but allow the request through.
**Why rejected:** worst of both worlds — pretends to be defence-in-depth
in code review but provides zero actual defence at runtime. Confuses
future auditors.

## Decision

**Option A.** Remove `assertArticleAcl()` and all call-sites. Replace
each call-site with a single-line comment carrying the rationale + ADR
back-reference. `SecurityLogger::EVENT_L2_ACL_DENIED` is marked
`@deprecated` (preserved for namespace reservation should the future
user-binding model in "Path back" land).

## Defence-in-depth surfaces retained

This ADR removes ONE defence layer (per-article Joomla user ACL).
The remaining stack is fully load-bearing:

1. **Session-strip** (ADR-001 / Ytbmcp.php priority-1 listener) — closes
   the cookie-bypass surface. UNCHANGED.
2. **Bearer scope check** (`AbstractApiController::dispatch('write', …)`)
   — already the canonical authority for every yt-builder-mcp write
   request. UNCHANGED.
3. **Rate limiting** (60 writes/min/key-id, cookbook §2.5) — applies
   uniformly to L1 and L2. UNCHANGED.
4. **Optimistic locking** (If-Match ETag — cookbook §3.1.6) — prevents
   concurrent overwrite. UNCHANGED.
5. **Cross-article-pointer guard** (JsonPointer scoping — cookbook
   §3.1.7) — prevents `articleId=12 + pointer=/templates/13/...`
   cross-resource writes. UNCHANGED.
6. **Article-existence probe** (`$storage->articleExists($id)`) — 404
   on missing resource. UNCHANGED.

## Path back (if customer demand emerges)

If per-article ACL becomes a requirement, the canonical implementation
is **Bearer-claim-based**, NOT `Factory::getUser()`-based:

1. Extend Bearer issuance (KeyService) with optional
   `allowed_article_ids: number[]` claim.
2. L2 controllers read the claim from the verified Bearer (already in
   scope via `$claims` parameter) and short-circuit 403 when the request
   `articleId` isn't in the allow-list.
3. This stays compatible with session-strip — no `Factory::getUser()`
   call, no cookie-bypass surface re-opening.
4. KeyService rotation + claim-shape migration is the substantial work,
   not the controller-side guard.

Until that work is scoped, the controllers stay at "Bearer scope is the
sole authority" per this ADR.

## Pin-test enforcement

`tests/php/unit/Platform/Joomla/Pin/L2BearerAuthorityPinTest.php` fails
loudly if any of these substrings reappear in the L2 controller sources:

- `assertArticleAcl`
- `$user->authorise(`
- `Factory::getUser()`

The pin also asserts the docblock retains the "Bearer write-scope is
authoritative" rationale text so a future grep-driven refactor cannot
quietly delete the architectural decision.
