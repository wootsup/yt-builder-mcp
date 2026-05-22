# Security Policy — YT Builder MCP for YOOtheme Pro (unofficial)

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
- REST endpoints (`/wp-json/yt-builder-mcp/v1/*`)
- Bearer token authentication
- Plugin storage in `wp_options`

Out of scope:
- YOOtheme Pro core (report to YOOtheme directly)
- WordPress core
- Other plugins on the same site

## Bearer Token Security

Bearer tokens issued by YT Builder MCP grant write access to your builder pages. Treat them as you would WordPress Application Passwords:

- Never commit tokens to version control
- Rotate tokens that have been exposed
- Use the WP-Admin Settings page to revoke tokens
- Set sensible expiration when generating

## Supported Versions

I currently support security updates for the latest minor version line. Older versions are best-effort.
