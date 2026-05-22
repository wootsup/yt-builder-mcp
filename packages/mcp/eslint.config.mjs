/**
 * ESLint flat config for @wootsup/yt-builder-mcp.
 *
 * Enforces the project-wide React/TypeScript standards on Server-side
 * TypeScript (no React rules — this is a pure Node MCP server):
 *
 *   - no `any` (use `unknown` + type guards / generics / concrete types).
 *     Documented escape-hatches are allowed via
 *     `// eslint-disable-next-line @typescript-eslint/no-explicit-any -- <reason>`.
 *   - no unused vars (allow `_`-prefixed for intentional ignores).
 *
 * Runs against `src/`. Tests are excluded by the npm script via the
 * `src` argument; type-only files (`*.d.ts`) under `dist/` are ignored.
 *
 * @license MIT
 */

import tseslint from 'typescript-eslint';

export default tseslint.config(
    {
        ignores: ['dist/**', 'node_modules/**', 'bin/**'],
    },
    ...tseslint.configs.recommended,
    {
        files: ['src/**/*.ts'],
        rules: {
            '@typescript-eslint/no-explicit-any': 'error',
            '@typescript-eslint/no-unused-vars': [
                'error',
                {
                    argsIgnorePattern: '^_',
                    varsIgnorePattern: '^_',
                    caughtErrorsIgnorePattern: '^_',
                },
            ],
        },
    },
);
