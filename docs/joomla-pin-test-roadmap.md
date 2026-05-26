# Joomla Pin-Test Coverage Roadmap

Round-3 audit (A3 P2-1) tracker doc — lists the cookbook §5.14.4
pin-tests still owed for the Joomla platform-adapter. Round-4 audit
(A3 P2) promoted this file out of the previously-gitignored
`_internal/audits/` location into the committed `docs/` tree so the
roadmap is reviewable on every PR and stays in sync with what actually
lands in `tests/php/unit/Platform/Joomla/Pin/`. The sibling
`tests/php/unit/Platform/Joomla/Pin/README.md` references this
roadmap as the canonical inventory.

## Status legend

* ✅ Done (test file exists + green in CI)
* 🟡 Partial (test file exists but doesn't pin the full contract)
* ⬜ Pending (no test yet)
* ⏭ Out-of-scope (covered elsewhere)

## Cookbook §5.14.4 gotchas — pin coverage

| Pin | Status | Wave | File | Note |
|---|---|---|---|---|
| 5.14.4 — quote(null) returns `''` on J6 | ✅ | 2 | `QuoteNullReturnsEmptyPinTest.php` | |
| 5.14.4 — getQuery() removed on J6 | ✅ | 2 | `Joomla6ForwardCompatPinTest.php` | |
| 5.14.4 — bearer header mod_rewrite fallback | ✅ | 2 | `BearerHeaderModRewriteFallbackPinTest.php` | |
| 5.14.4 — JCache TTL avoidance | ✅ | 6.5 | `JCacheTtlAvoidancePinTest.php` | |
| 5.14.4 — lock-row atomicity (INSERT IGNORE / ON CONFLICT) | ✅ | 2 | `JoomlaStateLockAtomicityPinTest.php` | |
| 5.14.4 — uninstall ordering / data-protection gate | ✅ | 4 | `UninstallOrderingPinTest.php` + `OptOutPreservesDataPinTest.php` | Round-3 NEW-P1 |
| 5.14.4 — MEDIUMTEXT custom_data overflow guard | ✅ | 2 (R3 add) | `MediumTextOverflowPinTest.php` | Round-3 P1-3 |
| 4.13.3 — JoomlaCacheFlusher API contract (flushL1 / flushL2, no dead ->flush) | ✅ | 6.5 (R4 add) | `JoomlaCacheFlusherContractPinTest.php` | Round-4 F-A1-005 release-blocker |
| 5.14.4 — Web Services route registration mid-bootstrap | ⬜ | 2 | TBD `WebServicesRouteRegistrationPinTest.php` | Pin that onBeforeApiRoute event arg shape is the J5+J6 union |
| 5.14.4 — ApiApplication session-revival strip | ⬜ | 2 | TBD `SessionRevivalStripPinTest.php` | Pin priority-1 listener fires before any other onAfterInitialise hook |
| 5.14.4 — com_scheduler routine dispatch | ⬜ | 4 | TBD `SchedulerRoutineDispatchPinTest.php` | Pin onExecuteTask payload shape across J5/J6 |
| 5.14.4 — encryption-key tier fall-through | ⬜ | 2 | TBD `EncryptionKeyTierFallthroughPinTest.php` | Pin Tier-1 > Tier-2 > Tier-3 > null precedence |
| 5.14.4 — JSON_THROW_ON_ERROR on save-transform | ⬜ | 4 | TBD `SaveTransformJsonErrorPinTest.php` | Pin that malformed encode falls through to unmodified tree |
| 5.14.4 — verify-read mismatch detection | ⬜ | 4 | TBD `VerifyReadMismatchPinTest.php` | Pin EVENT_WRITE_FAILED on byte-mismatch |
| 5.14.4 — JSON encoding flags pinned | ⬜ | 2 | TBD `JsonEncodingFlagsPinTest.php` | Pin UNESCAPED_SLASHES \| UNESCAPED_UNICODE on every encode site |
| 5.14.4 — extension_id cache invalidation | ⬜ | 2 | TBD `ExtensionIdCacheInvalidationPinTest.php` | Pin static cache is reset on YT plugin reinstall |
| 5.14.4 — Web Services API namespace mounting | ⬜ | 2 | TBD `WebServicesNamespacePinTest.php` | Pin that v1/ namespace appears under com_ytbmcp routes only |
| 5.14.4 — PluginHelper::getPlugin null on disabled | ⬜ | 4 | TBD `PluginHelperDisabledPinTest.php` | Pin that uninstall hook copes with null plugin row |

## Assignment / scheduling

* **Wave 5 (current)** — close `WebServicesRouteRegistrationPinTest`,
  `SessionRevivalStripPinTest`, `EncryptionKeyTierFallthroughPinTest`.
* **Wave 6 (cache/perf)** — close `ExtensionIdCacheInvalidationPinTest`,
  `WebServicesNamespacePinTest`.
* **Wave 7 (hardening)** — close the remaining 6 pins.

## Out-of-scope

* Anything not in cookbook §5.14.4 — that document is the authoritative
  list of pins. New gotchas discovered during Joomla-port work must
  first land in the cookbook before they qualify for a pin.

## Maintenance

Update this file in the same PR that lands a new pin test. CI doesn't
check this file (gitignored), but the README.md sibling that IS tracked
should reference back to this roadmap so reviewers know where the full
list lives.
