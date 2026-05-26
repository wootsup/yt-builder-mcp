# Platform-Joomla Pin Tests

Pin-tests are **contract guards** for Joomla-specific behaviour that
either:

1. **Reproduce a J5/J6 gotcha** the cookbook §5.14.4 documents and
   yt-builder-mcp source MUST steer around (e.g. quote(null) returning
   `''` on J6, BearerHeader mod_rewrite fallback, getQuery() removal,
   JCache TTL avoidance, lock-row atomicity), or

2. **Pin an audit-driven contract** that a future innocent-looking
   refactor would otherwise quietly break — `UninstallOrderingPinTest`
   and `OptOutPreservesDataPinTest` are the canonical examples
   (Audit-A5 P1-1/P1-2/NEW-P1 data-protection gate).

Tests in this directory follow a stricter style than the rest of the
suite:

* The test class is named `*PinTest.php`.
* Each public test method names the cookbook section it pins via the
  `@cookbook` annotation in its docblock.
* The first paragraph of the class docblock cites the audit-finding
  (if any) that motivated the pin.
* Failures should fail loudly — `assertSame` over `assertEquals`,
  exact-string matches over `contains`, etc.

## Coverage roadmap

The cookbook §5.14.4 pin-tests we still owe (one per documented J5/J6
gotcha) are tracked in `docs/joomla-pin-test-roadmap.md`. Round-4
audit (A3 P2) promoted this roadmap out of the previously-gitignored
`_internal/audits/` location into the committed `docs/` tree so the
inventory is reviewable on every PR and stays in sync with what
actually lands in this directory. The roadmap lists which Wave or
module each remaining pin belongs to so the gap can be closed
incrementally rather than as a single doc-PR.

## When to add a pin

* Cookbook §5.14.4 documents a Joomla 5/6 quirk → mandatory pin.
* Audit finds a missing contract guard → pin in the same fix-round
  branch (Round-N).
* CI catches a regression → pin in the fix-PR so the contract sticks.

Do NOT add a pin for behaviour already covered by a regular unit
test — pins are for invariants that traverse multiple modules or
that have a clear "this used to silently break customer data" smell.
