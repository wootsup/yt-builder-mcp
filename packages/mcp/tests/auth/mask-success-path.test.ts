/**
 * Success-path sanitisation tests — Wave G.6.4b.
 *
 * Even when REST calls succeed, the returned payload could carry a
 * `oauth_refresh_token` / `auth_data` / similar from an upstream source.
 * `jsonResult` + `structuredResult` must route the payload through
 * `sanitizeSecrets` BEFORE constructing the content envelope.
 *
 * @license MIT
 */

import { describe, expect, test } from 'vitest';

import { jsonResult, structuredResult } from '../../src/tools/tool-builder.js';

function extractText(result: { content: Array<{ text?: string }> }): string {
    return result.content[0]?.text ?? '';
}

describe('jsonResult — success-path sanitisation', () => {
    test('redacts oauth_refresh_token in the text leg', () => {
        const payload = {
            sources: [
                {
                    name: 'pexels',
                    oauth_refresh_token: 'secret-abc-123',
                },
            ],
        };
        const out = jsonResult(payload);
        const text = extractText(out);
        expect(text).not.toMatch(/secret-abc-123/);
        expect(text).toMatch(/\[REDACTED\]/);
    });

    test('redacts deeply nested secret-keys', () => {
        const payload = {
            data: { credential: { auth_data: 'opaque-encrypted-blob' } },
        };
        const out = jsonResult(payload);
        expect(extractText(out)).not.toMatch(/opaque-encrypted-blob/);
        expect(extractText(out)).toMatch(/\[REDACTED\]/);
    });

    test('passes through unchanged when no secrets present', () => {
        const payload = { id: 1, name: 'public', count: 42 };
        const out = jsonResult(payload);
        const parsed = JSON.parse(extractText(out)) as Record<string, unknown>;
        expect(parsed).toEqual(payload);
    });

    test('preserves error envelope (isError flag) while sanitising', () => {
        const payload = { error: 'failed', secret: 'leak' };
        const out = jsonResult(payload, { isError: true });
        expect(out.isError).toBe(true);
        expect(extractText(out)).toMatch(/\[REDACTED\]/);
    });
});

describe('structuredResult — success-path sanitisation', () => {
    test('redacts secrets in BOTH the text leg AND structuredContent', () => {
        const toolkitResult = {
            content: [
                {
                    type: 'text',
                    text: JSON.stringify({
                        sources: [{ name: 'p', oauth_refresh_token: 'tok-XYZ' }],
                    }),
                },
            ],
        };
        const structured = {
            items: [{ name: 'p', oauth_refresh_token: 'tok-XYZ' }],
            total: 1,
        };
        const out = structuredResult(toolkitResult, structured);

        // Text leg must not contain the raw token.
        const text = out.content[0]?.text as string;
        expect(text).not.toMatch(/tok-XYZ/);

        // structuredContent leg must not contain the raw token either.
        const sc = out.structuredContent as {
            items: Array<{ oauth_refresh_token: string }>;
        };
        expect(sc.items[0].oauth_refresh_token).toBe('[REDACTED]');
    });

    test('passes through clean payloads unchanged', () => {
        const toolkitResult = { content: [{ type: 'text', text: 'hi' }] };
        const structured = { items: [], total: 0 };
        const out = structuredResult(toolkitResult, structured);
        expect(out.structuredContent).toEqual({ items: [], total: 0 });
        expect(out.content[0]?.text).toBe('hi');
    });

    test('preserves _meta.ui from the toolkit', () => {
        const toolkitResult = {
            content: [{ type: 'text', text: 'x' }],
            _meta: { ui: { display: 'detail', payload: { token: 'X' } } },
        };
        const structured = { ok: true };
        const out = structuredResult(toolkitResult, structured);
        // _meta is preserved structurally — sanitisation also applies to
        // any secret-key inside _meta.ui.payload (defense-in-depth).
        expect(out._meta).toBeDefined();
        const meta = out._meta as { ui: { payload: { token: string } } };
        expect(meta.ui.payload.token).toBe('[REDACTED]');
    });

    test('preserves isError flag', () => {
        const toolkitResult = {
            content: [{ type: 'text', text: 'err' }],
            isError: true,
        };
        const out = structuredResult(toolkitResult, { code: 'x' });
        expect(out.isError).toBe(true);
    });
});
