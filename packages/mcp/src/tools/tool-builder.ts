/**
 * `tool-builder.ts` — backward-compat re-export barrel.
 *
 * The implementation was split into `tool-builder/{types,annotations,
 * results,define}.ts` in Round-1.5 (replaces the Round-1 LoC-exception
 * spec-amendment with a structural code-fix). All existing
 * `./tool-builder.js` imports continue to work via this re-export.
 *
 * For new code, prefer importing from `./tool-builder/index.js` (or
 * the specific submodule) — but the barrel here is the stable
 * public-import path for the package's internal modules.
 *
 * @license MIT
 */

export * from './tool-builder/index.js';
