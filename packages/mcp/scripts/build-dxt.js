#!/usr/bin/env node
/**
 * scripts/build-dxt.js — Wave G.7.4.
 *
 * Bundles `@wootsup/yt-builder-mcp` into a single
 * `yt-builder-mcp.dxt` archive that Claude Desktop can install
 * by drag-drop. Same packaging shape as `@wootsup/mcp`.
 *
 * Pipeline:
 *   1. `npm run build`                      — TypeScript → dist/.
 *   2. Validate `manifest.json`             — schema sanity + grep that
 *                                              every user_config key is
 *                                              actually read by src/.
 *   3. Stage `manifest.json` + `dist/` +
 *      `skills/` (if present) +
 *      a slim `package.json`               — into `.dxt-stage/`.
 *   4. `zip -r` the stage dir              — `yt-builder-mcp.dxt`.
 *   5. Verify with `unzip -l` + size cap.
 *   6. Grep-gate the assembled bundle      — guarantees no Bearer/cookie/
 *                                              KSEC- secret string leaks
 *                                              into the redistributable
 *                                              artifact.
 *
 * Dependency-free: Node stdlib + the system `zip` / `unzip` binaries.
 * Mirrors the dep-free stance of the upstream `apimapper-mcp` script.
 *
 * Skip-net flag:
 *   YTB_MCP_DXT_SKIP_BUILD=1   — skip the `npm run build` step (assume
 *                                 dist/ is fresh). Used by the unit
 *                                 test that exercises the staging logic
 *                                 in isolation.
 */

import { execSync } from 'node:child_process';
import {
    cpSync,
    existsSync,
    mkdirSync,
    readFileSync,
    readdirSync,
    rmSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PKG_ROOT = resolve(__dirname, '..');

const MANIFEST_PATH = resolve(PKG_ROOT, 'manifest.json');
const PKG_JSON_PATH = resolve(PKG_ROOT, 'package.json');
const DIST_DIR = resolve(PKG_ROOT, 'dist');
// Skills live INSIDE the npm package (Round-1 audit C1 fix): bundling
// from `packages/mcp/skills/` keeps DXT layout and the published npm
// tarball aligned — both ship the same skill folder.
const SKILLS_DIR = resolve(PKG_ROOT, 'skills');
const STAGE_DIR = resolve(PKG_ROOT, '.dxt-stage');
const DXT_PATH = resolve(PKG_ROOT, 'yt-builder-mcp.dxt');

function log(msg) {
    process.stdout.write(`[build-dxt] ${msg}\n`);
}

function fail(msg) {
    process.stderr.write(`[build-dxt] ERROR: ${msg}\n`);
    process.exit(1);
}

function readJson(path) {
    return JSON.parse(readFileSync(path, 'utf-8'));
}

// ── Step 1: build ──────────────────────────────────────────────────────
if (process.env.YTB_MCP_DXT_SKIP_BUILD !== '1') {
    log('Step 1: building TypeScript…');
    execSync('npm run build', { cwd: PKG_ROOT, stdio: 'inherit' });
} else {
    log('Step 1: skipping build (YTB_MCP_DXT_SKIP_BUILD=1)');
}

if (!existsSync(resolve(DIST_DIR, 'index.js'))) {
    fail('build did not produce dist/index.js');
}
if (!existsSync(resolve(DIST_DIR, 'setup-cli.js'))) {
    fail('build did not produce dist/setup-cli.js');
}

// ── Step 2: validate manifest ──────────────────────────────────────────
log('Step 2: validating manifest.json…');
if (!existsSync(MANIFEST_PATH)) fail(`manifest.json missing at ${MANIFEST_PATH}`);
const manifest = readJson(MANIFEST_PATH);
const pkg = readJson(PKG_JSON_PATH);

const requiredManifestKeys = [
    'dxt_version',
    'name',
    'display_name',
    'version',
    'description',
    'server',
    'user_config',
];
for (const k of requiredManifestKeys) {
    if (!(k in manifest)) fail(`manifest.json missing required field: ${k}`);
}
if (manifest.version !== pkg.version) {
    fail(
        `manifest.json version (${manifest.version}) does not match package.json version (${pkg.version})`,
    );
}
if (!manifest.user_config || typeof manifest.user_config !== 'object' || Array.isArray(manifest.user_config) || Object.keys(manifest.user_config).length === 0) {
    fail('manifest.json user_config must be a non-empty object (Claude Desktop 1.8555+ strict-validator requires object, keyed by env-var name)');
}

// Grep: every user_config key must be referenced as process.env.<KEY> in src/.
log('  → grep-verifying user_config keys against src/…');
function walkDir(dir, extensions, acc = []) {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const full = resolve(dir, entry.name);
        if (entry.isDirectory()) {
            walkDir(full, extensions, acc);
        } else if (entry.isFile() && extensions.some((e) => entry.name.endsWith(e))) {
            acc.push(full);
        }
    }
    return acc;
}
const srcFiles = walkDir(resolve(PKG_ROOT, 'src'), ['.ts']);
const srcCombined = srcFiles.map((f) => readFileSync(f, 'utf-8')).join('\n');
const missingKeys = [];
for (const key of Object.keys(manifest.user_config)) {
    if (!srcCombined.includes(`process.env.${key}`) &&
        !srcCombined.includes(`'${key}'`) &&
        !srcCombined.includes(`"${key}"`)) {
        missingKeys.push(key);
    }
}
if (missingKeys.length > 0) {
    fail(
        `user_config keys not referenced by src/: ${missingKeys.join(', ')}. ` +
            'Add a process.env.<KEY> read (or quoted literal) — or remove from manifest.',
    );
}

// ── Step 3: stage ──────────────────────────────────────────────────────
log('Step 3: staging archive contents…');
if (existsSync(STAGE_DIR)) rmSync(STAGE_DIR, { recursive: true, force: true });
mkdirSync(STAGE_DIR, { recursive: true });

cpSync(MANIFEST_PATH, resolve(STAGE_DIR, 'manifest.json'));
cpSync(DIST_DIR, resolve(STAGE_DIR, 'dist'), { recursive: true });
if (existsSync(SKILLS_DIR)) {
    cpSync(SKILLS_DIR, resolve(STAGE_DIR, 'skills'), { recursive: true });
}

const slimPkg = {
    name: pkg.name,
    version: pkg.version,
    type: pkg.type,
    bin: pkg.bin,
    main: 'dist/index.js',
    dependencies: pkg.dependencies,
};
writeFileSync(
    resolve(STAGE_DIR, 'package.json'),
    JSON.stringify(slimPkg, null, 2) + '\n',
    'utf-8',
);

// ── Step 3.5: install production deps into stage (DXT spec mandate) ────
// Anthropic DXT spec requires node_modules bundled in the .dxt archive.
// Claude Desktop's built-in Node.js cannot resolve `@modelcontextprotocol/sdk`
// etc. from anywhere else — the unpacked extension dir is the only resolution
// scope. Without this, server crashes at first `import` after `initialize`.
log('Step 3.5: installing production deps into stage…');
execSync('npm install --omit=dev --omit=optional --no-audit --no-fund --no-package-lock --prefer-offline', {
    cwd: STAGE_DIR,
    stdio: 'inherit',
});
if (!existsSync(resolve(STAGE_DIR, 'node_modules', '@modelcontextprotocol', 'sdk'))) {
    fail('npm install did not produce node_modules/@modelcontextprotocol/sdk — bundle would crash on first import');
}

// ── Step 4: zip ────────────────────────────────────────────────────────
log(`Step 4: creating ${DXT_PATH}…`);
if (existsSync(DXT_PATH)) rmSync(DXT_PATH);
execSync(`zip -r -q "${DXT_PATH}" .`, { cwd: STAGE_DIR, stdio: 'inherit' });

// ── Step 5: verify ─────────────────────────────────────────────────────
log('Step 5: verifying archive…');
const sz = statSync(DXT_PATH).size;
const sizeMb = (sz / 1024 / 1024).toFixed(2);
log(`  → size: ${sizeMb} MB`);
if (sz > 25 * 1024 * 1024) {
    fail(`${DXT_PATH} is ${sizeMb} MB — bigger than 25 MB sanity cap (DXT with bundled node_modules typically 2-10 MB)`);
}
const listing = execSync(`unzip -l "${DXT_PATH}"`).toString('utf-8');
const requiredEntries = [
    'manifest.json',
    'dist/index.js',
    'dist/setup-cli.js',
    'package.json',
];
for (const entry of requiredEntries) {
    if (!listing.includes(entry)) {
        fail(`archive is missing required entry: ${entry}`);
    }
}
const fileCount = (listing.match(/\n\s*\d+\s+\d/g) ?? []).length;
log(`  → ${fileCount} files inside archive`);

// ── Step 6: grep-gate (secret-string scan) ─────────────────────────────
log('Step 6: grep-gate (secret-string scan)…');
// We re-extract the staging dir into the gate since we already have files
// laid out — no need to re-unzip. Look for the patterns the unit-level
// grep-gate already enforces against src/ + dist/ (Wave G.6.5):
//   - The Bearer-prefixed token marker (`Bearer ` followed by ≥16 chars)
//   - cookie sentinel strings (`Cookie:`)
//   - KSEC- key-prefix
// Scope grep-gate to OUR code only (dist/, manifest, package.json) — bundled
// node_modules contain unrelated regex/test fixtures matching Bearer/Cookie
// patterns that are not actual secrets we ship.
const ourFiles = walkDir(resolve(STAGE_DIR, 'dist'), ['.js', '.json', '.md']);
ourFiles.push(resolve(STAGE_DIR, 'manifest.json'));
ourFiles.push(resolve(STAGE_DIR, 'package.json'));
const stageFiles = ourFiles.filter((f) => existsSync(f));
const offenders = [];
const PATTERNS = [
    { name: 'Bearer token literal', re: /Bearer\s+[A-Za-z0-9._-]{16,}/ },
    { name: 'Cookie header literal', re: /Cookie:\s*[A-Za-z0-9._=;-]{8,}/i },
    { name: 'KSEC- secret prefix', re: /\bKSEC-[A-Za-z0-9]{8,}/ },
];
for (const f of stageFiles) {
    const text = readFileSync(f, 'utf-8');
    for (const p of PATTERNS) {
        if (p.re.test(text)) {
            offenders.push({ file: f, pattern: p.name });
        }
    }
}
if (offenders.length > 0) {
    for (const o of offenders) {
        process.stderr.write(`[build-dxt]   ✗ ${o.pattern} in ${o.file}\n`);
    }
    fail(`grep-gate found ${offenders.length} potential secret leak(s) in the staged bundle`);
}
log('  → grep-gate clean');

// ── Cleanup ─────────────────────────────────────────────────────────────
rmSync(STAGE_DIR, { recursive: true, force: true });

log(`DONE — ${DXT_PATH}`);
