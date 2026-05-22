#!/usr/bin/env bash
#
# sync-to-public.sh — Reproducible release sync from api-mapper dev-monorepo
# to the standalone public repo wootsup/yootheme-builder-mcp + NPM.
#
# Usage:  ./scripts/sync-to-public.sh <version>
# Example: ./scripts/sync-to-public.sh v0.2.0-alpha.1
#
# Pre-flight: must be on feat/yootheme-builder-mcp, clean tree, versions
# match, tests + tsc green. Pre-flight fail-fast prevents partial syncs.
#
# Per CLAUDE.md hard rule: NEVER rsync. Uses tar c | tar x instead.
#
# Outputs are documented at the end (NPM publish + GitHub release are
# manual follow-ups because npm publish needs 2FA OTP from the operator).
#
set -euo pipefail

VERSION="${1:?Usage: $0 <version>  e.g. v0.2.0-alpha.1}"
VERSION_NO_V="${VERSION#v}"

DEV_REPO="$HOME/Projekte/wootsup-yootheme-builder-mcp/yootheme-builder-mcp"
TARGET="$HOME/Projekte/wootsup-yootheme-builder-mcp-public"
REMOTE="https://github.com/wootsup/yootheme-builder-mcp.git"

echo "==> Sync $VERSION from dev-monorepo to public repo"
echo "    Dev:    $DEV_REPO"
echo "    Target: $TARGET"
echo ""

# ─── 1. Pre-flight ────────────────────────────────────────────────────────
cd "$DEV_REPO"

current_branch="$(git branch --show-current)"
if [ "$current_branch" != "feat/yootheme-builder-mcp" ]; then
    echo "ERROR: dev-repo branch is '$current_branch', expected 'feat/yootheme-builder-mcp'" >&2
    exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
    echo "ERROR: dev-repo working tree is dirty. Commit or stash first." >&2
    git status --short >&2
    exit 1
fi

if ! grep -q "\"version\": \"$VERSION_NO_V\"" packages/mcp/package.json; then
    echo "ERROR: packages/mcp/package.json version != $VERSION_NO_V" >&2
    grep '"version"' packages/mcp/package.json >&2
    exit 1
fi

if ! grep -q "Version: $VERSION_NO_V" src/yootheme-builder-mcp.php; then
    echo "ERROR: src/yootheme-builder-mcp.php plugin-header Version != $VERSION_NO_V" >&2
    grep "Version:" src/yootheme-builder-mcp.php >&2
    exit 1
fi

echo "==> Running vitest + tsc gate (silent unless fail)"
(cd packages/mcp && npx vitest run --reporter=basic > /tmp/sync-vitest.log 2>&1) || {
    echo "ERROR: vitest failed. See /tmp/sync-vitest.log" >&2
    tail -30 /tmp/sync-vitest.log >&2
    exit 1
}
(cd packages/mcp && npx tsc --noEmit > /tmp/sync-tsc.log 2>&1) || {
    echo "ERROR: tsc failed. See /tmp/sync-tsc.log" >&2
    tail -30 /tmp/sync-tsc.log >&2
    exit 1
}
echo "    ✓ vitest green, tsc clean"

# ─── 2. Clone or update public mirror ─────────────────────────────────────
if [ ! -d "$TARGET/.git" ]; then
    echo "==> Cloning $REMOTE → $TARGET"
    git clone "$REMOTE" "$TARGET"
else
    echo "==> Updating existing public mirror"
fi

cd "$TARGET"
if [ -n "$(git status --porcelain)" ]; then
    echo "ERROR: public mirror at $TARGET is dirty. Commit/discard first." >&2
    git status --short >&2
    exit 1
fi
git fetch origin
git checkout main
git pull --ff-only origin main

# Guard against re-syncing same version
if git tag -l "$VERSION" | grep -q "^$VERSION$"; then
    echo "ERROR: tag $VERSION already exists in public repo. Bump version first." >&2
    exit 1
fi

# ─── 3. Wipe target (except .git) ─────────────────────────────────────────
echo "==> Wiping target (except .git) for clean-slate copy"
find . -mindepth 1 -maxdepth 1 ! -name '.git' -exec rm -rf {} +

# ─── 4. Copy filtered tree via tar (NOT rsync — CLAUDE.md hard rule) ──────
echo "==> Copying filtered tree via tar"
cd "$DEV_REPO"
tar c \
    --exclude='./.git' \
    --exclude='./node_modules' \
    --exclude='./packages/*/node_modules' \
    --exclude='./vendor' \
    --exclude='./packages/*/vendor' \
    --exclude='./packages/*/dist' \
    --exclude='./_internal' \
    --exclude='./.claude' \
    --exclude='./.phpunit.cache' \
    --exclude='./.dxt-stage' \
    --exclude='*.dxt' \
    --exclude='*.tgz' \
    --exclude='./scripts/sync-to-public.sh' \
    --exclude='.DS_Store' \
    . | (cd "$TARGET" && tar x)

# ─── 5. Commit + tag + push ───────────────────────────────────────────────
cd "$TARGET"
git add -A

if git diff --cached --quiet; then
    echo "==> No changes to commit (already in sync). Nothing to push."
    exit 0
fi

echo "==> Changes (top 30):"
git status --short | head -30

git commit -m "Release $VERSION — Goldstandard refactor (8/8 axes 10/10)

Synced from wootsup/api-mapper@feat/yootheme-builder-mcp.

Highlights (full CHANGELOG.md inside):

- Gateway-Hub: tools/list = 10 (Cursor-cap-safe; was 22 pre-refactor)
- structuredContent for 11 read tools (toolkit-driven sidecar formatters)
- Sparse-fields opt-in for 4 list tools (~44% byte-Δ on element_list)
- page_get_layout flat:true client-side flattening
- Elicitation on 3 sites (delete + unbind + bind ambiguity)
- 3-phase Progress for page_save / page_publish
- Sanitization choke-point on all 4 envelope sites
- Setup-Polish: DXT bundle + SKILL.md (5 workflows ≥60 LoC) + 6-client matrix
- Cline + Roo Code client writers (ALL_CLIENTS = 6)
- 569 vitest tests; 98.23% lines / 89.38% branches coverage
- TSC strict 0; ESLint 0/0; PHPStan level 8 0
- 22/22 LIVE-VERIFIED auf dev.wootsup.com (YT Pro 4.5.33)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
"
git tag -a "$VERSION" -m "$VERSION"
git push origin main "$VERSION"

# ─── 6. Post-flight ───────────────────────────────────────────────────────
echo ""
echo "✓ Synced + pushed $VERSION to wootsup/yootheme-builder-mcp"
echo "    Target: $TARGET"
echo "    Commit: $(git rev-parse --short HEAD)"
echo "    Tag:    $VERSION"
echo ""
echo "Next steps (manual — npm publish needs 2FA OTP):"
echo "  1. cd '$TARGET/packages/mcp' && npm publish --tag alpha"
echo "  2. (cd '$TARGET' && gh release create '$VERSION' \\"
echo "        --notes-file CHANGELOG.md --prerelease \\"
echo "        --title '$VERSION — Goldstandard'"
echo "     )"
echo "  3. (Optional) Plugin re-deploy to dev.wootsup.com so setup-wizard"
echo "     dist-tag handshake parity holds."
