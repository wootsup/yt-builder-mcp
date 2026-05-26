# Joomla release build — `pkg_ytbmcp_v<version>.zip`

Wave 8 of the yt-builder-mcp Joomla port (branch `feat/yt-builder-mcp-joomla`).

This document describes how the Joomla package is built and deployed.
The pipeline lives in the **main repo** at `scripts/release.php` so the
yt-builder-mcp Joomla artefact stays alongside the WordPress release
system rather than reinventing build infrastructure.

> **Status:** Joomla-only opt-in. `server/releases/yt-builder-mcp/product.json`
> still lists `platforms: ["wordpress"]` so `release.php release yt-builder-mcp`
> won't build the Joomla package automatically. Use the explicit
> `build-joomla-ytbmcp` / `deploy-joomla-ytbmcp` commands until the port
> graduates from alpha and the platforms array is extended.

---

## Output

```
dist/joomla/pkg_ytbmcp_v<version>.zip
└── pkg_ytbmcp.xml                  (package manifest, version + sub-extension wiring)
└── script.php                      (package InstallerScript — auto-enables plg_system)
└── extensions/
    ├── plg_system_ytbmcp.zip       (~3-5 MB — system plugin + shared modules + vendor)
    │   ├── ytbmcp.php              (entry stub)
    │   ├── ytbmcp.xml              (manifest)
    │   ├── script.php              (plugin install/uninstall hooks)
    │   ├── services/provider.php   (DI registration)
    │   ├── src/Extension/Ytbmcp.php (main CMSPlugin class)
    │   ├── src/Task/...            (scheduled tasks)
    │   ├── sql/install.*.sql       (synced from platform-joomla/src/Storage/sql/)
    │   ├── sql/uninstall.*.sql
    │   ├── language/en-GB/*.ini
    │   ├── modules/                (shared WootsUp\BuilderMcp\* PSR-4 sources)
    │   │   ├── core-auth/
    │   │   ├── core-storage/
    │   │   ├── platform-joomla/
    │   │   └── ... (12 modules total)
    │   └── vendor/                 (composer no-dev autoloader)
    │       ├── autoload.php
    │       └── composer/autoload_*.php  (paths rewritten src/modules/ → modules/)
    └── com_ytbmcp.zip              (~50-100 KB — admin component + Web Services API)
        ├── ytbmcp.xml
        ├── services/provider.php
        ├── administrator/...       (View/Controller/Extension + tmpl)
        └── api/src/Controller/     (25 Web Services API controllers)
```

The component carries **no** vendor of its own — the system plugin's
composer autoloader is registered globally before com_api dispatches
to controllers (Joomla boots system plugins first). This keeps the
package size minimal.

---

## Commands

All commands run from the main repo root.

### Build only

```bash
php scripts/release.php build-joomla-ytbmcp [version]
```

If `version` is omitted, the current `getProductVersion('yt-builder-mcp')`
is used (read from `yt-builder-mcp/src/yt-builder-mcp.php` plugin header).

**What happens:**

1. `yt-builder-mcp/scripts/build-joomla-package.php` runs — copies the 4
   canonical SQL files into the packaging slot (Audit-A1 F-001).
2. `composer install --no-dev --optimize-autoloader --classmap-authoritative`
   runs inside `yt-builder-mcp/` to produce a production vendor/.
3. `plg_system_ytbmcp.zip` is built (packaging tree + shared modules +
   vendor with autoload paths rewritten `src/modules/...` → `modules/...`).
4. `com_ytbmcp.zip` is built (packaging tree only).
5. Both sub-extension ZIPs + `pkg_ytbmcp.xml` + `script.php` are bundled
   into `dist/joomla/pkg_ytbmcp_v<version>.zip`.
6. Dev dependencies are restored via try/finally so local PHPUnit/PHPStan
   gates keep working.

**Output line on success:**

```
[OK] Joomla package built: pkg_ytbmcp_v1.0.1.zip (XXX.X KB)
[INFO] sha256: <hex>
```

### Build + deploy to dev

```bash
php scripts/release.php deploy-joomla-ytbmcp [version]
```

**What happens:**

1. Runs `build-joomla-ytbmcp` (above).
2. SCPs the ZIP to `deploy@116.203.202.124:/var/www/dev.wootsup.com/joomla/tmp/`.
3. Attempts `ssh deploy@... 'cd /var/www/dev.wootsup.com/joomla && php cli/joomla.php extension:install --path=tmp/pkg_ytbmcp_v<version>.zip'`.
4. Probes `https://dev.wootsup.com/joomla/api/index.php/v1/health`:
   - HTTP 200 → success.
   - HTTP 401/403 → install OK (routing works, just rejecting unauth).
   - Other → warning + manual verification advised.

**Environment overrides** (rarely needed):

```bash
DEV_SSH_HOST="deploy@<other-host>" \
DEV_JOOMLA_ROOT="/var/www/other/joomla" \
DEV_JOOMLA_URL="https://other.example/joomla" \
php scripts/release.php deploy-joomla-ytbmcp 1.0.1
```

### Manual install fallback

If the dev box doesn't have `cli/joomla.php` extension:install (older
Joomla 4.x, or CLI deliberately disabled), the deploy command prints
this fallback:

1. Open `https://dev.wootsup.com/joomla/administrator`
2. **System → Install → Extensions → Upload Package File** → choose
   the ZIP (it's already on the server at
   `/var/www/dev.wootsup.com/joomla/tmp/pkg_ytbmcp_v<version>.zip` — use
   the **Install from Folder** tab with path `/var/www/dev.wootsup.com/joomla/tmp/`
   if "Upload" isn't convenient).
3. Verify in **System → Manage → Extensions** (filter: `yt`):
   - `Package - YT Builder MCP for YOOtheme Pro (unofficial)` (enabled)
   - `Plugin - System - plg_system_ytbmcp` (enabled — auto-enabled by package script)
   - `Component - com_ytbmcp` (enabled)
4. Verify Web Services routes appear in **System → Manage → Web Services API** (Joomla 5+ logs them).
5. Probe health from a terminal:
   ```bash
   curl -i https://dev.wootsup.com/joomla/api/index.php/v1/health
   ```
   Expected: HTTP 401 with `WWW-Authenticate: Bearer realm="ytbmcp"` (the
   plugin is routing requests but rejecting unauthenticated probes).

---

## Pre-flight requirements

- **Composer 2.x** on PATH (`composer --version`). If missing, install
  via `brew install composer` (macOS) or `curl -sS https://getcomposer.org/installer | php` (Linux).
- **PHP 8.2+** on PATH.
- **SSH key** authorised on `deploy@116.203.202.124` (for deploy command).
- **`yt-builder-mcp/composer.json`** present (validated up-front).
- **`yt-builder-mcp/src/packaging/joomla/`** tree intact (validated up-front).

---

## Idempotency + reproducibility

Re-running `build-joomla-ytbmcp` with the same version produces an
equivalent ZIP. The bytes are **not** sha256-stable due to:

- `vendor/composer/installed.json` carrying composer install timestamps.
- ZipArchive's local-file-header timestamps reflecting the build run.

For byte-stable releases we would need a deterministic ZIP builder
(equivalent to the WordPress side's strip-php-mtime + sort-paths pass).
Currently out of scope — the build is reproducible at the **content**
level (same source → same files) which is what matters for install
verification.

---

## Composer autoload reconciliation

`yt-builder-mcp/composer.json` declares PSR-4 paths relative to its
own root:

```json
"autoload": {
  "psr-4": {
    "WootsUp\\BuilderMcp\\Auth\\": "src/modules/core-auth/src/",
    "WootsUp\\BuilderMcp\\Platform\\Joomla\\": "src/modules/platform-joomla/src/",
    ...
  }
}
```

When composer generates `vendor/composer/autoload_psr4.php`, paths
embed `$baseDir . '/src/modules/...'`. But the shipped Joomla plugin
places `modules/` directly under the plugin root (no `src/` wrapper),
so we rewrite these paths to drop the `src/` prefix via
{@see rewriteComposerAutoloadPrefix} — same mechanism the WordPress
src-tree build uses.

At runtime, `src/Extension/Ytbmcp.php` calls
`require_once __DIR__ . '/../../vendor/autoload.php'` which sets
`$baseDir = plugin_root`, and the rewritten paths resolve to
`plugin_root/modules/core-auth/src/`, etc.

---

## Related

- `scripts/release.php :: buildJoomlaPackage_ytbmcp()` — main build function.
- `scripts/release.php :: deployJoomlaToDev()` — deploy + verify function.
- `scripts/release.php :: buildJoomlaPackage()` — api-mapper sibling
  (different slug `pkg_apimapper`, different layout — DO NOT confuse).
- `yt-builder-mcp/scripts/build-joomla-package.php` — SQL sync (Audit-A1 F-001).
- `.claude/commands/release-yt-builder-mcp.md` — release-cut command
  (Step 4b documents the Joomla flow).
- `yt-builder-mcp/src/packaging/joomla/pkg_ytbmcp.xml` — package manifest.
