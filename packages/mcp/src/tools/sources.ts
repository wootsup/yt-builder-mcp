/**
 * Backwards-compatible re-export shim.
 *
 * Wave G.4.0b split the original 228-LoC `sources.ts` into
 * `./sources/{index, handlers, builders}.ts` after Wave G.4.2/G.4.3
 * added elicitation + ambiguity-resolution logic that pushed it well
 * over the 200-LoC budget. This shim keeps the legacy import path
 * (`from '../tools/sources.js'`) working for downstream callers and
 * tests. New code should import from `./sources/index.js` directly.
 *
 * @license MIT
 */

export { buildSourcesTools } from './sources/index.js';
