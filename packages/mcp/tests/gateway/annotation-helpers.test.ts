/**
 * Annotation-helper composition pin tests.
 *
 * `readOnly` / `mutating` / `creating` / `destructive` are the only way
 * tool builders express MCP behavioural hints. The output shape directly
 * controls LLM-host UI surface (read-only badge, confirmation prompt,
 * idempotency banner, etc.). These pins prevent silent regressions in
 * those helpers when refactoring.
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import {
    creating,
    destructive,
    mutating,
    readOnly,
} from '../../src/tools/tool-builder.js';

describe('annotation helpers — shape pins (D3 T3 — all 4 hints explicit)', () => {
    // T3 (Audit-v3 / Anthropic MCP spec 2026-03-16):
    // every registered tool MUST advertise ALL four behavioural hints
    // (readOnly, destructive, idempotent, openWorld). Conservative
    // spec-default treats absence as "potentially destructive" — so
    // each helper now sets the full 4-tuple. `openWorldHint:false`
    // reflects the closed Builder domain (REST is a local WordPress
    // option store, no external world-side-effects).
    it('readOnly("X") sets all 4 hints explicitly (closed-world)', () => {
        const ann = readOnly('X');
        expect(ann).toEqual({
            title: 'X',
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        });
    });

    it('mutating("X") sets all 4 hints (idempotent write, non-destructive)', () => {
        const ann = mutating('X');
        expect(ann).toEqual({
            title: 'X',
            readOnlyHint: false,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        });
    });

    it('creating("X") sets all 4 hints (non-idempotent additive write)', () => {
        const ann = creating('X');
        expect(ann).toEqual({
            title: 'X',
            readOnlyHint: false,
            destructiveHint: false,
            idempotentHint: false,
            openWorldHint: false,
        });
    });

    it('destructive("X") sets all 4 hints (destructive, non-idempotent)', () => {
        const ann = destructive('X');
        expect(ann).toEqual({
            title: 'X',
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
            openWorldHint: false,
        });
    });

    it('all four helpers accept an optional title (undefined when omitted)', () => {
        expect(readOnly().title).toBeUndefined();
        expect(mutating().title).toBeUndefined();
        expect(creating().title).toBeUndefined();
        expect(destructive().title).toBeUndefined();
    });

    it('lane disjointness: destructive is the only helper that sets destructiveHint=true', () => {
        expect(readOnly().destructiveHint).toBe(false);
        expect(mutating().destructiveHint).toBe(false);
        expect(creating().destructiveHint).toBe(false);
        expect(destructive().destructiveHint).toBe(true);
    });

    it('lane disjointness: readOnly is the only helper that sets readOnlyHint=true', () => {
        expect(readOnly().readOnlyHint).toBe(true);
        expect(mutating().readOnlyHint).toBe(false);
        expect(creating().readOnlyHint).toBe(false);
        expect(destructive().readOnlyHint).toBe(false);
    });

    it('lane disjointness: readOnly+mutating set idempotentHint=true; creating+destructive set false', () => {
        expect(readOnly().idempotentHint).toBe(true);
        expect(mutating().idempotentHint).toBe(true);
        expect(creating().idempotentHint).toBe(false);
        expect(destructive().idempotentHint).toBe(false);
    });

    it('every helper sets openWorldHint=false (closed Builder domain)', () => {
        expect(readOnly().openWorldHint).toBe(false);
        expect(mutating().openWorldHint).toBe(false);
        expect(creating().openWorldHint).toBe(false);
        expect(destructive().openWorldHint).toBe(false);
    });
});
