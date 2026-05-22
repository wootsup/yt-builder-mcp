/**
 * Grep-gate — Wave G.6.5.
 *
 * Walks the package source tree (and `dist/` when present after `npm
 * run build`) and fails if any file contains:
 *   - A literal `Bearer <opaque-token>` substring outside of test or
 *     documentation comments.
 *   - A string-literal `bearerToken = "…"` / `bearerToken: "…"` shape
 *     suggesting a hard-coded credential.
 *
 * This is the LAST line of defense — if the static check finds nothing,
 * the runtime sanitisers (mask + sanitizeSecrets) are the only thing
 * standing between a regression and an LLM-visible leak.
 *
 * @license MIT
 */

import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, test } from 'vitest';

/** Recursively collect every file under `dir` matching `extensions`. */
function walk(dir: string, extensions: readonly string[]): string[] {
    if (!existsSync(dir)) return [];
    const out: string[] = [];
    for (const entry of readdirSync(dir)) {
        const full = join(dir, entry);
        let stat;
        try {
            stat = statSync(full);
        } catch {
            continue;
        }
        if (stat.isDirectory()) {
            if (entry === 'node_modules' || entry === '.git') continue;
            out.push(...walk(full, extensions));
        } else if (extensions.some((ext) => full.endsWith(ext))) {
            out.push(full);
        }
    }
    return out;
}

// Anything that legitimately mentions the Bearer / token shape:
//   - mask.ts itself (the masker)
//   - tests/ (which assert against the literal string)
//   - declarative docs in comments (we don't ship inside comments to LLM
//     but exclude here for clarity)
const ALLOWED_PATH_FRAGMENTS = [
    '/src/errors/mask.ts',
    '/src/errors/hints.ts',
    '/src/errors/sanitize.ts',
    '/src/auth.ts', // declares the env-var name only
    '/tests/',
];

/**
 * Return a list of `{ file, line, snippet }` for any source line that
 * matches the secret-leakage regex outside the allow-list paths.
 */
function findLeaks(files: readonly string[]): Array<{
    file: string;
    line: number;
    snippet: string;
    rule: string;
}> {
    // Bearer <opaque-token> — match credential-shaped sequences (must
    // include at least one digit OR an underscore-segment, OR exceed 12
    // chars, OR look like base64/jwt — anything that resembles an
    // opaque secret rather than the english word "key" / "token" used
    // in documentation prose ("Bearer key", "Bearer token in Settings").
    //
    // Two-pass logic: capture `Bearer <token>` then reject the match if
    // <token> is an english documentation word.
    const BEARER_REGEX = /Bearer ([A-Za-z0-9._-]+)/g;
    const DOC_WORDS = new Set([
        'key',
        'keys',
        'token',
        'tokens',
        'credential',
        'credentials',
        'probe',
        'group',
        'value',
        'Bonds',
        'Auth',
    ]);
    // bearerToken: "…" or bearerToken = "…" with a non-empty literal.
    const HARDCODED_REGEX = /bearerToken\s*[:=]\s*["'][^"']+["']/g;
    const leaks: Array<{ file: string; line: number; snippet: string; rule: string }> = [];
    for (const file of files) {
        if (ALLOWED_PATH_FRAGMENTS.some((frag) => file.includes(frag))) continue;
        const content = readFileSync(file, 'utf8');
        const lines = content.split('\n');
        for (let i = 0; i < lines.length; i++) {
            const line = lines[i] as string;
            // Bearer pass: iterate every match and skip documentation prose.
            let m: RegExpExecArray | null;
            BEARER_REGEX.lastIndex = 0;
            while ((m = BEARER_REGEX.exec(line)) !== null) {
                // Strip trailing sentence punctuation so "Bearer key."
                // is recognised as the english word "key", not as
                // `key.` (which would otherwise match looksLikeCredential
                // via the period).
                const token = (m[1] ?? '').replace(/[.,;:!?]+$/, '');
                if (DOC_WORDS.has(token)) continue;
                // Heuristic: documentation words tend to be a single short
                // English word with no digits / underscores / hyphens.
                // Credential shapes virtually always contain at least one
                // of those.
                const looksLikeCredential =
                    /[0-9_-]/.test(token) || token.length > 12;
                if (!looksLikeCredential) continue;
                leaks.push({ file, line: i + 1, snippet: line.trim(), rule: 'bearer-token' });
            }
            BEARER_REGEX.lastIndex = 0;
            if (HARDCODED_REGEX.test(line)) {
                leaks.push({ file, line: i + 1, snippet: line.trim(), rule: 'hardcoded-credential' });
            }
            HARDCODED_REGEX.lastIndex = 0;
        }
    }
    return leaks;
}

describe('Grep-gate — no Bearer tokens or string-literal credentials in source', () => {
    test('src/ tree is clean', () => {
        const files = walk('src', ['.ts', '.js']);
        expect(files.length).toBeGreaterThan(0);
        const leaks = findLeaks(files);
        if (leaks.length > 0) {
            // eslint-disable-next-line no-console -- diagnostic on failure
            console.error('Secret-leak grep-gate found:', leaks);
        }
        expect(leaks).toEqual([]);
    });

    test('dist/ tree is clean (if built)', () => {
        const files = walk('dist', ['.js']);
        if (files.length === 0) {
            // Build not present — skip with a note in the test name only.
            // We don't want to fail CI on the "build wasn't run" branch.
            return;
        }
        const leaks = findLeaks(files);
        if (leaks.length > 0) {
            // eslint-disable-next-line no-console -- diagnostic on failure
            console.error('Secret-leak grep-gate (dist) found:', leaks);
        }
        expect(leaks).toEqual([]);
    });
});
