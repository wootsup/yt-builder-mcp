# Changelog

All notable changes to `yt-builder-mcp` (both PHP plugin and NPM package) are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Renamed

- **Plugin slug + display name** — yootheme-builder-mcp → yt-builder-mcp. The full display name is now "YT Builder MCP for YOOtheme Pro (unofficial)" — only used at the top of each surface (plugin header, README hero, About tab, getting-started intro). Body copy uses the short "YT Builder MCP" form. Why: reduces trademark density (~70 references → ~10) and matches the new wootsup/yt-builder-mcp GitHub repo + @wootsup/yt-builder-mcp NPM package.
- **REST namespace** — `yootheme-builder-mcp/v1` → `yt-builder-mcp/v1`. Existing Bearer tokens stay valid (the wp_options key-store prefix `ytb_mcp_*` is unchanged), but customers running the npm wizard need to re-run setup once after upgrading — the wizard re-detects the new namespace automatically.
- **GitHub repo** — wootsup/yootheme-builder-mcp → wootsup/yt-builder-mcp (separate release wave R6).
- **NPM package** — @wootsup/yootheme-builder-mcp → @wootsup/yt-builder-mcp (separate release wave R6, old package will be deprecated with a clear migration message).

### Added

**Wave A — UX Cleanup (2026-05-22)**

- Plugin menu moved from top-level WP sidebar to **Tools → YT Builder MCP**. The plugin is a utility — top-level menu was visual debt.
- "(unofficial)" suffix added at every customer-facing surface: plugin header, version-too-low admin pages, `wp_die` titles, Brand-Header h1, About-tab h2, Diagnostics Markdown header, `readme.txt`, `README.md`.
- New amber **UNOFFICIAL** pill in the Brand-Header with a hover tooltip carrying the trademark disclaimer: *"Independent third-party plugin. Not affiliated with or endorsed by YOOtheme GmbH."*
- Scope-dropdown in the Generate-Key form now has explicit `min-width:160px` + `appearance:auto` overrides — fixes a Chrome rendering bug where the dropdown opened at the bottom of the viewport at oversized width.

### Changed

**Wave B — 3 new MCP-client adapters (2026-05-22)**

- `claude-code` — Anthropic Claude Code CLI, writes `~/.claude.json` (`mcpServers` map, same shape as Claude Desktop).
- `codex-cli` — OpenAI Codex CLI, writes `~/.codex/config.toml` (`[mcp_servers.<name>]` TOML sections; minimal serializer + append-or-replace pattern).
- `gemini-cli` — Google Gemini CLI, writes `~/.gemini/settings.json` (`mcpServers` map).
- `ALL_CLIENTS` catalog now exposes 9 ids (was 6). About-tab pill list expanded to all 9.

**Wave C — Post-Key Pickup-URL UX (2026-05-22)**

- New REST endpoint `POST /v1/setup/pickup` (`PickupController`, no Bearer auth required — the nonce IS the credential):
  - 256-bit `random_bytes(32)` → base64url nonce stored in transient `ytb_mcp_pickup_<nonce>` with 300 s TTL.
  - **IP-bound by default** (`$_SERVER['REMOTE_ADDR']` recorded at generate-time, checked at claim-time).
  - **One-shot** — `delete_transient` runs BEFORE response so replay is structurally impossible.
  - **Rate-limited** 10 attempts / 60 s / sha256(IP)[0:16] — orthogonal to the bearer-keyed `RateLimiter`.
  - Nonce-shape validation (32–64 base64url chars) BEFORE transient lookup — prevents timing-oracle attack on the nonce space.
  - Same response shape (404) for "expired" / "consumed" / "never-existed" — no information leak.
- wp-admin Reveal-Box redesigned to **3 ordered CTAs**:
  1. **"Paste this prompt into your AI assistant"** — pre-built `npx -y @wootsup/yt-builder-mcp setup --pickup <URL> --nonce <CODE> --client <id>` snippet + Copy-as-AI-Prompt button. Token-free — the customer's AI chat history, the LLM provider's logs, and bash history see only an expired-after-claim URL.
  2. **"Or run the wizard manually"** — collapsed `<details>` with the interactive `npx ... setup` command + the site URL + the token as separate copy-snippets.
  3. **Token + Copy** — bottom-of-box, single source of truth, "save now, won't be shown again".
- NPM wizard new flags `--pickup <url>` + `--nonce <code>`:
  - Mutually exclusive with `--url` / `--token` (warning on stderr when both forms passed, no abort).
  - `buildPickupDeps()` synthesizes `WizardAnswers` from the `fetchPickup` response — handshake + writeClient run unchanged.
  - `defaultFetchPickup` HTTP-status mapping: 200 → `PickupResult`, 404 → "expired or claimed (5-minute TTL)", 403 → "bound to a different IP", 429 → "rate limit, wait 60s", 400 → "invalid request" + surfaced server message, network failure → "check URL is reachable".

**Wave D — Multi-plugin release system (2026-05-22)**

- `scripts/release.php` + `scripts/publish.php` + `scripts/cleanup-releases.php` refactored from api-mapper-only to multi-plugin via new `build.<platform>.{plugin_entry,readme_path,zip_base_name,manifest_path,package_path}` config in `server/releases/<product>/product.json`.
- `server/releases/_schema/product.schema.json` extended with optional `build` section + `repository` field.
- New `server/releases/yt-builder-mcp/{product.json,releases.json}` (empty `releases: []` array — ready for first publish).
- 3 new helpers in `scripts/release.php`: `getBuildConfig`, `getReleasesPath`, `bumpNpmVersion`.
- 2 new helpers in `scripts/publish.php`: `publishPaths()`, `publishUrls()` (replacing the now-removed `const PUBLISH_PATHS` / `PUBLISH_URLS`).
- `cmdBuild` platform-gates Joomla build via `in_array('joomla', $productInfo['platforms'], true)` — yt-builder-mcp is WordPress-only by design.
- Backward-compat: api-mapper without a `build` section gets legacy paths via fallback, byte-identical to pre-refactor.
- 23-assertion smoke test `scripts/__tests__/release-multi-product.test.php` covers backward-compat + platform-gating + schema-validate + filename parity.

### Removed

- Removed the redundant **GitHub** CTA from the Brand-Header. The repo link survives in the About-tab "MCP server (NPM)" row + the Brand-Footer "Documentation / Report an issue" links.
- Wave B initially added 5 adapters; **ChatGPT Desktop + VS Code** were dropped before release on operator decision — ChatGPT Desktop's MCP config location is undocumented and VS Code's project-scoped config introduces CWD complexity that the wizard doesn't model yet. May revisit if customer demand surfaces.

### Security

- Pickup endpoint follows defense-in-depth: nonce entropy 256-bit > brute-force-infeasible, IP-binding default-on, one-shot delete-on-read, rate limit per-IP, info-leak-resistant 404 responses. Token never traverses an AI chat history when the pickup CTA is used.

### Internal

- Repo directory renamed `yootheme-builder-mcp-prototype/` → `yootheme-builder-mcp/`. The "-prototype" suffix was a scaffolding leftover; the plugin has graduated (Wave 0–12 shipped, gates green, hardened). Affects internal-only paths in `scripts/sync-to-public.sh` + `packages/mcp/scripts/capture-baseline.mjs`. No public API or package-name impact.
- PHPUnit: **283 tests / 717 assertions** passing (8 new in `PickupControllerTest`, 4 new in `SettingsPageTabsTest`).
- Vitest: **610 / 610** passing (18 new in `pickup.test.ts`).
- PHPStan: 0 errors level 8 (`--memory-limit=1G`).
- TSC: 0 errors strict.

## [0.2.0-alpha.1] — 2026-05-21

**Goldstandard refactor — apimapper-mcp rc.13 W3 parity.**

### Added

**Wave G.0 — Dependencies + Platform Abstraction**

- Bumped `zod` `^3.22.4` → `^4.3.6` (MAJOR; required by `@getimo/mcp-toolkit@^1.1.1` + reference parity); 3 zod 4 root-cause fixes (`z.record(key, value)`, `ZodRawShapeCompat`).
- Added `@modelcontextprotocol/sdk@^1.27.0`, `@getimo/mcp-toolkit@^1.1.1`, `pino@^10.3.1`.
- New `src/platform/index.ts` — `Platform` interface + `WordPressPlatform` impl; seam-marker for Joomla via `PlatformKind = 'wordpress' | 'joomla'`.
- `RestClient` dual-form constructor (legacy `{baseUrl}` + new `{platform}`) — full backward-compat.
- ESLint flat-config enforcing `@typescript-eslint/no-explicit-any: error`.

**Wave G.1 — Gateway-Hub (3-Lane Registration)**

- `src/gateway/{essentials,capturing-server,advanced-tool,test-support}.ts` — verbatim port of apimapper-mcp's gateway pattern.
- 3-lane registration: 7 L1-essentials forwarded by `CapturingServer`, 12 L2-advanced captured, 2 L3 direct (`health`, `diagnose`) registered directly on `McpServer` before wrap. `skip-if-direct` guard prevents SDK duplicate-name errors.
- `yootheme_builder_advanced` gateway tool: discovery-mode (`z.toJSONSchema` per captured tool) + execute-mode (`Map.get` + `Zod.strict().safeParse` + handler).
- **`tools/list` = 10 entries** (Cursor-cap-safe, was 22 pre-refactor). Pin tests `gateway/{essentials,tools-list-size,annotations-pin,annotation-helpers}.test.ts`.

**Wave G.2 — structuredContent + outputSchema**

- 11 read tools migrated `jsonResult` → `tableResult` / `detailResult` / `statsResult` via `@getimo/mcp-toolkit`.
- Per-domain format sidecars: `tools/format/{pages,elements,sources,inspection,health}-format.ts` (each ≤ 200 LoC).
- `ToolDefinition.outputSchema?: z.ZodTypeAny` + `structuredResult()` helper merges toolkit's `_meta.ui` Rich-Card hints with domain-typed `structuredContent`.
- `page_get_schema` forces `'compact'` detail-override (templates routinely exceed 21-row threshold).

**Wave G.3 — Sparse-fields + Layout Flatten**

- `src/tools/sparse-fields.ts` — `FIELDS` schema, `DEFAULT_FIELDS_*` per tool, `projectFields()` + `projectedFieldsEcho()`. 4 list tools opt-in: `pages_list`, `element_list`, `sources_list`, `element_types_list`.
- `src/tools/layout-flatten.ts` — pure depth-first walker, `page_get_layout` `flat: true` parameter for projection-friendly responses.
- Token-Δ measured: **44.71% real baseline reduction**, **43.95% sparse-fields reduction** on `element_list`.

**Wave G.4 — Elicitation (3 sites)**

- `src/tools/elicitation.ts` — `toElicitationCapability` adapter; capability wired on `McpServer` startup.
- 3 elicitation sites: `element_delete` + `element_unbind_source` (destructive-confirm via `elicitConfirmation`) + `element_bind_source` (ambiguity-resolution via `elicitChoice` + local `ambiguityFallbackError`).
- Mandatory non-elicitation fallback via `confirmGuard` preserved for hosts without elicitation capability.
- `tools/elements.ts` (294 LoC) + `tools/sources.ts` (164 LoC) split into `elements/{index,handlers,handlers-write,builders}.ts` + `sources/{index,handlers,handlers-bind,builders}.ts` (each ≤ 200 LoC) ahead of elicitation wiring.

**Wave G.5 — Progress Reports (7 sites)**

- `src/tools/progress-phases.ts` — `PHASE_SEND` / `PHASE_SERVER` / `PHASE_CONFIRM` constants.
- 7 `wp_option`-write tools instrumented: 5 element mutations (`element_add/update_settings/move/clone/delete`) emit 2-phase, 2 page mutations (`page_save/page_publish`) emit honest 3-phase (synthetic intermediate emitted before `await` to surface in-flight progress to MCP host).
- Null-safe: `extra ? createProgressReporter(extra) : null`; `progress?.report(...)` everywhere.

**Wave G.6 — Error-Mapping + Sanitization + Token-Efficiency**

- `src/errors/hints.ts` — `YtbErrorCode` union (9 codes incl. `rate_limit` for WAF/Cloudflare); `classify(status, opts)` + `hintFor(code)` returns AI-actionable recovery.
- `src/errors/mask.ts` — `maskBearerToken` (greedy `Bearer \S+` regex), `sanitizeForLogs` (2000-char trunc).
- `src/errors/sanitize.ts` — `sanitizeSecrets` deep-walk over 32 secret-key names (oauth/bearer/refresh/access/client_secret/api_key/private_key/signing_key/webhook_secret/etc.). Single choke-point.
- Sanitization wired on **all 4 envelope-construction sites**: `jsonResult` + `structuredResult` (success-path, prevents oauth_refresh_token leak through `sources_list`) + `errorResult` + `RestError` constructor (deep-walks `body`).
- `tests/auth/secrets-grep-gate.test.ts` — regex pin against `src/` + `dist/` ensures no `Bearer <token>` / `bearerToken: "..."` literals leak into build output.
- All 21 tool descriptions trimmed to ≤ 250 chars.

**Wave G.7 — Setup-Polish + DXT + SKILL.md**

- `setup-cli.ts` + `setup-wizard.ts` + `setup-wizard-defaults.ts` + `setup-prompts.ts` + `setup-wizard-types.ts` (Round-1.5 / R2 splits keep each ≤ 200 LoC). Wizard rollback on write-fail + dist-tag handshake.
- `install-skill` subcommand — installs SKILL.md to universal-marker path `~/.claude/skills/yootheme-builder/`, idempotent.
- `scripts/build-dxt.js` — 6-step pipeline (build → manifest-validate → stage → zip → verify → grep-gate). DXT bundle 0.20 MB.
- `scripts/extract-tools.mjs` — auto-generates `docs/TOOL-CATALOG.md` + SKILL.md appendix from `buildAllTools()` registry.
- `skills/yootheme-builder/SKILL.md` (577 lines) — 5 hand-written workflows (each ≥ 60 LoC: canonical tool-call sequence + ≥ 3 pitfalls + worked example + success criterion + edge case): build hero section, bind dynamic source to grid, clone & modify template, diagnose 401/auth failure, add custom element type.
- `manifest.json` (DXT v0.1) + `icon.png` placeholder + `LICENSE` (MIT, WootsUp / getimo productions).

**Wave G.8 — Test Volume + Coverage + Token Baseline**

- Test count: 62 → **569 vitest tests** (9.2× scale).
- Coverage: **98.23% lines / 89.38% branches / 94% functions** (gates 85/80/85/85).
- `tests/perf/token-baseline.test.ts` — REAL pre-G.0 baseline captured from worktree (commit `5895bb8b1`), pinned ≥ 40% reduction.
- `tests/perf/sparse-fields-bench.test.ts` — 100-element fixture, pinned ≥ 30% sparse Δ.
- `tests/perf/bind-latency.test.ts` — median ≤ 2× baseline (5ms→10ms).
- `tests/errors/error-scenarios-matrix.test.ts` — 12 read tools × 7 HTTP codes = 84 generated matrix cases (401/403/404/412/429/500/network).

**Wave G.9 — Live-Verify + Real Baseline**

- `scripts/live-verify.mjs` — JSON-RPC stdio harness, spawns local MCP server, exercises all 22 registered tools (10 surface + 12 via gateway). Bearer-token resolved from env or 1Password reference. `YTB_MCP_VERIFY_TEMPLATE_ID` override for real-data testing.
- `scripts/capture-baseline.mjs` — worktree-isolated capture of pre-G.0 `tools/list` byte-size for true Δ measurement (no detached HEAD).
- **22/22 tools LIVE-VERIFIED** against `https://dev.wootsup.com/wordpress` (YOOtheme Pro 4.5.33, plugin v0.1.0-alpha.1).

**Phase 6 — 8-Auditor Goldstandard Audit (4 rounds)**

- 4 audit-rounds executed: R1 (initial), R2 (post-Round-1-Fixer), R3 (post-Round-2-Fixer), R4 (final). Strict 10/10 NO-COMPROMISE gate.
- Initial axes: Goldstandard / Architecture / Security / Tests / Performance / UX-Docs + 2 Bonus (Feature-Verification / Code-Quality) — all 8.
- 3 Fix-Pass-Rounds adressierten 17 findings (2 CRITICAL + 13 IMPORTANT + 2 NIT) — alle strukturell ohne spec-amendments.
- Final score: **10/10 on all 8 axes**.

**Phase 7 — Cline + Roo Code client writers (delivery on 6-client promise)**

- `src/clients/cline.ts` — VS Code globalStorage path detection (darwin / linux / win32) for `saoudrizwan.claude-dev`.
- `src/clients/roo-code.ts` — same pattern für `RooVeterinaryInc.roo-cline`.
- `ALL_CLIENTS` array = 6 writers (was 4); README / SKILL.md / manifest / package.json 6-client matrix now matches code.
- Non-interactive setup flags: `--non-interactive`, `--client <name>` (repeatable), `--url`, `--token`. Documented in README with CI examples.

### Changed

- `zod` MAJOR bump (see Wave G.0). No backward-compat constraint — v0.x is pre-customer per project memory.
- `tools/list` content: 22 → 10 entries via Gateway-Hub. Cursor-cap-safe.
- `tool-builder.ts` (320 LoC) split into `tool-builder/{types,results,annotations,define,index}.ts` (Round-1.5).
- `pages.ts` (358 LoC) split into `pages/{builders,handlers-read,handlers-write,schemas,index}.ts` (R2).
- `advanced-tool.ts` (283 LoC) split into `advanced-tool/{index,domains,discovery,execute,register}.ts` (R2).
- `health` tool migrated from `detailResult` → `statsResult` (spec parity, R1.5).

### Security

- 4-choke-point sanitization: `jsonResult` + `structuredResult` + `errorResult` + `RestError` all run `sanitizeSecrets` before envelope.
- Bearer client-side zod regex check (`/^ytb_(live|test)_[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/`, max 512) — fail-fast before HTTP.
- 7 new security tests (auth.test.ts).
- Grep-gate against `dist/` + `src/`: **0 secret leaks**.

### Verification

- 569 vitest tests grün (run time 3.96s under maxWorkers=2 CI parity)
- 98.23% lines / 89.38% branches / 94% functions coverage
- TSC strict 0 errors / ESLint 0/0
- PHPStan level 8 0 errors (PHP plugin unchanged from Wave 6 Round 2.5)
- Live: 22/22 tools verified against dev.wootsup.com (YT 4.5.33)
- 8/8 Goldstandard axes 10/10 (4 audit rounds)
- npm tarball: 144.1 kB / 283 files / 543.7 kB unpacked

## [0.1.0-alpha.1] — 2026-05-21 (initial public alpha)

### Added

**Wave 0 — Repo Setup**

- Initial repository scaffolding.

**Wave 1 — Plugin Skeleton + core-auth**

- `core-auth` module with `KeyService` (HMAC token gen + verify), `KeyStore` (wp_options wrapper), and `BearerVerifier`.
- `SigningSecret` (lazy 64-byte CSPRNG, persisted in wp_options).
- `core-util` + `core-storage` placeholder bootstraps so the module loader resolves.
- `rest-bridge` module with `HealthController` (public `/health` endpoint).
- `platform-wordpress` Settings page for Bearer-key UI (generate / revoke).
- Plugin entry point `yootheme-builder-mcp.php` with `plugins_loaded@20` guard for YOOtheme Pro.

**Wave 2 — Read Operations**

- `builder-state` module — `LayoutReader` + RFC-6901 `JsonPointer`.
- `builder-pages` module — `PageQuery` + `PagesController` + REST routes (`/pages`, `/pages/{id}/layout`, `/pages/{id}/schema`).
- `builder-elements` module — read-only `TreeWalker` + `ElementOps` + `ElementsController` REST routes.
- `builder-inspection` module — element-type catalog + `InspectionController` REST routes.
- `builder-source-binding` module — `SourceRegistry` + read-only binding REST.
- Bootstrap loader with brace-glob module discovery.
- `HealthController` endpoint introspection — `available_endpoints` field auto-enumerates routes in the plugin namespace.

**Wave 3 — Write Operations**

- `LayoutWriter` — `writeTemplate` / `writeByPointer` / `delete` / `runSaveTransforms`.
- `EtagMiddleware` — optimistic-lock enforcement via `If-Match` header (412 on mismatch, RFC-7232 wildcard support).
- `ElementOps` write methods — `add` / `delete` / `move` / `clone` / `updateSettings`.
- `ElementsController` write endpoints — `POST /elements`, `PUT /elements/{path}/settings`, `DELETE /elements/{path}`, `POST /elements/{path}/move`, `POST /elements/{path}/clone`.
- `builder-cache` module — `CacheFlusher` invalidates YOOtheme caches after writes.
- Source binding write-op — `PUT /binding` + `DELETE /binding`.
- `PagesController` save/publish write-ops — `POST /pages/{id}/save`, `POST /pages/{id}/publish`.

**Wave 4 — NPM Package + MCP Server**

- `@wootsup/yootheme-builder-mcp` NPM package (MIT) under `packages/mcp/`.
- MCP server (`server.ts`) wired up with 21 tools across 5 domains:
  - Health (2): `health`, `diagnose`
  - Pages (6): `pages_list`, `page_get_layout`, `page_get_schema`, `get_etag`, `page_save`, `page_publish`
  - Elements (7): `element_list`, `element_get`, `element_add`, `element_update_settings`, `element_move`, `element_clone`, `element_delete`
  - Sources (4): `sources_list`, `element_get_binding`, `element_bind_source`, `element_unbind_source`
  - Inspection (2): `element_types_list`, `element_type_get_schema`
- Interactive setup wizard (`setup.ts`) — multi-select AI client config writer.
- Client config writers for Claude Desktop, Cursor, Continue, Zed.
- `home.ts` hardening — never writes to a config path that escapes the home dir.
- 62 vitest tests covering tool builders, client config merge semantics, and auth wiring.

**Wave 5 — Docs + E2E Skeleton + WP.org readme**

- Full marketing-grade `README.md` with TL;DR, use-cases, quickstart, tool catalogue, architecture diagram, license, acknowledgments.
- `docs/getting-started.md` — step-by-step end-user install + first prompt.
- `docs/rest-api-reference.md` — every REST endpoint with request/response schemas and curl examples.
- `docs/mcp-tool-reference.md` — all 21 MCP tools with Zod input schemas, output shapes, annotations.
- `src/readme.txt` — WP.org plugin readme.
- `e2e/playwright.config.ts` + `e2e/tutorial-workflow-pexels-grid.spec.ts` — E2E skeleton (Wave 6 implementation, all specs currently `.skip`'d).

**Wave 6 — 6-Axis Audit Hardening**

- Security: split `RestController` (auth required) vs new `PublicRestController` (Health). BearerVerifier is now non-nullable in authenticated controllers (fail-closed).
- Security: scope hierarchy enforced via `bearer_permission_for(read|write|admin)`. GET endpoints require `read`, POST/PUT/DELETE require `write`.
- Security: `KeyStore` `expires_at` is authoritative (BearerVerifier checks both token `exp` claim and keystore-side expiry).
- Security: `SigningSecret` race-fix via `add_option` (atomic create-if-absent) + AES-256-GCM encrypt-at-rest using `AUTH_KEY` (graceful degrade when missing).
- Security: per-kid rate-limiter (60 writes/min/kid) via `RateLimiter` + transients.
- Security: Authorization-header length capped at 8 KiB.
- Security: `exp` claim must be an integer (no silent ignore on stringy values).
- Defense: TOCTOU-close on every write — re-read state immediately before persist; abort 412 on drift.
- Defense: cross-template-pointer rejected with code `yootheme_builder_mcp.elements.cross_template_write_denied`. `JsonPointer::isWithinPrefix` is the choke-point.
- Defense: `update_option` persist-failure raises `RuntimeException`; controllers translate to 500 with code `yootheme_builder_mcp.write_failed`.
- Defense: `json_encode` paths use `JSON_THROW_ON_ERROR` + log + bubble (no silent identity fall-through).
- Defense: `HealthController` no longer discloses `php_version` or full endpoint list to anonymous callers; authenticated callers get the richer payload.
- Defense: `CacheFlusher` scoped to plugin-owned options — replaces `wp_cache_flush()` nuclear blast.
- Defense: `If-Match` is now REQUIRED for `PUT`/`DELETE` writes (428 Precondition Required on missing header).
- Architecture: action-suffix-aware regex on element-path routes (no more fragile suffix-stripping in pointerFromRequest).
- Tests: PHPUnit suite grew 170 → **197 tests** (+ new `RestControllerScopeTest`, `JsonPointer::isWithinPrefix` suite, `SettingsPageTest`, `InspectionControllerTest`, encryption + race-fix coverage on `SigningSecretTest`).
- Docs: stripped Cline/Roo Code/ChatGPT from "supported clients" — Wave 4 ships writers for Claude Desktop / Cursor / Continue / Zed only.
- Docs: removed broken screenshot references; placeholder note pending v0.2.

**Wave 6 Round 2 — Final 10/10 Audit Hardening**

- Architecture: extracted `YoothemeAdapter` (single coupling point — replaces 4 open-coded `class_exists('\YOOtheme\…')` sites in LayoutWriter, SourceRegistry, Inspector, HealthController, CacheFlusher).
- Architecture: `PointerControllerTrait` (shared `pointerFromRequest` + `assertPointerWithinTemplate` between ElementsController and SourcesController).
- Security: `KeyStore` versioned envelope + CAS (re-read-before-persist with up-to-5 retries on version drift, logged via `SecurityLogger::EVENT_KEYSTORE_RACE`).
- Security: `WwwAuthenticateFilter` (RFC-6750 §3) — every 401/403 inside our namespace now carries a `WWW-Authenticate: Bearer realm=…, error=…, error_description=…` header.
- Security: `HealthController` L4 disclosure-tier reduction — anonymous payload is now ONLY `plugin_version` + `status: "ok"`. Every other field (WP/YT version, storage layout, endpoint count, schema_version) requires a valid bearer.
- Defense: `StateLock` — per-template `add_option`-CAS advisory lock (closes the residual TOCTOU window on concurrent same-template writes; stale-lock reclaim after 5s TTL).
- Defense: `SecurityLogger` — single sink for structured security events (`bearer_fail`, `scope_deny`, `rate_limit`, `write_failed`, `cross_template_deny`, `cache_flush_failed`, `keystore_race`, `lock_timeout`). Wired into `RestController::check_bearer`, `RateLimiter::checkWrite`, `LayoutWriter::persist`, `CacheFlusher::flushYoothemeCache`, `PointerControllerTrait::assertPointerWithinTemplate`, `KeyStore::mutateWithCas`, `StateLock::acquireForTemplate`.
- Data: `JsonPointer::MAX_DEPTH = 64` — pointer parser rejects depth > 64 with `InvalidArgumentException`; defense against pathological depth (call-stack / memory).
- Data: `SchemaVersion` stamp module — forward-compatibility marker (`wp_option('ytb_mcp_schema_version')`, set on activation, surfaced via authenticated `/health`).
- Tests: PHPUnit suite grew 197 → **240 tests** (+ `YoothemeAdapterTest`, `SchemaVersionTest`, `SecurityLoggerTest`, `StateLockTest`, `WwwAuthenticateFilterTest`, KeyStore CAS coverage, JsonPointer MAX_DEPTH boundary).
- CI: PHPUnit + Vitest now collect coverage (PCOV for PHP, `@vitest/coverage-v8` for the npm package) and upload reports as artifacts.
- Docs: README Wave-6 status `pending → round-2 complete`, `rest-api-reference.md` clarifies POST-create-element opt-out vs PUT/DELETE/save/publish require-If-Match contradiction.

### Coverage (Wave 6 Round 2)

- **240 PHPUnit tests / 519 assertions** across the plugin modules.
- **62 vitest tests** across the NPM package.
- 0 phpstan errors (level 8), 0 TypeScript errors.

Initial pre-release.
