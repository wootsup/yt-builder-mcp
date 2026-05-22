/**
 * Backwards-compatible re-export shim.
 *
 * Wave G.4.0 split the original 382-LoC `elements.ts` into
 * `./elements/{index, handlers, builders}.ts`. This shim keeps the
 * legacy import path (`from '../tools/elements.js'`) working for
 * downstream callers and tests. New code should import from
 * `./elements/index.js` directly.
 *
 * @license MIT
 */

export { buildElementsTools } from './elements/index.js';
