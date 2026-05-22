/**
 * Tests for error classes.
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import { ConfigError, NetworkError, RestError } from '../src/errors.js';

describe('RestError', () => {
    it('carries status / code / message / body', () => {
        const e = new RestError({
            status: 404,
            code: 'not_found',
            message: 'Template not found.',
            body: { code: 'not_found' },
        });
        expect(e.name).toBe('RestError');
        expect(e.status).toBe(404);
        expect(e.code).toBe('not_found');
        expect(e.message).toBe('Template not found.');
        expect(e.body).toEqual({ code: 'not_found' });
    });

    it('makes code optional', () => {
        const e = new RestError({ status: 500, message: 'x', body: null });
        expect(e.code).toBeUndefined();
    });
});

describe('NetworkError', () => {
    it('formats the message using the cause', () => {
        const e = new NetworkError({ cause: new Error('boom'), url: 'https://x.test' });
        expect(e.message).toContain('boom');
        expect(e.message).toContain('https://x.test');
    });

    it('handles non-Error causes', () => {
        const e = new NetworkError({ cause: { detail: 'weird' }, url: 'https://x.test' });
        expect(e.message).toContain('weird');
    });
});

describe('ConfigError', () => {
    it('has the right name', () => {
        const e = new ConfigError('missing');
        expect(e.name).toBe('ConfigError');
        expect(e.message).toBe('missing');
    });
});
