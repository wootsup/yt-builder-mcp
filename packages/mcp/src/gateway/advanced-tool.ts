/**
 * Backward-compat shim for `gateway/advanced-tool.ts`.
 *
 * The original 283-LoC module was split into `gateway/advanced-tool/`
 * (4 files, each ≤ 100 LoC) per Architecture §11 cap (Round-2
 * R2-A2-CRIT2 audit fix). This shim preserves the public import path
 * (`from '../gateway/advanced-tool.js'`) so server.ts, src/index.ts,
 * and the test suite keep working unchanged.
 *
 * @license MIT
 */

export * from './advanced-tool/index.js';
