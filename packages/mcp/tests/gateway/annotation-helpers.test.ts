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

describe('annotation helpers — shape pins', () => {
    it('readOnly("X") sets readOnlyHint=true + openWorldHint=true, no mutation flags', () => {
        const ann = readOnly('X');
        expect(ann).toEqual({
            title: 'X',
            readOnlyHint: true,
            openWorldHint: true,
        });
    });

    it('mutating("X") sets readOnlyHint=false + idempotentHint=true + openWorldHint=true', () => {
        const ann = mutating('X');
        expect(ann).toEqual({
            title: 'X',
            readOnlyHint: false,
            openWorldHint: true,
            idempotentHint: true,
        });
    });

    it('creating("X") sets readOnlyHint=false + idempotentHint=false + openWorldHint=true', () => {
        const ann = creating('X');
        expect(ann).toEqual({
            title: 'X',
            readOnlyHint: false,
            openWorldHint: true,
            idempotentHint: false,
        });
    });

    it('destructive("X") sets destructiveHint=true + readOnlyHint=false + idempotentHint=false + openWorldHint=true', () => {
        const ann = destructive('X');
        expect(ann).toEqual({
            title: 'X',
            readOnlyHint: false,
            destructiveHint: true,
            openWorldHint: true,
            idempotentHint: false,
        });
    });

    it('all four helpers accept an optional title (undefined when omitted)', () => {
        expect(readOnly().title).toBeUndefined();
        expect(mutating().title).toBeUndefined();
        expect(creating().title).toBeUndefined();
        expect(destructive().title).toBeUndefined();
    });

    it('lane disjointness: destructive is the only helper that sets destructiveHint', () => {
        expect(readOnly().destructiveHint).toBeUndefined();
        expect(mutating().destructiveHint).toBeUndefined();
        expect(creating().destructiveHint).toBeUndefined();
        expect(destructive().destructiveHint).toBe(true);
    });

    it('lane disjointness: readOnly is the only helper that sets readOnlyHint=true', () => {
        expect(readOnly().readOnlyHint).toBe(true);
        expect(mutating().readOnlyHint).toBe(false);
        expect(creating().readOnlyHint).toBe(false);
        expect(destructive().readOnlyHint).toBe(false);
    });

    it('lane disjointness: mutating is the only helper that sets idempotentHint=true', () => {
        expect(readOnly().idempotentHint).toBeUndefined();
        expect(mutating().idempotentHint).toBe(true);
        expect(creating().idempotentHint).toBe(false);
        expect(destructive().idempotentHint).toBe(false);
    });
});
