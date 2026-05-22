/**
 * Wave G.5 + Round-1.5 — shared progress labels for write handlers.
 *
 * Two contracts coexist:
 *
 * ─── 2-phase (element_* mutations) ───────────────────────────────────
 *
 *   0/2  PHASE_SEND     — before the REST call,
 *   2/2  PHASE_CONFIRM  — after a 2xx response.
 *
 * Used by the five element_* write tools (add / update_settings / move /
 * clone / delete). Each is a single shallow `wp_option('yootheme')` write
 * — the request completes in tens of milliseconds and there is no
 * client-observable window between dispatch and confirmation that would
 * benefit from a synthetic intermediate event.
 *
 * ─── 3-phase (page_save / page_publish) ──────────────────────────────
 *
 *   0/3  PHASE_SEND     — before the REST call,
 *   1/3  PHASE_SERVER   — synthetic intermediate, emitted AFTER dispatch
 *                         starts but BEFORE the await resolves; lets the
 *                         MCP-client UI render a mid-flight indicator
 *                         matching the reference UX intent without
 *                         requiring an SSE-streaming REST endpoint.
 *   2/3  PHASE_CONFIRM  — after a 2xx response.
 *
 * Reserved for the two page-level mutations (`page_save` /
 * `page_publish`). Both trigger a save-transform pass + a full layout
 * re-serialisation on the PHP side; on large templates this can take
 * 500-3000 ms — well over the perceptual threshold where a mid-flight
 * progress signal materially improves the operator experience.
 *
 * Round-1.5 revert: an earlier Round-1 audit amendment collapsed the
 * page_save / page_publish handlers down to 2-phase on the grounds that
 * the synthetic intermediate was "theatre". Thomas-mandate 2026-05-22
 * reinstates the 3-phase contract: the intermediate is honest if it
 * marks an observable boundary (dispatch-start → await-resolved is one
 * such boundary even without server-side streaming, because the
 * dispatch itself is non-blocking from the host UI's perspective).
 *
 * @license MIT
 */

// ── 2-phase (element_*) ─────────────────────────────────────────────
export const PHASE_SEND = 'Sending write request';
export const PHASE_CONFIRM = 'Confirmed by server';

// ── 3-phase intermediate (page_save / page_publish only) ────────────
export const PHASE_SERVER = 'Server processing';
