/**
 * `sanitizeSecrets` — recursive value-walker that swaps any value at a
 * known-secret key for the sentinel `[REDACTED]`.
 *
 * Wave G.6.4. Pattern ported verbatim from
 * `apimapper-mcp/src/modules/apimapper/credential-sanitizer.ts` because
 * it's the same defense-in-depth shape and the apimapper version is
 * audit-hardened (A4 round 1 + 2).
 *
 * Used on both the error-path (RestError.body excerpts) AND the
 * success-path (jsonResult / structuredResult payloads in
 * `tool-builder.ts`) so a regressing REST handler — or a logging path —
 * can never leak `oauth_refresh_token` / `auth_data` / `bearer` etc.
 * into the LLM's context.
 *
 * Key-list intentionally mirrors apimapper-mcp; review both together
 * when extending.
 *
 * @license MIT
 */

const SECRET_KEYS = new Set<string>([
    // Generic auth tokens
    'token',
    'bearer',
    'bearer_token',
    'bearerToken',
    // Plugin-internal auth blob
    'auth_data',
    'authData',
    // OAuth flows
    'oauth_refresh_token',
    'oauthRefreshToken',
    'refresh_token',
    'refreshToken',
    'access_token',
    'accessToken',
    // App-secret pairs
    'client_secret',
    'clientSecret',
    'api_key',
    'apiKey',
    'password',
    'secret',
    'secrets',
    'raw_secret',
    'rawSecret',
    // Defense-in-depth additions (cf. apimapper A4 audit round 2)
    'private_key',
    'privateKey',
    'signing_key',
    'signingKey',
    'webhook_secret',
    'webhookSecret',
    'consumer_secret',
    'consumerSecret',
    'consumer_key',
    'consumerKey',
    'session_token',
    'sessionToken',
    'id_token',
    'idToken',
    // W12-R1.3 (A2-M2) — 1Password Secret References are not secrets
    // per se (the ref string itself is safe to log), BUT they identify
    // the vault path + item + field of a real bearer. Surfacing them
    // verbatim in an error payload that ends up in a Discord paste, a
    // GitHub issue, or an LLM-shared transcript leaks the production
    // 1Password layout to anyone reading. Treat as secret to be safe.
    'bearer_ref',
    'bearerRef',
    'token_ref',
    'tokenRef',
]);

/**
 * Recursively walk any JSON-serialisable value and redact any nested
 * value whose key matches a known-secret name. Returns a fresh tree —
 * the input is never mutated.
 *
 * Primitives (string / number / boolean / null / undefined) pass through
 * unchanged. Arrays are mapped element-wise. Objects are reconstructed
 * with `[REDACTED]` substituted at any secret key.
 */
export function sanitizeSecrets<T>(input: T): T {
    if (Array.isArray(input)) {
        return input.map((item) => sanitizeSecrets(item)) as unknown as T;
    }
    if (input !== null && typeof input === 'object') {
        const out: Record<string, unknown> = {};
        for (const [k, v] of Object.entries(input as Record<string, unknown>)) {
            if (SECRET_KEYS.has(k)) {
                out[k] = '[REDACTED]';
            } else {
                out[k] = sanitizeSecrets(v);
            }
        }
        return out as unknown as T;
    }
    return input;
}
