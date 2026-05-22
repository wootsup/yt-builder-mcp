/**
 * Pin-test for the LICENSE file shipped in the npm tarball.
 *
 * R3-A6-I1: `package.json:files[]` listed `LICENSE` but the file
 * did not exist. This test guards against future drift between
 * the package manifest and the source tree.
 *
 * @license MIT
 */

import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const __dirname = fileURLToPath(new URL('.', import.meta.url));
const PACKAGE_ROOT = join(__dirname, '..', '..');
const LICENSE_PATH = join(PACKAGE_ROOT, 'LICENSE');

describe('LICENSE file', () => {
    it('exists at the package root', () => {
        expect(existsSync(LICENSE_PATH)).toBe(true);
    });

    it('contains the MIT License header', () => {
        const contents = readFileSync(LICENSE_PATH, 'utf-8');
        expect(contents).toContain('MIT License');
    });

    it('mentions the WootsUp / getimo productions copyright holder', () => {
        const contents = readFileSync(LICENSE_PATH, 'utf-8');
        expect(contents).toMatch(/WootsUp|getimo productions/i);
    });

    it('is referenced by package.json:files[]', () => {
        const pkgRaw = readFileSync(join(PACKAGE_ROOT, 'package.json'), 'utf-8');
        const pkg = JSON.parse(pkgRaw) as { files?: string[] };
        expect(pkg.files).toContain('LICENSE');
    });

    it('declares MIT in package.json:license', () => {
        const pkgRaw = readFileSync(join(PACKAGE_ROOT, 'package.json'), 'utf-8');
        const pkg = JSON.parse(pkgRaw) as { license?: string };
        expect(pkg.license).toBe('MIT');
    });
});
