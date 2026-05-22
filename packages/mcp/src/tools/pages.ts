/**
 * Backward-compat shim for `src/tools/pages.ts`.
 *
 * The original 358-LoC module was split into `src/tools/pages/`
 * (5 files, each ≤ 200 LoC) per Architecture §11 cap (Round-2
 * R2-A2-CRIT1 audit fix). This shim preserves the public import path
 * (`from '../../src/tools/pages.js'`) so all existing test imports
 * and `tools/index.ts` keep working unchanged.
 *
 * @license MIT
 */

export * from './pages/index.js';
