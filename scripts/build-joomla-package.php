<?php
/**
 * build-joomla-package.php — populate plg_system_ytbmcp/sql/ at build-time.
 *
 * Closes Audit-A1 F-001 (SQL duplication). The canonical SQL files live
 * in the platform-joomla module so the Reader/Writer/Lock implementations
 * and the manifest see the same schema. This script copies them into the
 * Joomla packaging slot immediately before the release ZIP is built.
 *
 * SCOPE OF THIS SCRIPT (intentionally narrow):
 *   - Copy 4 SQL files (install/uninstall × mysql/postgresql) from
 *     `yt-builder-mcp/src/modules/platform-joomla/src/Storage/sql/` into
 *     `yt-builder-mcp/src/packaging/joomla/extensions/plg_system_ytbmcp/sql/`.
 *   - sha256-verify each copy is byte-identical.
 *
 * NOT IN SCOPE (the full ZIP build):
 *   The complete `pkg_ytbmcp_v{version}.zip` packaging — composer install,
 *   sub-extension ZIPs, package manifest bundling — lives in the main-repo
 *   release system at `scripts/release.php`. This script is invoked AS the
 *   first step of that pipeline so the SQL is fresh before the plugin ZIP
 *   is sealed.
 *
 * Full-build entry points (canonical):
 *   php scripts/release.php build-joomla-ytbmcp [version]    # build only
 *   php scripts/release.php deploy-joomla-ytbmcp [version]   # build + dev deploy
 *   php scripts/release.php build yt-builder-mcp [version]   # build all platforms
 *
 * Direct invocation (SQL sync only — useful for local manifest verification):
 *
 *     php yt-builder-mcp/scripts/build-joomla-package.php
 *
 * Exit codes:
 *   0  All four SQL files copied byte-identical.
 *   1  Source-of-truth missing or unreadable.
 *   2  Destination directory missing or not writable.
 *   3  Copy verification failed (sha256 mismatch).
 *
 * Idempotent: re-running over an already-populated destination overwrites
 * with the current canonical content. Safe to invoke from CI.
 *
 * @package    WootsUp\BuilderMcp\Build
 * @license    GPL-2.0-or-later
 * @copyright  (C) 2026 getimo productions
 *
 * @see        scripts/release.php :: buildJoomlaPackage_ytbmcp()
 * @see        scripts/release.php :: deployJoomlaToDev()
 * @see        yt-builder-mcp/docs/joomla-release-build.md
 */

declare(strict_types=1);

const FILES = [
    'install.mysql.sql',
    'install.postgresql.sql',
    'uninstall.mysql.sql',
    'uninstall.postgresql.sql',
];

$repoRoot = \dirname(__DIR__, 2); // /Users/.../wootsup-joomla-port
$ytbRoot  = $repoRoot . '/yt-builder-mcp';
$srcDir   = $ytbRoot . '/src/modules/platform-joomla/src/Storage/sql';
$dstDir   = $ytbRoot . '/src/packaging/joomla/extensions/plg_system_ytbmcp/sql';

if (!\is_dir($srcDir) || !\is_readable($srcDir)) {
    \fwrite(\STDERR, "[build-joomla-package] FATAL: canonical SQL dir missing or unreadable: {$srcDir}\n");
    exit(1);
}
if (!\is_dir($dstDir)) {
    if (!\mkdir($dstDir, 0o755, true) && !\is_dir($dstDir)) {
        \fwrite(\STDERR, "[build-joomla-package] FATAL: cannot create destination: {$dstDir}\n");
        exit(2);
    }
}
if (!\is_writable($dstDir)) {
    \fwrite(\STDERR, "[build-joomla-package] FATAL: destination not writable: {$dstDir}\n");
    exit(2);
}

$failed = 0;
foreach (FILES as $name) {
    $src = $srcDir . '/' . $name;
    $dst = $dstDir . '/' . $name;
    if (!\is_file($src) || !\is_readable($src)) {
        \fwrite(\STDERR, "[build-joomla-package] FATAL: missing source: {$src}\n");
        $failed++;
        continue;
    }
    if (\copy($src, $dst) === false) {
        \fwrite(\STDERR, "[build-joomla-package] FATAL: copy failed for {$name}\n");
        $failed++;
        continue;
    }
    // Verify byte-identical via sha256 hashes.
    $srcHash = \hash_file('sha256', $src);
    $dstHash = \hash_file('sha256', $dst);
    if ($srcHash !== $dstHash) {
        \fwrite(\STDERR, "[build-joomla-package] FATAL: sha256 mismatch for {$name} (src={$srcHash} dst={$dstHash})\n");
        $failed++;
        continue;
    }
    \fwrite(\STDOUT, "[build-joomla-package] OK  {$name}  sha256={$srcHash}\n");
}

if ($failed > 0) {
    \fwrite(\STDERR, "[build-joomla-package] FAILED — {$failed} file(s) did not copy cleanly.\n");
    exit(3);
}

\fwrite(\STDOUT, "[build-joomla-package] All " . \count(FILES) . " SQL files populated in packaging slot.\n");
exit(0);
