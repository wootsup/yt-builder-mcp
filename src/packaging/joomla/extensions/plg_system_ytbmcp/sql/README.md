# SQL — packaging slot (populated at build-time)

This directory is intentionally **empty** in source control.

The four files Joomla's `<install>` / `<uninstall>` manifest references —

- `install.mysql.sql`
- `install.postgresql.sql`
- `uninstall.mysql.sql`
- `uninstall.postgresql.sql`

— live canonically in:

```
yt-builder-mcp/src/modules/platform-joomla/src/Storage/sql/
```

The release-build copies them into this directory immediately before the
ZIP is produced. Editing either copy independently violates A1 (single
source of truth) and will be rejected by the build verifier.

## Build-time population

A minimal stub that performs the copy lives at:

```
yt-builder-mcp/scripts/build-joomla-package.php
```

Invocation (manual, until Wave 8's release-system integrates the call
into `/release-yt-builder-mcp`):

```sh
php yt-builder-mcp/scripts/build-joomla-package.php
```

Exit code `0` → all four files copied byte-identical from the canonical
location. Exit code `> 0` → source is missing or unreadable; build must
abort.

## Why a build-step copy (rather than a symlink or manifest-relative path)

1. **Joomla manifests resolve `<file>` paths relative to the extension
   root.** Joomla's installer does not follow symlinks outside the
   `<files>` tree.
2. **ZIP archives flatten symlinks** to their targets by default — and
   different ZIP toolchains (PHP `ZipArchive`, `zip(1)`, GitHub's
   archive endpoint) disagree on whether to follow or rewrite them.
3. **The packaging tree is the contract Joomla sees.** Build-time copy
   keeps the manifest schema verbatim ("`<file>sql/install.mysql.sql`")
   while the source-of-truth stays in the platform module where the
   reader/writer that depend on those tables live.

## Audit trail (F-A1-001, Wave 4 fix-round F3)

Before this round, the four SQL files existed twice in the repo
(byte-identical) — once in the platform module, once here. A schema
change therefore required two diffs and could silently drift. F3
removed the packaging copies; the platform-module location is now
canonical for both runtime tests and the build pipeline.
