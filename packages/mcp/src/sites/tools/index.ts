/**
 * W7 — sites/tools/ aggregator.
 *
 * Returns the two L1 platform-agnostic site-management tools:
 *   - `yootheme_builder_sites_list`   (registry inspection — no REST)
 *   - `yootheme_builder_sites_test`   (per-site connectivity probe)
 *
 * Wired into `buildAllTools(pool)` — the aggregator pulls the
 * read-only `SiteRegistry` off the pool internally so callers thread
 * only the pool through, never the registry separately.
 *
 * @license MIT
 */

import type { ClientPool } from '../client-pool.js';
import type { AnyToolDefinition } from '../../tools/tool-builder.js';

import { buildSitesListTool } from './sites-list.js';
import { buildSitesTestTool } from './sites-test.js';

/**
 * Build the W7 sites-management tool pair. The pool's read-only
 * `registry` accessor feeds `buildSitesListTool` (which never resolves
 * a bearer); the pool itself feeds `buildSitesTestTool` (which calls
 * `pool.resolve(site_id)` to obtain a `RestClient`).
 *
 * W12-R1.3 (A1-L-01): single-arg signature — reading the registry off
 * the pool inside this aggregator removes the only call-site that
 * needed the pool's `registry` getter, so the getter can stay
 * `@internal`.
 */
export function buildSitesTools(
    pool: ClientPool,
): readonly AnyToolDefinition[] {
    return [buildSitesListTool(pool.registry), buildSitesTestTool(pool)];
}
