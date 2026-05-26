# Security Policy. YT Builder MCP for YOOtheme Pro (unofficial)

> Independent third-party project. YOOtheme® is a registered trademark of YOOtheme GmbH
> ([yootheme.com](https://yootheme.com)). YT Builder MCP is built by WootsUp (getimo
> productions) and is not affiliated with, endorsed by, or sponsored by YOOtheme.
> The integration uses YOOtheme Pro's public extension points.

## Reporting a Vulnerability

If you discover a security vulnerability in `yt-builder-mcp`, please report it responsibly.

**Do NOT open a public GitHub issue.** Instead:

- Email: `security@wootsup.com`
- Subject prefix: `[security] yt-builder-mcp`

Include:
- Affected plugin or npm package version
- Steps to reproduce
- Potential impact (data exposure, privilege escalation, etc.)

I aim to acknowledge reports within 48 hours and provide a fix or mitigation within 14 days for high-severity issues.

## Scope

In scope:
- PHP plugin (`src/`)
- NPM package (`packages/mcp/`)
- REST endpoints. WordPress (`/wp-json/yt-builder-mcp/v1/*`) and
  Joomla (`/api/index.php/v1/yt-builder-mcp/*`)
- Bearer token authentication
- Plugin storage in `wp_options` (WordPress) / `#__extensions.custom_data`
  + `#__content.fulltext` (Joomla)

Out of scope:
- YOOtheme Pro core (report to YOOtheme directly)
- WordPress / Joomla core
- Other plugins / extensions on the same site

## Bearer Token Security

Bearer tokens issued by YT Builder MCP grant write access to your builder pages. Treat them as you would WordPress Application Passwords:

- Never commit tokens to version control
- Rotate tokens that have been exposed
- Use the WP-Admin Settings page to revoke tokens
- Set sensible expiration when generating

## Joomla platform notes

The Joomla package (`plg_system_ytbmcp` + `plg_webservices_ytbmcp` + `com_ytbmcp`)
shares the platform-neutral auth core with WordPress but resolves a few primitives
differently:

- **Encryption key (3-tier resolver).** The signing-secret is encrypted at rest with
  an AES-256-GCM key derived (`hash('sha256', 'ytb_mcp_joomla:' . $secret)`) from the
  first tier that resolves:
  1. PHP constant `YTB_MCP_ENCRYPTION_KEY` (define it in `configuration.php` for the
     strongest, file-managed key);
  2. a key-file stored **one level above `JPATH_ROOT`** (outside the web root);
  3. a last-resort auto-generated `media/ytb_mcp_secure/.encryption_key`, a
     deliberately **non-manifest-owned** path so a package uninstall does not
     orphan a still-preserved encrypted signing-secret. Existing alpha installs
     keyed at the legacy `media/com_ytbmcp/.encryption_key` are migrated
     verbatim on first resolve (no token-break).
  The third tier is web-accessible by path, so it is hardened with denying
  `.htaccess` (Apache 2.2 + 2.4), `web.config` (IIS request-filtering + deny-all
  authorization) and `index.html` files, and written with restrictive
  permissions. Prefer tier 1 or 2 in production. The multi-server hardening
  has a known gap on nginx (config lives outside the document root and cannot
  be augmented from a writable directory); a one-time admin warning is enqueued
  the first time the tier-3 fallback is auto-generated. The resolver
  intentionally does **not** key off `configuration.php $secret`, because
  Joomla recovery tooling can regenerate it and silently orphan every
  encrypted secret.

- **Bearer is the sole authority on the API surface.** The REST routes run in
  Joomla's `com_api` application, where `Factory::getUser()->authorise('core.edit', …)`
  is always false (no logged-in identity). The L2 article-write `core.edit` gate is
  intentionally absent. All API access (L1 type templates and L2 per-article layouts
  alike) is governed entirely by the Bearer token's scope hierarchy
  (`read` < `write` < `admin`), ETag optimistic-locking, and rate-limiting. Joomla
  ACL governs only the **admin component** (`com_ytbmcp` is gated by
  `core.admin`/`core.manage` via `access.xml`), never the token-authenticated API.

- **Treat Bearer tokens like Joomla API tokens.** Never commit them, rotate exposed
  ones, and revoke from **Components → YT Builder MCP → Bearer Keys**.

## Supported Versions

I currently support security updates for the latest minor version line. Older versions are best-effort.
