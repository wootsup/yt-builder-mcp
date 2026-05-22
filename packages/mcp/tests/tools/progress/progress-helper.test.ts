/**
 * Wave G.5 — progress helper tests.
 *
 * Validates the local re-export `createProgressReporter` integrates with
 * the toolkit signature and degrades to `null` when no progressToken
 * was supplied by the caller (i.e. when running under a host that did
 * not request progress).
 *
 * @license MIT
 */

import { describe, expect, it, vi } from 'vitest';

import {
    type HandlerExtra,
    createProgressReporter,
} from '../../../src/tools/tool-builder.js';

describe('Wave G.5 — createProgressReporter', () => {
    it('returns null when caller omits progressToken', () => {
        const extra: HandlerExtra = {
            sendNotification: vi.fn(),
        };
        expect(createProgressReporter(extra)).toBeNull();
    });

    it('returns a reporter when progressToken is present', () => {
        const extra: HandlerExtra = {
            _meta: { progressToken: 'tok-1' },
            sendNotification: vi.fn(),
        };
        expect(createProgressReporter(extra)).not.toBeNull();
    });

    it('forwards report() calls to sendNotification with the token', async () => {
        const sendNotification = vi.fn(async () => undefined);
        const extra: HandlerExtra = {
            _meta: { progressToken: 'tok-2' },
            sendNotification,
        };
        const reporter = createProgressReporter(extra);
        await reporter?.report(0, 2, 'Sending write request');
        expect(sendNotification).toHaveBeenCalledTimes(1);
        const note = sendNotification.mock.calls[0]![0] as {
            method: string;
            params: { progressToken: unknown; progress: number; total: number; message?: string };
        };
        expect(note.method).toBe('notifications/progress');
        expect(note.params.progressToken).toBe('tok-2');
        expect(note.params.progress).toBe(0);
        expect(note.params.total).toBe(2);
        expect(note.params.message).toBe('Sending write request');
    });
});
