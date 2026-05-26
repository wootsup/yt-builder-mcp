/**
 * W12-R3 (F-A2-M2) — bearer_ref / token_ref redaction pin.
 *
 * W12-R1.3 added the 1Password Secret Reference keys (`bearer_ref`,
 * `bearerRef`, `token_ref`, `tokenRef`) to the
 * `sanitizeSecrets` SECRET_KEYS allowlist. The reference string itself
 * is not a secret (the vault path is safe to commit), BUT it identifies
 * the vault + item + field of a real bearer — surfacing it verbatim in
 * an error payload that ends up in a Discord paste or a GitHub issue
 * leaks the production 1Password layout.
 *
 * These pins ensure the four key-variants stay redacted at every
 * nesting depth — a future refactor that, say, replaced the
 * `SECRET_KEYS` Set with an array-includes lookup but typo'd one of
 * the four entries would fail this pin in CI before any leak ships.
 *
 * @license MIT
 */

import { describe, expect, it } from 'vitest';

import { sanitizeSecrets } from '../../src/errors/sanitize.js';

describe('W12-R3 — sanitizeSecrets bearer_ref redaction', () => {
    it('top-level bearer_ref → [REDACTED] (snake_case)', () => {
        const input = { bearer_ref: 'op://Claude-Secrets/Item-1/credential' };
        const out = sanitizeSecrets(input);
        expect(out).toEqual({ bearer_ref: '[REDACTED]' });
    });

    it('both bearerRef + tokenRef variants (camelCase) → [REDACTED]', () => {
        // The W12-R1.3 SECRET_KEYS Set carries 4 variants:
        // bearer_ref / bearerRef / token_ref / tokenRef. Defense-in-
        // depth: case-folded handlers MUST NOT drift apart over time.
        const input = {
            bearerRef: 'op://Vault/Item-A/credential',
            tokenRef: 'op://Vault/Item-B/credential',
            token_ref: 'op://Vault/Item-C/credential',
            bearer_ref: 'op://Vault/Item-D/credential',
        };
        const out = sanitizeSecrets(input);
        expect(out).toEqual({
            bearerRef: '[REDACTED]',
            tokenRef: '[REDACTED]',
            token_ref: '[REDACTED]',
            bearer_ref: '[REDACTED]',
        });
    });

    it('nested bearer_ref deep inside a structured-error context is also redacted', () => {
        // The `errorResult` envelope deep-walks `context`; the
        // realistic regression is a tool that echoes its `site_id` +
        // resolved-bearer-ref into the error context for debugging,
        // and the ref then lands in the LLM transcript / Sentry.
        const input = {
            site: {
                site_id: 'wp-acme',
                bearer_ref: 'op://Claude-Secrets/Acme-Prod/credential',
                url: 'https://acme.com',
            },
            additional: {
                deeply: {
                    nested: {
                        tokenRef: 'op://Vault/Other/credential',
                    },
                },
            },
        };
        const out = sanitizeSecrets(input);
        // Non-secret siblings are preserved verbatim.
        expect(out.site.site_id).toBe('wp-acme');
        expect(out.site.url).toBe('https://acme.com');
        // Both nested refs are redacted.
        expect(out.site.bearer_ref).toBe('[REDACTED]');
        expect(out.additional.deeply.nested.tokenRef).toBe('[REDACTED]');
    });
});
